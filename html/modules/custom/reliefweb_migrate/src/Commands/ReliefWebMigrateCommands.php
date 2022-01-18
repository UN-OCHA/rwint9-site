<?php

namespace Drupal\reliefweb_migrate\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\ConfigManager;
use Drupal\Core\Database\Database;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\MigrationPluginManager;
use Drupal\migrate\Plugin\RequirementsInterface;
use Drupal\reliefweb_migrate\Plugin\migrate\source\SourceMigrationStatusInterface;
use Drupal\reliefweb_utility\Helpers\LegacyHelper;
use Drush\Commands\DrushCommands;

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
   * Key-value store service.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected $keyValue;

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
    DateFormatter $date_formatter,
    EntityTypeManagerInterface $entity_type_manager,
    KeyValueFactoryInterface $key_value,
    MigrationPluginManager $migration_plugin_manager
  ) {
    parent::__construct();
    $this->configInstaller = $config_installer;
    $this->configManager = $config_manager;
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
    $this->keyValue = $key_value;
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

}
