<?php

namespace Drupal\reliefweb_docstore\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemList;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;

/**
 * List of ReliefWebFile field items.
 */
class ReliefWebFileList extends FieldItemList {

  use EntityDatabaseInfoTrait;

  /**
   * The docstore client service.
   *
   * @var \Drupal\reliefweb_docstore\Services\DocstoreClient
   */
  protected $docstoreClient;

  /**
   * The docstore config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $docstoreConfig;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    // Filter out empty items.
    $this->filterEmptyItems();

    // Extract the original items so that we can process replaced files,
    // create revisions for old ones etc.
    $original_items = [];
    $original = $this->getEntity()->original;
    if (isset($original)) {
      foreach ($original->get($this->definition->getName()) as $item) {
        if (!$item->isEmpty()) {
          $original_items[$item->getUuid()] = $item;
        }
      }
    }

    // Add the original items to the replaced ones.
    $presave = [];
    foreach ($this->list as $item) {
      $uuid = $item->getUuid();

      // Store the item and its original version to use when calling the item
      // ::preSave() later. We cannot call it now because we need to take care
      // of the "deleted" items first to avoid URI conflicts when moving files
      // around. Notably to ensure there is no 2 items using the same permanent
      // URI.
      $presave[$uuid] = [
        'item' => $item,
        'original_item' => $original_items[$uuid] ?? NULL,
      ];

      // Remove items that exists in both the current list and old one so
      // that only the items that need to be deleted are left.
      unset($original_items[$uuid]);
    }

    // Update all "deleted" items.
    foreach ($original_items as $item) {
      $item->updateRemovedItem();
    }

    // Call presave on the items than needs to be saved, passing the original
    // version so we can compare what changed.
    foreach ($presave as $item) {
      $item['item']->preSave($item['original_item']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    $entity = $this->getEntity();
    $entity_type_id = $entity->getEntityTypeId();
    $field_name = $this->definition->getName();

    $table = $this->getFieldRevisionTableName($entity_type_id, $field_name);
    $fields = [
      $this->getFieldColumnName($entity_type_id, $field_name, 'uuid') => 'uuid',
      $this->getFieldColumnName($entity_type_id, $field_name, 'file_uuid') => 'file_uid',
      $this->getFieldColumnName($entity_type_id, $field_name, 'preview_uuid') => 'preview_uuid',
    ];

    $query = $this->getDatabase()
      ->select($table, $table)
      ->fields($table, $fields)
      ->condition($table . '.entity_id', $entity->id(), '=');

    // If we are deleting a translation, limit to the translation language.
    if (!$entity->isDefaultTranslation()) {
      $query->condition($table . '.langcode', $this->getLangcode());
    }

    // Retrieve the item and file UUIDs from the field revisions.
    $records = $query->distinct()->execute();

    $uuids = [];
    $file_uuids = [];
    if (!empty($records)) {
      foreach ($records as $record) {
        if (!empty($record->uuid)) {
          $uuids[$record->uuid] = $record->uuid;
        }
        if (!empty($record->file_uuid)) {
          $file_uuids[$record->file_uuid] = $record->file_uuid;
        }
        if (!empty($record->preview_uuid)) {
          $file_uuids[$record->preview_uuid] = $record->preview_uuid;
        }
      }
    }

    // Delete the remote files.
    if ($this->getDocstoreConfig()->get('local') === TRUE && !empty($uuids)) {
      foreach ($uuids as $uuid) {
        $this->getDocstoreClient()->deleteFile($uuid);
      }
    }

    // Load the field item and preview files.
    $files = $this->getEntityTypeStorage($entity_type_id)
      ->loadByProperties(['uuid' => $file_uuids]);

    // Delete the files.
    foreach ($files as $file) {
      $uri = $file->getFileUri();
      if (!empty($uri) && file_exists($uri)) {
        $this->getFileSystem()->unlink($uri);
      }
      $file->delete();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteRevision() {
    $entity = $this->getEntity();
    $entity_type_id = $entity->getEntityTypeId();
    $revision_id = $entity->getRevisionId();
    $field_name = $this->definition->getName();

    // Map of the field items keyed by file UUID.
    $items = [];
    foreach ($this->list as $item) {
      $file_uuid = $item->getFileUuid();
      if (!empty($file_uuid)) {
        $items[$file_uuid] = $item;
      }
    }

    $table = $this->getFieldRevisionTableName($entity_type_id, $field_name);
    $field = $this->getFieldColumnName($entity_type_id, $field_name, 'file_uuid');

    // Get the other revision records that have the same file UUIDs than the
    // current revision to be deleted.
    $records = $this->getDatabase()
      ->select($table, $table)
      ->fields($table, [$field])
      ->condition($table . '.entity_id', $entity->id(), '=')
      ->condition($table . '.revision_id', $revision_id, '<>')
      ->condition($table . '.' . $field, array_keys($items), 'IN')
      ->execute();

    // Filter the items for which there are other revisions using the same
    // file UUID.
    if (!empty($records)) {
      foreach ($records as $record) {
        unset($items[$record->{$field}]);
      }
    }

    // Delete the items.
    foreach ($items as $item) {
      $item->deleteRevision();
    }
  }

  /**
   * Get the docstore client service.
   *
   * @return \Drupal\reliefweb_docstore\Services\DocstoreClient
   *   The docstore client service.
   */
  protected function getDocstoreClient() {
    if (!isset($this->docstoreClient)) {
      $this->docstoreClient = \Drupal::service('reliefweb_docstore.client');
    }
    return $this->docstoreClient;
  }

  /**
   * Get the reliefweb docstore config.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   Docstore config.
   */
  protected function getDocstoreConfig() {
    if (!isset($this->docstoreConfig)) {
      $this->docstoreConfig = \Drupal::config('reliefweb_docstore.settings');
    }
    return $this->docstoreConfig;
  }

  /**
   * Get the file system serice.
   *
   * @return \Drupal\Core\File\FileSystemInterface
   *   The file system service.
   */
  protected function getFileSystem() {
    if (!isset($this->fileSystem)) {
      $this->fileSystem = \Drupal::service('file_system');
    }
    return $this->fileSystem;
  }

}
