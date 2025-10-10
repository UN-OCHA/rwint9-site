<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Service;

use Drupal\reliefweb_entities\Entity\Job;
use Drupal\reliefweb_import\Exception\ReliefwebImportException;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionSoftViolation;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionViolation;

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
   * Set the settings for the WorkDay service.
   *
   * @param array $settings
   *   The settings to use for the WorkDay service.
   */
  public function setSettings(array $settings): void {
    $this->settings = $settings;
  }

  /**
   * Get authorization token from WorkDay.
   */
  public function getAuthToken(): string {
    $timeout = $this->settings['timeout'] ?? 10;
    $base_url = $this->settings['base_url'] ?? '';
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
      $token = json_decode($response->getBody()->getContents(), TRUE, flags: \JSON_THROW_ON_ERROR)['access_token'];
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
   *   List of documents keyed by IDs.
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
      foreach ($jobs['data'] as $job) {
        // Skip jobs without title or URL.
        if (empty($job['title']) || empty($job['url'])) {
          continue;
        }

        $documents[] = [
          'id' => $job['id'],
          'field_job_closing_date' => $job['startDate'] ?? '',
          'job_type' => $job['jobType']['descriptor'] ?? '',
          'company' => $job['company']['descriptor'] ?? '',
          'time_type' => $job['timeType']['descriptor'] ?? '',
          'field_city' => $job['primaryLocation']['descriptor'] ?? '',
          'field_country' => [$job['primaryLocation']['country']['descriptor'] ?? ''],
          'url' => $job['url'] ?? '',
          'title' => $job['title'],
          'body' => $job['jobDescription'] ?? '',
        ];
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
      $name = $item['title'] ?? 'unknown';
      try {
        $guid = trim($item['url'] ?? '');
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
          $this->updateJob($job, $item);
        }
        else {
          $this->getLogger()->notice(strtr('Creating new job @guid', [
            '@guid' => $guid,
          ]));
          $this->createJob($guid, (object) $item, $uid, $source_id);
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
   * Create a new job.
   *
   * @param string $guid
   *   Feed item unique ID.
   * @param object|array $data
   *   Data for the job.
   * @param int $uid
   *   ID of the job owner.
   * @param int $source_id
   *   Source ID.
   */
  protected function createJob(string $guid, object|array $data, int $uid, int $source_id): void {
    $values = [
      'type' => 'job',
      'uid' => $uid,
      'field_source' => $source_id,
      'field_import_guid' => $guid,
    ];
    $job = $this->entityTypeManager->getStorage('node')->create($values);
    $this->updateJob($job, $data);
  }

  /**
   * Update an existing job.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param object|array $data
   *   Data for the job.
   */
  protected function updateJob(Job $job, object|array $data): void {
    $fields = [
      'title' => [
        'callback' => 'setJobTitle',
        'property' => 'title',
      ],
      'body' => [
        'callback' => 'setJobBody',
        'property' => 'body',
      ],
      'field_how_to_apply' => [
        'callback' => 'setJobHowToApply',
        'property' => 'field_how_to_apply',
      ],
      'field_job_closing_date' => [
        'callback' => 'setJobClosingDate',
        'property' => 'field_job_closing_date',
      ],
      'field_job_type' => [
        'callback' => 'setJobType',
        'property' => 'field_job_type',
      ],
      'field_job_experience' => [
        'callback' => 'setJobExperience',
        'property' => 'field_job_experience',
      ],
      'field_career_categories' => [
        'callback' => 'setJobCareerCategories',
        'property' => 'field_career_categories',
      ],
      'field_theme' => [
        'callback' => 'setJobThemes',
        'property' => 'field_theme',
      ],
      'field_country' => [
        'callback' => 'setJobCountry',
        'property' => 'field_country',
      ],
      'field_city' => [
        'callback' => 'setJobCity',
        'property' => 'field_city',
      ],
    ];

    $data_for_hash = [
      'field_source' => $job->field_source->getValue(),
      'field_import_guid' => $job->field_import_guid->getValue(),
    ];
    foreach ($fields as $field => $info) {
      try {
        if (isset($data->{$info['property']})) {
          $this->{$info['callback']}($job, $data->{$info['property']});
        }
      }
      catch (ReliefwebImportException $exception) {
        // Empty the field if invalid and store the error.
        $job->{$field} = [];
        $job->_import_errors[$field] = $exception->getMessage();
      }
      $data_for_hash[$field] = $job->{$field}->getValue();
    }

    $hash = hash('sha256', serialize($data_for_hash));
    if ($job->field_import_hash->value === $hash) {
      $this->getLogger()->notice(strtr('No changes detected for job @guid, skipping.', [
        '@guid' => $job->field_import_guid->value,
      ]));
    }
    else {
      $job->field_import_hash->value = $hash;
      $this->validateAndSaveJob($job);
    }
  }

}
