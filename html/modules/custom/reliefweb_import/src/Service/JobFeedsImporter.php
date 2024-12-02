<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_entities\Entity\Job;
use Drupal\reliefweb_entities\Plugin\Validation\Constraint\DateNotInPastConstraint;
use Drupal\reliefweb_import\Exception\ReliefwebImportException;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionSoftViolation;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionViolation;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionXml;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drupal\taxonomy\Entity\Term;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * ReliefWeb job feeds importer service.
 */
class JobFeedsImporter implements JobFeedsImporterInterface {

  /**
   * The source URL.
   *
   * @var string
   */
  protected string $url;

  /**
   * Loaded term IDs.
   *
   * @var array
   */
  protected array $loadedTermIds = [];

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

  /**
   * Check mandatory fields.
   *
   * @param \SimpleXMLElement $data
   *   XML data for the job.
   * @param string $base_url
   *   Feed's base URL.
   * @param int $source_id
   *   ID of the source term the feed belongs to.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportExceptionViolation
   *   If the mandatory fields are not all present or valid.
   */
  protected function checkMandatoryFields(\SimpleXMLElement $data, string $base_url, int $source_id): void {
    try {
      $this->validateLink((string) ($data->link[0] ?? ''), $base_url);
      $this->validateTitle((string) ($data->title[0] ?? ''));
      $this->validateSource((string) ($data->field_source[0] ?? ''), $source_id);
    }
    catch (\Exception $exception) {
      throw new ReliefwebImportExceptionViolation($exception->getMessage());
    }
  }

  /**
   * Check if job exists.
   *
   * @param string $guid
   *   Job feed unique ID.
   *
   * @return bool
   *   TRUE if the job was already imported.
   */
  protected function jobExists(string $guid): bool {
    $ids = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_import_guid', $guid, '=')
      ->execute();
    return !empty($ids);
  }

  /**
   * Load job by its import unique ID.
   *
   * @param string $guid
   *   Job feed unique ID.
   *
   * @return \Drupal\reliefweb_entities\Entity\Job|null
   *   The job entity if it exists.
   */
  protected function loadJobByGuid(string $guid): ?Job {
    $entities = $this->entityTypeManager
      ->getStorage('node')
      ->loadByProperties([
        'field_import_guid' => $guid,
      ]);
    return !empty($entities) ? reset($entities) : NULL;
  }

