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
use Drupal\reliefweb_import\JobImport\JobImportStateStore;
use Drupal\reliefweb_import\Exception\ReliefwebImportException;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionSoftViolation;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionViolation;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use GuzzleHttp\ClientInterface;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * ReliefWeb job feeds importer service.
 */
class JobFeedsImporterBase {

  /**
   * Loaded term IDs.
   *
   * @var array
   */
  protected array $loadedTermIds = [];

  /**
   * The source URL.
   *
   * @var string
   */
  protected string $url;

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
    $this->updateJobFromImportData(
      $job,
      $this->normalizeXmlImportData($data),
      $this->getImportFieldDefinitions(include_city: TRUE),
      $this->getFeedHashFields(),
      ['source' => 'feed'],
    );
  }

  /**
   * Normalizes XML feed data to a plain object for import.
   *
   * @param \SimpleXMLElement $data
   *   XML feed item.
   *
   * @return object
   *   Import data object.
   */
  protected function normalizeXmlImportData(\SimpleXMLElement $data): object {
    $normalized = new \stdClass();
    foreach ($this->getImportFieldDefinitions(include_city: TRUE) as $info) {
      $property = $info['property'];
      if (isset($data->{$property})) {
        $value = $data->{$property};
        if ($value instanceof \SimpleXMLElement && in_array($info['callback'], [
          'setJobType',
          'setJobExperience',
          'setJobCareerCategories',
          'setJobThemes',
          'setJobCountry',
        ], TRUE)) {
          $normalized->{$property} = (array) $value;
        }
        elseif ($value instanceof \SimpleXMLElement && $info['callback'] === 'setJobBody') {
          $normalized->{$property} = $value->asXML();
        }
        elseif ($value instanceof \SimpleXMLElement && $info['callback'] === 'setJobHowToApply') {
          $normalized->{$property} = $value->asXML();
        }
        else {
          $normalized->{$property} = (string) $value;
        }
      }
    }
    return $normalized;
  }

  /**
   * Returns field setter definitions for job imports.
   *
   * @param bool $include_city
   *   Whether to include the deprecated city field.
   *
   * @return array<string, array{callback: string, property: string}>
   *   Field definitions keyed by field name.
   */
  protected function getImportFieldDefinitions(bool $include_city = FALSE): array {
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
    ];

    if ($include_city) {
      $fields['field_city'] = [
        'callback' => 'setJobCity',
        'property' => 'field_city',
      ];
    }

    return $fields;
  }

  /**
   * Returns feed-sourced fields used for import hash calculation.
   *
   * @return string[]
   *   Field machine names.
   */
  protected function getFeedHashFields(): array {
    return [
      'title',
      'body',
      'field_how_to_apply',
      'field_job_closing_date',
      'field_job_type',
      'field_job_experience',
      'field_country',
    ];
  }

  /**
   * Updates a job from mapped import data and saves when the hash changes.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param object $data
   *   Mapped import data.
   * @param array<string, array{callback: string, property: string}> $fields
   *   Field setter definitions.
   * @param string[] $hash_fields
   *   Properties included in the change-detection hash.
   * @param array<string, mixed> $import_context
   *   Import context passed to JobImportStateStore.
   */
  protected function updateJobFromImportData(
    Job $job,
    object $data,
    array $fields,
    array $hash_fields,
    array $import_context = [],
  ): void {
    JobImportStateStore::markImporting($job, $import_context);

    if (!empty($data->import_notes) && is_array($data->import_notes)) {
      foreach ($data->import_notes as $note) {
        if (is_string($note) && $note !== '') {
          JobImportStateStore::addNote($job, $note);
        }
      }
    }

    foreach ($fields as $field => $info) {
      try {
        if (isset($data->{$info['property']})) {
          $this->{$info['callback']}($job, $data->{$info['property']});
        }
      }
      catch (ReliefwebImportException $exception) {
        if (!$this->shouldDeferImportError($job, $field)) {
          $job->{$field} = [];
          JobImportStateStore::setError($job, $field, $exception->getMessage());
        }
      }
    }

    $hash = hash('sha256', serialize($this->buildImportHashData($data, $hash_fields)));
    if ($job->field_import_hash->value === $hash) {
      JobImportStateStore::clear($job);
      $this->getLogger()->notice(strtr('No changes detected for job @guid, skipping.', [
        '@guid' => $job->field_import_guid->value,
      ]));
      return;
    }

    $job->field_import_hash->value = $hash;
    $this->validateAndSaveJob($job);
  }

  /**
   * Builds hash input from mapped feed data.
   *
   * @param object $data
   *   Mapped import data.
   * @param string[] $hash_fields
   *   Property names to include.
   *
   * @return array<string, mixed>
   *   Hash payload.
   */
  protected function buildImportHashData(object $data, array $hash_fields): array {
    $hash_data = [];
    foreach ($hash_fields as $property) {
      if (isset($data->{$property})) {
        $hash_data[$property] = $data->{$property};
      }
    }
    return $hash_data;
  }

  /**
   * Whether a field validation error should be deferred during import.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job being imported.
   * @param string $field
   *   Field machine name.
   *
   * @return bool
   *   TRUE when the error should not be recorded.
   */
  protected function shouldDeferImportError(Job $job, string $field): bool {
    $context = JobImportStateStore::getContext($job);
    if ($context === NULL || !$context->classificationEnabled) {
      return FALSE;
    }
    return in_array($field, $context->deferredFields, TRUE);
  }

  /**
   * Set the job title.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobTitle(Job $job, string|\SimpleXMLElement $data): void {
    if ($data instanceof \SimpleXMLElement) {
      $data = (string) $data;
    }
    $job->title = $this->validateTitle($data);
  }

  /**
   * Set the job body.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobBody(Job $job, string|\SimpleXMLElement $data): void {
    if ($data instanceof \SimpleXMLElement) {
      $data = $data->asXML();
    }
    $job->body = [
      'value' => $this->validateBody($data ?? ''),
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
  protected function setJobHowToApply(Job $job, string|\SimpleXMLElement $data): void {
    if ($data instanceof \SimpleXMLElement) {
      $data = $data->asXML();
    }
    $job->field_how_to_apply = [
      'value' => $this->validateHowToApply($data ?? ''),
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
  protected function setJobClosingDate(Job $job, string|\SimpleXMLElement $data): void {
    if ($data instanceof \SimpleXMLElement) {
      $data = (string) $data;
    }
    $job->field_job_closing_date = $this->validateJobClosingDate($data);
  }

  /**
   * Set the job type.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobType(Job $job, array|\SimpleXMLElement $data): void {
    if ($data instanceof \SimpleXMLElement) {
      $data = (array) $data;
    }
    $ids = $this->getTermIds('job_type');
    // Silently skip invalid term ids and limit to 1 term.
    $job->field_job_type = array_slice(array_intersect($data, $ids), 0, 1);
  }

  /**
   * Set the job type.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobExperience(Job $job, array|\SimpleXMLElement $data): void {
    if ($data instanceof \SimpleXMLElement) {
      $data = (array) $data;
    }
    $ids = $this->getTermIds('job_experience');
    // Silently skip invalid term ids.
    $job->field_job_experience = $this->validateJobExperience(array_intersect($data, $ids));
  }

  /**
   * Set the job career categories.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobCareerCategories(Job $job, array|\SimpleXMLElement $data): void {
    if ($data instanceof \SimpleXMLElement) {
      $data = (array) $data;
    }
    $ids = $this->getTermIds('career_category');
    // Silently skip invalid term ids.
    $job->field_career_categories = array_intersect($data, $ids);
  }

  /**
   * Set the job themes.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobThemes(Job $job, array|\SimpleXMLElement $data): void {
    if ($data instanceof \SimpleXMLElement) {
      $data = (array) $data;
    }
    $ids = $this->getTermIds('theme');
    // Silently skip invalid term ids and limit to 3 themes.
    $job->field_theme = array_slice(array_intersect($data, $ids), 0, 3);
  }

  /**
   * Set the job country.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobCountry(Job $job, array|\SimpleXMLElement $data): void {
    if ($data instanceof \SimpleXMLElement) {
      $data = (array) $data;
    }
    $job->field_country = $this->mapCountries($data);
  }

  /**
   * Set the job city.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job to update.
   * @param \SimpleXMLElement $data
   *   Data from the XML feed.
   */
  protected function setJobCity(Job $job, string|\SimpleXMLElement $data): void {
    if ($data instanceof \SimpleXMLElement) {
      $data = (string) $data;
    }
    if (!$job->field_country->isEmpty()) {
      $job->field_city = $this->validateCity($data);
    }
    else {
      $job->field_city = [];
    }
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
      if ($this->shouldDeferImportError($job, $field)) {
        continue;
      }
      // No need to add another validation message if there was already one for
      // the field.
      $errors = JobImportStateStore::getErrors($job);
      if (!isset($errors[$field])) {
        JobImportStateStore::setError($job, $field, $violation->getMessage()->__toString());
      }
    }

    // Update the revision log message with the list of validation errors to
    // help identify what was wrong. Join with spaces so history rendering
    // (Markdown inlines) still reads clearly when newlines collapse.
    $formatted_errors = $this->formatImportErrorsForRevisionLog($job);
    $formatted_messages = $this->formatImportMessagesForRevisionLog($job);
    if (!empty($formatted_messages)) {
      $job->setRevisionLogMessage(implode(' ', array_merge([$log], $formatted_messages)));
    }
    else {
      $job->setRevisionLogMessage($log);
    }

    // Ensure notifications are disabled.
    $job->notifications_content_disable = TRUE;

    // Save the job.
    $job->save();

    // Log the message about the creation or update.
    $this->getLogger()->info($log);

    // If there were validation errors, throw a soft violation exception with
    // the concatenated error messages.
    if (!empty($formatted_errors)) {
      throw new ReliefwebImportExceptionSoftViolation(strtr('Validation errors for job @guid imported from @url: @errors', [
        '@guid' => $job->field_import_guid->value,
        '@url' => $this->url,
        '@errors' => implode(' ', $formatted_errors),
      ]));
    }
  }

  /**
   * Format import validation errors and notes for revision log messages.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job with import state in JobImportStateStore.
   *
   * @return string[]
   *   Editorial messages including field labels and import notes.
   */
  protected function formatImportMessagesForRevisionLog(Job $job): array {
    $messages = $this->formatImportErrorsForRevisionLog($job);
    foreach (JobImportStateStore::getNotes($job) as $note) {
      $note = trim($note);
      if ($note !== '') {
        $messages[] = $note;
      }
    }
    return $messages;
  }

  /**
   * Format import validation errors for editorial revision log messages.
   *
   * @param \Drupal\reliefweb_entities\Entity\Job $job
   *   Job with import errors in JobImportStateStore.
   *
   * @return string[]
   *   Editorial error messages including field labels.
   */
  protected function formatImportErrorsForRevisionLog(Job $job): array {
    $import_errors = JobImportStateStore::getErrors($job);
    if ($import_errors === []) {
      return [];
    }

    $messages = [];
    foreach ($import_errors as $field => $error) {
      if (!isset($job->{$field})) {
        continue;
      }
      $label = (string) $job->{$field}->getFieldDefinition()->getLabel();
      $error = trim((string) $error);
      if ($this->isMissingRequiredValueMessage($error)) {
        $messages[] = strtr('@field is missing.', [
          '@field' => $label,
        ]);
      }
      else {
        $messages[] = strtr('@field: @error', [
          '@field' => $label,
          '@error' => $error,
        ]);
      }
    }

    return $messages;
  }

  /**
   * Check whether a validation message is the default required/empty message.
   *
   * @param string $message
   *   Raw validation or import error message.
   *
   * @return bool
   *   TRUE if the message is Drupal/Symfony's default NotNull message.
   */
  protected function isMissingRequiredValueMessage(string $message): bool {
    return strcasecmp(rtrim($message, '.'), 'This value should not be null') === 0;
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
    if ($length < 7 || $length > 150) {
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
      return '';
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

}
