<?php

namespace Drupal\reliefweb_import\Command;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\State;
use Drupal\reliefweb_entities\Entity\Job;
use Drupal\reliefweb_import\Exception\ReliefwebImportException;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionSoftViolation;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionViolation;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionXml;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\taxonomy\Entity\Term;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Docstore Drush commandfile.
 */
class ReliefwebImportCommand extends DrushCommands implements SiteAliasManagerAwareInterface {

  // Drush traits.
  use ProcessManagerAwareTrait;
  use SiteAliasManagerAwareTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The account switcher.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;

  /**
   * An http client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The state store.
   *
   * @var Drupal\Core\State\State
   */
  protected $state;

  /**
   * The source URL.
   *
   * @var string
   */
  protected $url;

  /**
   * The errors.
   *
   * @var array
   */
  protected $errors;

  /**
   * The wanings.
   *
   * @var array
   */
  protected $warnings;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    $account_switcher,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    State $state
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->accountSwitcher = $account_switcher;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->state = $state;
  }

  /**
   * Import jobs.
   *
   * @param int $limit
   *   Max number of items to send.
   *
   * @command reliefweb_import:jobs
   * @usage reliefweb_import:jobs
   *   Send emails.
   * @validate-module-enabled reliefweb_import
   */
  public function jobs($limit = 50) {
    // Load terms having a job URL.
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query->condition('vid', 'source');
    $query->condition('field_job_import_feed', NULL, 'IS NOT NULL');

    $tids = $query->execute();
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
  public function fetchJobs(Term $term) {
    $this->url = $term->field_job_import_feed->first()->feed_url;
    $uid = $term->field_job_import_feed->first()->uid ?? 2;
    $base_url = $term->field_job_import_feed->first()->base_url ?? '';
    $label = $term->label();

    $this->logger()->info('Processing @name, fetching jobs from @url.', [
      '@name' => $label,
      '@url' => $this->url,
    ]);

    $this->validateBaseUrl($base_url);

    try {
      $this->errors = [];
      $this->warnings = [];

      // Fetch the XML.
      $data = $this->fetchXml($this->url);

      // Switch to proper user and import XML.
      /** @var \Drupal\user\UserInterface $account */
      $account = $this->entityTypeManager->getStorage('user')->load($uid);
      $account->addRole('job_importer');
      $this->accountSwitcher->switchTo($account);

      $this->processXml($data, $uid, $base_url);

      // Restore user account.
      $this->accountSwitcher->switchBack();
    }
    catch (ClientException $exception) {
      $this->logger()->error('Unable to process @name, got http error @code: @message.', [
        '@name' => $label,
        '@code' => $exception->getCode(),
        '@message' => $exception->getMessage(),
      ]);
    }
    catch (RequestException $exception) {
      $this->logger()->error('Unable to process @name, general error @code: @message.', [
        '@name' => $label,
        '@code' => $exception->getCode(),
        '@message' => $exception->getMessage(),
      ]);
    }
    catch (\Exception $exception) {
      $this->logger()->error('Unable to process @name, got @code: @message.', [
        '@name' => $label,
        '@code' => $exception->getCode(),
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * Fetch XML data.
   */
  protected function fetchXml($url) {
    $response = $this->httpClient->request('GET', $url);
    // Check status code.
    if ($response->getStatusCode() !== 200) {
      throw new ReliefwebImportExceptionXml(strtr('HTTP error, got @statuscode.', [
        '@statuscode' => $response->getStatusCode(),
      ]));
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
   */
  protected function processXml($body, $uid, $base_url) {
    $index = 0;
    $xml = new \SimpleXMLElement($body);
    foreach ($xml as $item) {
      try {
        $this->checkMandatoryFields($item, $base_url);

        // Check if job already exist.
        if ($this->jobExists((string) $item->link)) {
          $this->logger()->notice('Update existing job');
          $this->updateJob($this->loadJobById((string) $item->link), $item);
        }
        else {
          $this->logger()->notice('Create new job');
          $this->createJob($item, $uid);
        }
      }
      catch (ReliefwebImportExceptionViolation $exception) {
        $this->errors[$index] = $exception->getMessage();
      }
      catch (ReliefwebImportExceptionSoftViolation $exception) {
        $this->warnings[$index] = $exception->getMessage();
      }

      $index++;
    }
  }

  /**
   * Get errors.
   */
  public function getErrors() {
    return $this->errors;
  }

  /**
   * Get warnings.
   */
  public function getWarnings() {
    return $this->warnings;
  }

  /**
   * Check mandatory fields.
   */
  protected function checkMandatoryFields($data, $base_url) {
    $this->validateLink($data->link[0], $base_url);
    $this->validateTitle($data->title[0]);
  }

  /**
   * Check if job exists.
   */
  protected function jobExists($id) {
    $sql = "SELECT COUNT(entity_id)
      FROM {node__field_job_id}
      WHERE bundle = 'job'
      AND field_job_id_value = :id";

    $count = $this->database->query($sql, [
      ':id' => $id,
    ])->fetchField();

    return $count != 0;
  }

  /**
   * Load job by Id.
   */
  protected function loadJobById($id) {
    $sql = "SELECT entity_id
      FROM {node__field_job_id}
      WHERE bundle = 'job'
      AND field_job_id_value = :id";

    $nid = $this->database->query($sql, [
      ':id' => $id,
    ])->fetchField();

    return Job::load($nid);
  }

  /**
   * Create a new job.
   */
  protected function createJob($data, $uid) {
    $values = [
      'type' => 'job',
      'uid' => $uid,
      'field_job_id' => (string) $data->link,
      'title' => (string) $this->validateTitle((string) $data->title),
      'field_career_categories' => $data->field_career_categories[0] ? (array) $data->field_career_categories : [],
      'field_city' => (string) $data->field_city,
      'field_job_closing_date' => (string) $data->field_job_closing_date,
      'field_country' => $this->mapCountries((array) $data->field_country),
      'field_how_to_apply' => (string) $data->field_how_to_apply,
      'body' => [
        'value' => $this->validateBody((string) $data->body),
        'format' => 'markdown',
      ],
      'field_job_type' => $data->field_job_type[0] ? (array) $data->field_job_type : [],
      'field_job_experience' => $data->field_job_experience[0] ? $this->validateJobExperience((array) $data->field_job_experience) : [],
      'field_source' => $data->field_source[0] ? (array) $data->field_source : [],
      'field_theme' => $data->field_theme[0] ? (array) $data->field_theme : [],
    ];

    $job = Job::create($values);

    $this->validateAndSaveJob($job);
  }

  /**
   * Create a new job.
   */
  protected function updateJob(Job $job, $data) {
    $job->title = $this->validateTitle((string) $data->title);
    $job->field_career_categories = $data->field_career_categories[0] ? (array) $data->field_career_categories : [];
    $job->field_city = (string) $data->field_city;
    $job->field_job_closing_date = (string) $data->field_job_closing_date;
    $job->field_country = $this->mapCountries((array) $data->field_country);
    $job->field_how_to_apply = (string) $data->field_how_to_apply;
    $job->body = [
      'value' => $this->validateBody((string) $data->body),
      'format' => 'markdown',
    ];
    $job->field_job_type = $data->field_job_type[0] ? (array) $data->field_job_type : [];
    $job->field_job_experience = $data->field_job_experience[0] ? $this->validateJobExperience((array) $data->field_job_experience) : [];
    $job->field_source = $data->field_source[0] ? (array) $data->field_source : [];
    $job->field_theme = $data->field_theme[0] ? (array) $data->field_theme : [];

    $this->validateAndSaveJob($job);
  }

  /**
   * Validate and save job.
   */
  protected function validateAndSaveJob(Job $job) {
    // Revision user is always 'System'.
    $job->setRevisionUserId(2);
    $job->setNewRevision(TRUE);
    $job->setRevisionLogMessage(strtr('Job @guid updated from @url', [
      '@guid' => $job->field_job_id->value,
      '@url' => $this->url,
    ]));

    $violations = $job->validate();
    if (count($violations) === 0) {
      // Save as published.
      $job->setPublished();
      $job->save();
    }
    else {
      // Save as draft.
      $job->setUnpublished();
      $job->save();

      $errors = [];
      /** @var \Symfony\Component\Validator\ConstraintViolation $violation */
      foreach ($violations as $violation) {
        $errors[] = strtr('Validation failed in @path with message: @message for job @guid', [
          '@path' => $violation->getPropertyPath(),
          '@message' => $violation->getMessage()->__toString(),
          '@guid' => $job->field_job_id->value,
        ]);
      }

      throw new ReliefwebImportExceptionSoftViolation(implode("\n", $errors));
    }
  }

  /**
   * Our logger.
   */
  protected function logger() {
    return $this->loggerFactory->get('reliefweb_import');
  }

  /**
   * Validate base URL.
   */
  protected function validateBaseUrl($base_url) {
    if (empty(trim($base_url))) {
      throw new ReliefwebImportExceptionViolation('Base URL is empty.');
    }

    if (!UrlHelper::isValid($base_url, TRUE)) {
      throw new ReliefwebImportExceptionViolation('Base URL is not a valid link.');
    }
  }

  /**
   * Validate link.
   */
  protected function validateLink($link, $base_url) {
    if (empty(trim($link))) {
      throw new ReliefwebImportExceptionViolation('Feed item found without a link.');
    }

    if (!UrlHelper::isValid($link, TRUE)) {
      throw new ReliefwebImportExceptionViolation('Invalid feed item link.');
    }

    if (strpos($link, $base_url) !== 0) {
      throw new ReliefwebImportExceptionViolation('Invalid feed item link base.');
    }
  }

  /**
   * Validate title.
   */
  protected function validateTitle($title) {
    if (empty(trim($title))) {
      throw new ReliefwebImportExceptionViolation('Job found with empty title.');
    }

    // Clean the title.
    $title = strip_tags($title);

    $options = [
      'line_breaks' => TRUE,
      'consecutive' => TRUE,
    ];
    $title = $this->cleanText($title, $options);

    // Ensure the title size is reasonable. The max length matches the one from
    // the job form.
    $length = mb_strlen($title);
    if ($length < 10 || $length > 150) {
      throw new ReliefwebImportExceptionViolation('Invalid title length.');
    }

    return $title;
  }

  /**
   * Validate the body field of a feed item, clean and check its size.
   *
   * @param string $data
   *   Raw data from XML.
   */
  protected function validateBody($data) {
    // Clean the body field.
    $body = $this->sanitizeText('body', $data, 'plain_text');

    // Ensure the body field size is reasonable.
    $length = mb_strlen($body);
    if ($length < 400 || $length > 50000) {
      throw new ReliefwebImportException(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
        '@length' => $length,
      ]));
    }

    return $body;
  }

  /**
   * Sanitize text, converting it to markdown.
   *
   * @param string $field
   *   Field name.
   * @param string $text
   *   Field text content.
   * @param string $format
   *   Format to which conver the text if not already HTML.
   *
   * @return string
   *   Sanitized content.
   */
  protected function sanitizeText($field, $text, $format = 'plain_text') {
    if (!is_string($text)) {
      return '';
    }

    // Trim the input text.
    $text = trim($text);
    if (empty($text)) {
      return '';
    }

    // Sometimes the parser bugs and leaves the closing field tag at the end
    // of the text. We remove it before proceeding.
    $position = mb_strpos($text, '</' . $field . '>');
    if ($position !== FALSE) {
      $text = mb_substr($text, 0, $position);
    }

    // Clean the text, removing notably control characters.
    $text = $this->cleanText($text);

    // Check if the text is wrapped in a CDATA.
    if (mb_stripos($text, '<![CDATA[') === 0) {
      $end = mb_strpos($text, ']]>');
      $text = mb_substr($text, 9, $end !== FALSE ? $end - 9 : NULL);
    }

    // Check if the content contains some non encoded html tags, in which case
    // we will assume that the text is non encoded html/markdown. For that we
    // simply search for a closing tag '</...>'. Otherwise we decode the text.
    if (preg_match('#</[^>]+>#', $text) !== 1) {
      $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    }

    // Convert the text to HTML.
    /*
    if (function_exists('check_markup')) {
    $text = check_markup($text, $format)->__toString();
    }
     */

    // We then sanitize the HTML string.
    $sanitizer = new HtmlSanitizer();
    $text = $sanitizer->sanitizeHtml($text);

    // Remove embedded content.
    $text = $this->stripEmbeddedContent($text);

    // Finally we convert it to markdown.
    $converter = new HtmlConverter();
    $converter->getConfig()->setOption('strip_tags', TRUE);
    $converter->getConfig()->setOption('use_autolinks', FALSE);
    $converter->getConfig()->setOption('header_style', 'atx');
    $converter->getConfig()->setOption('strip_placeholder_links', TRUE);

    $text = trim($converter->convert($text));

    return $text;
  }

  /**
   * Clean a text.
   *
   * 1. Replace tabulations with double spaces.
   * 2. Replace non breaking spaces with normal spaces.
   * 3. Remove control characters (except line feed).
   * 4. Optionally, replace line breaks and consecutive whitespaces.
   * 4. Trim the text.
   *
   * @param string $text
   *   Text to clean.
   * @param array $options
   *   Associative array with the following replacement options:
   *   - line_breaks (boolean): replace line breaks with spaces.
   *   - consecutive (boolean): replace consecutive whitespaces.
   *
   * @return string
   *   Cleaned text.
   */
  protected function cleanText($text, array $options = []) {
    $patterns = ['/[\t]/u', '/[\xA0]+/u', '/[\x00-\x09\x0B-\x1F\x7F]/u'];
    $replacements = ['  ', ' ', ''];
    // Replace (consecutive) line breaks with a single space.
    if (!empty($options['line_breaks'])) {
      $patterns[] = '/[\x0A]+/u';
      $replacements[] = ' ';
    }
    // Replace consecutive whitespaces with single space.
    if (!empty($options['consecutive'])) {
      $patterns[] = '/\s{2,}/u';
      $replacements[] = ' ';
    }
    return trim(preg_replace($patterns, $replacements, $text));
  }

  /**
   * Remove embedded content in html or markdown format from the given text.
   *
   * Note: it's using a very basic pattern matching that may not work with
   * broken html (missing </iframe> ending tag for example)
   *
   * @param string $text
   *   Text to clean.
   *
   * @return string
   *   Cleaned up text.
   */
  protected function stripEmbeddedContent($text) {
    $patterns = [
      "<embed [^>]+>",
      "<img [^>]+>",
      "<param [^>]+>",
      "<source [^>]+>",
      "<track [^>]+>",
      "<audio [^>]+>.*</audio>",
      "<iframe [^>]+>.*</iframe>",
      "<map [^>]+>.*</map>",
      "<object [^>]+>.*</object>",
      "<video [^>]+>.*</video>",
      "<svg [^>]+>.*</svg>",
      "!\[[^\]]*\]\([^\)]+\)",
      "\[iframe[^\]]*\]\([^\)]+\)",
    ];
    return preg_replace('@' . implode("|", $patterns) . '@i', '', $text);
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
  protected function mapCountries(array $values) {
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
   * Validate the job_experience field for the feed item.
   */
  protected function validateJobExperience(array $values) {
    // Map "N/A" to "0-3 years" to accomodate changes to the specifications.
    foreach ($values as &$value) {
      if ($value == 262) {
        $value = 258;
      }
    }

    return $values;
  }

}