  /**
   * Create a new job.
   *
   * @param string $guid
   *   Feed item unique ID.
   * @param \SimpleXMLElement $data
   *   XML data for the job.
   * @param int $uid
   *   ID of the job owner.
   * @param int $source_id
   *   Source ID.
   */
  protected function createJob(string $guid, \SimpleXMLElement $data, int $uid, int $source_id): void {
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
   * Create a new job.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   XML data for the job.
   */
  protected function updateJob(Job $job, \SimpleXMLElement $data): void {
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

  /**
   * Set the job title.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobTitle(Job $job, \SimpleXMLElement $data): void {
    $job->title = $this->validateTitle((string) $data);
  }

  /**
   * Set the job body.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobBody(Job $job, \SimpleXMLElement $data): void {
    $job->body = [
      'value' => $this->validateBody($data->asXML() ?: ''),
      'summary' => NULL,
      'format' => 'markdown_editor',
    ];
  }

  /**
   * Set the job how to apply.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobHowToApply(Job $job, \SimpleXMLElement $data): void {
    $job->field_how_to_apply = [
      'value' => $this->validateHowToApply($data->asXML() ?: ''),
      'format' => 'markdown_editor',
    ];
  }

  /**
   * Set the job closing date.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobClosingDate(Job $job, \SimpleXMLElement $data): void {
    $job->field_job_closing_date = $this->validateJobClosingDate((string) $data);
  }

  /**
   * Set the job type.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobType(Job $job, \SimpleXMLElement $data): void {
    $ids = $this->getTermIds('job_type');
    // Silently skip invalid term ids and limit to 1 term.
    $job->field_job_type = array_slice(array_intersect((array) $data, $ids), 0, 1);
  }

  /**
   * Set the job type.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobExperience(Job $job, \SimpleXMLElement $data): void {
    $ids = $this->getTermIds('job_experience');
    // Silently skip invalid term ids.
    $job->field_job_experience = $this->validateJobExperience(array_intersect((array) $data, $ids));
  }

  /**
   * Set the job career categories.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobCareerCategories(Job $job, \SimpleXMLElement $data): void {
    $ids = $this->getTermIds('career_category');
    // Silently skip invalid term ids.
    $job->field_career_categories = array_intersect((array) $data, $ids);
  }

  /**
   * Set the job themes.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobThemes(Job $job, \SimpleXMLElement $data): void {
    $ids = $this->getTermIds('theme');
    // Silently skip invalid term ids and limit to 3 themes.
    $job->field_theme = array_slice(array_intersect((array) $data, $ids), 0, 3);
  }

  /**
   * Set the job country.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobCountry(Job $job, \SimpleXMLElement $data): void {
    $job->field_country = $this->mapCountries((array) $data);
  }

  /**
   * Set the job city.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobCity(Job $job, \SimpleXMLElement $data): void {
    if (!$job->field_country->isEmpty()) {
      $job->field_city = $this->validateCity((string) $data);
    }
    else {
      $job->field_city = [];
    }
  }

  /**
   * Get the taxonomy term ids for the given vocabulary.
   *
   * @param string $vocabulary
   *   Taxonomy vocabulary.
   *
   * @return array
   *   List of term IDs.
   */
  protected function getTermIds(string $vocabulary): array {
    if (!isset($this->loadedTermIds[$vocabulary])) {
      $ids = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', $vocabulary, '=')
        ->execute();
      $this->loadedTermIds[$vocabulary] = $ids;
    }
    return $this->loadedTermIds[$vocabulary];
  }

  /**
   * Validate and save job.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportExceptionSoftViolation
   *   Exception if there were validation errors so they can be logged.
   */
  protected function validateAndSaveJob(Job $job): void {
    // Revision user is always 'System'.
    $job->setRevisionUserId($job->getOwnerId());
    $job->setNewRevision(TRUE);
    $job->setRevisionCreationTime(time());

    // Revision message.
    if ($job->isNew()) {
      $log = strtr('Job @guid imported from @url.', [
        '@guid' => $job->field_import_guid->value,
        '@url' => $this->url,
      ]);
    }
    else {
      $log = strtr('Job @guid updated from @url.', [
        '@guid' => $job->field_import_guid->value,
        '@url' => $this->url,
      ]);
    }

    // Set the default status as pending as if it were a submission by an
    // unverified user. The appropriate status will be set when saving the job.
    $job->setModerationStatus('pending');

    // Validate the job.
    /** @var \Symfony\Component\Validator\ConstraintViolation $violation */
    foreach ($job->validate() as $violation) {
      $constraint = $violation->getConstraint();
      // Ignore the constraint on the closing date so that the feed publisher
      // can close a job by changing the closing date which will mark the job
      // as expired.
      if ($constraint instanceof DateNotInPastConstraint) {
        continue;
      }
      $field = preg_replace('#^([a-z0-9_-]+).*#', '$1', $violation->getPropertyPath());
      // No need to add another validation message if there was already one for
      // the field.
      if (!isset($job->_import_errors[$field])) {
        $job->_import_errors[$field] = $violation->getMessage()->__toString();
      }
    }

    // Update the revision log message with the list of validation errors to
    // help identify what was wrong.
    if (!empty($job->_import_errors)) {
      $job->setRevisionLogMessage(implode("\n", array_merge([$log], $job->_import_errors)));
    }
    else {
      $job->setRevisionLogMessage($log);
    }

    // Flag the job as being imported so that the status can be updated
    // in reliefweb_import_node_presave() based on the validation errors.
    $job->_is_importing = TRUE;

    // Ensure notifications are disabled.
    $job->notifications_content_disable = TRUE;

    // Save the job.
    $job->save();

    // Log the message about the creation or update.
    $this->getLogger()->info($log);

    // If there were validation errors, throw a soft violation exception with
    // the cancatenated error messages.
    if (!empty($job->_import_errors)) {
      $message = '';
      foreach ($job->_import_errors as $field => $error) {
        $field_label = $job->{$field}->getFieldDefinition()->getLabel();
        $message .= "\n--- {$field_label}: {$error}";
      }

      throw new ReliefwebImportExceptionSoftViolation(strtr('Validation errors for job @guid imported from @url: @errors', [
        '@guid' => $job->field_import_guid->value,
        '@url' => $this->url,
        '@errors' => $message,
      ]));
    }
  }

  /**
   * Our logger.
   *
   * @return \Drupal\Core\Logger\LoggerChannelInterface
   *   Logger.
   */
  protected function getLogger(): LoggerChannelInterface {
    return $this->loggerFactory->get('reliefweb_import');
  }

  /**
   * Validate user.
   *
   * @param mixed $uid
   *   User ID.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportException
   *   If invalid.
   */
  protected function validateUser(mixed $uid): void {
    if (is_string($uid)) {
      if (trim($uid) === '') {
        throw new ReliefwebImportException('User Id is not defined.');
      }

      if (!is_numeric($uid)) {
        throw new ReliefwebImportException('User Id is not numeric.');
      }

      $uid = (int) $uid;
    }
    elseif (!is_int($uid)) {
      throw new ReliefwebImportException('User Id is not numeric.');
    }

    if ($uid <= 2) {
      throw new ReliefwebImportException('User Id is an admin.');
    }
  }

  /**
   * Validate base URL.
   *
   * @param string $base_url
   *   Base URL.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportException
   *   If invalid.
   */
  protected function validateBaseUrl(string $base_url): void {
    $base_url = trim($base_url);
    if ($base_url === '') {
      throw new ReliefwebImportException('Base URL is empty.');
    }

    if (!UrlHelper::isValid($base_url, TRUE)) {
      throw new ReliefwebImportException('Base URL is not a valid link.');
    }
  }

  /**
   * Validate link.
   *
   * @param string $link
   *   Raw job link.
   * @param string $base_url
   *   Base URL for the job links.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportException
   *   If invalid.
   */
  protected function validateLink(string $link, string $base_url): void {
    $link = trim($link);
    if ($link === '') {
      throw new ReliefwebImportException('Feed item found without a link.');
    }

    if (!UrlHelper::isValid($link, TRUE)) {
      throw new ReliefwebImportException('Invalid feed item link.');
    }

    if (strpos($link, $base_url) !== 0) {
      throw new ReliefwebImportException('Invalid feed item link base.');
    }
  }

  /**
   * Validate and sanitize title.
   *
   * @param string $title
   *   Raw title.
   *
   * @return string
   *   Sanitized title.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportException
   *   If invalid.
   */
  protected function validateTitle(string $title): string {
    $title = trim($title);
    if ($title === '') {
      throw new ReliefwebImportException('Job found with empty title.');
    }

    // Clean the title.
    $title = strip_tags($title);

    $options = [
      'line_breaks' => TRUE,
      'consecutive' => TRUE,
    ];
    $title = TextHelper::cleanText($title, $options);

    // Ensure the title size is reasonable. The max length matches the one
    // from the job form.
    $length = mb_strlen($title);
    if ($length < 10 || $length > 150) {
      throw new ReliefwebImportException('Invalid title length.');
    }

    return $title;
  }

  /**
   * Validate source.
   *
   * @param string $source
   *   Raw source.
   * @param int $source_id
   *   ID of the source term being processed.
   *
   * @return int
   *   Valid source ID.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportException
   *   If invalid.
   */
  protected function validateSource(string $source, int $source_id): int {
    $source = trim($source);
    if ($source === '') {
      throw new ReliefwebImportException('Job found with empty source.');
    }

    if (!is_numeric($source)) {
      throw new ReliefwebImportException('Job found with non numeric source.');
    }

    $source = (int) $source;

    if ($source !== $source_id) {
      throw new ReliefwebImportException(strtr('Invalid job source: expected @source_id, got @source.', [
        '@source_id' => $source_id,
        '@source' => $source,
      ]));
    }

    return $source;
  }

  /**
   * Validate the body field of a feed item, clean and check its size.
   *
   * @param string $data
   *   Raw data from XML.
   *
   * @return string
   *   Sanitized body.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportException
   *   If invalid.
   */
  protected function validateBody(string $data): string {
    // Clean the body field.
    $body = $this->sanitizeText('body', $data);

    // Ensure the body field size is reasonable.
    $length = mb_strlen($body);
    if ($length < 400 || $length > 50000) {
      throw new ReliefwebImportException(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000.', [
        '@length' => $length,
      ]));
    }

    return $body;
  }

