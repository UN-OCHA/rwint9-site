<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\destination;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\reliefweb_migrate\Entity\AccumulatedPathAliasStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Image migration destination.
 *
 * @MigrateDestination(
 *   id = "reliefweb_entity:file"
 * )
 */
class EntityFile extends Entity {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Acummulator for the new and old image URIs.
   *
   * @var array
   */
  protected $accumulatedImageUris = [];

  /**
   * Constructs a content entity.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration entity.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The storage for this entity type.
   * @param array $bundles
   *   The list of bundles this entity type has.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface $field_type_manager
   *   The field type plugin manager service.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $account_switcher
   *   The account switcher service.
   * @param \Drupal\reliefweb_migrate\Entity\AccumulatedPathAliasStorage $path_alias_storage
   *   The path alias storage.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    EntityStorageInterface $storage,
    array $bundles,
    EntityFieldManagerInterface $entity_field_manager,
    FieldTypePluginManagerInterface $field_type_manager,
    AccountSwitcherInterface $account_switcher = NULL,
    AccumulatedPathAliasStorage $path_alias_storage,
    Connection $database
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $storage, $bundles, $entity_field_manager, $field_type_manager, $account_switcher, $path_alias_storage);
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    $entity_type_id = static::getEntityTypeId($plugin_id);
    $entity_type_manager = $container->get('entity_type.manager');
    $storage = $entity_type_manager->getStorage($entity_type_id);
    $entity_type = $storage->getEntityType();

    $storage_class = get_class($storage);
    $storage_class_name = (new \ReflectionClass($storage_class))->getShortName();
    $accumulated_storage_class = 'Drupal\reliefweb_migrate\Entity\Accumulated' . $storage_class_name;

    // Use the accumulated storage if for the entity type if available.
    if (class_exists($accumulated_storage_class) && is_subclass_of($accumulated_storage_class, $storage_class)) {
      $storage = $accumulated_storage_class::createInstance($container, $entity_type);
    }

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $storage,
      array_keys($container->get('entity_type.bundle.info')->getBundleInfo($entity_type_id)),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.field_type'),
      $container->get('account_switcher'),
      AccumulatedPathAliasStorage::createInstance($container, $entity_type_manager->getDefinition('path_alias')),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntity(Row $row, array $old_destination_id_values) {
    $entity = parent::getEntity($row, $old_destination_id_values);
    // Store the old ReliefWeb URI so we can store the mapping.
    $entity->old_uri = $row->getDestinationProperty('old_uri');
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function save(ContentEntityInterface $entity, array $old_destination_id_values = []) {
    /** @var \Drupal\file\FileInterface $entity */
    if (!empty($entity->getFileUri()) && !empty($entity->old_uri)) {
      $this->accumulatedImageUris[] = [
        $entity->id(),
        $entity->getFileUri(),
        $entity->old_uri,
      ];
    }

    if (count($this->accumulatedImageUris) > 1000) {
      $this->flushAccumulatedImageUris();
    }

    return parent::save($entity, $old_destination_id_values);
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    if (!empty($destination_identifier)) {
      // Delete the URI mapping.
      \Drupal::database()
        ->delete('reliefweb_migrate_uri_mapping')
        ->condition('id', reset($destination_identifier))
        ->execute();
    }

    parent::rollback($destination_identifier);
  }

  /**
   * {@inheritdoc}
   */
  protected static function getEntityTypeId($plugin_id) {
    return 'file';
  }

  /**
   * Save the accumulated image URIs to the database.
   */
  protected function flushAccumulatedImageUris() {
    if (!empty($this->accumulatedImageUris)) {
      $query = \Drupal::database()
        ->insert('reliefweb_migrate_uri_mapping')
        ->fields(['id', 'new_uri', 'old_uri']);

      foreach ($this->accumulatedImageUris as $values) {
        $query->values($values);
      }

      $query->execute();
    }

    $this->accumulatedImageUris = [];
  }

  /**
   * {@inheritdoc}
   */
  public function postImport(MigrateImportEvent $event) {
    parent::postImport($event);
    $this->flushAccumulatedImageUris();
  }

}
