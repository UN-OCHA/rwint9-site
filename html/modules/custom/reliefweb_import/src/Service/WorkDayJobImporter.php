<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_entities\Entity\Job;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionSoftViolation;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionViolation;
use GuzzleHttp\ClientInterface;

/**
 * Service to interact with the WorkDay API.
 */
class WorkDayJobImporter extends JobFeedsImporterBase implements JobFeedsImporterInterface {

  /**
   * The settings for the WorkDay service.
   *
   * @var array
   */
  protected array $settings;

  /**
   * Constructs a WorkDayJobImporter.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $account_switcher
   *   Account switcher.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Logger factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   State service.
   * @param \Drupal\reliefweb_import\Service\WorkDayJobDocumentMapper $documentMapper
   *   Workday document mapper.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    AccountSwitcherInterface $account_switcher,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    protected WorkDayJobDocumentMapper $documentMapper,
  ) {
    parent::__construct(
      $database,
      $entity_type_manager,
      $account_switcher,
      $http_client,
      $logger_factory,
      $state,
    );
  }

  /**
   * Set the settings for the WorkDay service.
   *
   * @param array $settings
   *   The settings to use for the WorkDay service.
   */
  public function setSettings(array $settings): void {
    $this->settings = $settings;
  }

  /**
   * Validate settings.
   */
  public function validateSettings(): void {
    $required_settings = [
      'base_url',
      'tenant',
      'client_id',
      'client_secret',
      'refresh_token',
      'source_id',
      'uid',
    ];

    $missing_settings = [];
    foreach ($required_settings as $setting) {
      if (empty($this->settings[$setting])) {
        $missing_settings[] = $setting;
      }
    }

    if (!empty($missing_settings)) {
      throw new \InvalidArgumentException('Missing required WorkDay settings: ' . implode(', ', $missing_settings));
    }

    // Make sure base_url is a valid URL.
    if (filter_var($this->settings['base_url'], FILTER_VALIDATE_URL) === FALSE) {
      throw new \InvalidArgumentException('The base_url setting is not a valid URL.');
    }

    // Make sure uid points to an existing user.
    if (!$this->entityTypeManager->getStorage('user')->load($this->settings['uid'])) {
      throw new \InvalidArgumentException('The uid setting is not valid.');
    }

    // Make sure source_id points to an existing source.
    if (!$this->entityTypeManager->getStorage('taxonomy_term')->load($this->settings['source_id'])) {
      throw new \InvalidArgumentException('The source_id setting is not valid.');
    }
  }

  /**
   * Get authorization token from WorkDay.
   */
  public function getAuthToken(): string {
    $timeout = $this->settings['timeout'] ?? 10;
    $base_url = rtrim($this->settings['base_url'] ?? '', '/');
    $tenant = $this->settings['tenant'] ?? '';
    $client_id = $this->settings['client_id'] ?? '';
    $client_secret = $this->settings['client_secret'] ?? '';
    $refresh_token = $this->settings['refresh_token'] ?? '';
    $token_url = $base_url . '/ccx/oauth2/' . $tenant . '/token';

    $response = $this->httpClient->request('POST', $token_url, [
      'connect_timeout' => $timeout,
      'timeout' => $timeout,
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'application/json',
      ],
      'form_params' => [
        'grant_type' => 'refresh_token',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $refresh_token,
      ],
    ]);

    if ($response->getStatusCode() !== 200) {
      throw new \Exception('Failure with response code: ' . $response->getStatusCode());
    }

    try {
      $json = json_decode($response->getBody()->getContents(), TRUE, flags: \JSON_THROW_ON_ERROR);
      $token = '';
      if (is_array($json) && isset($json['access_token'])) {
        $token = $json['access_token'];
      }

      if (empty($token)) {
        throw new \Exception('Auth token not found in the response.');
      }
    }
    catch (\Exception $e) {
      throw new \Exception('Unable to decode the auth token: ' . $e->getMessage());
    }