  /**
   * Validate the how to apply field.
   *
   * @param string $data
   *   Raw data from XML.
   *
   * @return string
   *   Sanitized How to apply field.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportException
   *   If invalid.
   */
  protected function validateHowToApply(string $data): string {
    // Clean the field.
    $field_how_to_apply = $this->sanitizeText('field_how_to_apply', $data, 3);

    // Ensure the field size is reasonable.
    $length = mb_strlen($field_how_to_apply);
    if ($length < 100 || $length > 10000) {
      throw new ReliefwebImportException(strtr('Invalid field size for field_how_to_apply, @length characters found, has to be between 100 and 10000.', [
        '@length' => $length,
      ]));
    }

    return $field_how_to_apply;
  }

  /**
   * Validate job closing date field.
   *
   * @param string $data
   *   Raw data from XML.
   *
   * @return string
   *   Sanitized job closing date.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportException
   *   If invalid.
   */
  protected function validateJobClosingDate(string $data): string {
    // Clean the field.
    $field_job_closing_date = mb_substr($data, 0, 10);

    // Ensure the field size is reasonable.
    $length = mb_strlen($field_job_closing_date);
    if ($length !== 0 && $length !== 10) {
      throw new ReliefwebImportException(strtr('Invalid data for field_job_closing_date, @length characters found, format has to be yyyy-mm-dd.', [
        '@length' => $length,
      ]));
    }

    // Make sure field can be converted to a date.
    if ($length === 10 && !date_create_from_format('Y-m-d', $field_job_closing_date)) {
      throw new ReliefwebImportException(strtr('Invalid data for field_job_closing_date, @data has to be in format yyyy-mm-dd.', [
        '@data' => $field_job_closing_date,
      ]));
    }

    return $field_job_closing_date;
  }

