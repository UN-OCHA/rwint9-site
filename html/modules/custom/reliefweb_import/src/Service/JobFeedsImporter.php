<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_import\Exception\ReliefwebImportException;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionSoftViolation;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionViolation;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionXml;
use Drupal\taxonomy\Entity\Term;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

/**
 * ReliefWeb job feeds importer service.
 */
class JobFeedsImporter extends JobFeedsImporterBase implements JobFeedsImporterInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountSwitcherInterface $accountSwitcher,
    protected ClientInterface $httpClient,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function importJobs(int $limit = 50): void {
    // Load terms having a job URL.
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query->condition('vid', 'source');
    $query->condition('field_job_import_feed.feed_url', NULL, 'IS NOT NULL');
    $tids = $query->accessCheck(TRUE)->execute();
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
    foreach ($terms as $term) {
      $this->fetchJobs($term);
    }
  }

  /**
   * Fetch and process jobs.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   Source term.
   */
  public function fetchJobs(Term $term): void {
    $label = $term->label();
    $source_id = (int) $term->id();
    $base_url = $term->field_job_import_feed->first()->base_url ?? '';
    $uid = $term->field_job_import_feed->first()->uid ?? FALSE;
    $this->url = $term->field_job_import_feed->first()->feed_url;

    $this->getLogger()->info('Processing @name, fetching jobs from @url.', [
      '@name' => $label,
      '@url' => $this->url,
    ]);

    try {
      $this->validateUser($uid);
      $this->validateBaseUrl($base_url);
    }
    catch (ReliefwebImportException $exception) {
      $this->getLogger()->error(strtr('Unable to process @name, invalid feed information: @message.', [
        '@name' => $label,
        '@message' => $exception->getMessage(),
      ]));
      return;
    }

    // Ensure the user ID is an integer.
    $uid = (int) $uid;

    // Fetch the XML.
    try {
      $data = $this->fetchXml($this->url);
    }
    catch (ClientException $exception) {
      $this->getLogger()->error(strtr('Unable to process @name, http error @code: @message.', [
        '@name' => $label,
        '@code' => $exception->getCode(),
        '@message' => $exception->getMessage(),
      ]));
      return;
    }
    catch (RequestException $exception) {
      $this->getLogger()->error(strtr('Unable to process @name, general error @code: @message.', [
        '@name' => $label,
        '@code' => $exception->getCode(),
        '@message' => $exception->getMessage(),
      ]));
      return;
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(strtr('Unable to process @name: @message.', [
        '@name' => $label,
        '@message' => $exception->getMessage(),
      ]));
      return;
    }

    // Switch to proper user and import XML.
    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    // @todo review if that's needed.
    $account->addRole('job_importer');
    $this->accountSwitcher->switchTo($account);

    // Process the feed items.
    $this->processXml($label, $data, $uid, $base_url, $source_id);

    // Restore user account.
    $this->accountSwitcher->switchBack();
  }

  /**
   * Fetch XML data.
   *
   * @param string $url
   *   URL of the job feed to import.
   *
   * @return string
   *   Feed's content.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportExceptionXml
   *   If the feed XML data could not be retrieved.
   * @throws \Psr\Http\Client\Throwable
   *   If there is an error during the request.
   */
  protected function fetchXml(string $url): string {
    $response = $this->httpClient->request('GET', $url);
    // Check status code.
    if ($response->getStatusCode() !== 200) {
      throw new ReliefwebImportExceptionXml($response->getReasonPhrase());
    }

    // Get body.
    $body = (string) $response->getBody();

    // Return if empty.
    if (empty($body)) {
      throw new ReliefwebImportExceptionXml('Empty body.');
    }

    return $body;
  }

  /**
   * Process XML data.
   *
   * @param string $name
   *   Source name.
   * @param string $body
   *   XML content.
   * @param int $uid
   *   ID of the user entity to use as owner of the job.
   * @param string $base_url
   *   Feed's base URL.
   * @param int $source_id
   *   ID of the source term the feed belongs to.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportExceptionViolation
   *   If there an error perventing the processing of a job.
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportExceptionSoftViolation
   *   If the processed job was not completely valid.
   */
  protected function processXml(string $name, string $body, int $uid, string $base_url, int $source_id): void {
    $xml = new \SimpleXMLElement($body);

    if ($xml->count() === 0) {
      $this->getLogger()->info(strtr('No feed items to import for @name', [
        '@name' => $name,
      ]));
      return;
    }

    $errors = [];
    $warnings = [];
    foreach ($xml as $item) {
      try {
        $this->checkMandatoryFields($item, $base_url, $source_id);

        $guid = trim((string) $item->link);

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
          $this->createJob($guid, $item, $uid, $source_id);
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

}
