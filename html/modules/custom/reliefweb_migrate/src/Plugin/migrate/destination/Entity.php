<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\destination;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\ImportAwareInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\migrate\destination\EntityContentBase;
use Drupal\migrate\Row;
use Drupal\reliefweb_migrate\Entity\AccumulatedPathAliasStorage;
use Drupal\reliefweb_migrate\Entity\AccumulatedSqlContentEntityStorageInterface;
use Drupal\reliefweb_migrate\Plugin\migrate\id_map\AccumulatedSql;
use Drupal\reliefweb_migrate\Plugin\migrate\source\EntityBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Entity migration destination.
 *
 * @MigrateDestination(
 *   id = "reliefweb_entity",
 *   deriver = "Drupal\reliefweb_migrate\Plugin\Derivative\MigrateEntity"
 * )
 */
class Entity extends EntityContentBase implements ImportAwareInterface {

  /**
   * URL aliases accumulator.
   *
   * @var array
   */
  protected $accumulatedUrlAliases = [];

  /**
   * The path alias storage.
   *
   * @var \Drupal\reliefweb_migrate\Entity\AccumulatedPathAliasStorage
   */
  protected $pathAliasStorage;

  /**
   * Batch size.
   *
   * @var int
   */
  protected $batchSize;

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
    AccumulatedPathAliasStorage $path_alias_storage
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $storage, $bundles, $entity_field_manager, $field_type_manager, $account_switcher);
    $this->pathAliasStorage = $path_alias_storage;
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
      AccumulatedPathAliasStorage::createInstance($container, $entity_type_manager->getDefinition('path_alias'))
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntity(Row $row, array $old_destination_id_values) {
    $entity_id = reset($old_destination_id_values) ?: $this->getEntityId($row);

    $source_plugin = $this->migration->getSourcePlugin();
    if ($source_plugin instanceof EntityBase) {
      $exists = $this->migration->getSourcePlugin()->entityExists($entity_id);
    }
    elseif ($original = $this->storage->load($entity_id)) {
      $exists = TRUE;
    }
    else {
      $exists = FALSE;
    }

    // Attempt to ensure we always have a bundle.
    if ($bundle = $this->getBundle($row)) {
      $row->setDestinationProperty($this->getKey('bundle'), $bundle);
    }

    // When the entity already exists, we still create a new entity with the new
    // data rather than trying to update the revious data to make sure
    // everything is properly overridden.
    $entity = $this->storage->create($row->getDestination());
    $entity->isDefaultRevision(TRUE);
    if ($entity->getEntityType()->isRevisionable()) {
      $entity->setNewRevision(TRUE);
    }

    if ($exists) {
      $entity->enforceIsNew(FALSE);
      $entity->original = $this->storage->load($entity_id);
      $entity->original->_is_migrating = TRUE;
      $entity->_exists = TRUE;
    }
    else {
      $entity->enforceIsNew(TRUE);
    }
    return $entity;
  }

  /**
   * Get the destination entity bundle if any.
   *
   * @return string|null
   *   Destination entity bundle.
   */
  public function getDestinationBundle() {
    return $this->configuration['default_bundle'] ?? NULL;
  }

  /**
   * Delete all the previously imported content.
   */
  public function deleteImported() {
    // Truncate all the database tables.
    if ($this->storage instanceof AccumulatedSqlContentEntityStorageInterface) {
      $this->storage->deleteAll();
    }
    // Remove the migration mapping.
    $id_map = $this->migration->getIdMap();
    if ($id_map instanceof AccumulatedSql) {
      $id_map->deleteIdMapping();
    }
    // Remove the high water if any.
    $source_plugin = $this->migration->getSourcePlugin();
    if ($source_plugin instanceof EntityBase) {
      $source_plugin->resetHighWater();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function save(ContentEntityInterface $entity, array $old_destination_id_values = []) {
    $this->saveUrlAlias($entity);

    if ($this->storage instanceof AccumulatedSqlContentEntityStorageInterface) {
      $this->storage->saveAccumulated($entity, $this->getBatchSize());
      return [$entity->id()];
    }
    else {
      return parent::save($entity, $old_destination_id_values);
    }
  }

  /**
   * Get the migration batch size.
   *
   * @return int
   *   The migration batch size.
   */
  protected function getBatchSize() {
    if (!isset($this->batchSize)) {
      $source_plugin = $this->migration->getSourcePlugin();
      if ($source_plugin instanceof EntityBase) {
        $this->batchSize = $this->migration->getSourcePlugin()->getBatchSize();
      }
      else {
        $this->batchSize = 1000;
      }
    }
    return $this->batchSize;
  }

  /**
   * Save the url alias for the entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity.
   */
  protected function saveUrlAlias(ContentEntityInterface $entity) {
    if (isset($entity->url_alias) && isset($this->pathAliasStorage)) {
      $path = '/' . strtr($entity->getEntityTypeId(), '_', '/') . '/' . $entity->id();
      $alias = '/' . trim($entity->url_alias['alias'], " \n\r\t\v\0\\/");
      $uuid = Uuid::v3(Uuid::fromString(Uuid::NAMESPACE_URL), 'https://reliefweb.int' . $path)->toRfc4122();
      $path_alias = $this->pathAliasStorage->create([
        'id' => $entity->url_alias['id'],
        'revision_id' => $entity->url_alias['id'],
        'uuid' => $uuid,
        'path' => $path,
        'alias' => $alias,
        'langcode' => $entity->language()->getId(),
        'status' => 1,
      ]);
      $this->pathAliasStorage->saveAccumulated($path_alias);
      unset($entity->url_alias);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    // Delete the specified entity from Drupal if it exists.
    $entity = $this->storage->load(reset($destination_identifier));
    if ($entity) {
      $entity->_is_migrating = TRUE;
      $entity->delete();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preImport(MigrateImportEvent $event) {
  }

  /**
   * {@inheritdoc}
   */
  public function postImport(MigrateImportEvent $event) {
    // Make sure everything is saved.
    $this->storage->flushAccumulated();
    $this->pathAliasStorage->flushAccumulated();
  }

}
