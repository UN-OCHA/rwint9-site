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
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\taxonomy\Entity\Term;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
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
  protected function fetchJobs(Term $term) {
    $this->url = $term->field_job_import_feed->first()->feed_url;
    $uid = $term->field_job_import_feed->first()->uid ?? 2;
    $base_url = $term->field_job_import_feed->first()->base_url ?? '';

    $this->logger()->info('Processing @name, fetching jobs from @url.', [
      '@name' => $term->label(),
      '@url' => $this->url,
    ]);

    $this->validateBaseUrl($base_url);

    try {
      // Fetch the XML.
      // $data = $this->fetchXml($this->url, $term->label());
      $data = $this->getTestXml();

      // Switch to proper user and import XML.
      /** @var \Drupal\user\UserInterface $account */
      $account = $this->entityTypeManager->getStorage('user')->load($term->field_job_import_feed->first()->uid);
      $account->addRole('job_importer');
      $this->accountSwitcher->switchTo($account);

      $this->processXml($data, $uid, $base_url);

      // Restore user account.
      $this->accountSwitcher->switchBack();

      // Report errors and warnings.
      print_r($this->errors);
      print_r($this->warnings);
    }
    catch (\Exception $exception) {
      $this->logger()->error('Unable to process @name, got @code: @message.', [
        '@name' => $term->label(),
        '@code' => $exception->getCode(),
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * Fetch XML data.
   */
  protected function fetchXml($url, $label) {
    try {
      $response = $this->httpClient->request('GET', $url);
      // Check status code.
      if ($response->getStatusCode() !== 200) {
        $this->logger()->error('Unable to process @name, got @statuscode.', [
          '@name' => $label,
          '@statuscode' => $response->getStatusCode(),
        ]);

        return;
      }

      // Get body.
      $body = (string) $response->getBody();

      // Return if empty.
      if (empty($body)) {
        $this->logger()->info('Nothing to do for @name.', [
          '@name' => $label,
        ]);

        return;
      }

      return $body;
    }
    catch (ClientException $exception) {
      $this->logger()->error('Unable to process @name, got http error @code: @message.', [
        '@name' => $label,
        '@code' => $exception->getCode(),
        '@message' => $exception->getMessage(),
      ]);
    }
    catch (\Exception $exception) {
      $this->logger()->error('Unable to process @name, general error @code: @message.', [
        '@name' => $label,
        '@code' => $exception->getCode(),
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * Process XML data.
   */
  protected function processXml($body, $uid, $base_url) {
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
        $this->errors[] = $exception->getMessage();
      }
      catch (ReliefwebImportExceptionSoftViolation $exception) {
        $this->warnings[] = $exception->getMessage();
      }
    }
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

  /**
   * Test data.
   */
  protected function getTestXml() {
    return <<<XML
<?xml version="1.0" standalone="yes"?><channel><item>
 <link>https://www.aplitrak.com/?adid=ZmsuMjEwMDcuMTIxODVAc2F2ZXRoZWNoaWxkcmVuYW8uYXBsaXRyYWsuY29t</link>
 <title>Head of Supply Chain</title>
 <field_job_closing_date>2021-10-05</field_job_closing_date>
 <field_country>AFG</field_country>
 <field_city>Kabul</field_city>
 <field_source>2865</field_source>
 <body>&lt;p style="font-family: Arial;"&gt;The Opportunity&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;The Logistics Senior Manager provides functional leadership to ensure sound logistical systems, performance and coordination providing Country Office programs and teams with optimum support. In addition, the post holder is responsible for the strategic management of the Save the Children International Afghanistan supply chain, Pharmaceutical and Construction management, and Fleet Management so that it delivers effective solutions for staff and programs. &lt;br /&gt; The Senior Logistics Manager is responsible for the coordination and support of all logistics activities throughout Save the Children's areas of operation within Afghanistan and ensuring quality operations and program deliveries. &lt;br /&gt; KEY AREAS OF ACCOUNTABILITY:&lt;br /&gt; KEY AREAS OF ACCOUNTABILITY:&lt;br /&gt; Contribute to Country Programme Strategy by:&lt;br /&gt; * Support the development of an organisational culture that reflects our dual mandate values, promotes accountability and high performance, encourages a team culture of learning, creativity and innovation, and frees up our people to deliver outstanding results for children and excellent customer service for our Members and donors&lt;br /&gt; * Help design and implement a coherent organizational structure that is consistent with agency practices and appropriate to program needs&lt;br /&gt; * Ensure that the required support is provided promptly, at scale and in line with the rules and principles during emergencies.&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Oversight and Management of Logistics&lt;br /&gt; &lt;br /&gt; * Responsible for overall coordination and delegated responsibility for field delivery of high quality logistics support to programming in line with the objectives of the country strategy&lt;br /&gt; * In close coordination with other departments, participate in the program proposal process and ensure that all programs progress in accordance with grant agreements, are completed within time and on budget&lt;br /&gt; * Collaborate frequently with program staff on Awards Management and proposal development (operational budgeting) to minimize variances, no-cost extensions (NCE's), waiver exceptions, etc.&lt;br /&gt; * Ensure SCI minimum standards of logistics are implemented and provide input into the Country Annual Plan (CAP) particularly as category owner of the logistics essential standards. Ensure gaps and needs are included in the CAP Improvement Plan;&lt;br /&gt; * Liaise with local government, Logistics Cluster (or WFP) and other INGO agencies to ensure up to date understanding of logistic and operational issues in Afghanistan; &lt;br /&gt; * Participate in conceptualizing and designing cost effective, innovative and high quality programs to serve difficult to reach children &lt;br /&gt; * Ensure that programs are supported in ways responsive to the communities, and children in line with Save the Children principles, values and strategic plan and following Save the Children compliance procedures. &lt;br /&gt; * Ensure the preparation of timely and high quality logistics reports and, in coordination with program teams, the development of grant specific procurement plans.&lt;br /&gt; * Ensure country office supply planning is develop and it is in accordance with grants specific procurement plans, &lt;br /&gt; * Maintaining effective functioning of logistics systems to procure, store and distribute stock, supplies and services for the timely delivery of project objectives.&lt;br /&gt; * Develop and maintain effective pharmacy management system for ordering, distribution and consumption monitoring in the country;&lt;br /&gt; * Ensure medical supply chain is effective and medical supplies are deliver on time to operating sites;&lt;br /&gt; * Develop and maintain effective system for construction management in the country;&lt;br /&gt; * Oversee warehousing and stock management maintaining the SCI Warehousing and Stock Management procedures; &lt;br /&gt; * Ensure program managers and budget holders provide Distribution Plans, ensure it is in line with DIP and procurement plans. &lt;br /&gt; * To ensure that Save the Children's commitment to improving quality and accountability in humanitarian work is upheld, through reference to the Sphere Charter, Save the Children Essential Standards and the NGO Code of Conduct.&lt;br /&gt; * Ensure that partners understand and accept SCI policies and donor guidelines for all operations and logistics activities. &lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Logistics, Inventory and Procurement &lt;/p&gt;&lt;p style="font-family: Arial;"&gt;* Ensure that the Country Office logistics capacity and systems meet the Save the Children Essential Standards and are able to satisfy the programming requirements &lt;br /&gt; * Ensure appropriate and adequate emergency logistics procedures are detailed in the Country Office Emergency Preparedness Plan in order to enable rapid scale up &lt;br /&gt; * Manage the Country Office Logistics department ensuring that all logistics activities (fleet, transport, supply chain, etc) are coordinated&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Emergency Response Management - Logistics&lt;br /&gt; * Assist in strengthening organisational readiness to respond to emergencies in line with global SCI emergency goal and benchmarks&lt;br /&gt; * Assist to design, update and implement a full set of emergency preparedness actions, drawing on SCI member input and resources&lt;br /&gt; * Assist to mount appropriate and timely responses at scale to all emergencies consistent with established benchmarks, plans and organizational policies, and in close cooperation with incoming surge teams&lt;br /&gt; * In coordination with the Program Implementation Director and SMT members maintain consistent and coherent engagement in key inter-agency emergency preparedness and response coordination mechanisms including the Cluster system&lt;br /&gt; * Develop the logistical aspects of the programme emergency preparedness plan.&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Staff Management, Mentorship, and Development - Logistics&lt;br /&gt; * Managing and motivating the team of logistics and quality control staff (Logistics, Construction and Pharmacy) to ensure high performance in their respective roles, including leading on recruitment, induction, objective setting, and regular supervision/feedback sessions and performance reviews;&lt;br /&gt; * Ensure appropriate staffing within Logistics, including provincial office staff&lt;br /&gt; * Ensure that all staff understand and are able to perform their role in an emergency&lt;br /&gt; * and evaluate direct reports regularly&lt;br /&gt; * Ensure the recruitment, training, and promotion of staff as appropriate and ensure availability of appropriate professional development opportunities for staff&lt;br /&gt; * Incorporate staff development strategies and Performance Management Systems into team building process. Establish result based system and follow up&lt;br /&gt; * Manage the performance of all staff in the Logistics work area through: &lt;br /&gt; - Effective use of the Performance Management System including the establishment of clear, measureable objectives, ongoing feedback, periodic reviews and fair and unbiased evaluations;&lt;br /&gt; - Coaching, mentoring and other developmental opportunities;&lt;br /&gt; - Recognition and rewards for outstanding performance;&lt;br /&gt; -Documentation of performance that is less than satisfactory, with appropriate performance improvements/ work plans &lt;br /&gt; The Organisation&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;We employ approximately 25,000 people across the globe and work on the ground in over 100 countries to help children affected by crises, or those that need better healthcare, education and child protection. We also campaign and advocate at the highest levels to realise the right of children and to ensure their voices are heard. &lt;/p&gt;&lt;p style="font-family: Arial;"&gt;We are working towards three breakthroughs in how the world treats children by 2030:&lt;br /&gt; * No child dies from preventable causes before their 5th birthday &lt;br /&gt; * All children learn from a quality basic education and that,&lt;br /&gt; * Violence against children is no longer tolerated &lt;/p&gt;&lt;p style="font-family: Arial;"&gt;We know that great people make a great organization, and that our employees play a crucial role in helping us achieve our ambitions for children. We value our people and offer a meaningful and rewarding career, along with a collaborative and inclusive workplace where ambition, creativity, and integrity are highly valued. &lt;/p&gt;&lt;p style="font-family: Arial;"&gt;QUALIFICATIONS AND EXPERIENCE&lt;br /&gt; Essential:&lt;br /&gt; * Minimum 5 years' experience in logistics and operation management at a senior level in a large international non-governmental organisation or other international relief/development body; &lt;br /&gt; * Experience in logistics operations scale up in emergencies. &lt;br /&gt; * Experience of training and developing staff in logistics and administrative systems &lt;br /&gt; * Strong interpersonal and team skills, with the ability to work in a multi-cultural environment. &lt;br /&gt; * Experience in budget and financial management;&lt;br /&gt; * In-depth practical knowledge of logistics systems and requirements in a multi-field office, multi-programme setting; &lt;br /&gt; * Effective self-organised, time management and prioritisation skills. &lt;br /&gt; * Ability to work alongside others under stressful conditions in conflict-prone situations &lt;br /&gt; * Extensive prior NGO experience in logistics management, within a complex/large scale country program and in emergency response/humanitarian environments (5 yrs +) &lt;br /&gt; * Substantial experience in all technical areas of logistics operations including procurement, transport/distribution, warehousing and stock management. &lt;br /&gt; * Experience of developing / implementing a complex international supply chain to support different types of programs, and coordinating resources to meet the program objectives &lt;br /&gt; * Proven track-record in managing and supervising others in logistics, including training and capacity building (international staff) &lt;br /&gt; * Ability to synthesise and analyse complex information, and make clear, informed decisions &lt;br /&gt; * Experience of advising and supporting others at all levels with logistics aspects of a program, including strategic thinking and planning &lt;br /&gt; * Excellent planning, management and coordination skills, with the ability to organise a substantial workload comprised of complex, diverse tasks and responsibilities&lt;br /&gt; * Excellent communication and presentation skills in English, and the capacity to identify and develop opportunities with an organisation-wide perspective; &lt;br /&gt; * Cultural awareness with strong written and spoken communication and interpersonal skills &lt;br /&gt; * Technical experience/knowledge in specific types of humanitarian intervention (e.g. Health, WASH, Food Security (5+ sectors)) &lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Desirable:&lt;br /&gt; * Master degree in a relevant academic discipline;&lt;br /&gt; * Experience in delivering logistics and operational services in Afghanistan.&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Application Information&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Please apply using a cover letter and up-to-date CV as a single document. Please also include details of your current remuneration and salary expectations. A copy of the full role profile can be found at www.savethechildren.net/careers&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Closing Date for Application: 05 October 2021&lt;br /&gt; We need to keep children safe so our selection process, which includes rigorous background checks, reflects our commitment to the protection of children from abuse.&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;All employees are expected to carry out their duties in accordance with our global anti-harassment policy.&lt;/p&gt;&lt;img src="https://counter.adcourier.com/ZmsuMjEwMDcuMTIxODVAc2F2ZXRoZWNoaWxkcmVuYW8uYXBsaXRyYWsuY29t.gif"&gt;</body>
 <field_how_to_apply>Please follow this link to apply: https://www.aplitrak.com/?adid=ZmsuMjEwMDcuMTIxODVAc2F2ZXRoZWNoaWxkcmVuYW8uYXBsaXRyYWsuY29t</field_how_to_apply>
 <field_job_type>263</field_job_type>
 <field_career_categories>36601</field_career_categories>
 <field_job_experience>260</field_job_experience>
 <field_job_experience>262</field_job_experience>
</item><item>
 <link>https://www.aplitrak.com/?adid=ZmsuODgzMjguMTIxODVAc2F2ZXRoZWNoaWxkcmVuYW8uYXBsaXRyYWsuY29t</link>
 <title>Humanitarian Policy and Advocacy Advisor</title>
 <field_job_closing_date>2021-10-31</field_job_closing_date>
 <field_country>AFG</field_country>
 <field_city>Kabul</field_city>
 <field_source>2865</field_source>
 <body>&lt;p style="font-family: Arial;"&gt;The Opportunity&lt;br /&gt; The Humanitarian Policy Advisor is responsible for supporting the Policy, Advocacy and Campaigns team in developing complex policy positions and answers to difficult policy questions on humanitarian issues in Afghanistan and support in reporting and developing briefings and research humanitarian issues in Afghanistan. In the event of a major humanitarian emergency, the role holder will be expected to work outside the normal role profile and be able to vary working hours accordingly.&lt;br /&gt; KEY AREAS OF ACCOUNTABILITY: &lt;br /&gt; Advocacy development and implementation&lt;br /&gt; * Draft regular humanitarian policy products, including regular reports education and literacy, child protection, children and armed conflict and violence against children.&lt;br /&gt; * Support and advance the Afghanistan CO policy objectives during emergencies, including drafting and distributing plans, advocacy-policy messages, policy briefs, and letters to governments, policymakers and other stakeholders, &lt;br /&gt; * Support thematic directors/advisors to articulate their top priority policy objectives in line with the new Country Strategic Plan&lt;br /&gt; * Identify key opportunities and events for the Afghanistan CO to position itself as the leading organisation for children's issues in the country &lt;br /&gt; * Work with global campaigns team to ensure alignment with Afghanistan priorities&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Reporting&lt;br /&gt; * Provide project management, communications, policy and administrative support to the Advocacy, Media, Campaigns and Communications team, Save the Children Afghanistan CO partners, senior management, and other departments around priority initiatives and events including humanitarian appropriations. &lt;br /&gt; * Gather information, edit for length and clarity and support field teams in communicating and reporting on key programme and humanitarian developments in Afghanistan&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Research&lt;br /&gt; * Conducting a rigorous analysis of relevant existing secondary data and previously un-analysed primary data and write a short overview of key findings and trends;&lt;br /&gt; * Conducting interviews with key SCI staff and potentially external experts on the topic of humanitarian policy, child protection and psychosocial support in displacement contexts;&lt;br /&gt; * Drafting a policy brief on the on the interlinkage of humanitarian policy issues and ongoing conflict in Afghanistan, with concrete policy recommendations, incorporating comments from the SCI team and delivering a final version. &lt;br /&gt; * Represent emergency- and response-related policy views of Save the Children in meetings with other departments within Save the Children as well as with other non-governmental organizations (NGOs) and policy makers stakeholders; &lt;br /&gt; * Contribute meaningful input into initiatives and communiqués; report on and track all relevant joint initiatives. &lt;/p&gt;&lt;p style="font-family: Arial;"&gt;BEHAVIOURS (Values in Practice)&lt;br /&gt; Accountability:&lt;br /&gt; * holds self accountable for making decisions, managing resources efficiently, achieving and role modelling Save the Children values&lt;br /&gt; * holds the team and partners accountable to deliver on their responsibilities - giving them the freedom to deliver in the best way they see fit, providing the necessary development to improve performance and applying appropriate consequences when results are not achieved.&lt;br /&gt; Ambition:&lt;br /&gt; * sets ambitious and challenging goals for themselves and their team, takes responsibility for their own personal development and encourages their team to do the same&lt;br /&gt; * widely shares their personal vision for Save the Children, engages and motivates others&lt;br /&gt; * future orientated, thinks strategically and on a global scale.&lt;br /&gt; Collaboration:&lt;br /&gt; * builds and maintains effective relationships, with their team, colleagues, Members and external partners and supporters&lt;br /&gt; * values diversity, sees it as a source of competitive strength&lt;br /&gt; * approachable, good listener, easy to talk to.&lt;br /&gt; Creativity:&lt;br /&gt; * develops and encourages new and innovative solutions&lt;br /&gt; * willing to take disciplined risks.&lt;br /&gt; Integrity:&lt;br /&gt; * honest, encourages openness and transparency; demonstrates highest levels of integrity&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;&lt;br /&gt; QUALIFICATIONS AND EXPERIENCE &lt;br /&gt; * Education to BSc/BA/BEng level in a relevant subject or equivalent field experience&lt;br /&gt; * A minimum of five years' in humanitarian advocacy in an NGO environment, with experience in successfully leading the development and implementation of advocacy strategies&lt;br /&gt; * Experience of working in humanitarian contexts and knowledge of key trends in the humanitarian sector&lt;br /&gt; * Experience in leading the creation and implementation of a strategy, demonstrating the ability to identify the necessary steps towards an ambitious goal.&lt;br /&gt; * Experience of a range of campaigning and advocacy techniques and approaches&lt;br /&gt; * Experience in influencing government, donors, and other organizations through representation and/or advocacy&lt;br /&gt; * Demonstrable creative ability in accessing new opportunities, expertise and ideas&lt;br /&gt; * Demonstrable track record of leading change which has led to significant results for the organization and their stakeholders&lt;br /&gt; * Highly developed interpersonal and communication skills including influencing and negotiation&lt;br /&gt; * Highly developed cultural awareness and ability to work well in an environment with people from diverse backgrounds and cultures&lt;br /&gt; * Strong results orientation, with the ability to challenge existing mindsets&lt;br /&gt; * Ability to present complex information in a succinct and compelling manner&lt;br /&gt; * Strong research and policy development skills&lt;br /&gt; * Experience of building networks, resulting in securing significant new opportunities for the organization&lt;br /&gt; * Experience of solving complex issues through analysis, definition of a clear way forward and ensuring buy in&lt;br /&gt; * Knowledge of children's rights key international agreements and conventions&lt;br /&gt; * Commitment to Save the Children values &lt;br /&gt; * Excellence in written and spoken English. &lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Desirable:&lt;br /&gt; * Knowledge and/or experience of the Afghanistan crisis is desirable&lt;br /&gt; * National candidates and returning nationals are strongly encouraged to apply.&lt;br /&gt; * Experience in coalition building, advocacy, humanitarian protection and/or coordination&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;&lt;br /&gt; Application Information&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Please apply using a cover letter and up-to-date CV as a single document. Please also include details of your current remuneration and salary expectations. A copy of the full role profile can be found at www.savethechildren.net/careers&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;Closing Date for Application: 31 October 2021&lt;br /&gt; We need to keep children safe so our selection process, which includes rigorous background checks, reflects our commitment to the protection of children from abuse.&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;All employees are expected to carry out their duties in accordance with our global anti-harassment policy.&lt;/p&gt;&lt;img src="https://counter.adcourier.com/ZmsuODgzMjguMTIxODVAc2F2ZXRoZWNoaWxkcmVuYW8uYXBsaXRyYWsuY29t.gif"&gt;</body>
 <field_how_to_apply>Please follow this link to apply: https://www.aplitrak.com/?adid=ZmsuODgzMjguMTIxODVAc2F2ZXRoZWNoaWxkcmVuYW8uYXBsaXRyYWsuY29t</field_how_to_apply>
 <field_job_type>263</field_job_type>
 <field_career_categories>6865</field_career_categories>
 <field_job_experience>260</field_job_experience>
 <field_theme>4600</field_theme>
 <field_theme>4594</field_theme>
 </item><item>
 <field_theme>4600</field_theme>
 <link>https://www.aplitrak.com/?adid=bHJ1aXouNDI3MjEuMTIxODVAc2F2ZXRoZWNoaWxkcmVuYW8uYXBsaXRyYWsuY29t</link>
 <title>Asistente de Control Interno</title>
 <field_job_closing_date>2021-10-04</field_job_closing_date>
 <field_country>COL</field_country>
 <field_city>Bogotá</field_city>
 <field_source>2865</field_source>
 <body>&lt;p style="font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Asistente de Control Interno&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;&lt;strong&gt;PROGRAMA: &lt;/strong&gt;Dirección Ejecutiva&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;&lt;strong&gt;UBICACION: &lt;/strong&gt;Bogotá, Colombia / con desplazamiento por el país&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;&lt;strong&gt;GRADO&lt;/strong&gt;: (5) Asistente &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="tab-stops: 49.2pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;&lt;strong&gt;Tipo de Contrato: &lt;/strong&gt;Fijo&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;"&gt; &lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 115%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;&lt;strong&gt;SALVAGUARDA DE LA INFANCIA: &lt;/strong&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 115%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Nivel 2 - El titular del puesto tendrá acceso a los datos personales de niños y niñas como parte de su trabajo; por esta razón será un requisito la verificación policial.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 115%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;El Marco de Salvaguarda se compone por tres políticas y un Código de Conducta: Política de Salvaguarda de la niñez, Política para la Protección contra el Abuso, el Acoso y la Explotación Sexual (PSEAH) o Salvaguarda de la adultez y Política Antiacoso, Antidiscriminación y/o anti Bullying.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 115%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Nosotros necesitamos mantener procesos seguros de selección, por lo cual se incluyen verificaciones rigurosas de antecedentes, Refleja nuestro compromiso con la protección de los niños contra el abuso&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="font-family: Arial;"&gt; &lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;&lt;strong&gt;OBJETIVO DEL CARGO:&lt;/strong&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;"&gt; &lt;/p&gt;&lt;p style="font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Apoyar las actividades y procesos del área, el cumplimiento y ejecución de los planes de acción de prevención del fraude y de control interno, realizando revisiones y seguimiento de procesos y procedimientos y asegurando todas las acciones que se requieran para garantizar los resultados previstos.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="font-family: Arial;"&gt; &lt;/p&gt;&lt;p style="font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;&lt;strong&gt;PRINCIPALES ÁREAS DE RESPONSABILIDAD:&lt;/strong&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="font-family: Arial;"&gt; &lt;/p&gt;&lt;ul&gt;&lt;li style="margin-top: 10px; margin-right: 40px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Ejecutar las auditorías internas de acuerdo con el plan de acción establecido y generar los informes correspondientes, de acuerdo con el proceso establecido para remitirlos a los responsables directos.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="margin-top: 11px; margin-right: 40px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Apoyar en el proceso de revisión y actualización de políticas y procedimientos organizacionales de acuerdo con los requerimientos establecidos.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="margin-top: 11px; margin-right: 40px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Brindar apoyo y fortalecimiento en el control interno de la organización con el fin de garantizar un mejoramiento continuo en los procesos.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="margin-top: 11px; margin-right: 40px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Apoyar en la recopilación de información y documentación para el desarrollo y fortalecimiento del mejoramiento continuo de la organización y ejecución de las actividades del área. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li class="Default"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Apoyar en la implementación y sensibilización de la política de fraude de SCI y sus respectivas denuncias; fomentar activamente la notificación de sospechas de desviación de los valores de SCI por parte del personal o establecer normas de fraude, conducta no ética, negligencia o actividad delictiva dentro de SCI. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li class="Default"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Brindar capacitación continua de actualización/refrescamiento e inducción de la política de fraude, soborno y corrupción según sea apropiado para todo el personal, voluntarios y socios. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li class="Default"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Apoyar la identificación de riesgos de fraude, soborno y corrupción en la implementación en contextos de emergencia y en los diferentes procesos de la organización, así como las acciones de mejora y lecciones aprendidas.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li class="Default"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Acompañar y gestionar las sospechas de fraude asegurando que puedan ser notificadas eficazmente de acuerdo con la política y el procedimiento y reportando a la Coordinadora Nacional de Control Interno. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li class="Default"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Apoyar en los procesos de investigaciones sobre las sospechas de fraude; dando cumplimiento a los protocolos establecidos y de acuerdo con lo que considere la coordinación del área. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li class="Default" style="margin-right: 40px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Apoyar en iniciativas para promover activamente la notificación de sospechas de las situaciones de fraude y conflicto de intereses por parte del staff.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li class="Default"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Garantizar confidencialidad de los reportes y casos de fraude de los que tenga conocimiento, de acuerdo con las normas y acuerdos establecidos. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li class="Default" style="margin-right: 40px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Apoyar con la organización y generación de material para la capacitación de la política de fraude, soborno y corrupción, teniendo en cuenta los roles y áreas a la que va dirigida, tanto interna como externamente.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li class="Default" style="margin-right: 40px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Gestionar espacios con las diferentes áreas y oficinas de terreno para fortalecer temas de control interno y de prevención del fraude.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li class="Default" style="margin-right: 40px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Proponer actividades para generar información de prevención de fraude, soborno y corrupción en las diferentes oficinas de terreno.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="margin-right: 40px;" class="Default"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Acompañar la representación de la organización bajo delegación de la coordinación, en espacios que promuevan y fortalezcan la implementación de la política de fraude, al interior y al exterior de la organización. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="margin-right: 40px;" class="Default"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Gestionar la documentación correspondiente del área, participando de reuniones y haciendo seguimiento al cumplimiento de los compromisos y acciones que se produzcan.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="margin-top: 10px; margin-right: 40px;" class="Default"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="line-height: 105%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Realizar el oportuno reporte de actividades, logros y lecciones aprendidas según los esquemas y estructura del sistema de monitoreo y evaluación de la organización y del área.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Participar en la elaboración de reportes e informes de acuerdo con los requerimientos recibidos.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="margin-top: 10px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Realizar oportunamente reportes administrativos y/o planes de acción asociados a las misiones asignadas.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="margin-top: 10px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Promover, aplicar y cumplir las políticas y prácticas de Save the Children con respecto al fraude, el soborno y la corrupción y otras políticas y procedimientos pertinentes.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="margin-top: 10px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span lang="ES-PA" style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Informar oportunamente de las situaciones que se presenten en el desarrollo de las actividades y que por su trascendencia requieran la participación de instancias superiores.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="margin-top: 10px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span lang="ES-PA" style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Otras según el nivel y responsabilidad del cargo.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="margin-top: 10px;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="tab-stops: 40.65pt 40.7pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;" lang="ES-PA"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Cumplir con altos estándares éticos y de comportamiento&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;/ul&gt;&lt;p style="margin-top: 10px; margin-left: 24px; font-family: Arial;"&gt; &lt;/p&gt;&lt;p style="font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;&lt;strong&gt;CONDICIONES &lt;/strong&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Debido al mandato de La Fundación Save the Children Colombia, en caso de una situación de emergencia, se espera que el/la colaboradora(a) tenga flexibilidad para adecuarse a las tareas adicionales que deba atender en su puesto, asumiendo horarios y tareas de acuerdo con los requerimientos que la Fundación Save the Children Colombia defina. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p class="Default" style="text-align: justify; font-family: Arial;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="line-height: 115%;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;strong&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="line-height: 115%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;&lt;span style="color: #00000a;"&gt;REQUISITOS &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/strong&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;" class="Default"&gt; &lt;/p&gt;&lt;p style="font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;&lt;a name="_Hlk83653140"&gt;&lt;/a&gt;Tecnólogo, estudiante de últimos semestres o Profesional en contaduría pública, administrador de empresas, preferiblemente con estudios en Revisoría Fiscal, Control interno, Auditoria Forense y/o afines.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="font-family: Arial;"&gt; &lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;&lt;strong&gt;EXPERIENCIA Y HABILIDADES:&lt;/strong&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;"&gt; &lt;/p&gt;&lt;ul&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Experiencia de 2 años desempeñándose en áreas de auditoría o control interno.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Dominio amplio de la legislación tributaria, laboral y comercial del país&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Experiencia deseable en entidades sin ánimo de lucro y/o agencias de cooperación internacional&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Experiencia o deseos de aprendizaje en la gestión y realización de investigaciones complejas de situaciones de fraude interno y externo.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Experiencia multidisciplinaria de trabajar con éxito con la alta dirección. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Excelentes habilidades de comunicación verbal y de redacción de informes. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Excelentes habilidades interpersonales. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Voluntad y capacidad para cambiar drásticamente las prácticas de trabajo y las horas, y trabajar con los equipos emergentes, en caso de emergencias &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Buen nivel de inglés escrito y hablado.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Destreza en el uso de Microsoft Office (Excel, Word, Powerpoint, Visio), en otras herramientas de auditoría asistidas por computadora y excelentes habilidades de documentación. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Manejo de tablas dinámicas/bases de datos&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Habilidad en trabajo en equipo&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Capacidad para gestionar y maximizar los beneficios de la diversidad cultural. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Disponibilidad para viajar&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Iniciativa para proponer nuevas ideas, pensamiento creativo y análisis&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Buen criterio y capacidad para priorizar eficazmente múltiples tareas en un entorno de cambio constante&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Comprensión de la visión y misión de SCI y compromiso con sus objetivos y valores. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;li style="text-align: justify;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Times New Roman',serif;"&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Alto nivel de compromiso con valores de Save the Children&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/li&gt;&lt;/ul&gt;&lt;p style="font-family: Arial;"&gt; &lt;/p&gt;&lt;p class="Default" style="text-align: justify; font-family: Arial;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;strong&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;NOTA:&lt;/span&gt;&lt;/span&gt;&lt;/strong&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt; Con la presentación para participar en la presente convocatoria se autoriza a Save the Children a comprobar la información personal suministrada, así como a hacer uso de los datos personales para efecto de comprobación en bases de datos públicas y privadas relacionadas con nuestras políticas antifraude, lavado de activos y financiación del terrorismo. Los datos utilizados serán los indicados en la cedula de ciudadanía entregada.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;" class="Default"&gt; &lt;/p&gt;&lt;p class="Default" style="text-align: justify; font-family: Arial;"&gt;&lt;span style="font-size: 12pt;"&gt;&lt;span style="font-family: 'Gill Sans MT',sans-serif;"&gt;&lt;span style="color: black;"&gt;&lt;strong&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;NOTA 2:&lt;/span&gt;&lt;/span&gt;&lt;/strong&gt;&lt;span style="font-size: 11.0pt;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt; En todo caso, la Con la presentación para participar en la presente convocatoria se autoriza a Save the Children a comprobar la información personal suministrada, así como a hacer uso de los datos personales para efecto de comprobación en bases de datos públicas y privadas relacionadas con nuestras políticas antifraude, lavado de activos y financiación del terrorismo. Los datos utilizados serán los indicados en la cedula de ciudadanía entregada. solución a la prueba técnica será un criterio de evaluación y selección del personal, por lo que solo se usará el contenido para los efectos del proceso de selección y se respetará la propiedad intelectual del mismo, no genera en ningún caso remuneración alguna. &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="font-family: Arial;"&gt; &lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;" class="Default"&gt; &lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;&lt;a name="_Hlk61873990"&gt;&lt;/a&gt;Se recibirán aplicaciones desde el &lt;strong&gt;27 de septiembre de 2021 al 04 de octubre de 2021&lt;/strong&gt; &lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="text-align: justify; font-family: Arial;"&gt;&lt;span style="font-size: 11pt;"&gt;&lt;span style="line-height: 107%;"&gt;&lt;span style="font-family: 'Gill Sans Infant MT',sans-serif;"&gt;Las propuestas presentadas una vez cumplida y pasada la hora fijada para el efecto, o radicadas en dependencia distinta a la enunciada en este documento, &lt;strong&gt;NO SERÁN RECIBIDAS&lt;/strong&gt; ni tenidas en cuenta por el comité de selección, de lo cual se dejará constancia en la respectiva acta.&lt;/span&gt;&lt;/span&gt;&lt;/span&gt;&lt;/p&gt;&lt;p style="font-family: Arial;"&gt; &lt;/p&gt;&lt;img src="https://counter.adcourier.com/bHJ1aXouNDI3MjEuMTIxODVAc2F2ZXRoZWNoaWxkcmVuYW8uYXBsaXRyYWsuY29t.gif"&gt;</body>
 <field_how_to_apply>Please follow this link to apply: https://www.aplitrak.com/?adid=bHJ1aXouNDI3MjEuMTIxODVAc2F2ZXRoZWNoaWxkcmVuYW8uYXBsaXRyYWsuY29t</field_how_to_apply>
 <field_job_type>263</field_job_type>
 <field_career_categories>6867</field_career_categories>
 <field_job_experience>258</field_job_experience>
</item></channel>
XML;
  }

}
