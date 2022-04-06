<?php

namespace Drupal\reliefweb_migrate\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\user\UserStorage;

/**
 * User SQL storage for the migrations that regroup queries.
 */
class AccumulatedUserStorage extends UserStorage implements AccumulatedSqlContentEntityStorageInterface {

  use AccumulatedSqlContentEntityStorageTrait;

  /**
   * {@inheritdoc}
   */
  protected function processRowBeforeInsertion($table, array &$row) {
    // Remove the password.
    if ($table === $this->dataTable && isset($row['pass'])) {
      $row['pass'] = NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $transaction = $this->database->startTransaction();
    try {
      $this->database
        ->delete($this->baseTable)
        ->condition($this->idKey, 2, '>')
        ->execute();
      if ($this->revisionTable) {
        $this->database
          ->delete($this->revisionTable)
          ->condition($this->idKey, 2, '>')
          ->execute();
      }
      if ($this->dataTable) {
        $this->database
          ->delete($this->dataTable)
          ->condition($this->idKey, 2, '>')
          ->execute();
      }
      if ($this->revisionDataTable) {
        $this->database
          ->delete($this->revisionDataTable)
          ->condition($this->idKey, 2, '>')
          ->execute();
      }
      if ($this->database->schema()->tableExists('users_data')) {
        $this->database
          ->delete('users_data')
          ->condition($this->idKey, 2, '>')
          ->execute();
      }

      $this->deleteAllFromDedicatedTables();
    }
    catch (\Exception $exception) {
      $transaction->rollBack();
      watchdog_exception($this->entityTypeId, $exception);
      throw new EntityStorageException($exception->getMessage(), $exception->getCode(), $exception);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function deleteAllFromDedicatedTables() {
    $table_mapping = $this->getTableMapping();

    foreach ($this->fieldStorageDefinitions as $storage_definition) {
      if (!$table_mapping->requiresDedicatedTableStorage($storage_definition)) {
        continue;
      }

      $table_name = $table_mapping->getDedicatedDataTableName($storage_definition);
      $revision_name = $table_mapping->getDedicatedRevisionTableName($storage_definition);

      $this->database
        ->delete($table_name)
        ->condition('entity_id', 2, '>')
        ->execute();
      if ($this->entityType->isRevisionable()) {
        $this->database
          ->delete($revision_name)
          ->condition('entity_id', 2, '>')
          ->execute();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function invokeHook($hook, EntityInterface $entity) {
    $blacklist = [
      'social_auth_hid_user_insert' => TRUE,
      'social_auth_hid_user_presave' => TRUE,
    ];

    $this->doInvokeHook($this->entityTypeId . '_' . $hook, $entity, $blacklist);
    $this->doInvokeHook('entity_' . $hook, $entity, $blacklist);
  }

}
