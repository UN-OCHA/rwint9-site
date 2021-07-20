<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\destination;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\file\Plugin\migrate\destination\EntityFile;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Image migration destination.
 *
 * @MigrateDestination(
 *   id = "reliefweb_image"
 * )
 */
class Image extends EntityFile {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

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
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, EntityStorageInterface $storage, array $bundles, EntityFieldManagerInterface $entity_field_manager, FieldTypePluginManagerInterface $field_type_manager, Connection $database) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $storage, $bundles, $entity_field_manager, $field_type_manager);
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    $entity_type = static::getEntityTypeId($plugin_id);
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('entity_type.manager')->getStorage($entity_type),
      array_keys($container->get('entity_type.bundle.info')->getBundleInfo($entity_type)),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.field_type'),
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
    $uri = $entity->getFileUri();
    if (!empty($uri) && !empty($entity->old_uri)) {
      \Drupal::database()
        ->merge('reliefweb_migrate_uri_mapping')
        ->keys(['new_uri' => $uri])
        ->fields(['old_uri' => $entity->old_uri])
        ->execute();
    }
    return parent::save($entity, $old_destination_id_values);
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    // Delete the specified entity from Drupal if it exists.
    /** @var \Drupal\file\FileInterface $entity */
    $entity = $this->storage->load(reset($destination_identifier));
    if (!empty($entity)) {
      // Delete the URI mapping.
      $uri = $entity->getFileUri();
      if (!empty($uri)) {
        \Drupal::database()
          ->delete('reliefweb_migrate_uri_mapping')
          ->condition('new_uri', $uri)
          ->execute();
      }

      $entity->delete();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected static function getEntityTypeId($plugin_id) {
    return 'file';
  }

}
