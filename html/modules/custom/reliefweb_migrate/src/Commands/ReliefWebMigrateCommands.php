<?php

namespace Drupal\reliefweb_migrate\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\ConfigManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\MigrationPluginManager;
use Drupal\migrate\Plugin\RequirementsInterface;
use Drupal\reliefweb_migrate\Entity\AccumulatedRedirectStorage;
use Drupal\reliefweb_migrate\Plugin\migrate\source\SourceMigrationHighWaterInterface;
use Drupal\reliefweb_migrate\Plugin\migrate\source\SourceMigrationStatusInterface;
use Drupal\reliefweb_utility\Helpers\LegacyHelper;
use Drush\Commands\DrushCommands;
use GuzzleHttp\Psr7\Utils;
use Symfony\Component\Uid\Uuid;

/**
 * ReliefWeb migration Drush commandfile.
 */
class ReliefWebMigrateCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;

  /**
   * Config Installer.
   *
   * @var Drupal\Core\Config\ConfigInstallerInterface
   */
  protected $configInstaller;

  /**
   * Config Manager.
   *
   * @var Drupal\Core\Config\ConfigManager
   */
  protected $configManager;

  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Key-value store service.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected $keyValue;

  /**
   * The memory cache backend.
   *
   * @var Drupal\Core\Cache\MemoryCache\MemoryCacheInterface
   */
  protected $memoryCache;

  /**
   * Migration plugin manager service.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManager
   */
  protected $migrationPluginManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigInstallerInterface $config_installer,
    ConfigManager $config_manager,
    Connection $database,
    DateFormatter $date_formatter,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    KeyValueFactoryInterface $key_value,
    MemoryCacheInterface $memory_cache,
    MigrationPluginManager $migration_plugin_manager
  ) {
    parent::__construct();
    $this->configInstaller = $config_installer;
    $this->configManager = $config_manager;
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->keyValue = $key_value;
    $this->memoryCache = $memory_cache;
    $this->migrationPluginManager = $migration_plugin_manager;
  }

  /**
   * Reload ReliefWeb migration configurations.
   *
   * @command rw-migrate:reload-configuration
   * @aliases rw-mrc,rw-migrate-reload-configuration
   * @usage rw-migrate:reload-configuration
   *   Reload all ReliefWeb migration configurations.
   * @validate-module-enabled reliefweb_migrate
   */
  public function reloadConfiguration() {
    // Uninstall and reinstall all configuration.
    $this->configManager->uninstall('module', 'reliefweb_migrate');
    $this->configInstaller->installDefaultConfig('module', 'reliefweb_migrate');

    // Rebuild cache.
    $process = $this->processManager()->drush($this->siteAliasManager()->getSelf(), 'cache-rebuild');
    $process->mustrun();

    $this->logger()->success(dt('Config reload complete.'));
    return TRUE;
  }

  /**
   * List all migrations with current status.
   *
   * @command rw-migrate:status
   *
   * @option group A comma-separated list of migration groups to list
   * @option tag Name of the migration tag to list
   *
   * @default $options []
   *
   * @usage rw-migrate:status
   *   Retrieve status for all migrations
   * @usage rw-migrate:status beer_term,beer_node
   *   Retrieve status for specific migrations
   * @usage rw-migrate:status --group=beer
   *   Retrieve status for all migrations in a given group
   * @usage migrate:status --tag=user
   *   Retrieve status for all migrations with a given tag
   *
   * @validate-module-enabled reliefweb_migrate
   *
   * @aliases rw-ms, rw-migrate-status
   *
   * @field-labels
   *   group: Group
   *   id: Migration ID
   *   status: Status
   *   total: Total
   *   imported: Imported
   *   unchanged: Unchanged
   *   new: New
   *   updated: Updated
   *   deleted: Deleted
   *   last_imported: Last Imported
   * @default-fields group,id,status,total,imported,unchanged,new,updated,deleted,last_imported
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Migrations status formatted as table.
   */
  public function status($migration_names = '', array $options = [
    'group' => '',
    'tag' => '',
  ]) {
    $migrations = $this->migrationsList($migration_names, $options);

    $table = [];
    // Take it one group at a time, listing the migrations within each group.
    foreach ($migrations as $group_id => $migration_list) {
      /** @var \Drupal\migrate_plus\Entity\MigrationGroup $group */
      $group = $this->entityTypeManager->getStorage('migration_group')->load($group_id);
      $group_name = !empty($group) ? "{$group->label()} ({$group->id()})" : $group_id;

      foreach ($migration_list as $migration_id => $migration) {
        $source_plugin = $migration->getSourcePlugin();

        if ($source_plugin instanceof SourceMigrationStatusInterface) {
          extract($source_plugin->getMigrationStatus());
        }
        else {
          $total = dt('N/A');
          $imported = dt('N/A');
          $new = dt('N/A');
          $unchanged = dt('N/A');
          $deleted = dt('N/A');
          $updated = dt('N/A');

          $map = $migration->getIdMap();
          $imported = $map->importedCount();
          $total = $source_plugin->count();
          // -1 indicates uncountable sources.
          if ($total == -1) {
            $total = dt('N/A');
            $imported = dt('N/A');
          }
          else {
            $new = $total - $map->processedCount();
          }
        }

        $status = $migration->getStatusLabel();

        $migrate_last_imported_store = $this->keyValue->get(
          'migrate_last_imported'
        );
        $last_imported = $migrate_last_imported_store->get(
          $migration->id(),
          FALSE
        );
        if ($last_imported) {
          $last_imported = $this->dateFormatter->format(
            $last_imported / 1000,
            'custom',
            'Y-m-d H:i:s'
          );
        }
        else {
          $last_imported = '';
        }
        $table[] = [
          'group' => $group_name,
          'id' => $migration_id,
          'status' => $status,
          'total' => $total,
          'imported' => $imported,
          'unchanged' => $unchanged,
          'new' => $new,
          'updated' => $updated,
          'deleted' => $deleted,
          'last_imported' => $last_imported,
        ];
      }

      // Add empty row to separate groups, for readability.
      end($migrations);
      if ($group_id !== key($migrations)) {
        $table[] = [];
      }
    }

    return new RowsOfFields($table);
  }

  /**
   * Retrieve a list of active migrations.
   *
   * @param string $migration_ids
   *   Comma-separated list of migrations -
   *   if present, return only these migrations.
   * @param array $options
   *   Command options.
   *
   * @return \Drupal\migrate\Plugin\MigrationInterface[][]
   *   An array keyed by migration group, each value containing an array of
   *   migrations or an empty array if no migrations match the input criteria.
   */
  protected function migrationsList($migration_ids = '', array $options = []) {
    // Filter keys must match the migration configuration property name.
    $filter['migration_group'] = explode(',', $options['group']);
    $filter['migration_tags'] = explode(',', $options['tag']);

    $manager = $this->migrationPluginManager;

    $matched_migrations = [];

    if (empty($migration_ids)) {
      // Get all migrations.
      $plugins = $manager->createInstances([]);
      $matched_migrations = $plugins;
    }
    else {
      // Get the requested migrations.
      $migration_ids = explode(',', mb_strtolower($migration_ids));

      $definitions = $manager->getDefinitions();

      foreach ($migration_ids as $given_migration_id) {
        if (isset($definitions[$given_migration_id])) {
          $matched_migrations[$given_migration_id] = $manager->createInstance($given_migration_id);
        }
        else {
          $error_message = dt('Migration @id does not exist', ['@id' => $given_migration_id]);
          throw new \Exception($error_message);
        }

      }
    }

    // Do not return any migrations which fail to meet requirements.
    /** @var \Drupal\migrate\Plugin\Migration $migration */
    foreach ($matched_migrations as $id => $migration) {
      try {
        if ($migration->getSourcePlugin() instanceof RequirementsInterface) {
          $migration->getSourcePlugin()->checkRequirements();
        }
      }
      catch (RequirementsException $e) {
        unset($matched_migrations[$id]);
      }
      catch (PluginNotFoundException $exception) {
        throw $exception;
      }
    }

    // Filters the matched migrations if a group or a tag has been input.
    if (!empty($filter['migration_group']) || !empty($filter['migration_tags'])) {
      // Get migrations in any of the specified groups and with any of the
      // specified tags.
      foreach ($filter as $property => $values) {
        if (!empty($values)) {
          $filtered_migrations = [];
          foreach ($values as $search_value) {
            foreach ($matched_migrations as $id => $migration) {
              // Cast to array because migration_tags can be an array.
              $definition = $migration->getPluginDefinition();
              $configured_values = (array) ($definition[$property] ?? NULL);
              $configured_id = in_array($search_value, $configured_values, TRUE) ? $search_value : 'default';
              if (empty($search_value) || $search_value === $configured_id) {
                if (empty($migration_ids) || in_array(
                    mb_strtolower($id),
                    $migration_ids,
                    TRUE
                  )) {
                  $filtered_migrations[$id] = $migration;
                }
              }
            }
          }
          $matched_migrations = $filtered_migrations;
        }
      }
    }

    // Sort the matched migrations by group.
    if (!empty($matched_migrations)) {
      foreach ($matched_migrations as $id => $migration) {
        $configured_group_id = empty($migration->migration_group) ? 'default' : $migration->migration_group;
        $migrations[$configured_group_id][$id] = $migration;
      }
    }
    return $migrations ?? [];
  }

  /**
   * Migrate the images.
   *
   * @command rw-migrate:migrate-images
   *
   * @option base_url The base url of the site from which to retrieve the files.
   * @option batch_size Number of reports with images to process at once.
   * @option limit Maximum number of reports with non migrated files to process,
   *   0 means process everything.
   * @option delete Delete the old -> new uri mapping.
   *
   * @default options [
   *   'base_url' => 'https://reliefweb.int',
   *   'batch_size' => 1000,
   *   'limit' => 0,
   *   'delete' => FALSE,
   * ]
   *
   * @aliases rw-mmi,rw-migrate-migrate-images
   *
   * @usage rw-migrate:migrate-images
   *   Download the images.
   *
   * @validate-module-enabled reliefweb_migrate
   */
  public function migrateImages($options = [
    'base_url' => 'https://reliefweb.int',
    'batch_size' => 1000,
    'limit' => 0,
    'delete' => FALSE,
  ]) {
    $base_url = $options['base_url'];
    $batch_size = (int) $options['batch_size'];
    $limit = (int) $options['limit'];

    if (preg_match('#^https?://[^/]+$#', $base_url) !== 1) {
      $this->logger()->error(dt('The base url must be in the form http(s)://example.test.'));
      return FALSE;
    }
    if ($batch_size < 1 || $batch_size > 1000) {
      $this->logger()->error(dt('The batch size must be within 1 and 1000.'));
      return FALSE;
    }
    if ($limit < 0) {
      $this->logger()->error(dt('The limit must be equal or superior to 0.'));
      return FALSE;
    }

    // Get the attachment field table.
    $table = 'reliefweb_migrate_uri_mapping';

    // Retrieve the most recent report node with attachments.
    $query = $this->database->select($table, $table);
    $query->addExpression('MAX(' . $table . '.id)');
    $last = $query->execute()?->fetchField();

    if (empty($last)) {
      $this->logger()->info(dt('No images to download found.'));
      return TRUE;
    }

    $last = $last + 1;
    $count_images = 0;
    $count_downloaded = 0;

    while ($last !== NULL) {
      $records = $this->database
        ->select($table, $table)
        ->fields($table, ['id', 'new_uri', 'old_uri'])
        ->orderBy('id', 'DESC')
        ->condition('id', $last, '<')
        ->range(0, $limit > 0 ? min($limit - $count_images, $batch_size) : $batch_size)
        ->execute()
        ?->fetchAllAssoc('id', \PDO::FETCH_ASSOC) ?? [];

      if (!empty($records)) {
        $last = array_key_last($records);
        $count_images += count($records);

        foreach ($records as $record) {
          $source = str_replace('public://', '', $record['old_uri']);
          $source = UrlHelper::encodePath($source);
          $source = $base_url . '/sites/default/files/' . $source;
          $result = $this->downloadFile($source, $record['new_uri']);
          $count_downloaded += $result ? 1 : 0;
        }

        // Remove the entries form the url mapping.
        // @todo check if there is a different way to mark files as downloaded.
        if (!empty($options['delete'])) {
          $this->database->delete($table)
            ->condition('id', array_keys($records), 'IN')
            ->execute();
        }
      }
      else {
        $last = NULL;
        break;
      }

      if ($limit > 0 && $count_images >= $limit) {
        break;
      }
    }

    $this->logger()->info(dt('Successfully downloaded @count_downloaded out of @count_images images.', [
      '@count_downloaded' => $count_downloaded,
      '@count_images' => $count_images,
    ]));
    return TRUE;
  }

  /**
   * Download a file to its local location.
   *
   * @param string $source
   *   Source file URI.
   * @param string $destination
   *   Destination file URI.
   *
   * @return bool
   *   TRUE if the file could be downloaded.
   */
  protected function downloadFile($source, $destination) {
    // Try to download the file.
    $success = FALSE;
    $directory = dirname($destination);
    if ($this->fileSystem->prepareDirectory($directory, $this->fileSystem::CREATE_DIRECTORY)) {
      try {
        $input = Utils::TryFopen($source, 'r');
        $output = Utils::TryFopen($destination, 'w');
        $success = stream_copy_to_stream($input, $output);
      }
      catch (\Exception $exception) {
        $this->logger()->error(dt('Unable to download file @source to @destination: @message.', [
          '@source' => $source,
          '@destination' => $destination,
          '@message' => $exception->getMessage(),
        ]));
        return FALSE;
      }
    }
    else {
      $this->logger()->error(dt('Unable to create directory for @destination.', [
        '@destination' => $destination,
      ]));
      return FALSE;
    }

    if (empty($success)) {
      $this->logger()->error(dt('Unable to download file @source to @destination.', [
        '@source' => $source,
        '@destination' => $destination,
      ]));
    }
    else {
      $this->logger()->info(dt('Successfully downloaded file @source to @destination.', [
        '@source' => $source,
        '@destination' => $destination,
      ]));
    }
    return $success;
  }

  /**
   * Reset the hight water mark.
   *
   * @command rw-migrate:reset-high-water
   *
   * @option group A comma-separated list of migration groups to list
   * @option tag Name of the migration tag to list
   * @option check-only Calculate the ID to use for the high water but don't
   * change it yet.
   * @option set-to-max Set the high water to the max ID of the imported
   * content.
   *
   * @default $options [
   *   'group' => '',
   *   'tag' => '',
   *   'check-only' => FALSE,
   *   'set-to-max' => FALSE,
   * ]
   *
   * @usage rw-migrate:reset-high-water
   *   Reset the stored high water for all migrations.
   * @usage rw-migrate:reset-high-water beer_term,beer_node
   *   Reset the stored high water for specific migrations.
   * @usage rw-migrate:reset-high-water --group=beer
   *   Reset the stored high water for all migrations in a given group.
   * @usage migrate:reset-high-water --tag=user
   *   Reset the stored high water for all migrations with a given tag.
   *
   * @validate-module-enabled reliefweb_migrate
   *
   * @aliases rw-mrhw
   */
  public function resetHighWater($migration_names = '', array $options = [
    'group' => '',
    'tag' => '',
    'check-only' => FALSE,
    'set-to-max' => FALSE,
  ]) {
    $migrations = $this->migrationsList($migration_names, $options);
    $check_only = !empty($options['check-only']);
    $set_to_max = !empty($options['set-to-max']);

    // Take it one group at a time, listing the migrations within each group.
    foreach ($migrations as $migration_list) {
      foreach ($migration_list as $migration_id => $migration) {
        $source_plugin = $migration->getSourcePlugin();

        if ($source_plugin instanceof SourceMigrationHighWaterInterface) {
          $id = $source_plugin->setHighWaterToLatestNonImported($check_only, $set_to_max);
          $this->logger->info(strtr('Set high water to @id for @migration.', [
            '@id' => $id,
            '@migration' => $migration_id,
          ]));
        }
      }
    }
  }

  /**
   * Check if the attachments are migrated properly.
   *
   * DEBUG.
   *
   * @command rw-mcf
   *
   * @validate-module-enabled reliefweb_migrate
   */
  public function checkFiles() {
    $d7_db = Database::getConnection('default', 'rwint7');
    $d9_db = Database::getConnection('default', 'default');

    $d7_query = $d7_db->select('field_data_field_file', 'f');
    $d7_query->innerJoin('file_managed', 'fm', '%alias.fid = f.field_file_fid');
    $d7_query->fields('fm', ['fid', 'uri', 'filename']);
    $d7_query->fields('f', ['entity_id', 'field_file_description']);
    $d7_query->distinct();

    $d7_files = [];
    foreach ($d7_query->execute() ?? [] as $record) {
      $uuid = LegacyHelper::generateAttachmentUuid($record->uri);
      $file_uuid = LegacyHelper::generateAttachmentFileUuid($uuid, $record->fid);

      $d7_files[$uuid] = [
        'field_file_uuid' => $uuid,
        'field_file_file_uuid' => $file_uuid,
      ];

      if (
        preg_match('/\|(\d+)\|(0|90|-90)$/', $record->field_file_description) === 1 &&
        strtolower(pathinfo($record->filename, PATHINFO_EXTENSION)) === 'pdf'
      ) {
        $d7_files[$uuid]['field_file_preview_uuid'] = LegacyHelper::generateAttachmentPreviewUuid($uuid, $file_uuid);
      }
    }

    $d9_files = $d9_db->select('node__field_file', 'f')
      ->fields('f', [
        'field_file_uuid',
        'field_file_file_uuid',
        'field_file_preview_uuid',
      ])
      ->distinct()
      ->execute()
      ->fetchAllAssoc('field_file_uuid', \PDO::FETCH_ASSOC);

    $d9_file_query = $d9_db->select('file_managed', 'fm');
    $d9_file_query->innerJoin('node__field_file', 'f', '%alias.field_file_file_uuid = fm.uuid');
    $d9_file_query->addExpression('COUNT(fm.fid)', 'total');
    $d9_file_count = $d9_file_query->execute()?->fetchField() ?? 'ERROR';

    $d9_preview_query = $d9_db->select('file_managed', 'fm');
    $d9_preview_query->innerJoin('node__field_file', 'f', '%alias.field_file_preview_uuid = fm.uuid');
    $d9_preview_query->condition('f.field_file_file_mime', 'application/pdf');
    $d9_preview_query->addExpression('COUNT(fm.fid)', 'total');
    $d9_preview_count = $d9_preview_query->execute()?->fetchField() ?? 'ERROR';

    print_r([
      'd7' => count($d7_files),
      'd9' => count($d9_files),
      'diff_d7_d9' => array_diff_key($d7_files, $d9_files),
      'diff_d9_d7' => array_diff_key($d9_files, $d7_files),
      'd9_file_count' => $d9_file_count,
      'd9_preview_count' => $d9_preview_count,
      'd7_file_preview_count' => count(array_filter($d7_files, function ($item) {
        return !empty($item['field_file_preview_uuid']);
      })),
      'd9_file_preview_count' => count(array_filter($d9_files, function ($item) {
        return !empty($item['field_file_preview_uuid']);
      })),
    ]);
  }

  /**
   * Check if the attachment files are migrated properly.
   *
   * DEBUG.
   *
   * @command rw-mcfe
   *
   * @option info If set, show the info of the missing files.
   * @option limit If set, check only the given number of files.
   *
   * @default options [
   *   'info' => FALSE,
   *   'limit' => 0,
   * ]
   *
   * @usage rw-mcfe
   *   Count the number of missing files.
   * @usage rw-mcfe --info --limit=10
   *   Check 10 files and show the details of the missing files.
   *
   * @validate-module-enabled reliefweb_migrate
   */
  public function checkFileExistence($options = [
    'info' => FALSE,
    'limit' => 0,
  ]) {
    $query = Database::getConnection('default', 'default')
      ->select('file_managed', 'fm')
      ->fields('fm', ['fid', 'uuid', 'uri'])
      ->orderBy('fm.fid', 'ASC');
    if (!empty($options['limit']) && (int) $options['limit'] > 1) {
      $query->range(0, (int) $options['limit']);
    }

    $files = $query->execute()?->fetchAllAssoc('fid', \PDO::FETCH_ASSOC) ?? [];
    $total = count($files);
    $count = 0;

    $missing = [];
    foreach (array_chunk($files, 1000, TRUE) as $chunk) {
      foreach ($chunk as $fid => $file) {
        $count++;
        if (!file_exists($file['uri'])) {
          $missing[$fid] = $file;
        }
      }
      $this->logger()->info(dt('Checked @count / @total files: @missing missing.', [
        '@count' => $count,
        '@total' => $total,
        '@missing' => count($missing),
      ]));
    }

    if (!empty($options['info'])) {
      print_r($missing);
    }
  }

  /**
   * Fix migrated files with wrong PDF mimetype.
   *
   * DEBUG.
   *
   * @command rw-mffm
   *
   * @usage rw-mffm
   *   Fix migrated files with wrong PDF mimetype.
   *
   * @validate-module-enabled reliefweb_migrate
   */
  public function fixFileMimetype() {
    $transaction = $this->database->startTransaction();
    try {
      $arguments = [
        ':mimetype' => 'application/pdf',
        ':extension' => 'pdf',
      ];
      $options = [
        // Note: this is deprecated in Drupal 9.4 and will be removed in Drupal
        // 11 and there is no replacement. We don't really care because the
        // reliefweb_migrate will be removed way before that.
        'return' => Database::RETURN_AFFECTED,
      ];

      $results['@deleted_files'] = $this->database->query("
        DELETE fm.* FROM {file_managed} AS fm
        INNER JOIN {node__field_file} AS nf
        ON nf.field_file_preview_uuid = fm.uuid
        WHERE nf.field_file_file_mime = :mimetype
        AND LOWER(RIGHT(nf.field_file_file_name,3)) <> :extension
      ", $arguments, $options) ?? 0;

      $results['@deleted_files'] += $this->database->query("
        DELETE fm.* FROM {file_managed} AS fm
        INNER JOIN {node_revision__field_file} AS nf
        ON nf.field_file_preview_uuid = fm.uuid
        WHERE nf.field_file_file_mime = :mimetype
        AND LOWER(RIGHT(nf.field_file_file_name,3)) <> :extension
      ", $arguments, $options) ?? 0;

      $results['@updated_fields'] = $this->database->query("
        UPDATE {node__field_file} SET
        field_file_file_mime = CASE LOWER(RIGHT(field_file_file_name,3))
          WHEN 'bmp' THEN 'image/bmp'
          WHEN 'gif' THEN 'image/gif'
          WHEN 'jpg' THEN 'image/jpeg'
          WHEN 'kml' THEN 'application/vnd.google-earth.kml+xml'
          WHEN 'png' THEN 'image/png'
          WHEN 'ppt' THEN 'application/vnd.ms-powerpoint'
        END,
        field_file_page_count = NULL,
        field_file_preview_uuid = NULL,
        field_file_preview_page = NULL,
        field_file_preview_rotation = NULL
        WHERE field_file_file_mime = :mimetype
        AND LOWER(RIGHT(field_file_file_name,3)) <> :extension
      ", $arguments, $options) ?? 0;

      $results['@updated_revision_fields'] = $this->database->query("
        UPDATE {node_revision__field_file} SET
        field_file_file_mime = CASE LOWER(RIGHT(field_file_file_name,3))
          WHEN 'bmp' THEN 'image/bmp'
          WHEN 'gif' THEN 'image/gif'
          WHEN 'jpg' THEN 'image/jpeg'
          WHEN 'kml' THEN 'application/vnd.google-earth.kml+xml'
          WHEN 'png' THEN 'image/png'
          WHEN 'ppt' THEN 'application/vnd.ms-powerpoint'
        END,
        field_file_page_count = NULL,
        field_file_preview_uuid = NULL,
        field_file_preview_page = NULL,
        field_file_preview_rotation = NULL
        WHERE field_file_file_mime = :mimetype
        AND LOWER(RIGHT(field_file_file_name,3)) <> :extension
      ", $arguments, $options) ?? 0;

      $this->logger()->info(dt('Deleted files: @deleted_files. Updated fields: @updated_fields. Updated revision fields: @updated_revision_fields.', $results));

      // Ignore replica server temporarily.
      // phpcs:ignore
      \Drupal::service('database.replica_kill_switch')->trigger();
    }
    catch (\Exception $exception) {
      $transaction->rollBack();
      $this->logger()->error($exception->getMessage());
    }
  }

  /**
   * Check migrated content.
   *
   * @command rw-migrate:check-content
   *
   * @option migrated-only Limit results from the D7 database to migrated ids.
   *
   * @default options [
   *   'migrated-only' => FALSE,
   * ]
   *
   * @usage rw-migrate:check-content
   *   Check migrated content.
   * @usage rw-migrate:check-content --migrated-only
   *   Check migrated content, limiting to the latest migrated IDs.
   *
   * @validate-module-enabled reliefweb_migrate
   *
   * @aliases rw-mcc
   *
   * @field-labels
   *   name: Name
   *   rw9: RW9
   *   rw7: RW7
   *   diff: Diff
   * @default-fields name,rw9,rw7,diff
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Formatted table.
   */
  public function checkMigratedContent($options = [
    'migrated-only' => FALSE,
  ]) {
    $d7_database = Database::getConnection('default', 'rwint7');

    $duplicate_reports = $this->getDuplicateReports();

    $on_hold_reports = $this->getOnHoldReports();

    $exclude_reports = array_unique(array_merge($duplicate_reports, $on_hold_reports));

    if (empty($options['migrated-only'])) {
      $node_max_id = $d7_database->query("
        SELECT MAX(vid) FROM node
      ")?->fetchField() ?? 0;
      $term_max_id = $d7_database->query("
        SELECT MAX(revision_id) FROM taxonomy_term_data
      ")?->fetchField() ?? 0;
      $media_max_id = $d7_database->query("
        SELECT MAX(fm.fid)
        FROM file_managed AS fm
        LEFT JOIN field_data_field_image AS fi
          ON fi.field_image_fid = fm.fid
          AND fi.bundle IN ('announcement', 'blog_post', 'report')
        LEFT JOIN field_data_field_attached_images AS fai
          ON fai.field_attached_images_fid = fm.fid
          AND fai.bundle = 'blog_post'
        LEFT JOIN field_data_field_headline_image AS fhi
          ON fhi.field_headline_image_fid = fm.fid
          AND fhi.bundle = 'report'
        LEFT JOIN field_data_field_term_image AS fti
          ON fti.field_term_image_fid = fm.fid
          AND fti.bundle IN ('source', 'topics')
        WHERE fi.entity_id IS NOT NULL
          OR fai.entity_id IS NOT NULL
          OR fhi.entity_id IS NOT NULL
          OR fti.entity_id IS NOT NULL
      ")?->fetchField() ?? 0;
      $file_max_id = $d7_database->query("
        SELECT MAX(fm.fid) FROM file_managed AS fm
        INNER JOIN field_data_field_file AS f
        ON f.field_file_fid = fm.fid
      ")?->fetchField() ?? 0;
    }
    else {
      $node_max_id = $this->database->query("
        SELECT MAX(vid) FROM node
      ")?->fetchField() ?? 0;
      $term_max_id = $this->database->query("
        SELECT MAX(revision_id) FROM taxonomy_term_data
      ")?->fetchField() ?? 0;
      $media_max_id = $this->database->query("
        SELECT MAX(mid) FROM media
      ")?->fetchField() ?? 0;
      $file_max_id = $this->database->query("
        SELECT MAX(fm.fid) FROM file_managed AS fm
        INNER JOIN node__field_file AS f
        ON f.field_file_file_uuid = fm.uuid
      ")?->fetchField() ?? 0;
    }

    $d9_data = $this->database->query("
        SELECT CONCAT('node - ', type) AS name, COUNT(*) AS total
        FROM node
        GROUP BY type
      UNION
        SELECT CONCAT('node - ', 'total') AS name, COUNT(*) AS total
        FROM node
      UNION
        SELECT CONCAT('term - ', vid) AS name, COUNT(*) AS total
        FROM taxonomy_term_data
        GROUP BY vid
      UNION
        SELECT CONCAT('term - ', 'total') AS name, COUNT(*) AS total
        FROM taxonomy_term_data
      UNION
        SELECT CONCAT('media - ', bundle) AS name, COUNT(*) AS total
        FROM media
        GROUP BY bundle
      UNION
        SELECT CONCAT('media - ', 'total') AS name, COUNT(*) AS total
        FROM media
      UNION
        SELECT CONCAT('image - ', m.bundle) AS name, COUNT(m.mid) AS total
        FROM media AS m
        INNER JOIN media__field_media_image AS fmi
          ON fmi.entity_id = m.mid
        INNER JOIN file_managed AS fm
          ON fm.fid = fmi.field_media_image_target_id
        GROUP BY m.bundle
      UNION
        SELECT CONCAT('image - ', 'total') AS name, COUNT(m.mid) AS total
        FROM media AS m
        INNER JOIN media__field_media_image AS fmi
          ON fmi.entity_id = m.mid
        INNER JOIN file_managed AS fm
          ON fm.fid = fmi.field_media_image_target_id
      UNION
        SELECT 'attachment' AS name, COUNT(field_file_file_uuid)
        FROM node__field_file
      UNION
        SELECT 'preview' AS name, COUNT(field_file_preview_uuid)
        FROM node__field_file
        WHERE field_file_preview_uuid IS NOT NULL
      UNION
        SELECT 'attachment file' AS name, COUNT(fm.fid)
        FROM file_managed AS fm
        INNER JOIN node__field_file AS nf ON nf.field_file_file_uuid = fm.uuid
      UNION
        SELECT 'preview file' AS name, COUNT(fm.fid)
        FROM file_managed AS fm
        INNER JOIN node__field_file AS nf
          ON nf.field_file_preview_uuid = fm.uuid
    ")?->fetchAllKeyed(0, 1) ?? [];

    $d7_data = $d7_database->query("
        SELECT CONCAT('node - ',
          CASE type
            WHEN 'topics' THEN 'topic'
            ELSE type
          END) AS name, COUNT(*) AS total
        FROM node
        WHERE type NOT IN ('faq')
          AND nid NOT IN (:exclude_reports[])
          AND nid <= :node_max_id
        GROUP BY type
      UNION
        SELECT CONCAT('node - ', 'total') AS name, COUNT(*) AS total
        FROM node
        WHERE type NOT IN ('faq')
          AND nid NOT IN (:exclude_reports[])
          AND nid <= :node_max_id
      UNION
        SELECT CONCAT('term - ',
          CASE tv.machine_name
            WHEN 'career_categories' THEN 'career_category'
            WHEN 'tags' THEN 'tag'
            WHEN 'vulnerable_groups' THEN 'vulnerable_group'
            ELSE tv.machine_name
          END) AS name, COUNT(td.tid) AS total
        FROM taxonomy_term_data AS td
        INNER JOIN taxonomy_vocabulary AS tv
          ON tv.vid = td.vid
        WHERE tv.machine_name NOT IN ('city', 'faq_category', 'region')
          AND td.tid <= :term_max_id
        GROUP BY tv.vid
      UNION
        SELECT CONCAT('term - ', 'total') AS name, COUNT(td.tid) AS total
        FROM taxonomy_term_data AS td
        INNER JOIN taxonomy_vocabulary AS tv
          ON tv.vid = td.vid
        WHERE tv.machine_name NOT IN ('city', 'faq_category', 'region')
          AND td.tid <= :term_max_id
      UNION
        SELECT CONCAT('media - ', 'image_announcement') AS name, COUNT(DISTINCT fm.fid)
        FROM file_managed AS fm
        INNER JOIN field_data_field_image AS fi
          ON fi.field_image_fid = fm.fid
          AND fi.bundle = 'announcement'
        WHERE fm.fid <= :media_max_id
      UNION
        SELECT CONCAT('media - ', 'image_blog_post') AS name, COUNT(DISTINCT fm.fid)
        FROM file_managed AS fm
        LEFT JOIN field_data_field_image AS fi
          ON fi.field_image_fid = fm.fid
          AND fi.bundle = 'blog_post'
        LEFT JOIN field_data_field_attached_images AS fai
          ON fai.field_attached_images_fid = fm.fid
          AND fai.bundle = 'blog_post'
        WHERE (fi.entity_id IS NOT NULL OR fai.entity_id IS NOT NULL)
          AND fm.fid <= :media_max_id
      UNION
        SELECT CONCAT('media - ', 'image_report') AS name, COUNT(DISTINCT fm.fid)
        FROM file_managed AS fm
        LEFT JOIN field_data_field_image AS fi
          ON fi.field_image_fid = fm.fid
          AND fi.bundle = 'report'
        LEFT JOIN field_data_field_headline_image AS fhi
          ON fhi.field_headline_image_fid = fm.fid
          AND fhi.bundle = 'report'
        WHERE (fi.entity_id IS NOT NULL OR fhi.entity_id IS NOT NULL)
          AND fm.fid <= :media_max_id
      UNION
        SELECT CONCAT('media - ', 'image_source') AS name, COUNT(DISTINCT fm.fid)
        FROM file_managed AS fm
        INNER JOIN field_data_field_term_image AS fti
          ON fti.field_term_image_fid = fm.fid
          AND fti.bundle = 'source'
        WHERE fm.fid <= :media_max_id
      UNION
        SELECT CONCAT('media - ', 'image_topic') AS name, COUNT(DISTINCT fm.fid)
        FROM file_managed AS fm
        INNER JOIN field_data_field_term_image AS fi
          ON fi.field_term_image_fid = fm.fid
          AND fi.bundle = 'topics'
        WHERE fm.fid <= :media_max_id
      UNION
        SELECT CONCAT('media - ', 'total') AS name, COUNT(DISTINCT fm.fid)
        FROM file_managed AS fm
        LEFT JOIN field_data_field_image AS fi
          ON fi.field_image_fid = fm.fid
          AND fi.bundle IN ('announcement', 'blog_post', 'report')
        LEFT JOIN field_data_field_attached_images AS fai
          ON fai.field_attached_images_fid = fm.fid
          AND fai.bundle = 'blog_post'
        LEFT JOIN field_data_field_headline_image AS fhi
          ON fhi.field_headline_image_fid = fm.fid
          AND fhi.bundle = 'report'
        LEFT JOIN field_data_field_term_image AS fti
          ON fti.field_term_image_fid = fm.fid
          AND fti.bundle IN ('source', 'topics')
        WHERE (fi.entity_id IS NOT NULL
          OR fai.entity_id IS NOT NULL
          OR fhi.entity_id IS NOT NULL
          OR fti.entity_id IS NOT NULL)
          AND fm.fid <= :media_max_id
      UNION
        SELECT 'attachment' AS name, COUNT(DISTINCT f.field_file_fid)
        FROM field_data_field_file AS f
        INNER JOIN file_managed AS fm
          ON fm.fid = f.field_file_fid
        WHERE f.bundle = 'report'
          AND fm.fid <= :file_max_id
          AND f.entity_id NOT IN (:exclude_reports[])
      UNION
        SELECT 'preview' AS name, COUNT(DISTINCT f.field_file_fid)
        FROM field_data_field_file AS f
        INNER JOIN file_managed AS fm
          ON fm.fid = f.field_file_fid
        WHERE f.bundle = 'report'
          AND f.field_file_description REGEXP :preview_description
          AND (fm.filemime = 'application/pdf' OR LOWER(RIGHT(fm.uri,3)) = 'pdf')
          AND fm.fid <= :file_max_id
          AND f.entity_id NOT IN (:exclude_reports[])
    ", [
      ':exclude_reports[]' => $exclude_reports,
      ':preview_description' => '[|][1-9][0-9]*[|](0|90|-90)$',
      ':node_max_id' => $node_max_id,
      ':term_max_id' => $term_max_id,
      ':media_max_id' => $media_max_id,
      ':file_max_id' => $file_max_id,
    ])?->fetchAllKeyed(0, 1) ?? [];

    $table = [];
    foreach ($d9_data as $key => $value) {
      if ($key === 'attachment file') {
        $d7_value = $d7_data['attachment'];
      }
      elseif ($key === 'preview file') {
        $d7_value = $d7_data['preview'];
      }
      elseif (strpos($key, 'image - ') === 0) {
        $d7_value = $d7_data[str_replace('image - ', 'media - ', $key)];
      }
      else {
        $d7_value = $d7_data[$key];
      }

      $table[] = [
        'name' => $key,
        'rw9' => $value,
        'rw7' => $d7_value,
        'diff' => $value - $d7_value,
      ];
    }

    return new RowsOfFields($table);
  }

  /**
   * Check migrated content status.
   *
   * @command rw-migrate:check-content-status
   *
   * @option fix Fix the changed and status inconsistencies.
   * @option batch_size Number of items to retrieve from the database at once.
   *
   * @default options [
   *   'fix' => FALSE,
   *   'batch_size' => 10000,
   * ]
   *
   * @usage rw-migrate:check-content-status
   *   Check migrated content.
   *
   * @validate-module-enabled reliefweb_migrate
   *
   * @aliases rw-mccs
   *
   * @field-labels
   *   type: Type
   *   bundle: Bundle
   *   id: Id
   *   rw9_revision_id: RW9 Revision ID
   *   rw7_revision_id: RW7 Revision ID
   *   rw9_created: RW9 Created
   *   rw7_created: RW7 Created
   *   rw9_changed: RW9 Changed
   *   rw7_changed: RW7 Changed
   *   rw9_status: RW9 Status
   *   rw7_status: RW7 Status
   * @default-fields type,bundle,id,rw9_revision_id,rw7_revision_id,rw9_created,rw7_created,rw9_changed,rw7_changed,rw9_status,rw7_status
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Formatted table.
   */
  public function checkMigratedContentStatus(array $options = [
    'fix' => FALSE,
    'batch_size' => 1000,
  ]) {
    $d7_database = Database::getConnection('default', 'rwint7');

    $table = [];
    $batch_size = $options['batch_size'] ?? 1000;

    // Terms.
    $last_id = 0;
    while (TRUE) {
      $d9_data = $this->database->queryRange("
        SELECT
          td.vid AS bundle,
          td.tid AS id,
          td.revision_id AS revision_id,
          td.changed AS changed,
          td.moderation_status AS status
        FROM taxonomy_term_field_data AS td
        WHERE td.vid IN ('country', 'disaster', 'source')
          AND td.tid > :last_id
        ORDER BY td.tid ASC
      ", 0, $batch_size, [
        ':last_id' => $last_id,
      ])?->fetchAllAssoc('id', \PDO::FETCH_ASSOC) ?? [];

      if (empty($d9_data)) {
        break;
      }
      else {
        $last_id = array_key_last($d9_data);
      }

      $d7_data = $d7_database->query("
        SELECT
          td.tid AS id,
          td.revision_id AS revision_id,
          tr.timestamp  AS changed,
          fs.field_status_value AS status
        FROM taxonomy_term_data AS td
        INNER JOIN taxonomy_vocabulary AS tv
          ON tv.vid = td.vid
        INNER JOIN taxonomy_term_data_revision AS tr
          ON tr.tid = td.tid AND tr.revision_id = td.revision_id
        LEFT JOIN field_data_field_status AS fs
          ON fs.entity_id = td.tid
          AND fs.entity_type = 'taxonomy_term'
        WHERE tv.machine_name IN ('country', 'disaster', 'source')
          AND td.tid IN (:tids[])
      ", [
        ':tids[]' => array_keys($d9_data),
      ])?->fetchAllAssoc('id', \PDO::FETCH_ASSOC) ?? [];

      foreach ($d9_data as $id => $d9_record) {
        $id = $d9_record['id'];
        $d9_revision_id = $d9_record['revision_id'] ?? '-';
        $d9_changed = $d9_record['changed'] ?? '-';
        $d9_status = $d9_record['status'] ?? '-';

        $d7_record = $d7_data[$id] ?? [];
        $d7_revision_id = $d7_record['revision_id'] ?? '-';
        $d7_changed = $d7_record['changed'] ?? '-';
        $d7_status = $d7_record['status'] ?? '-';
        if ($d7_status == 'current') {
          $d7_status = 'ongoing';
        }

        if ($d9_revision_id == $d7_revision_id && $d9_changed == $d7_changed && $d9_status == $d7_status) {
          continue;
        }

        $table[] = [
          'type' => 'term',
          'bundle' => $d9_record['bundle'],
          'id' => $id,
          'rw9_revision_id' => $d9_revision_id,
          'rw7_revision_id' => $d7_revision_id,
          'rw9_created' => '-',
          'rw7_created' => '-',
          'rw9_changed' => $d9_changed,
          'rw7_changed' => $d7_changed,
          'rw9_status' => $d9_status,
          'rw7_status' => $d7_status,
        ];
      }
    }

    // Nodes.
    $last_id = 0;
    while (TRUE) {
      $d9_data = $this->database->queryRange("
        SELECT
          n.type AS bundle,
          n.nid AS id,
          n.vid AS revision_id,
          n.created AS created,
          n.changed AS changed,
          n.moderation_status AS status
        FROM node_field_data AS n
        WHERE n.nid > :last_id
        ORDER BY n.nid ASC
      ", 0, $batch_size, [
        ':last_id' => $last_id,
      ])?->fetchAllAssoc('id', \PDO::FETCH_ASSOC) ?? [];

      if (empty($d9_data)) {
        break;
      }
      else {
        $last_id = array_key_last($d9_data);
      }

      $d7_data = $d7_database->query("
        SELECT
          n.nid AS id,
          n.vid AS revision_id,
          n.created AS created,
          n.changed  AS changed,
          fs.field_status_value AS status
        FROM node AS n
        LEFT JOIN field_data_field_status AS fs
          ON fs.entity_id = n.nid
          AND fs.entity_type = 'node'
        WHERE n.type NOT IN ('faq')
          AND n.nid IN (:nids[])
      ", [
        ':nids[]' => array_keys($d9_data),
      ])?->fetchAllAssoc('id', \PDO::FETCH_ASSOC) ?? [];

      foreach ($d9_data as $d9_record) {
        $id = $d9_record['id'];
        $d9_revision_id = $d9_record['revision_id'] ?? '-';
        $d9_created = $d9_record['created'] ?? '-';
        $d9_changed = $d9_record['changed'] ?? '-';
        $d9_status = $d9_record['status'] ?? '-';

        $d7_record = $d7_data[$id] ?? [];
        $d7_revision_id = $d7_record['revision_id'] ?? '-';
        $d7_created = $d7_record['created'] ?? '-';
        $d7_changed = $d7_record['changed'] ?? '-';
        $d7_status = $d7_record['status'] ?? '-';

        if ($d9_revision_id == $d7_revision_id && $d9_created == $d7_created && $d9_changed == $d7_changed && $d9_status == $d7_status) {
          continue;
        }

        $table[] = [
          'type' => 'node',
          'bundle' => $d9_record['bundle'],
          'id' => $id,
          'rw9_revision_id' => $d9_revision_id,
          'rw7_revision_id' => $d7_revision_id,
          'rw9_created' => $d9_created,
          'rw7_created' => $d7_created,
          'rw9_changed' => $d9_changed,
          'rw7_changed' => $d7_changed,
          'rw9_status' => $d9_status,
          'rw7_status' => $d7_status,
        ];
      }
    }

    $this->logger()->info(dt('Found @count entries', [
      '@count' => count($table),
    ]));

    if (!empty($table) && !empty($options['fix'])) {
      $this->logger()->info(dt('Fixing @count entries', [
        '@count' => count($table),
      ]));

      foreach ($table as $item) {
        // Skip if the revision are not the same. That should be picked up by
        // the migration process.
        if ($item['rw9_revision_id'] != $item['rw7_revision_id']) {
          continue;
        }

        switch ($item['type']) {
          case 'term':
            $this->database
              ->update('taxonomy_term_field_data')
              ->fields([
                'changed' => $item['rw7_changed'],
                'moderation_status' => $item['rw7_status'],
              ])
              ->condition('tid', $item['id'], '=')
              ->execute();

            $this->database
              ->update('taxonomy_term_field_revision')
              ->fields([
                'changed' => $item['rw7_changed'],
                'moderation_status' => $item['rw7_status'],
              ])
              ->condition('tid', $item['id'], '=')
              ->condition('revision_id', $item['rw9_revision_id'], '=')
              ->execute();
            break;

          case 'node':
            $this->database
              ->update('node_field_data')
              ->fields([
                'created' => $item['rw7_created'],
                'changed' => $item['rw7_changed'],
                'moderation_status' => $item['rw7_status'],
              ])
              ->condition('nid', $item['id'], '=')
              ->execute();

            $this->database
              ->update('node_field_revision')
              ->fields([
                'created' => $item['rw7_created'],
                'changed' => $item['rw7_changed'],
                'moderation_status' => $item['rw7_status'],
              ])
              ->condition('nid', $item['id'], '=')
              ->condition('vid', $item['rw9_revision_id'], '=')
              ->execute();
            break;
        }
      }
    }

    return new RowsOfFields($table);
  }

  /**
   * Fix attachment deltas.
   *
   * @command rw-migrate:fix-attachment-deltas
   *
   * @option dry-run Just check the number of files to handle.
   * @option batch_size Number of items to retrieve from the database at once.
   *
   * @default options [
   *   'dry-run' => FALSE,
   *   'batch_size' => 10000,
   * ]
   *
   * @usage rw-migrate:fix-attachment-deltas
   *   Fix attachment deltas.
   *
   * @validate-module-enabled reliefweb_migrate
   */
  public function fixAttachmentDeltas(array $options = [
    'dry-run' => FALSE,
    'batch_size' => 1000,
  ]) {
    $d7_database = Database::getConnection('default', 'rwint7');

    $ids = $this->database
      ->select('node__field_file', 'f')
      ->fields('f', ['revision_id'])
      ->condition('f.delta', '0', '>')
      ->distinct()
      ->execute()
      ?->fetchCol() ?? [];

    if (empty($ids)) {
      $this->logger()->info(dt('Nothing to update'));
      return TRUE;
    }

    $results = $this->database
      ->select('node__field_file', 'f')
      ->fields('f')
      ->condition('f.revision_id', $ids, 'IN')
      ->orderBy('f.revision_id', 'ASC')
      ->execute()
      ?->fetchAll(\PDO::FETCH_ASSOC) ?? [];

    $records = [];
    foreach ($results as $item) {
      $records[$item['revision_id']][$item['field_file_file_uuid']] = $item;
    }

    $d7_data = $d7_database->query("
      SELECT
        f.delta AS delta,
        f.revision_id AS revision_id,
        fm.fid AS fid,
        fm.uri AS uri
      FROM field_data_field_file AS f
      INNER JOIN file_managed AS fm
        ON fm.fid = f.field_file_fid
      WHERE f.revision_id IN (:ids[])
      ORDER BY f.revision_id ASC, f.delta ASC
    ", [
      ':ids[]' => $ids,
    ])?->fetchAll(\PDO::FETCH_ASSOC) ?? [];

    $delta = 0;
    $deltas = [];
    foreach ($d7_data as $item) {
      $revision_id = $item['revision_id'];
      $uuid = LegacyHelper::generateAttachmentUuid($item['uri']);
      $file_uuid = LegacyHelper::generateAttachmentFileUuid($uuid, $item['fid']);

      // Initialize the delta for the entity.
      if (!isset($deltas[$revision_id])) {
        $delta = 0;
      }

      // Add the delta for the file if not already set. This makes sure that the
      // few entries referencing twice the same file, have the correct delta.
      if (!isset($deltas[$revision_id][$file_uuid])) {
        $deltas[$revision_id][$file_uuid] = $delta;
        $delta++;
      }
    }

    $count_records = 0;
    $records_to_update = [];
    foreach ($records as $revision_id => $items) {
      if (!isset($deltas[$revision_id])) {
        continue;
      }
      $revision_deltas = $deltas[$revision_id];
      $max_delta = count($items) - 1;
      $process = FALSE;

      // Check if the file record for the entity with the revision id, should
      // be re-ordered.
      foreach ($items as $uuid => $record) {
        if (isset($revision_deltas[$uuid]) && ($record['delta'] != $revision_deltas[$uuid] || $record['delta'] > $max_delta)) {
          $process = TRUE;
          break;
        }
      }

      if ($process) {
        // Update the record deltas.
        foreach ($deltas[$revision_id] as $uuid => $delta) {
          if (isset($items[$uuid])) {
            $items[$uuid]['delta'] = $delta;
          }
        }
        // Sort the records by delta.
        usort($items, function ($a, $b) {
          return $a['delta'] <=> $b['delta'];
        });
        // Ensure there is no gap by resetting the deltas.
        $delta = 0;
        foreach ($items as $key => $item) {
          $items[$key]['delta'] = $delta;
          $delta++;
        }
        $records_to_update[$revision_id] = $items;
        $count_records += count($items);
      }
    }

    if (empty($records_to_update)) {
      $this->logger()->info(dt('No records to update'));
      return TRUE;
    }
    else {
      $this->logger()->info(dt('@count records to update for @count_entities entities', [
        '@count' => $count_records,
        '@count_entities' => count($records_to_update),
      ]));
    }

    if (!empty($options['dry-run'])) {
      return TRUE;
    }

    $options = [
      // Note: this is deprecated in Drupal 9.4 and will be removed in Drupal
      // 11 and there is no replacement. We don't really care because the
      // reliefweb_migrate will be removed way before that.
      'return' => Database::RETURN_AFFECTED,
    ];

    $transaction = $this->database->startTransaction();
    try {
      $tables = ['node__field_file', 'node_revision__field_file'];
      foreach ($tables as $table) {
        $deleted = $this->database
          ->delete($table, $options)
          ->condition('revision_id', array_keys($records_to_update), 'IN')
          ->execute();

        $query = $this->database
          ->insert($table, $options);
        $fields_set = FALSE;
        foreach ($records_to_update as $records) {
          foreach ($records as $record) {
            if (!$fields_set) {
              $query->fields(array_keys($record));
              $fields_set = TRUE;
            }
            $query->values($record);
          }
        }
        $inserted = $query->execute();

        $this->logger()->info(dt('@table: @deleted deleted, @inserted inserted', [
          '@table' => $table,
          '@deleted' => $deleted,
          '@inserted' => $inserted,
        ]));
      }

      return TRUE;
    }
    catch (\Exception $exception) {
      $transaction->rollback();
      $this->logger()->error(dt('Error while trying to update the database: @error', [
        '@error' => $exception->getMessage(),
      ]));
      return FALSE;
    }
  }

  /**
   * Get the IDs of duplicate reports.
   *
   * @return array
   *   Duplicate report IDs.
   */
  protected function getDuplicateReports() {
    $d7_database = Database::getConnection('default', 'rwint7');

    $duplicate_query = $d7_database->select('field_data_field_file', 'f');
    $duplicate_query->addField('f', 'field_file_fid', 'fid');
    $duplicate_query->addExpression('COUNT(f.field_file_fid)', 'total');
    $duplicate_query->addExpression('GROUP_CONCAT(f.entity_id ORDER BY f.entity_id)', 'ids');
    $duplicate_query->groupBy('f.field_file_fid');
    $duplicate_query->having('total > 1');

    $duplicate_reports = [];
    foreach ($duplicate_query->execute() ?? [] as $record) {
      $ids = explode(',', $record->ids);
      if (min($ids) !== max($ids)) {
        $duplicate_reports = array_merge($duplicate_reports, array_slice($ids, 0, -1));
      }
    }

    return $duplicate_reports;
  }

  /**
   * Get the IDs of on-hold reports.
   *
   * @return array
   *   On-hold report IDs.
   */
  protected function getOnHoldReports() {
    return Database::getConnection('default', 'rwint7')
      ->select('field_data_field_status', 'fs')
      ->fields('fs', ['entity_id'])
      ->condition('fs.bundle', 'report', '=')
      ->condition('fs.field_status_value', 'on-hold', '=')
      ->distinct()
      ->execute()
      ?->fetchCol() ?? [];
  }

  /**
   * Migrated import job Ids.
   *
   * @command rw-migrate:migrate-imported-job-ids
   *
   * @option dry-run Just check the number of records to insert.
   *
   * @default options [
   *   'dry-run' => FALSE,
   * ]
   *
   * @usage rw-migrate:migrate-imported-job-ids
   *   Migrated import job Ids.
   *
   * @validate-module-enabled reliefweb_migrate
   */
  public function migrateImportJobIds() {
    $nids = Database::getConnection('default', 'rwint7')
      ->select('feeds_item', 'fi')
      ->fields('fi', ['entity_id', 'guid'])
      ->condition('fi.id', 'jobs_importer', '=')
      ->execute()
      ?->fetchAllKeyed(0, 1);

    $total = count($nids);

    $this->logger()->info(dt('@total jobs to update', [
      '@total' => $total,
    ]));

    $entity_count = 0;
    $revision_count = 0;
    $row_affected_count = 0;

    $fields = [
      'bundle',
      'deleted',
      'entity_id',
      'revision_id',
      'langcode',
      'delta',
      'field_import_guid_value',
    ];

    $query_options = [
      // Note: this is deprecated in Drupal 9.4 and will be removed in Drupal
      // 11 and there is no replacement. We don't really care because the
      // reliefweb_migrate will be removed way before that.
      'return' => Database::RETURN_AFFECTED,
    ];

    foreach (array_chunk($nids, 1000, TRUE) as $chunk) {
      $ids = [];
      $ids['node__field_import_guid'] = $this->database
        ->select('node', 'n')
        ->fields('n', ['vid', 'nid'])
        ->condition('n.nid', array_keys($chunk), 'IN')
        ->execute()
        ?->fetchAllKeyed(0, 1) ?? [];
      if (empty($ids['node__field_import_guid'])) {
        continue;
      }
      $entity_count += count($ids['node__field_import_guid']);

      $ids['node_revision__field_import_guid'] = $this->database
        ->select('node_revision', 'nr')
        ->fields('nr', ['vid', 'nid'])
        ->condition('nr.nid', $ids['node__field_import_guid'], 'IN')
        ->execute()
        ?->fetchAllKeyed(0, 1) ?? [];
      $revision_count += count($ids['node_revision__field_import_guid']);

      try {
        $transaction = $this->database->startTransaction();

        foreach ($ids as $field => $items) {
          $query = $this->database
            ->insert($field, $query_options)
            ->fields($fields);
          foreach ($items as $revision_id => $entity_id) {
            $query->values([
              'bundle' => 'job',
              'deleted' => 0,
              'entity_id' => $entity_id,
              'revision_id' => $revision_id,
              'langcode' => 'en',
              'delta' => 0,
              'field_import_guid_value' => $chunk[$entity_id],
            ]);
          }
          $row_affected_count += $query->execute();
        }
      }
      catch (\Exception $exception) {
        $transaction->rollback();
        $this->logger()->error(dt('Error while trying to update the database: @error', [
          '@error' => $exception->getMessage(),
        ]));
        return FALSE;
      }

      $this->memoryCache->deleteAll();
      Cache::invalidateTags(array_map(function ($id) {
        return 'node:' . $id;
      }, $ids['node__field_import_guid']));

      $this->logger()->info(dt('Processed @count / @total jobs', [
        '@count' => $entity_count,
        '@total' => $total,
      ]));
    }

    $this->logger()->info(dt('Updated @entity_count jobs (@revision_count revisions, @row_affected_count rows inserted)', [
      '@entity_count' => $entity_count,
      '@revision_count' => $revision_count,
      '@row_affected_count' => $row_affected_count,
    ]));
  }

  /**
   * Migrated report content format revisions.
   *
   * @command rw-migrate:migrate-content-format-revisions
   *
   * @usage rw-migrate:migrate-content-format-revisions
   *   Migrated content format revisions
   *
   * @validate-module-enabled reliefweb_migrate
   */
  public function migrateContentFormatRevisions(array $options = [
    'dry-run' => FALSE,
  ]) {
    $last_id = 0;
    $count = 0;
    $fields = [
      'bundle',
      'deleted',
      'entity_id',
      'revision_id',
      'langcode',
      'delta',
      'field_content_format_target_id',
    ];
    $query_options = [
      // Note: this is deprecated in Drupal 9.4 and will be removed in Drupal
      // 11 and there is no replacement. We don't really care because the
      // reliefweb_migrate will be removed way before that.
      'return' => Database::RETURN_AFFECTED,
    ];
    $dry_run = !empty($options['dry-run']);

    while (TRUE) {
      $rw7_records = Database::getConnection('default', 'rwint7')
        ->select('field_revision_field_content_format', 'f')
        ->fields('f')
        ->condition('f.bundle', 'report', '=')
        ->condition('f.revision_id', $last_id, '>')
        ->orderBy('f.revision_id', 'ASC')
        ->range(0, 1000)
        ->execute()
        ?->fetchAll() ?? [];

      if (empty($rw7_records)) {
        break;
      }

      // Extract the revision IDs.
      $revision_ids = [];
      foreach ($rw7_records as $record) {
        $revision_ids[$record->revision_id] = $record->revision_id;
      }
      $last_id = max($revision_ids);

      // Get the existing revision IDs.
      $existing_revision_ids = [];
      $query = $this->database->select('node_revision', 'nr');
      $query->fields('nr', ['vid']);
      $query->leftJoin('node_revision__field_content_format', 'f', 'f.revision_id = nr.vid');
      $query->fields('f', ['field_content_format_target_id']);
      $query->condition('nr.vid', $revision_ids, 'IN');
      foreach ($query->execute() ?? [] as $record) {
        if (empty($existing_revision_ids[$record->vid])) {
          $existing_revision_ids[$record->vid] = !empty($record->field_content_format_target_id);
        }
      }

      // Generate the list of records to insert.
      $nids = [];
      $rw9_records = [];
      foreach ($rw7_records as $record) {
        if (isset($existing_revision_ids[$record->revision_id]) && $existing_revision_ids[$record->revision_id] === FALSE) {
          $rw9_records[] = [
            'bundle' => $record->bundle,
            'deleted' => $record->deleted,
            'entity_id' => $record->entity_id,
            'revision_id' => $record->revision_id,
            'langcode' => 'en',
            'delta' => $record->delta,
            'field_content_format_target_id' => $record->field_content_format_tid,
          ];
          $nids[$record->entity_id] = $record->entity_id;
        }
      }

      if (!empty($rw9_records)) {
        try {
          $transaction = $this->database->startTransaction();

          $query = $this->database
            ->insert('node_revision__field_content_format', $query_options)
            ->fields($fields);
          foreach ($rw9_records as $record) {
            $query->values($record);
          }
          $query->execute();

          if ($dry_run) {
            $transaction->rollback();
          }
        }
        catch (\Exception $exception) {
          $transaction->rollback();
          $this->logger()->error(dt('Error while trying to update the database: @error', [
            '@error' => $exception->getMessage(),
          ]));
          return FALSE;
        }

        $count += count($rw9_records);

        $this->logger()->info(dt('Inserted @count records', [
          '@count' => $count,
        ]));
      }
    }

    if ($count === 0) {
      $this->logger()->info(dt('Nothing to insert'));
    }
  }

  /**
   * Migrate the legacy redirections.
   *
   * @command rw-migrate:migrate-legacy-redirections
   *
   * @usage rw-migrate:migrate-legacy-redirections
   *   Migrate legacy redirections.
   *
   * @validate-module-enabled reliefweb_migrate
   */
  public function migrateLegacyRedirections() {
    $storage = AccumulatedRedirectStorage::createInstance(
      // phpcs:ignore
      \Drupal::getContainer(),
      $this->entityTypeManager->getDefinition('redirect')
    );
    $database = Database::getConnection('default', 'rwint7');

    $bundles = [
      'country',
      'disaster',
      'job',
      'report',
      'source',
      'training',
    ];

    // List of legacy paths to exclude because they already exist as a path
    // alias or redirect, in order to avoid redirection loops.
    // This only concern country and disaster legacy URLs because they get
    // rewritten by nginx to the path alias patterns.
    $exclude = [];

    // Get the path aliases for disasters and countries.
    $alias_records = $this->database
      ->select('path_alias', 'pa')
      ->fields('pa', ['alias'])
      ->condition('alias', '^/(disaster|country)/', 'REGEXP')
      ->execute() ?? [];
    foreach ($alias_records as $record) {
      $exclude[mb_strtolower($record->alias)] = TRUE;
    }

    // Get the redirects for disasters and countries.
    $redirect_records = $this->database
      ->select('redirect', 'r')
      ->fields('r', ['redirect_source__path'])
      ->condition('r.redirect_source__path', '^(disaster|country)/', 'REGEXP')
      ->execute() ?? [];
    foreach ($redirect_records as $record) {
      $exclude['/' . mb_strtolower($record->redirect_source__path)] = TRUE;
    }

    // Get the list of already migrated redirects. They can be identified by
    // their creation date (see below).
    $migrated = $this->database
      ->select('redirect', 'r')
      ->fields('r', ['uuid', 'uid'])
      ->condition('r.created', 844128000, '=')
      ->execute()
      ?->fetchAllKeyed(0, 1) ?? [];

    $this->logger()->info(dt('@already_migrated legacy URLs already migrated', [
      '@already_migrated' => count($migrated),
    ]));

    // Retrieve the legacy redirects.
    $records = $database
      ->select('feeds_item', 'fi')
      ->fields('fi', ['entity_type', 'entity_id', 'url', 'id'])
      ->condition('fi.id', $bundles, 'IN')
      ->condition('fi.url', '', '<>')
      ->execute() ?? [];

    // Transform and store the legacy redirects to migrate.
    $legacy_paths = [];
    foreach ($records as $record) {
      if (empty($record->url) || empty($record->entity_type) || empty($record->entity_id)) {
        continue;
      }

      $url = 'https://reliefweb.int/rw/' . str_replace('http://www.reliefweb.int/rw/', '', $record->url);
      $uuid = Uuid::v3(Uuid::fromString(Uuid::NAMESPACE_URL), $url)->toRfc4122();
      $url_parts = UrlHelper::parse($url);
      $path = parse_url($url_parts['path'], \PHP_URL_PATH);
      $query = $url_parts['query'] ?? [];
      $target = 'entity:' . $record->entity_type . '/' . $record->entity_id;

      switch ($record->id) {
        case 'country':
          if (empty($query['cc'])) {
            continue 2;
          }
          $path = '/country/' . $query['cc'];
          break;

        case 'disaster':
          if (empty($query['emid'])) {
            continue 2;
          }
          $path = '/disaster/' . $query['emid'];
          break;

        default:
          $path = str_replace('?OpenDocument', '', $path);
      }

      $path = mb_strtolower($path);
      if (!isset($exclude[$path]) && !isset($migrated[$uuid])) {
        $legacy_paths[$path] = [
          'uuid' => $uuid,
          'target' => $target,
        ];
      }
    }

    $total = count($legacy_paths);
    if ($total === 0) {
      $this->logger()->info(dt('Nothing new to migrate'));
    }
    else {
      $this->logger()->info(dt('@total legacy URLs to migrate', [
        '@total' => $total,
      ]));

      // Create the redirects for the legacy URLs.
      $count = 0;
      foreach (array_chunk($legacy_paths, 1000, TRUE) as $chunk) {
        foreach ($chunk as $path => $info) {
          $entity = $storage->create([
            'uuid' => $info['uuid'],
            'uid' => 2,
            'status_code' => 301,
            // We use the ReliefWeb creation date so that we can identify
            // migrated content: 1996-10-01T00:00:00+00:00.
            'created' => 844128000,
          ]);
          $entity->setSource($path);
          // Not using `setRedirect` so it doesn't prefix the target URI with
          // `internal:/`...
          $entity->redirect_redirect->set(0, ['uri' => $info['target']]);

          $storage->saveAccumulated($entity);
          $count++;
        }

        $storage->flushAccumulated();

        $this->logger()->info(dt('Migrated @count/@total legacy URLs', [
          '@count' => $count,
          '@total' => $total,
        ]));
      }
    }
  }

  /**
   * Migrated report file revisions.
   *
   * During the initial migration, only the current revision record was migrated
   * for files to ensure we only had the latest data. The side effect is that
   * it looks like the files were attached by the last person who created a
   * revision. This migrates the other file field revisions for existing files
   * so that the history shows properly when they were attached.
   *
   * @command rw-migrate:migrate-file-revisions
   *
   * @usage rw-migrate:migrate-file-revisions
   *   Migrated report file revisions
   *
   * @validate-module-enabled reliefweb_migrate
   */
  public function migrateFileRevisions($options = [
    'dry-run' => FALSE,
  ]) {
    $query_options = [
      // Note: this is deprecated in Drupal 9.4 and will be removed in Drupal
      // 11 and there is no replacement. We don't really care because the
      // reliefweb_migrate will be removed way before that.
      'return' => Database::RETURN_AFFECTED,
    ];
    $dry_run = !empty($options['dry-run']);

    // Retrieve the revision info from the RW7 database.
    $query = Database::getConnection('default', 'rwint7')->select('field_revision_field_file', 'fr');
    $query->fields('fr', ['revision_id', 'entity_id', 'delta']);
    $query->condition('fr.bundle', 'report', '=');
    $query->orderBy('fr.revision_id', 'ASC');
    // Only keep records for exiting files that are currently attached.
    $query->innerJoin('field_data_field_file', 'f', 'f.entity_id = fr.entity_id AND f.field_file_fid = fr.field_file_fid');
    $query->innerJoin('file_managed', 'fm', 'fm.fid = fr.field_file_fid AND fm.status = 1');
    $query->addExpression('IF(f.revision_id = fr.revision_id, 1, 0)', 'current');
    $query->fields('fm', ['uri']);

    $current_records = [];
    $older_records = [];
    $count = 0;
    foreach ($query->execute() ?? [] as $record) {
      if (!empty($record->current)) {
        $current_records[$record->revision_id] = $record->revision_id;
      }
      else {
        $older_records[$record->entity_id][$record->revision_id][$record->delta] = LegacyHelper::generateAttachmentUuid($record->uri);
        $count++;
      }
    }

    $this->logger()->info(dt('@count records to insert for @count_entities entities', [
      '@count' => $count,
      '@count_entities' => count($current_records),
    ]));

    // Retrieve the list of existing field file revisions so we can skip
    // already migrated ones.
    $existing_revisions = $this->database
      ->select('node_revision__field_file', 'f')
      ->fields('f', ['revision_id'])
      ->execute()
      ?->fetchAllKeyed(0, 0) ?? [];

    $count = 0;
    foreach (array_chunk($current_records, 500) as $chunk) {
      // Retrieve the existing RW9 revision records. We'll use that as base for
      // the other revisions. Note: those are the revisions that were migrated
      // and correspond to the current revision in RW7.
      $query = $this->database
        ->select('node_revision__field_file', 'f')
        ->fields('f')
        ->condition('f.revision_id', $chunk, 'IN');

      // Group the existing records by entity ID.
      $existing_records = [];
      foreach ($query->execute() ?? [] as $record) {
        $existing_records[$record->entity_id][$record->field_file_uuid] = (array) $record;
      }

      // Prepate the revision records to insert.
      $records_to_insert = [];
      foreach ($existing_records as $entity_id => $existing_items) {
        $revisions = $older_records[$entity_id] ?? NULL;
        if (empty($revisions)) {
          continue;
        }

        foreach ($revisions as $revision_id => $items) {
          if (isset($existing_revisions[$revision_id])) {
            continue;
          }

          // Sort the items by delta.
          ksort($items);

          // Note: we are using the current version of the file metatadata
          // (preview page, description...) because it's much simpler and after
          // confirmation with some editors, tracking the old version of those
          // metadata is not important.
          $delta = 0;
          foreach ($items as $uuid) {
            if (isset($existing_items[$uuid])) {
              $records_to_insert[] = [
                'revision_id' => $revision_id,
                'delta' => $delta,
              ] + $existing_items[$uuid];
              $delta++;
            }
          }
        }
      }

      if (!empty($records_to_insert)) {
        try {
          $transaction = $this->database->startTransaction();

          $fields = array_keys(reset($records_to_insert));

          $query = $this->database
            ->insert('node_revision__field_file', $query_options)
            ->fields($fields);
          foreach ($records_to_insert as $record) {
            $query->values($record);
          }
          $query->execute();

          if ($dry_run) {
            $transaction->rollback();
          }
        }
        catch (\Exception $exception) {
          $transaction->rollback();
          $this->logger()->error(dt('Error while trying to update the database: @error', [
            '@error' => $exception->getMessage(),
          ]));
          return FALSE;
        }

        $count += count($records_to_insert);

        $this->logger()->info(dt('Inserted @count records', [
          '@count' => $count,
        ]));
      }
    }

    if ($count === 0) {
      $this->logger()->info(dt('Nothing to insert'));
    }
  }

}