    return $token;
  }

  /**
   * {@inheritdoc}
   */
  public function importJobs(int $limit = 50): void {
    $jobs = $this->getDocuments($limit);

    // Switch to proper user and import XML.
    $uid = $this->settings['uid'] ?? 2;

    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    $account->addRole('job_importer');
    $this->accountSwitcher->switchTo($account);

    $this->importDocuments($jobs);

    // Restore user account.
    $this->accountSwitcher->switchBack();
  }

  /**
   * Retrieve documents from the WorkDay.
   *
   * @param int $limit
   *   Number of documents to fetch.
   *
   * @return array
   *   List of mapped import documents.
   */
  public function getDocuments(int $limit = 50): array {
    $this->getLogger()->info('Retrieving documents from the WorkDay.');

    $documents = [];

    try {
      $timeout = $this->settings['timeout'] ?? 10;
      $base_url = $this->settings['base_url'] ?? '';
      $tenant = $this->settings['tenant'] ?? '';
      $url = $base_url . '/ccx/api/recruiting/v4/' . $tenant . '/jobPostings';

      $auth = $this->getAuthToken();

      $response = $this->httpClient->request('GET', $url, [
        'connect_timeout' => $timeout,
        'timeout' => $timeout,
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'Authorization' => 'Bearer ' . $auth,
        ],
      ]);

      if ($response->getStatusCode() !== 200) {
        throw new \Exception('Failure with response code: ' . $response->getStatusCode());
      }

      $jobs = json_decode($response->getBody()->getContents(), TRUE);

      if (!isset($jobs['data']) || !is_array($jobs['data'])) {
        throw new \Exception('Invalid response structure from WorkDay API.');
      }

      $count = 0;
      foreach ($jobs['data'] as $job) {
        if ($limit > 0 && $count >= $limit) {
          break;
        }

        // Skip jobs without title or URL.
        if (empty($job['title']) || empty($job['url'])) {
          continue;
        }

        // Skip jobs without ID.
        if (!isset($job['id'])) {
          continue;
        }

        $documents[] = $this->documentMapper->mapApiJob($job);
        $count++;
      }

      return $documents;
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Unable to retrieve the WorkDay documents: ' . $e->getMessage());
      return [];
    }
  }

  /**
   * Process the WorkDay documents data.
   *
   * @param array $documents
   *   The WorkDay documents data.
   */
  public function importDocuments(array $documents): void {
    $source_id = $this->settings['source_id'] ?? 0;
    $uid = $this->settings['uid'] ?? 2;

    $errors = [];
    $warnings = [];

    foreach ($documents as $item) {
      $name = $item->title ?? 'unknown';
      try {
        $guid = trim($item->url ?? '');
        $this->url = $guid;

        // Check if job already exist.
        if ($this->jobExists($guid)) {
          $this->getLogger()->notice(strtr('Updating job @guid', [
            '@guid' => $guid,
          ]));

          $job = $this->loadJobByGuid($guid);
          if (empty($job)) {
            throw new ReliefwebImportExceptionViolation(strtr('Unable to load job @guid', [
              '@guid' => $guid,
            ]));
          }

          $this->updateWorkdayJob($job, $item);
        }
        else {
          $this->getLogger()->notice(strtr('Creating new job @guid', [
            '@guid' => $guid,
          ]));
          $this->createWorkdayJob($guid, $item, $uid, $source_id);
        }
      }
      catch (ReliefwebImportExceptionViolation $exception) {
        $errors[] = $exception->getMessage();
      }
      catch (ReliefwebImportExceptionSoftViolation $exception) {
        $warnings[] = $exception->getMessage();
      }
    }

    if (!empty($errors)) {
      $this->getLogger()->error(strtr('Errors while processing @name: @errors', [
        '@name' => $name,
        '@errors' => "\n- " . implode("\n- ", $errors),
      ]));
    }
    if (!empty($warnings)) {
      $this->getLogger()->warning(strtr('Warnings while processing @name: @warnings', [
        '@name' => $name,
        '@warnings' => "\n- " . implode("\n- ", $warnings),
      ]));
    }
  }

  /**
   * Creates a new Workday job.
   *
   * @param string $guid
   *   Feed item unique ID.
   * @param object $data
   *   Mapped import data.
   * @param int $uid
   *   ID of the job owner.
   * @param int $source_id
   *   Source ID.
   */
  protected function createWorkdayJob(string $guid, object $data, int $uid, int $source_id): void {
    $values = [
      'type' => 'job',
      'uid' => $uid,
      'field_source' => $source_id,
      'field_import_guid' => $guid,
    ];
    $job = $this->entityTypeManager->getStorage('node')->create($values);
    $this->updateWorkdayJob($job, $data);
  }

  /**
   * Updates a Workday job from mapped import data.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param object $data
   *   Mapped import data.
   */
  protected function updateWorkdayJob(Job $job, object $data): void {
    $this->updateJobFromImportData(
      $job,
      $data,
      $this->getWorkdayImportFieldDefinitions(),
      $this->getFeedHashFields(),
      $this->getWorkdayImportContext(),
    );
  }

  /**
   * Returns field definitions for Workday imports.
   *
   * @return array<string, array{callback: string, property: string}>
   *   Field definitions.
   */
  protected function getWorkdayImportFieldDefinitions(): array {
    return $this->getImportFieldDefinitions(include_city: FALSE);
  }

  /**
   * Returns import context for Workday job imports.
   *
   * @return array<string, mixed>
   *   Import context options.
   */
  protected function getWorkdayImportContext(): array {
    $context = [
      'source' => 'workday',
    ];

    if (!empty($this->settings['classification']['enabled'])) {
      $context['classification_enabled'] = TRUE;
      $context['deferred_fields'] = [
        'field_career_categories',
        'field_theme',
      ];
    }

    return $context;
  }

}
