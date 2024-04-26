<?php

namespace Drupal\reliefweb_xmlsitemap\Command;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;

/**
 * Drush commandfile.
 */
class ReliefwebXmlsitemapCommand extends DrushCommands implements SiteAliasManagerAwareInterface {

  // Drush traits.
  use ProcessManagerAwareTrait;
  use SiteAliasManagerAwareTrait;

  /**
   * Directory for the xmlsitemap files.
   */
  const RELIEFWEB_XMLSITEMAP_DIRECTORY = 'xmlsitemap';

  // Maximum number of links in a sitemap.
  const RELIEFWEB_XMLSITEMAP_MAX_LINKS = 50000;

  // Maximum number of links to retrieve at once.
  const RELIEFWEB_XMLSITEMAP_CHUNK_SIZE = 5000;

  // List of change frequencies.
  const RELIEFWEB_XMLSITEMAP_FREQUENCIES = [
    'always'  => 60,
    'hourly'  => 60 * 60,
    'daily'   => 24 * 60 * 60,
    'weekly'  => 7 * 24 * 60 * 60,
    'monthly' => 30 * 24 * 60 * 60,
    'yearly'  => 365 * 24 * 60 * 60,
  ];

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The extension path resolver.
   *
   * @var \Drupal\Core\Extension\ExtensionPathResolver
   */
  protected $extensionPathResolver;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

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
   * @var Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    Connection $database,
    ExtensionPathResolver $extension_path_resolver,
    FileSystemInterface $file_system,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
  ) {
    $this->database = $database;
    $this->extensionPathResolver = $extension_path_resolver;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   *
   * Ensure messages are logged into all the logging facilities (ex: syslog)
   * and use the classic Drupal placeholder replacement.
   *
   * We cannot rely on DrushCommands::logger() because, by default, it returns a
   * \Drush\Log\Logger that only logs to the console and uses a different
   * placeholder replacement: {placeholder} rather than @placeholder.
   *
   * However this drush command file being provided by a module, the boostrap
   * level is full by default, so we have access to the normal log stack and
   * can simply use the logger factory to get the logger channel with all the
   * loggers initialized (including \Drush\Log\DrushLog).
   *
   * If we wanted to only log to the console, we could use DrushLog directly
   * here.
   *
   * Finally we cannot replace the logger using the logger factory in the
   * constructor because the site is not yet fully boostrap when the constructor
   * is called and all the loggers haven't been added to the logger channel
   * yet at that time.
   */
  protected function getLogger(): LoggerChannelInterface {
    return $this->loggerFactory->get('reliefweb_xmlsitemap');
  }

  /**
   * Generate the ReliefWeb xmlsitemap.
   *
   * @command reliefweb_xmlsitemap:generate
   * @usage reliefweb_xmlsitemap:generate
   *   Generate the ReliefWeb xmlsitemap.
   * @validate-module-enabled reliefweb_xmlsitemap
   * @aliases reliefweb-xmlsitemap-generate
   */
  public function generate() {
    // Prepare and empty the xmlsitemap directory.
    if (!$this->prepareDirectory()) {
      return;
    }
    elseif (!$this->clearDirectory()) {
      return;
    }

    // Generation steps.
    $steps = $this->getSteps();

    // Number of created pages.
    $page_count = 0;

    // Number of processed links.
    $processed = 0;

    // Link accumulator and last link id tracking.
    $accumulator = [];
    $last_id = 0;

    // Maximum number of links to retrieve at once.
    // @todo use config
    $limit = $this->state->get('reliefweb_xmlsitemap_chunk_size', self::RELIEFWEB_XMLSITEMAP_CHUNK_SIZE);

    // Go through all the steps in order, generating a new page every time
    // we reach RELIEFWEB_XMLSITEMAP_MAX_LINKS or if there is no more links.
    $stop = FALSE;
    while ($stop === FALSE) {
      // Get the current step and corresponding callback.
      $step = key($steps);
      $callback = $steps[$step];

      // Get the links.
      $links = call_user_func([$this, $callback], $step, $limit, $last_id);
      $count = count($links);
      $processed += $count;

      // If there were no links for the step, move to the next one.
      if ($count === 0) {
        // If there is no more step, then instruct to stop.
        if (next($steps) === FALSE) {
          $stop = TRUE;
        }
        // Otherwise, reset the last id as we start a new step.
        else {
          $last_id = 0;
        }
      }
      // Add the links.
      else {
        $accumulator = array_merge($accumulator, array_values($links));
        // Get the last id, which is the key of the last link.
        end($links);
        $last_id = key($links);
      }

      // If we reached the maximum of links for a page or ended all the steps
      // we write the links to a new page.
      if (count($accumulator) >= self::RELIEFWEB_XMLSITEMAP_MAX_LINKS || $stop) {
        if (!empty($accumulator)) {
          // Extract RELIEFWEB_XMLSITEMAP_MAX_LINKS links from the accumulator.
          $page_links = array_splice($accumulator, 0, self::RELIEFWEB_XMLSITEMAP_MAX_LINKS);
          $page_count++;
          $this->writePage($page_count, $page_links);
        }
      }
    }

    // Create a page with the remaining links if any.
    if (!empty($accumulator)) {
      // Just to be safe.
      $page_links = array_splice($accumulator, 0, self::RELIEFWEB_XMLSITEMAP_MAX_LINKS);
      $page_count++;
      $this->writePage($page_count, $accumulator);
    }

    // Write the sitemap index.
    $this->writeIndex($page_count);

    // Log the result.
    $this->getLogger()->info('Successfully created @page_count sitemap page file(s) for a total of @processed links.', [
      '@page_count' => $page_count,
      '@processed' => $processed,
    ]);

    // Copy the XSL stylesheet.
    $this->copyXslStylesheet();
  }

  /**
   * Prepare the directory for the ReliefWeb xmlsitemap.
   *
   * @command reliefweb_xmlsitemap:prepare-directory
   * @usage reliefweb_xmlsitemap:prepare-directory
   *   Prepare the directory for the ReliefWeb xmlsitemap.
   * @validate-module-enabled reliefweb_xmlsitemap
   */
  public function prepareDirectory() {
    $directory = $this->getDirectory();

    $result = $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    if (!$result) {
      $this->getLogger()->error('The directory %directory does not exist or is not writable.', [
        '%directory' => $directory,
      ]);
    }

    return $result;
  }

  /**
   * Clear the directory for the ReliefWeb xmlsitemap.
   *
   * @command reliefweb_xmlsitemap:clear-directory
   * @usage reliefweb_xmlsitemap:clear-directory
   *   Clear the directory for the ReliefWeb xmlsitemap.
   * @validate-module-enabled reliefweb_xmlsitemap
   * @option delete
   *   Delete directory as well.
   */
  public function clearDirectory(
    array $options = [
      'delete' => FALSE,
    ],
  ) {
    $directory = $this->getDirectory();

    if (is_dir($directory)) {
      $dir = dir($directory);

      while (($entry = $dir->read()) !== FALSE) {
        if ($entry == '.' || $entry == '..') {
          continue;
        }

        $path = $directory . '/' . $entry;
        // If the path couldn't be deleted, stop the process and log the error.
        if (!$this->fileSystem->deleteRecursive($path)) {
          $dir->close();
          $this->getLogger()->error('Enable to delete %path', [
            '%path' => $path,
          ]);

          return FALSE;
        }
      }

      $dir->close();

      if ($options['delete']) {
        return rmdir($directory);
      }

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Copy the XSL stylesheet to the xmlsitemap directory.
   *
   * @command reliefweb_xmlsitemap:copy-xsl-stylesheet
   * @usage reliefweb_xmlsitemap:copy-xsl-stylesheet
   *   Copy the XSL stylesheet to the xmlsitemap directory.
   * @validate-module-enabled reliefweb_xmlsitemap
   */
  public function copyXslStylesheet() {
    if ($this->prepareDirectory()) {
      // Source.
      $module_path = $this->extensionPathResolver->getPath('module', 'reliefweb_xmlsitemap');
      $source = $this->fileSystem->realpath($module_path) . '/includes/sitemap.xsl';

      if (file_exists($source)) {
        // Destination.
        $directory = $this->getDirectory();
        $destination = $this->fileSystem->realpath($directory) . '/sitemap.xsl';

        // Copy the file.
        if (!$this->fileSystem->copy($source, $destination, TRUE)) {
          $this->getLogger()->error('Unable to copy file @file', [
            '@file' => $destination,
          ]);
        }
      }
    }

    return FALSE;
  }

  /**
   * Submit the sitemap to the search engines.
   *
   * @command reliefweb_xmlsitemap:submit
   * @usage reliefweb_xmlsitemap:submit
   *   Submit the sitemap to the search engines.
   * @validate-module-enabled reliefweb_xmlsitemap
   * @aliases reliefweb-xmlsitemap-submit
   */
  public function submit() {
    // Check that the xmlsitemap actually exists before trying to submit it.
    $directory = $this->getDirectory();
    if (!file_exists($directory . '/sitemap.xml')) {
      $this->getLogger()->error('Sitemap missing. Unable to submit it to search engines.');
      return;
    }

    // Encode the sitemap url.
    $sitemap = UrlHelper::encodePath($this->getBaseUrl() . '/sitemap.xml');

    // Submit to all the search engines.
    foreach ($this->getSearchEngines() as $engine) {
      $ping_url = str_replace('[sitemap]', $sitemap, $engine['url']);
      try {
        $response = $this->httpClient->request('GET', $ping_url);
      }
      catch (\Exception $exception) {
        $this->getLogger()->error('Failed to submit sitemap to @engine with error: @error.', [
          '@engine' => $engine['name'],
          '@error' => $exception->getMessage(),
        ]);
        return;
      }
      if (!empty($response->error)) {
        $this->getLogger()->error('Failed to submit sitemap to @engine with error: @error.', [
          '@engine' => $engine['name'],
          '@error' => $response->error,
        ]);
      }
      else {
        $this->getLogger()->info('Sitemap successfully submitted to @engine.', [
          '@engine' => $engine['name'],
        ]);
      }
    }
  }

  /**
   * Get the directory for the xmlsitemap files.
   *
   * @return string
   *   Directory (URI).
   */
  public function getDirectory() {
    static $directory;

    if (!isset($directory)) {
      $directory = 'public://' . self::RELIEFWEB_XMLSITEMAP_DIRECTORY;
    }

    return $directory;
  }

  /**
   * Get the base url for the sitemap links.
   *
   * @return string
   *   Base url to use for the sitemap links.
   */
  protected function getBaseUrl() {
    static $url;
    if (!isset($url)) {
      global $base_secure_url;
      $url = $this->state->get('reliefweb_xmlsitemap_base_url', $base_secure_url);
    }
    return $url;
  }

  /**
   * Get the list of search engines to which the sitemap should be submitted.
   *
   * @return array
   *   List of search engines.
   */
  protected function getSearchEngines() {
    return [
      'google' => [
        'name' => 'Google',
        'url' => 'https://www.google.com/ping?sitemap=[sitemap]',
        'reference' => 'https://support.google.com/webmasters/answer/183669?hl=en',
      ],
    ];
  }

  /**
   * Get the steps for the sitemap generation.
   *
   * This returns an associative array with entity bundles (or dummy names
   * like "main") as keys and callbacks to retrieve links as values.
   *
   * The callbacks will be passed the key (ex: bundle) of the current step, the
   * number of links to retrieve and the id of the last retrieved link
   * (ex: id of the last entity for the bundle).
   *
   * The callbacks should then return an associative array with link ids as
   * keys and sitemap links as values.
   *
   * A sitemap link is an associative array with the following properties:
   * - loc: url of the link (url alias)
   * - lastmod: ISO 8601 date of the last modification
   * - changefreq: change frequency as string (ex: yearly, daily)
   * - priority: the prority of the link relative to the other links (ex: 0.5)
   *
   * @return array
   *   List of steps.
   *
   * @see https://www.sitemaps.org/protocol.html
   */
  protected function getSteps() {
    return [
      'main' => 'getMainLinks',
      'report' => 'getNodeLinks',
      'job' => 'getNodeLinks',
      'training' => 'getNodeLinks',
      'topics' => 'getNodeLinks',
      'country' => 'getTermLinks',
      'disaster' => 'getTermLinks',
      'source' => 'getTermLinks',
      'book' => 'getNodeLinks',
      'blog_post' => 'getNodeLinks',
    ];
  }

  /**
   * Create an sitemap page with the given links.
   *
   * @param int $page
   *   Current page index.
   * @param array $links
   *   Sitemap links (with "loc", "changefreq", "lastmod" and "priority").
   *
   * @see https://www.sitemaps.org/protocol.html
   */
  protected function writePage($page, array $links) {
    $base_url = $this->getBaseUrl();
    $directory = $this->getDirectory();

    // Initialize the writer.
    $xw = new \XMLWriter();
    $xw->openUri($directory . '/sitemap.xml?page=' . $page);
    $xw->setIndent(TRUE);

    // Header.
    $xw->startDocument('1.0', 'UTF-8');

    // XSL stylesheet.
    $xw->writePi('xml-stylesheet', 'type="text/xsl" href="' . $base_url . '/sitemap.xsl"');

    // Create the main element.
    $xw->startElement('urlset');
    // Set the namespace attribute.
    $xw->startAttribute('xmlns');
    $xw->text('http://www.sitemaps.org/schemas/sitemap/0.9');
    $xw->endAttribute();

    // Create all the link elements.
    foreach ($links as $link) {
      $xw->startElement('url');
      foreach ($link as $tag => $text) {
        $xw->startElement($tag);
        $xw->text($text);
        $xw->endElement();
      }
      $xw->endElement();
    }

    // Close the main element.
    $xw->endElement();
    // Write the file.
    $xw->endDocument();
  }

  /**
   * Create a sitemap index for all the pages.
   *
   * @param int $page_count
   *   Number of sitemap pages.
   *
   * @see https://www.sitemaps.org/protocol.html
   */
  protected function writeIndex($page_count) {
    $base_url = $this->getBaseUrl();
    $directory = $this->getDirectory();

    // Date of the modication.
    $date = gmdate('c', time());

    // Initialize the writer.
    $xw = new \XMLWriter();
    $xw->openUri($directory . '/sitemap.xml');
    $xw->setIndent(TRUE);

    // Header.
    $xw->startDocument('1.0', 'UTF-8');

    // XSL stylesheet.
    $xw->writePi('xml-stylesheet', 'type="text/xsl" href="' . $base_url . '/sitemap.xsl"');

    // Create the main element.
    $xw->startElement('sitemapindex');
    // Set the namespace attribute.
    $xw->startAttribute('xmlns');
    $xw->text('http://www.sitemaps.org/schemas/sitemap/0.9');
    $xw->endAttribute();

    // Create all the link elements.
    for ($page = 1; $page < $page_count + 1; $page++) {
      $xw->startElement('sitemap');

      // Location of the page.
      $xw->startElement('loc');
      $xw->text($base_url . '/sitemap.xml?page=' . $page);
      $xw->endElement();
      // Last modification date.
      $xw->startElement('lastmod');
      $xw->text($date);
      $xw->endElement();

      $xw->endElement();
    }

    // Close the main element.
    $xw->endElement();
    // Write the file.
    $xw->endDocument();
  }

  /**
   * Get the sitemap link data for the main pages (home and landing pages).
   *
   * The frequency is roughly based on the editorial workflow.
   *
   * @param string $type
   *   Unused for this step.
   * @param int $limit
   *   Number of links to generate.
   * @param int $last_id
   *   Key of the last main link.
   *
   * @return array
   *   Link data for the site's main pages.
   */
  protected function getMainLinks($type, $limit, $last_id) {
    $base_url = $this->getBaseUrl();

    // We cache the pages, to avoid multiple calls to the functions
    // that retrieve the last modified entities.
    static $pages;

    if (!isset($pages)) {
      // We boost a bit the priority of the homepage and landing pages.
      $pages = [
        'home' => [
          'loc' => $base_url,
          'lastmod' => $this->getLastModifiedNode('report'),
          'changefreq' => 'hourly',
          'priority' => 1.0,
        ],
        'updates' => [
          'loc' => $base_url . '/updates',
          'lastmod' => $this->getLastModifiedNode('report'),
          'changefreq' => 'hourly',
          'priority' => 0.8,
        ],
        'countries' => [
          'loc' => $base_url . '/countries',
          'lastmod' => $this->getLastModifiedTerm('country'),
          'changefreq' => 'daily',
          'priority' => 0.8,
        ],
        'disasters' => [
          'loc' => $base_url . '/disasters',
          'lastmod' => $this->getLastModifiedTerm('disaster'),
          'changefreq' => 'daily',
          'priority' => 0.8,
        ],
        'organizations' => [
          'loc' => $base_url . '/organizations',
          'lastmod' => $this->getLastModifiedTerm('source'),
          'changefreq' => 'daily',
          'priority' => 0.8,
        ],
        'topics' => [
          'loc' => $base_url . '/topics',
          'lastmod' => $this->getLastModifiedNode('topics'),
          'changefreq' => 'weekly',
          'priority' => 0.8,
        ],
        'jobs' => [
          'loc' => $base_url . '/jobs',
          'lastmod' => $this->getLastModifiedNode('job'),
          'changefreq' => 'hourly',
          'priority' => 0.8,
        ],
        'training' => [
          'loc' => $base_url . '/training',
          'lastmod' => $this->getLastModifiedNode('training'),
          'changefreq' => 'hourly',
          'priority' => 0.8,
        ],
        'blog' => [
          'loc' => $base_url . '/blog',
          'lastmod' => $this->getLastModifiedNode('blog_post'),
          'changefreq' => 'weekly',
          'priority' => 0.8,
        ],
      ];
    }

    // Get the index of the last id.
    $index = 0;
    if ($last_id !== 0) {
      foreach ($pages as $key => $link) {
        $index++;
        if ($key === $last_id) {
          break;
        }
      }
    }

    return array_slice($pages, $index, $limit, TRUE);
  }

  /**
   * Get the sitemap links for the nodes of the given type.
   *
   * @param string $type
   *   Node type.
   * @param int $limit
   *   Number of links to generate.
   * @param int $last_id
   *   Id of the last node of this type.
   *
   * @return array
   *   Sitemap links.
   */
  public function getNodeLinks($type, $limit, $last_id) {
    // Get the node ids.
    $ids = $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid'])
      ->condition('n.status', 1)
      ->condition('n.type', $type)
      ->condition('n.nid', $last_id, '>')
      ->orderBy('n.nid', 'ASC')
      ->range(0, $limit)
      ->execute()->fetchCol();

    $links = [];
    if (!empty($ids)) {
      // Get the revision timestamps to calculate the change frequency.
      $revision_timestamps = $this->database->select('node_revision', 'nr')
        ->fields('nr', ['nid', 'revision_timestamp'])
        ->condition('nr.nid', $ids, 'IN')
        ->execute()->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_GROUP);

      // Get the url alias for each node.
      $aliases = $this->getEntityUrlAliases('node', $ids);

      // Prepare the links.
      foreach ($ids as $id) {
        $timestamps = $revision_timestamps[$id] ?? [];

        $links[$id] = [
          'loc' => $aliases[$id],
          'changefreq' => $this->getChangeFrequency($timestamps),
          'lastmod' => gmdate('c', !empty($timestamps) ? max($timestamps) : 0),
          'priority' => 0.5,
        ];
      }
    }

    return $links;
  }

  /**
   * Get the sitemap links for the taxonomy terms from the given vocabulary.
   *
   * @param string $vocabulary
   *   Taxonomy vocabulary.
   * @param int $limit
   *   Number of links to generate.
   * @param int $last_id
   *   Id of the last taxonomy term from this vocabulary.
   *
   * @return array
   *   Sitemap links.
   */
  public function getTermLinks($vocabulary, $limit, $last_id) {
    $ids = $this->database->select('taxonomy_term_field_data', 'ttfd')
      ->fields('ttfd', ['tid'])
      ->condition('ttfd.vid', $vocabulary)
      ->condition('ttfd.status', 1)
      ->condition('ttfd.tid', $last_id, '>')
      ->orderBy('ttfd.tid', 'ASC')
      ->range(0, $limit)
      ->execute()->fetchCol();

    $links = [];
    if (!empty($ids)) {
      // Get the revision timestamps to calculate the change frequency.
      $revision_timestamps = $this->database->select('taxonomy_term_field_revision', 'ttdr')
        ->fields('ttdr', ['tid', 'changed'])
        ->condition('ttdr.tid', $ids, 'IN')
        ->execute()->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC);

      // Get the url alias for each term.
      $aliases = $this->getEntityUrlAliases('taxonomy_term', $ids);

      // Prepare the links.
      foreach ($ids as $id) {
        $timestamps = $revision_timestamps[$id] ?? [];

        $links[$id] = [
          'loc' => $aliases[$id],
          'changefreq' => $this->getChangeFrequency($timestamps),
          'lastmod' => gmdate('c', !empty($timestamps) ? max($timestamps) : 0),
          'priority' => 0.5,
        ];
      }
    }

    return $links;
  }

  /**
   * Get the date of the last modified accessible node with the given type.
   *
   * @param string $type
   *   Node type.
   *
   * @return string
   *   ISO 8601 date.
   */
  public function getLastModifiedNode($type) {
    static $cache;

    if (!isset($cache[$type])) {
      // For nodes, it's enough to check the status field which indicates
      // if a node is viewable by anonymous users.
      $timestamp = $this->database->query("
        SELECT MAX(n.changed)
        FROM {node_field_data} AS n
        WHERE n.status = 1
          AND n.type = :type
      ", [
        ':type' => $type,
      ])->fetchField();

      $cache[$type] = gmdate('c', $timestamp ?: 0);
    }

    return $cache[$type];
  }

  /**
   * Get the date of the last modified accessible term for the given vocubalary.
   *
   * @param string $vocabulary
   *   Taxonomy vocabulary.
   *
   * @return string
   *   ISO 8601 date.
   */
  public function getLastModifiedTerm($vocabulary) {
    static $cache;

    if (!isset($cache[$vocabulary])) {
      $timestamp = 0;

      $timestamp = $this->database->query("
        SELECT MAX(ttdr.changed)
        FROM {taxonomy_term_field_data} AS fs
        INNER JOIN {taxonomy_term_field_revision} AS ttdr
          ON ttdr.revision_id = fs.revision_id
          AND ttdr.tid = fs.tid
        WHERE fs.vid = :bundle
          AND fs.status = 1
      ", [
        ':bundle' => $vocabulary,
      ])->fetchField();

      $cache[$vocabulary] = gmdate('c', $timestamp ?: 0);
    }

    return $cache[$vocabulary];
  }

  /**
   * Get the url aliases for the given entity ids.
   *
   * @param string $entity_type
   *   Entity type.
   * @param array $ids
   *   Entity ids.
   *
   * @return array
   *   Associative array with the entity ids as keys and the aliases as values.
   */
  public function getEntityUrlAliases($entity_type, array $ids) {
    $base_url = $this->getBaseUrl();

    // Derive the base source path from the entity type.
    $base = '/' . str_replace('_', '/', $entity_type) . '/';

    // Generate a map entity id => entity uri.
    $map = [];
    foreach ($ids as $id) {
      $map[$id] = $base . $id;
    }

    // Get the url aliases.
    $aliases = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['path', 'alias'])
      ->condition('pa.path', $map, 'IN')
      ->orderBy('pa.id', 'ASC')
      ->execute()
      ?->fetchAllKeyed() ?? [];

    // Generate the links using the alias if available.
    foreach ($map as $id => $path) {
      $map[$id] = $base_url . UrlHelper::encodePath($aliases[$path] ?? $path);
    }

    return $map;
  }

  /**
   * Calculate the change frequency based on a list of timestamps.
   *
   * @param array $timestamps
   *   List of timestamps (for each change to an entity for example).
   *
   * @return string
   *   The name of the change frequency.
   */
  protected function getChangeFrequency(array $timestamps) {
    if (count($timestamps) > 1) {
      sort($timestamps);
      $count = count($timestamps) - 1;
      $diff = 0;

      for ($i = 0; $i < $count; $i++) {
        $diff += $timestamps[$i + 1] - $timestamps[$i];
      }

      $interval = round($diff / $count);

      foreach (self::RELIEFWEB_XMLSITEMAP_FREQUENCIES as $frequency => $value) {
        if ($interval <= $value) {
          return $frequency;
        }
      }
    }

    return 'never';
  }

}
