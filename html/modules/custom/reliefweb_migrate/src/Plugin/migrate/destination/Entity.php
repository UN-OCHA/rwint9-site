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
use Symfony\Component\DependencyInjection\ContainerInterface;

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

    // Delete the existing entity so we can insert the new one without having
    // to worry about changes, unwanted new revisions etc.
    if ($this->migration->getSourcePlugin()->entityExists($entity_id)) {
      $entity = $this->storage->load($entity_id);
      if (!empty($entity)) {
        $entity->_is_migrating = TRUE;
        $entity->delete();
      }
    }

    // Attempt to ensure we always have a bundle.
    if ($bundle = $this->getBundle($row)) {
      $row->setDestinationProperty($this->getKey('bundle'), $bundle);
    }

    $entity = $this->storage->create($row->getDestination());
    $entity->enforceIsNew();
    $entity->isDefaultRevision(TRUE);
    if ($entity->getEntityType()->isRevisionable()) {
      $entity->setNewRevision(TRUE);
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function save(ContentEntityInterface $entity, array $old_destination_id_values = []) {
    if (isset($entity->url_alias)) {
      $path_alias = $this->pathAliasStorage->create([
        'path' => '/' . strtr($entity->getEntityTypeId(), '_', '/') . '/' . $entity->id(),
        'alias' => '/' . trim($entity->url_alias, " \n\r\t\v\0\\/"),
        'langcode' => $entity->language()->getId(),
      ]);
      $this->pathAliasStorage->save($path_alias);
      unset($entity->url_alias);
    }

    if ($this->storage instanceof AccumulatedSqlContentEntityStorageInterface) {
      $this->storage->saveAccumulated($entity, $this->migration->getSourcePlugin()->getBatchSize());
      return [$entity->id()];
    }
    else {
      return parent::save($entity, $old_destination_id_values);
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