  /**
   * Validate and sanitize the job_experience field for the feed item.
   *
   * @param array $values
   *   Job experience term IDs.
   *
   * @return array
   *   Valid job experience term IDs.
   */
  protected function validateJobExperience(array $values): array {
    // Map "N/A" to "0-3 years" to accomodate changes to the specifications.
    foreach ($values as &$value) {
      // Not using strict equality since it may be a string.
      if ($value == 262) {
        $value = 258;
      }
    }

    return $values;
  }

  /**
   * Validate and sanitize the city field.
   *
   * @param string $data
   *   Raw data from XML.
   *
   * @return string
   *   Sanitized city.
   *
   * @throws \Drupal\reliefweb_import\Exception\ReliefwebImportException
   *   If invalid.
   */
  protected function validateCity(string $data): string {
    // Clean the field.
    $field_city = TextHelper::cleanText(strip_tags($data), [
      'line_breaks' => TRUE,
      'consecutive' => TRUE,
    ]);

    // Skip if the city is empty.
    if (empty($field_city)) {
      return [];
    }

    // Ensure the field size is reasonable.
    $length = mb_strlen($field_city);
    if ($length < 3 || $length > 255) {
      throw new ReliefwebImportException(strtr('Invalid field size for field_city, @length characters found, has to be between 3 and 255.', [
        '@length' => $length,
      ]));
    }

    return $field_city;
  }

