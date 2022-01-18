<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\destination;

use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\reliefweb_migrate\Entity\AccumulatedPathAliasStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity migration destination.
 *
 * @MigrateDestination(
 *   id = "reliefweb_entity:redirect"
 * )
 */
class EntityRedirect extends Entity {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    $entity_type_id = static::getEntityTypeId($plugin_id);
    $entity_type_manager = $container->get('entity_type.manager');
    $storage = $entity_type_manager->getStorage($entity_type_id);
    $entity_type = $storage->getEntityType();

    // Use the accumulated storage if for the entity type if available.
    $accumulated_storage_class = 'Drupal\reliefweb_migrate\Entity\AccumulatedRedirectStorage';
    $storage = $accumulated_storage_class::createInstance($container, $entity_type);

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
  public function preImport(MigrateImportEvent $event) {
    // Safer to reimport all the redirects as deleting nodes and terms during
    // their migration may remove some redirects.
    $this->deleteImported();
  }

}
