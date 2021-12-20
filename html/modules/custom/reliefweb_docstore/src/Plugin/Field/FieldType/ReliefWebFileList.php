<?php

namespace Drupal\reliefweb_docstore\Plugin\Field\FieldType;

use Drupal\Core\Entity\EntityPublishedInterface;
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
    $entity = $this->getEntity();

    // Files migration is handled separately.
    // @todo remove when removing `reliefweb_migrate`.
    if (!empty($entity->_is_migrating)) {
      // If the entity is not published, we'll mark the files are private.
      $private = TRUE;
      if ($entity instanceof EntityPublishedInterface) {
        $private = !$entity->isPublished();
      }

      // Create the field item and preview files with the permanent URIs.
      foreach ($this->list as $item) {
        if ($item->isEmpty()) {
          continue;
        }

        $file = $item->createFile();
        if (empty($file)) {
          continue;
        }
        $file->setFileUri($item->getPermanentUri($private, FALSE));
        $file->setPermanent();
        $file->save();
        $item->get('file_uuid')->setValue($file->uuid());

        if (!$item->canHavePreview() || empty($item->getPreviewPage())) {
          continue;
        }
        $preview_file = $item->createPreviewFile(FALSE);
        if (empty($preview_file)) {
          continue;
        }
        $preview_file->setFileUri($item->getPermanentUri($private, TRUE));
        $preview_file->setPermanent();
        $preview_file->save();
        $item->get('preview_uuid')->setValue($preview_file->uuid());
      }
      return;
    }

    // Remove references to any files from the remote document associated with
    // the entity this field is attached to so that we can perform update and
    // deletion of the remote file resources.
    $this->deleteRemoteDocumentFileReferences();

    // Filter out empty items.
    $this->filterEmptyItems();

    // Extract the original items so that we can process replaced files,
    // create revisions for old ones etc.
    $original_items = [];
    $original = $entity->original;
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
  public function postSave($updated) {
    $entity = $this->getEntity();

    // Files migration is handled separately.
    // @todo remove when removing `reliefweb_migrate`.
    if (!empty($entity->_is_migrating)) {
      return;
    }
    parent::postSave($updated);

    // Update the references to the remote file resources in the remote document
    // associated with the entity this field is attached.
    $this->updateRemoteDocumentFileReferences();
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    $entity = $this->getEntity();

    // Remove references to any files from the remote document associated with
    // the entity this field is attached to so that we can perform update and
    // deletion of the remote file resources.
    //
    // If we are deleting a translation, we'll update the document with the
    // references to the files from the other translations.
    //
    // Otherwise, we delete the document directly.
    if (!$entity->isDefaultTranslation()) {
      $this->deleteRemoteDocumentFileReferences();
    }
    else {
      $this->getDocstoreClient()->deleteDocument($entity->uuid());
    }

    $entity_type_id = $entity->getEntityTypeId();
    $field_name = $this->definition->getName();

    $table = $this->getFieldRevisionTableName($entity_type_id, $field_name);

    // Query the field revision table to retrieve all the file entity UUIds
    // (file and preview) and the file resource UUIDs.
    $query = $this->getDatabase()
      ->select($table, $table)
      ->condition($table . '.entity_id', $entity->id(), '=');

    foreach (['uuid', 'file_uuid', 'preview_uuid'] as $field) {
      $column = $this->getFieldColumnName($entity_type_id, $field_name, $field);
      $query->addField($table, $column, $field);
    }

    // If we are deleting a translation, limit to the translation language.
    if (!$entity->isDefaultTranslation()) {
      $query->condition($table . '.langcode', $this->getLangcode());
    }

    // Retrieve the item and file UUIDs from the field revisions.
    $records = $query->distinct()->execute();

    // Store the resource UUIDs and the file UUIDs so we can first
    // delete the remote resource (if stored remotelly) then delete the
    // file entities.
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

    // Delete all the remote files.
    if (!$this->storeLocally() && !empty($uuids)) {
      $client = $this->getDocstoreClient();
      foreach ($uuids as $uuid) {
        $client->deleteFile($uuid);
      }
    }

    // We load the file entities for the field item and preview files.
    if (!empty($file_uuids)) {
      /** @var \Drupal\file\Entity\File[] $files */
      $files = $this->getEntityTypeStorage('file')
        ->loadByProperties(['uuid' => $file_uuids]);

      // Delete the file entities and the file on disk if any.
      foreach ($files as $file) {
        $uri = $file->getFileUri();
        if (!empty($uri) && file_exists($uri)) {
          $this->getFileSystem()->unlink($uri);
        }
        $file->delete();
      }
    }

    // If we just deleted a translation, we need to update the file references
    // for the remaining translations.
    if (!$entity->isDefaultTranslation()) {
      $this->updateRemoteDocumentFileReferences();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteRevision() {
    // Remove references to any files from the remote document associated with
    // the entity this field is attached to so that we can perform update and
    // deletion of the remote file resources.
    //
    // The references will be updated after all the calls to ::deleteRevision(),
    // in reliefweb_docstore_entity_revision_delete().
    $this->deleteRemoteDocumentFileReferences();

    // First remove all the files that are not used anymore, then remove
    // the revision of the files thare are still referenced.
    $this->deleteUnusedFiles();
    $this->deleteUnusedFileRevisions();
  }

  /**
   * Delete the files associated with the revision to be deleted.
   *
   * This will completely delete field item files that are not referenced in
   * other revisions.
   */
  protected function deleteUnusedFiles() {
    $entity = $this->getEntity();
    $entity_type_id = $entity->getEntityTypeId();
    $field_name = $this->definition->getName();

    // Map of the field items keyed by file resource UUID.
    $items = [];
    foreach ($this->list as $item) {
      $uuid = $item->getUuid();
      if (!empty($uuid)) {
        $items[$uuid] = $item;
      }
    }

    if (empty($items)) {
      return;
    }

    $table = $this->getFieldRevisionTableName($entity_type_id, $field_name);
    $field = $this->getFieldColumnName($entity_type_id, $field_name, 'uuid');

    // Get the other revision records that have the same UUIDs than the
    // current revision to be deleted.
    $records = $this->getDatabase()
      ->select($table, $table)
      ->fields($table, [$field])
      ->condition($table . '.entity_id', $entity->id(), '=')
      ->condition($table . '.revision_id', $entity->getRevisionId(), '<>')
      ->condition($table . '.' . $field, array_keys($items), 'IN')
      ->distinct()
      ->execute();

    // Remove the items for which there are other revisions using the same UUID.
    if (!empty($records)) {
      foreach ($records as $record) {
        unset($items[$record->{$field}]);
      }
    }

    // Delete the items that are not referenced anywhere else.
    foreach ($items as $item) {
      $item->delete();
      // Flag to indicate the field item and its files are already deleted.
      $item->_deleted = TRUE;
    }
  }

  /**
   * Delete the file revisions associated with the revision to be deleted.
   *
   * This will delete the file revisions that are not referenced by other
   * entity revisions.
   */
  protected function deleteUnusedFileRevisions() {
    $entity = $this->getEntity();
    $entity_type_id = $entity->getEntityTypeId();
    $field_name = $this->definition->getName();

    // Map of the field items keyed by file UUID.
    $items = [];
    foreach ($this->list as $item) {
      $file_uuid = $item->getFileUuid();
      // Skip invalid field items or items that were already processed in
      // ::deleteUnusedFiles().
      if (!empty($file_uuid) && empty($item->_deleted)) {
        $items[$file_uuid] = $item;
      }
    }

    if (empty($items)) {
      return;
    }

    $table = $this->getFieldRevisionTableName($entity_type_id, $field_name);
    $field = $this->getFieldColumnName($entity_type_id, $field_name, 'file_uuid');

    // Get the other revision records that have the same file UUIDs than the
    // current revision to be deleted.
    $records = $this->getDatabase()
      ->select($table, $table)
      ->fields($table, [$field])
      ->condition($table . '.entity_id', $entity->id(), '=')
      ->condition($table . '.revision_id', $entity->getRevisionId(), '<>')
      ->condition($table . '.' . $field, array_keys($items), 'IN')
      ->distinct()
      ->execute();

    // Remove the items for which there are other revisions using the same
    // file UUID.
    if (!empty($records)) {
      foreach ($records as $record) {
        unset($items[$record->{$field}]);
      }
    }

    // Delete the revision of the file if it's not referenced by other
    // revisions.
    foreach ($items as $item) {
      $item->deleteRevision();
    }
  }

  /**
   * Get all the field resource UUIDs for the field.
   *
   * @param string $field_name
   *   Field name.
   *
   * @return array
   *   List of file resource UUIDs.
   */
  protected function getFileResourceUuidsFromField($field_name) {
    $entity = $this->getEntity();
    if (empty($entity->id())) {
      return [];
    }

    $entity_type_id = $entity->getEntityTypeId();
    $table = $this->getFieldRevisionTableName($entity_type_id, $field_name);
    $field = $this->getFieldColumnName($entity_type_id, $field_name, 'uuid');

    return $this->getDatabase()
      ->select($table, $table)
      ->fields($table, [$field])
      ->condition($table . '.entity_id', $entity->id(), '=')
      ->distinct()
      ->execute()
      ?->fetchCol() ?? [];
  }

  /**
   * Delete all the file references from a remote document.
   *
   * Drupal doesn't provide a "preDeleteRevision" hook for revisionable entities
   * so we do that here and flag the entity so that if the the entity has
   * several reliefweb_file fields, then we don't run that again.
   *
   * This is needed because the remote document has a single "files" field
   * with references to all the files from the reliefweb_file fields on the
   * entity.
   */
  protected function deleteRemoteDocumentFileReferences() {
    if ($this->storeLocally()) {
      return;
    }

    $entity = $this->getEntity();

    // Skip if the entity has already been processed.
    if (!empty($entity->_references_deleted) || empty($entity->uuid())) {
      return;
    }
    $entity->_references_deleted = TRUE;

    // Note: it doesn't matter if the document exists or not.
    $this->getDocstoreClient()->updateDocument($entity->uuid(), [
      'files' => [],
    ]);
  }

  /**
   * Update the document resource with all the referenced remote files.
   */
  public function updateRemoteDocumentFileReferences() {
    if ($this->storeLocally()) {
      return;
    }

    $entity = $this->getEntity();
    $entity_uuid = $entity->uuid();
    $client = $this->getDocstoreClient();

    // Skip if the entity has already been processed.
    if (!empty($entity->_references_updated) || empty($entity->uuid())) {
      return;
    }
    $entity->_references_updated = TRUE;

    // Get all the file resource UUIDs referenced by the entity.
    $uuids = [];
    /** @var \Drupal\Core\Field\FieldItemListInterface $field_item_list */
    foreach ($entity as $field_name => $field_item_list) {
      if ($field_item_list instanceof ReliefWebFileList) {
        $uuids = array_merge($uuids, $this->getFileResourceUuidsFromField($field_name));
      }
    }

    // Retrieve the document.
    $response = $client->getDocument($entity_uuid);

    // Update the document.
    if ($response->isSuccessful()) {
      $this->getDocstoreClient()->updateDocument($entity->uuid(), [
        'files' => $uuids,
      ]);
    }
    // Try to create it if it doesn't exist and there are files.
    elseif ($response->isNotFound() && !empty($uuids)) {
      $response = $client->createDocument([
        'uuid' => $entity_uuid,
        'title' => $entity->label(),
        'private' => TRUE,
        'files' => $uuids,
      ]);
    }
  }

  /**
   * Check if we are storing the files locally or remotely.
   *
   * @return bool
   *   TRUE if the files are stored locally.
   */
  public function storeLocally() {
    return $this->getDocstoreConfig()->get('local') === TRUE;
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
