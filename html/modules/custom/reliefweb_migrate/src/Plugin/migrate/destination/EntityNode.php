<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\destination;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\reliefweb_entities\Entity\Report;
use Drupal\reliefweb_migrate\Entity\AccumulatedFileStorage;
use Drupal\reliefweb_migrate\Entity\AccumulatedPathAliasStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity migration destination.
 *
 * @MigrateDestination(
 *   id = "reliefweb_entity:node",
 * )
 */
class EntityNode extends Entity {

  /**
   * The file storage.
   *
   * @var \Drupal\reliefweb_migrate\Entity\AccumulatedFileStorage
   */
  protected $fileStorage;

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
   * @param \Drupal\reliefweb_migrate\Entity\AccumulatedFileStorage $file_storage
   *   The file storage.
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
    AccumulatedFileStorage $file_storage
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $storage, $bundles, $entity_field_manager, $field_type_manager, $account_switcher, $path_alias_storage);
    $this->fileStorage = $file_storage;
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
      AccumulatedFileStorage::createInstance($container, $entity_type_manager->getDefinition('file'))
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function save(ContentEntityInterface $entity, array $old_destination_id_values = []) {
    if ($entity instanceof Report) {
      $this->saveAttachments($entity);
    }
    return parent::save($entity, $old_destination_id_values);
  }

  /**
   * Save the file attachments of a report.
   *
   * @param \Drupal\reliefweb_entities\Entity\Report $entity
   *   Report node entity.
   */
  protected function saveAttachments(Report $entity) {
    if (!$entity->get('field_file')->isEmpty()) {
      // If the entity is not published, we'll mark the files are private.
      $private = TRUE;
      if ($entity instanceof EntityPublishedInterface) {
        $private = !$entity->isPublished();
      }

      // Create the field item and preview files with the permanent URIs.
      foreach ($entity->get('field_file') as $item) {
        if ($item->isEmpty()) {
          continue;
        }

        $file = $item->createFile();
        if (empty($file)) {
          continue;
        }
        $file->setFileUri($item->getPermanentUri($private, FALSE));
        $file->setSize($item->getFileSize());
        $file->setPermanent();
        $file->changed->value = $item->timestamp;
        $file->created->value = $item->timestamp;
        $this->fileStorage->saveAccumulated($file);
        $item->get('file_uuid')->setValue($file->uuid());

        if (!$item->canHavePreview() || empty($item->getPreviewPage())) {
          continue;
        }
        $preview_file = $item->createPreviewFile(FALSE);
        if (empty($preview_file)) {
          continue;
        }
        $preview_file->setFileUri($item->getPermanentUri($private, TRUE));
        $preview_file->changed->value = $item->timestamp;
        $preview_file->created->value = $item->timestamp;
        $preview_file->setPermanent();
        $this->fileStorage->saveAccumulated($preview_file);
        $item->get('preview_uuid')->setValue($preview_file->uuid());
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postImport(MigrateImportEvent $event) {
    parent::postImport($event);
    // Make sure everything is saved.
    $this->fileStorage->flushAccumulated();
  }

}
