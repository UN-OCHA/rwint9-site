<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\destination;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\ImportAwareInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\migrate\destination\EntityRevision as EntityRevisionBase;
use Drupal\migrate\Row;
use Drupal\reliefweb_migrate\Entity\AccumulatedSqlContentEntityStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides entity revision destination plugin.
 *
 * @todo preload all the entities with a loadMultiple so we can reduce the
 * number of requests.
 *
 * @MigrateDestination(
 *   id = "reliefweb_entity_revision",
 *   deriver = "Drupal\reliefweb_migrate\Plugin\Derivative\MigrateEntityRevision"
 * )
 */
class EntityRevision extends EntityRevisionBase implements ImportAwareInterface {

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
      $container->get('account_switcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntity(Row $row, array $old_destination_id_values) {
    $revision_id = reset($old_destination_id_values) ?: $this->getEntityRevisionId($row);

    // Delete the existing revision so we can insert the new one without having
    // to worry about changes, unwanted new revisions etc.
    if ($this->migration->getSourcePlugin()->entityExists($revision_id)) {
      $this->storage->deleteRevision($revision_id);
    }

    // Attempt to ensure we always have a bundle.
    if ($bundle = $this->getBundle($row)) {
      $row->setDestinationProperty($this->getKey('bundle'), $bundle);
    }

    // We create a "new" entity and mark is a non default revision and non new
    // so that we can avoid unnecessary loading from the database. The
    // information should be enough for the storage to fill the revision tables.
    // @todo we may want to check that the base entity exists in the system.
    $entity = $this->storage->create($row->getDestination());
    $entity->original = $entity;
    $entity->enforceIsNew(FALSE);
    $entity->isDefaultRevision(FALSE);
    $entity->setNewRevision(TRUE);
    return $entity;
  }

  /**
   * Get the revision ID of the entity row.
   *
   * @param \Drupal\migrate\Row $row
   *   Migration row.
   *
   * @return int
   *   Revision ID.
   */
  protected function getEntityRevisionId(Row $row) {
    return $row->getDestinationProperty($this->getKey('revision'));
  }

  /**
   * {@inheritdoc}
   */
  protected function save(ContentEntityInterface $entity, array $old_destination_id_values = []) {
    if (!empty($entity->skip_saving)) {
      return [$entity->getRevisionId()];
    }
    if ($this->storage instanceof AccumulatedSqlContentEntityStorageInterface) {
      $this->storage->saveAccumulated($entity, $this->migration->getSourcePlugin()->getBatchSize());
      return [$entity->getRevisionId()];
    }
    else {
      return parent::save($entity, $old_destination_id_values);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    $this->storage->deleteRevision(reset($destination_identifier));
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
    $this->storage->flushAccumulated();
  }

}
