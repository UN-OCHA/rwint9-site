<?php

namespace Drupal\reliefweb_sync_orgs\Plugin\QueueWorker;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\taxonomy\Entity\Term;
use Fuse\Fuse;
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
   *   The database connection.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, Connection $database) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * Used to grab functionality from the container.
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('database'),
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
   */
  protected function mergeHdxOrganizationTerm($item) {
    $source = 'hdx';
    $id = $item['id'];
    $term = NULL;

    $import_record = $this->getExistingImportRecord($source, $id);
    if (empty($import_record)) {
      $import_record = $this->constructReliefwebSyncOrgsRecord($source, $id, $item);
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
    }

    if ($term) {
      $import_record['tid'] = $term->id();
      $import_record['status'] = 'success';
      $import_record = $this->saveImportRecords($source, $id, $import_record);
      return;
    }

    // Try searching with fuse.
    $fuse = $this->buildFuseSearchForName();
    $search_results = $fuse->search($item['name']);
    if (!empty($search_results)) {
      $best_match = reset($search_results);

      if ($best_match['score'] < 0.2) {
        $import_record['tid'] = $best_match['item']['tid'];
        $import_record['status'] = 'partial';
        $import_record = $this->saveImportRecords($source, $id, $import_record);
        return;
      }
      else {
        $import_record['tid'] = $best_match['item']['tid'];
        $import_record['status'] = 'mismatch';
        $import_record = $this->saveImportRecords($source, $id, $import_record);
        return;
      }
    }

    $import_record['status'] = 'skipped';
    $import_record = $this->saveImportRecords($source, $id, $import_record);
  }

  /**
   * Merges an organization term based on a HPC item.
   */
  protected function mergeHpcOrganizationTerm($item) {
    $source = 'hpc';
    $id = $item['org id'];
    $term = NULL;

    $import_record = $this->getExistingImportRecord($source, $id);
    if (empty($import_record)) {
      $import_record = $this->constructReliefwebSyncOrgsRecord($source, $id, $item);
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
      $import_record = $this->saveImportRecords($source, $id, $import_record);
      return;
    }

    $import_record['status'] = 'skipped';
    $import_record = $this->saveImportRecords($source, $id, $import_record);
  }

  /**
   * Load a source taxonmy term using the name.
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
   * Load a source taxonomy term using a alias.
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
   * Construct a reliefweb_sync_orgs_records.
   */
  protected function constructReliefwebSyncOrgsRecord(string $source, string $id, array $item): array {
    return [
      'source' => $source,
      'id' => $id,
      'status' => $item['status'] ?? 'queued',
      'created' => time(),
      'changed' => time(),
      'message' => '',
      'csv_item' => $item,
    ];
  }

  /**
   * Retrieve existing records.
   */
  protected function getExistingImportRecord(string $source, string $id): array {
    $record = $this->database->select('reliefweb_sync_orgs_records', 'r')
      ->fields('r')
      ->condition('source', $source)
      ->condition('id', $id)
      ->execute()
      ?->fetch(\PDO::FETCH_ASSOC);

    if (isset($record['csv_item'])) {
      $record['csv_item'] = json_decode($record['csv_item'], TRUE);
    }

    return is_array($record) ? $record : [];
  }

  /**
   * Save import records.
   */
  protected function saveImportRecords(string $source, string $id, array $record): array {
    $existing_record = $this->getExistingImportRecord($source, $id);

    // Set timestamp for changed field.
    $record['changed'] = time();

    // Serialize json data.
    if (isset($record['csv_item'])) {
      $record['csv_item'] = json_encode($record['csv_item']);
    }

    // Create comparison copies without the 'changed' timestamp.
    $compare_existing = $existing_record;
    $compare_new = $record;
    unset($compare_existing['changed']);
    unset($compare_new['changed']);

    // Only update if the record has actually changed.
    if (!empty($existing_record)) {
      if ($compare_existing != $compare_new) {
        $this->database->update('reliefweb_sync_orgs_records')
          ->fields($record)
          ->condition('source', $source)
          ->condition('id', $id)
          ->execute();
      }
    }
    else {
      // Set timestamp for created field if not provided.
      if (!isset($record['created'])) {
        $record['created'] = time();
      }

      // Insert new record.
      $this->database->insert('reliefweb_sync_orgs_records')
        ->fields($record)
        ->execute();
    }

    return $record;
  }

  /**
   * Build a fuse search of all terms.
   */
  protected function buildFuseSearchForName(): Fuse {
    $terms = $this->database
      ->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid', 'name'])
      ->condition('vid', 'source')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $fuse = new Fuse($terms, [
      'keys' => ['name'],
      'isCaseSensitive' => FALSE,
      'ignoreDiacritics' => TRUE,
      'threshold' => 0.3,
      'includeScore' => TRUE,
      'minMatchCharLength' => 3,
    ]);

    return $fuse;
  }

}
