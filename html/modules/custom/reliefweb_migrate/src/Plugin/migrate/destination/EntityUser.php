<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\destination;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\ImportAwareInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\reliefweb_migrate\Entity\AccumulatedSqlContentEntityStorageInterface;
use Drupal\user\Plugin\migrate\destination\EntityUser as EntityUserBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity migration destination.
 *
 * @MigrateDestination(
 *   id = "reliefweb_entity:user"
 * )
 */
class EntityUser extends EntityUserBase implements ImportAwareInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    $entity_type_id = static::getEntityTypeId($plugin_id);
    $storage = $container->get('entity_type.manager')->getStorage($entity_type_id);
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
      $container->get('password'),
      $container->get('account_switcher')
    );
  }

  /**
   * {@inheritdoc}
   *
   * We use a shortcut here to reduce the number of database requests by
   * assuming we only create new entities with that migration destination.
   *
   * This is supposed to work due to the use of hight water properties to
   * only deal with new entities.
   *
   * @todo review how to handle updates.
   *
   * @todo use the preImport event to preload existing entities with a
   * loadMultiple which should, in theory, be more efficient than several
   * individual loads.
   */
  protected function getEntity(Row $row, array $old_destination_id_values) {
    // Attempt to ensure we always have a bundle.
    if ($bundle = $this->getBundle($row)) {
      $row->setDestinationProperty($this->getKey('bundle'), $bundle);
    }

    $entity = $this->storage->create($row->getDestination());
    $entity->enforceIsNew();
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function save(ContentEntityInterface $entity, array $old_destination_id_values = []) {
    if ($this->storage instanceof AccumulatedSqlContentEntityStorageInterface) {
      $this->storage->saveAccumulated($entity, $this->migration->getSourcePlugin()->getBatchSize());
    }
    else {
      $this->storage->save($entity);
    }
    return [$entity->id()];
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
  }

}
