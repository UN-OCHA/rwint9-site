<?php

declare(strict_types=1);

namespace Drupal\reliefweb_sync_orgs\Plugin\QueueWorker;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\reliefweb_sync_orgs\Service\ImportRecordService;
use Drupal\reliefweb_sync_orgs\Traits\CleanIdFieldTrait;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes a single item from the CSV import queue.
 *
 * @QueueWorker(
 *   id = "reliefweb_sync_orgs_from_export",
 *   title = @Translation("Process ReliefWeb Sync Orgs from export"),
 *   cron = {"time" = 60}
 * )
 */
class ProcessFromExport extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use CleanIdFieldTrait;
  use LoggerChannelTrait;

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
  public function processItem($queue_item): void {
    $this->updateOrCreateTerm($queue_item);
  }

  /**
   * Update or create a a new term.
   *
   * @param array $item
   *   The organization data array.
   */
  protected function updateOrCreateTerm($item): void {
    $source = $item['source'] ?? '';
    if (empty($source)) {
      throw new \Exception('Source must be provided in the item data.');
    }

    $field_info = reliefweb_sync_orgs_field_info($source);
    if (empty($field_info)) {
      throw new \Exception("No field info found for source: $source");
    }

    $id = $item['id'] ?? NULL;
    if (empty($id)) {
      throw new \Exception("ID must be provided in the item data for source: $source");
    }

    // Clean the ID to ensure it is safe for use.
    $id = $this->cleanId($id);

    $term = NULL;

    // Load existing import record or throw an error if none found.
    $import_record = $this->importRecordService->getExistingImportRecord($source, $id);
    if (empty($import_record)) {
      throw new \Exception("No import record found for source: $source and ID: $id");
    }

    // Skip if already processed.
    if ($import_record['status'] === 'success' || $import_record['status'] === 'fixed') {
      $this->getLogger('reliefweb_sync_orgs')->info('Skipping already processed item: @id', ['@id' => $id]);
      return;
    }

    // Do we need to create a new term?
    if (!empty($item['create_new']) && $item['create_new'] == '1') {
      $this->getLogger('reliefweb_sync_orgs')->info('Creating new term for item: @id', ['@id' => $id]);
      $payload = [
        'name' => $item['name'],
        'vid' => 'source',
        'field_shortname' => [
          'value' => $item['name'],
        ],
      ];

      // Add additional information.
      foreach ($field_info['mapping'] ?? [] as $field => $target) {
        if (isset($import_record['csv_item'][$field]) && !empty($import_record['csv_item'][$field])) {
          switch ($target) {
            case 'description':
              $payload[$target] = [
                'value' => $import_record['csv_item'][$field],
                'format' => 'markdown',
              ];
              break;

            case 'field_homepage':
              $payload[$target] = [
                'uri' => $import_record['csv_item'][$field],
              ];
              break;

            case 'country':
              // Try to load the country term if available.
              $country_id = $this->entityTypeManager
                ->getStorage('taxonomy_term')
                ->loadByProperties([
                  'vid' => 'country',
                  'name' => $import_record['csv_item'][$field],
                ]);

              if ($country_id) {
                $payload[$target] = [
                  'target_id' => reset($country_id)->id(),
                ];
              }

              break;

            case 'organization_type':
              // Try to load the organization type term if available.
              $org_type_id = $this->entityTypeManager
                ->getStorage('taxonomy_term')
                ->loadByProperties([
                  'vid' => 'organization_type',
                  'name' => $import_record['csv_item'][$field],
                ]);

              if ($org_type_id) {
                $payload[$target] = [
                  'target_id' => reset($org_type_id)->id(),
                ];
              }

              break;

            default:
              $payload[$target] = [
                'value' => $import_record['csv_item'][$field],
              ];
          }
        }
      }

      // Do we need to set a parent term?
      if (!empty($item['parent_id'])) {
        $payload['parent'] = [
          'target_id' => $item['parent_id'],
        ];
      }
      elseif (!empty($item['parent_name'])) {
        $parent_term = $this->loadSourceTermByName($item['parent_name']);
        if ($parent_term) {
          $payload['parent'] = [
            'target_id' => $parent_term->id(),
          ];
        }
      }

      // Use fields from the imported file if available.
      if ($item['use_sheet_data'] ?? FALSE && $item['use_sheet_data'] == '1') {
        $fields = [
          'homepage' => 'field_homepage',
          'countries' => 'field_country',
          'short_name' => 'field_shortname',
          'description' => 'description',
        ];

        foreach ($fields as $import_field => $target_field) {
          if (isset($item[$import_field]) && !empty($item[$import_field])) {
            switch ($import_field) {
              case 'description':
                $payload[$target_field] = [
                  'value' => $item[$import_field],
                  'format' => 'markdown',
                ];
                break;

              case 'countries':
                // Try to load the country terms if available.
                $payload[$target_field] = [];
                foreach (explode(',', $item[$import_field]) as $country_name) {
                  $country_name = trim($country_name);
                  if (empty($country_name)) {
                    continue;
                  }
                  $country_id = $this->entityTypeManager
                    ->getStorage('taxonomy_term')
                    ->loadByProperties([
                      'vid' => 'country',
                      'name' => $country_name,
                    ]);

                  if ($country_id) {
                    $payload[$target_field][] = [
                      'target_id' => reset($country_id)->id(),
                    ];
                  }
                }

                break;

              case 'homepage':
                $payload[$target_field] = [
                  'uri' => $item[$import_field],
                ];
                break;

              case 'short_name':
                $payload[$target_field] = [
                  'value' => $item[$import_field],
                ];
                break;
            }
          }
        }
      }

      $term = Term::create($payload);
      $term->save();

      $import_record['tid'] = $term->id();
      $import_record['status'] = 'fixed';
      $import_record['message'] = 'Organization created by import';
      $this->importRecordService->saveImportRecords($source, $id, $import_record);
      return;
    }

    // Check if we have a term ID.
    if (isset($item['term_id'])) {
      $item['term_id'] = trim($item['term_id']);
      if (!empty($item['term_id']) && $import_record['tid'] != $item['term_id']) {
        $this->getLogger('reliefweb_sync_orgs')->info('Processing item @id with term ID: @tid', [
          '@id' => $item['id'],
          '@tid' => $item['term_id'],
        ]);

        $import_record['tid'] = $item['term_id'];
        $import_record['status'] = 'fixed';
        $import_record['message'] = "Term ID updated to {$item['term_id']}";
        $import_record = $this->importRecordService->saveImportRecords($source, $id, $import_record);
        return;
      }
    }

    // Check if we have a term name.
    if (isset($item['term_name']) && !empty($item['term_name'])) {
      $item['term_name'] = trim($item['term_name']);

      $this->getLogger('reliefweb_sync_orgs')->info('Processing item @id with term name: @name', [
        '@id' => $item['id'],
        '@name' => $item['term_name'],
      ]);
      $term = $this->loadSourceTermByName($item['term_name']);
      if ($term) {
        if ($import_record['tid'] != $term->id()) {
          $import_record['tid'] = $term->id();
          $import_record['status'] = 'fixed';
          $import_record['message'] = "Term ID updated to {$term->id()}";
          $import_record = $this->importRecordService->saveImportRecords($source, $id, $import_record);
          return;
        }
      }
      else {
        $import_record['status'] = 'skipped';
        $import_record['message'] = "Term not found: {$item['term_name']}";
        $import_record = $this->importRecordService->saveImportRecords($source, $id, $import_record);
        return;
      }
    }
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

}
