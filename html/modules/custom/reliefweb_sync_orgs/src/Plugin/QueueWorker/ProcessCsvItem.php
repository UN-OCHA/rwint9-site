<?php

namespace Drupal\reliefweb_sync_orgs\Plugin\QueueWorker;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\reliefweb_sync_orgs\Service\FuzySearchService;
use Drupal\reliefweb_sync_orgs\Service\ImportRecordService;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes a single item from the CSV import queue.
 *
 * @QueueWorker(
 *   id = "reliefweb_sync_orgs_process_csv_item",
 *   title = @Translation("Process ReliefWeb Sync Orgs CSV Item"),
 *   cron = {"time" = 60}
 * )
 */
class ProcessCsvItem extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The cache.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBackend;

  /**
   * The import record service.
   *
   * @var \Drupal\reliefweb_sync_orgs\Service\ImportRecordService
   */
  protected $importRecordService;

  /**
   * Main constructor.
   *
   * @param array $configuration
   *   Configuration array.
   * @param mixed $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\reliefweb_sync_orgs\Service\ImportRecordService $import_record_service
   *   The import record service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, Connection $database, CacheBackendInterface $cache_backend, ImportRecordService $import_record_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->cacheBackend = $cache_backend;
    $this->importRecordService = $import_record_service;
  }

  /**
   * Used to grab functionality from the container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   *
   * @return \Drupal\reliefweb_sync_orgs\Plugin\QueueWorker\ProcessCsvItem
   *   A new instance of the queue worker plugin.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('cache.reliefweb_sync_orgs'),
      $container->get('reliefweb_sync_orgs.import_record_service'),
    );
  }

  /**
   * Processes an item in the queue.
   *
   * @param mixed $queue_item
   *   The queue item data.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  public function processItem($queue_item) {
    $this->mergeOrganizationTerm($queue_item);
  }

  /**
   * Try to merge with existing term, or create a new one.
   *
   * @param array $item
   *   The raw item data from the queue (decoded CSV row + source metadata).
   *
   * @throws \Exception
   *   Thrown when the source is unsupported.
   */
  protected function mergeOrganizationTerm($item) {
    $source = $item['_source'] ?? '';

    switch ($source) {
      case 'hdx':
        $this->mergeHdxOrganizationTerm($item);
        break;

      case 'hpc':
        $this->mergeHpcOrganizationTerm($item);
        break;

      default:
        throw new \Exception("Unsupported source: $source");
    }
  }

  /**
   * Merges an organization term based on a HDX item.
   *
   * Performs exact lookup attempts (fts_id, name, long name, short name,
   * aliases) followed by a fuzzy search fallback. Updates and persists the
   * corresponding import record with status (success | partial | skipped).
   *
   * @param array $item
   *   The HDX organization data array.
   */
  protected function mergeHdxOrganizationTerm($item) {
    $source = 'hdx';
    $id = $item['id'];
    $term = NULL;

    $import_record = $this->importRecordService->getExistingImportRecord($source, $id);
    if (empty($import_record)) {
      $import_record = $this->importRecordService->constructReliefwebSyncOrgsRecord($source, $id, $item);
    }

    // Skip if already processed.
    if ($import_record['status'] === 'success') {
      return;
    }

    // If fts_id is available, try to load the term by fts_id.
    if (!empty($item['fts_id'])) {
      $term = $this->loadSourceTermByFtsId($item['fts_id']);
    }

    // If term is not found by fts_id, try to load it by id.
    if (empty($term)) {
      // List of possible fields to check for existing terms.
      $fields_to_check = [
        'display_name',
        'name',
        'title',
      ];

      // Remove " (inactive)" from display_name and name fields.
      foreach ($fields_to_check as $field) {
        if (isset($item[$field]) && is_string($item[$field])) {
          $item[$field] = str_replace(' (inactive)', '', $item[$field]);
        }
      }

      // First try exact matches on the fields.
      $message = '';
      foreach ($fields_to_check as $field) {
        if (!empty($item[$field])) {
          $message = "Matched by $field: {$item[$field]}";
          $term = $this->loadSourceTermByName($item[$field]);
          if ($term) {
            break;
          }

          $term = $this->loadSourceTermByLongName($item[$field]);
          if ($term) {
            break;
          }

          $term = $this->loadSourceTermByShortName($item[$field]);
          if ($term) {
            break;
          }

          $term = $this->loadSourceTermByAlias($item[$field]);
          if ($term) {
            break;
          }
        }
      }
    }

    if ($term) {
      $import_record['tid'] = $term->id();
      $import_record['status'] = 'success';
      $import_record['message'] = $message;
      $import_record = $this->importRecordService->saveImportRecords($source, $id, $import_record);
      return;
    }

    // Try searching with fuse.
    $message = "No exact match found. Attempting fuzzy search.";
    $fuzzy_search = $this->buildFuseSearchForName();
    $search_result = $fuzzy_search->search($item['display_name']);

    if (!empty($search_result)) {
      $import_record['status'] = $search_result['status'] ?? 'partial';
      $import_record['tid'] = $search_result['tid'] ?? NULL;
      $import_record['message'] = "Fuzzy match found: {$search_result['name']} with score {$search_result['score']}.";
      $import_record = $this->importRecordService->saveImportRecords($source, $id, $import_record);
      return;
    }

    $import_record['status'] = 'skipped';
    $import_record = $this->importRecordService->saveImportRecords($source, $id, $import_record);
  }

  /**
   * Merges an organization term based on a HPC item.
   *
   * Attempts exact matches on abbreviation and name (including long/short
   * names and aliases). Updates the import record accordingly.
   *
   * @param array $item
   *   The HPC organization data array.
   */
  protected function mergeHpcOrganizationTerm($item) {
    $source = 'hpc';
    $id = $item['org id'];
    $term = NULL;

    $import_record = $this->importRecordService->getExistingImportRecord($source, $id);
    if (empty($import_record)) {
      $import_record = $this->importRecordService->constructReliefwebSyncOrgsRecord($source, $id, $item);
    }

    // List of possible fields to check for existing terms.
    $fields_to_check = [
      'org abbreviation',
      'org name',
    ];

    // First try exact matches on the fields.
    foreach ($fields_to_check as $field) {
      if (!empty($item[$field])) {
        $term = $this->loadSourceTermByName($item[$field]);
        if ($term) {
          break;
        }

        $term = $this->loadSourceTermByLongName($item[$field]);
        if ($term) {
          break;
        }

        $term = $this->loadSourceTermByShortName($item[$field]);
        if ($term) {
          break;
        }

        $term = $this->loadSourceTermByAlias($item[$field]);
        if ($term) {
          break;
        }
      }
    }

    if ($term) {
      $import_record['tid'] = $term->id();
      $import_record['status'] = 'success';
      $import_record = $this->importRecordService->saveImportRecords($source, $id, $import_record);
      return;
    }

    $import_record['status'] = 'skipped';
    $import_record = $this->importRecordService->saveImportRecords($source, $id, $import_record);
  }

  /**
   * Load a source taxonomy term using the name.
   *
   * @param string $name
   *   The term name to match.
   *
   * @return \Drupal\taxonomy\Entity\Term|null
   *   The matching taxonomy term entity, or NULL if none found.
   */
  protected function loadSourceTermByName(string $name): ?Term {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'name' => $name,
        'vid' => 'source',
      ]);

    if (empty($terms)) {
      return NULL;
    }

    return reset($terms);
  }

  /**
   * Load a source taxonomy term using the long name.
   *
   * @param string $long_name
   *   The long (expanded) name to match against field_longname.
   *
   * @return \Drupal\taxonomy\Entity\Term|null
   *   The matching taxonomy term entity, or NULL if none found.
   */
  protected function loadSourceTermByLongName(string $long_name): ?Term {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'field_longname' => $long_name,
        'vid' => 'source',
      ]);

    if (empty($terms)) {
      return NULL;
    }

    return reset($terms);
  }

  /**
   * Load a source taxonomy term using the short name.
   *
   * @param string $short_name
   *   The short (abbreviated) name to match against field_shortname.
   *
   * @return \Drupal\taxonomy\Entity\Term|null
   *   The matching taxonomy term entity, or NULL if none found.
   */
  protected function loadSourceTermByShortName(string $short_name): ?Term {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'field_shortname' => $short_name,
        'vid' => 'source',
      ]);

    if (empty($terms)) {
      return NULL;
    }

    return reset($terms);
  }

  /**
   * Load a source taxonomy term using an alias value.
   *
   * @param string $alias
   *   The alias to match against field_aliases.
   *
   * @return \Drupal\taxonomy\Entity\Term|null
   *   The matching taxonomy term entity, or NULL if none found.
   */
  protected function loadSourceTermByAlias(string $alias): ?Term {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'field_aliases' => $alias,
        'vid' => 'source',
      ]);

    if (empty($terms)) {
      return NULL;
    }

    return reset($terms);
  }

  /**
   * Load a source taxonomy term using the fts_id.
   *
   * @param string $fts_id
   *   The Financial Tracking Service (FTS) identifier to match.
   *
   * @return \Drupal\taxonomy\Entity\Term|null
   *   The matching taxonomy term entity, or NULL if none found.
   */
  protected function loadSourceTermByFtsId(string $fts_id): ?Term {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'field_fts_id' => $fts_id,
        'vid' => 'source',
      ]);

    if (empty($terms)) {
      return NULL;
    }

    return reset($terms);
  }

  /**
   * Build a fuse search of all terms.
   *
   * Pulls (tid, name) for taxonomy terms in the 'source' vocabulary from
   * cache (if available) or database (with a short-lived cache populate), and
   * initializes a FuzySearchService instance for fuzzy name matching.
   *
   * @return \Drupal\reliefweb_sync_orgs\Service\FuzySearchService
   *   A fuzzy search service preloaded with source term name data.
   */
  protected function buildFuseSearchForName(): FuzySearchService {
    $cid = 'reliefweb_sync_orgs:source_terms';
    $cache = $this->cacheBackend->get($cid);

    if ($cache && $cache->data) {
      $terms = $cache->data;
    }
    else {
      $terms = $this->database
        ->select('taxonomy_term_field_data', 't')
        ->fields('t', ['tid', 'name'])
        ->condition('vid', 'source')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      // Cache for 15 minutes.
      $this->cacheBackend->set($cid, $terms, time() + 15 * 60);
    }

    return new FuzySearchService($terms);
  }

}