  /**
   * Sanitize text, converting it to markdown.
   *
   * @param string $field
   *   Field name.
   * @param string $text
   *   Field text content.
   * @param int $max_heading_level
   *   Maximum heading level.
   *
   * @return string
   *   Sanitized content.
   */
  protected function sanitizeText(string $field, string $text, int $max_heading_level = 2): string {
    if (!is_string($text)) {
      return '';
    }

    // Trim the input text.
    $text = trim($text);
    if (empty($text)) {
      return '';
    }

    // Remove the field starting and closing tags.
    if (str_starts_with($text, '<' . $field . '>')) {
      $text = substr($text, strlen('<' . $field . '>'));
    }
    if (str_ends_with($text, '</' . $field . '>')) {
      $text = substr($text, 0, -strlen('</' . $field . '>'));
    }

    // Clean the text, removing notably control characters.
    $text = TextHelper::cleanText($text);

    // Check if the text is wrapped in a CDATA.
    if (mb_stripos($text, '<![CDATA[') === 0) {
      $end = mb_strpos($text, ']]>');
      $text = mb_substr($text, 9, $end !== FALSE ? $end - 9 : NULL);
    }
    elseif (mb_stripos($text, '&lt;![CDATA[') === 0) {
      $end = mb_strpos($text, ']]&gt;');
      $text = mb_substr($text, 12, $end !== FALSE ? $end - 12 : NULL);
    }

    // Check if the content contains some non encoded html tags, in which case
    // we will assume that the text is non encoded html/markdown. For that we
    // simply search for a closing tag '</...>'. Otherwise we decode the text.
    if (preg_match('#(?:</[^>]+>)|(?:<[^>]+/>)#', $text) !== 1) {
      $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    }

    // We assume the input is in markdow as recommended in the specificiations.
    // We convert it to HTML and sanitize the output to remove any unsupported
    // HTML markup.
    $html = HtmlSanitizer::sanitizeFromMarkdown($text, FALSE, $max_heading_level - 1);

    // Remove embedded content.
    $html = TextHelper::stripEmbeddedContent($html);

    // Finally we convert the HTML to markdown which is our storage format.
    $converter = new HtmlConverter();
    $converter->getConfig()->setOption('strip_tags', TRUE);
    $converter->getConfig()->setOption('use_autolinks', FALSE);
    $converter->getConfig()->setOption('header_style', 'atx');
    $converter->getConfig()->setOption('strip_placeholder_links', TRUE);
    $converter->getConfig()->setOption('italic_style', '*');
    $converter->getConfig()->setOption('bold_style', '**');

    $text = trim($converter->convert($html));

    return $text;
  }

  /**
   * Country mapping.
   *
   * Maps field_country values onto the target item
   * after converting ISO3 codes to their corresponding term ids.
   *
   * @param array $values
   *   List of values.
   */
  protected function mapCountries(array $values): array {
    // Load the country ISO3 -> ID mapping.
    static $ids;
    if (!isset($ids)) {
      $ids = $this->database->query("
        SELECT UPPER(field_iso3_value), entity_id
        FROM {taxonomy_term__field_iso3}
        WHERE bundle = 'country'
      ")->fetchAllKeyed();
    }

    // Convert the ISO3 values to their corresponding term IDs.
    $terms = [];
    foreach ($values as $value) {
      $iso3 = strtoupper($value);
      // We ignore invalid countries.
      if (isset($ids[$iso3])) {
        $terms[] = $ids[$iso3];
      }
    }

    return $terms;
  }

}
