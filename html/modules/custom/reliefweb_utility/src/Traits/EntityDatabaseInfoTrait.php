<?php

namespace Drupal\reliefweb_utility\Traits;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageException;

/**
 * Trait with helper methods to access database information about entities.
 */
trait EntityDatabaseInfoTrait {

  /**
   * Get the database connection.
   *
   * @return \Drupal\Core\Database\Connection
   *   The database connection.
   */
  protected function getDatabase() {
    if (isset($this->database) && $this->database instanceof Connection) {
      return $this->database;
    }
    return \Drupal::database();
  }

  /**
   * Get the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  protected function getEntityTypeManager() {
    if (isset($this->entityTypeManager) && $this->entityTypeManager instanceof EntityTypeManagerInterface) {
      return $this->entityTypeManager;
    }
    return \Drupal::entityTypeManager();
  }

  /**
   * Get the entity field manager.
   *
   * @return \Drupal\Core\Entity\EntityFieldManagerInterface
   *   The entity field manager.
   */
  protected function getEntityFieldManager() {
    if (isset($this->entityFieldManager) && $this->entityFieldManager instanceof EntityFieldManagerInterface) {
      return $this->entityFieldManager;
    }
    return \Drupal::service('entity_field.manager');
  }

  /**
   * Get the storage for the entity type id.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   Entity Storage.
   */
  protected function getEntityTypeStorage($entity_type_id) {
    return $this->getEntityTypeManager()
      ->getStorage($entity_type_id);
  }

  /**
   * Get the table name for a field.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string $field_name
   *   Field name.
   *
   * @return string
   *   Field table name.
   *
   * @throws \Drupal\Core\Entity\Sql\SqlContentEntityStorageException
   *   Exception of the field name table couldn't be retrieved.
   */
  protected function getFieldTableName($entity_type_id, $field_name) {
    return $this->getEntityTypeStorage($entity_type_id)
      ->getTableMapping()
      ->getFieldTableName($field_name);
  }

  /**
   * Get the revision table name for a field.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string $field_name
   *   Field name.
   *
   * @return string
   *   Field table name.
   *
   * @throws \Drupal\Core\Entity\Sql\SqlContentEntityStorageException
   *   Exception of the field name table couldn't be retrieved.
   */
  protected function getFieldRevisionTableName($entity_type_id, $field_name) {
    $field_storage_definitions = $this->getEntityFieldManager()
      ->getFieldStorageDefinitions($entity_type_id);

    if (!isset($field_storage_definitions[$field_name])) {
      throw new SqlContentEntityStorageException("The {$entity_type_id} doesn't have the {$field_name} field");
    }

    return $this->getEntityTypeStorage($entity_type_id)
      ->getTableMapping()
      ->getDedicatedRevisionTableName($field_storage_definitions[$field_name]);
  }

  /**
   * Generates a column name for a field property.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string $field_name
   *   Field name.
   * @param string $property_name
   *   Field property name.
   *
   * @return string
   *   Field table name.
   *
   * @throws \Drupal\Core\Entity\Sql\SqlContentEntityStorageException
   *   Exception of the column name couldn't be retrieved.
   */
  protected function getFieldColumnName($entity_type_id, $field_name, $property_name) {
    $field_storage_definitions = $this->getEntityFieldManager()
      ->getFieldStorageDefinitions($entity_type_id);

    if (!isset($field_storage_definitions[$field_name])) {
      throw new SqlContentEntityStorageException("The {$entity_type_id} doesn't have the {$field_name} field");
    }

    return $this->getEntityTypeStorage($entity_type_id)
      ->getTableMapping()
      ->getFieldColumnName($field_storage_definitions[$field_name], $property_name);
  }

  /**
   * Get the data table for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string
   *   Entity type data table name.
   */
  protected function getEntityTypeDataTable($entity_type_id) {
    return $this->getEntityTypeStorage($entity_type_id)
      ->getEntityType()
      ->getDataTable();
  }

  /**
   * Get the base table for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string
   *   Entity type base table name.
   */
  protected function getEntityTypeBaseTable($entity_type_id) {
    return $this->getEntityTypeStorage($entity_type_id)
      ->getEntityType()
      ->getBaseTable();
  }

  /**
   * Get the revision table for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string
   *   Entity type revision table name.
   */
  protected function getEntityTypeRevisionTable($entity_type_id) {
    return $this->getEntityTypeStorage($entity_type_id)
      ->getEntityType()
      ->getRevisionTable();
  }

  /**
   * Get the revision data table for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string
   *   Entity type revision table name.
   */
  protected function getEntityTypeRevisionDataTable($entity_type_id) {
    return $this->getEntityTypeStorage($entity_type_id)
      ->getEntityType()
      ->getRevisionDataTable();
  }

  /**
   * Get the id field for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string
   *   Entity type id field name or FALSE if the entity type doesn't
   *   have this key.
   */
  protected function getEntityTypeIdField($entity_type_id) {
    return $this->getEntityTypeKeyField($entity_type_id, 'id');
  }

  /**
   * Get the bundle field for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string
   *   Entity type bundle field name or FALSE if the entity type doesn't
   *   have this key.
   */
  protected function getEntityTypeBundleField($entity_type_id) {
    return $this->getEntityTypeKeyField($entity_type_id, 'bundle');
  }

  /**
   * Get the label field for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string|false
   *   Entity type label field name or FALSE if the entity type doesn't
   *   have this key.
   */
  protected function getEntityTypeLabelField($entity_type_id) {
    return $this->getEntityTypeKeyField($entity_type_id, 'label');
  }

  /**
   * Get the langcode field for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string|false
   *   Entity type langcode field name or FALSE if the entity type doesn't
   *   have this key.
   */
  protected function getEntityTypeLangcodeField($entity_type_id) {
    return $this->getEntityTypeKeyField($entity_type_id, 'langcode');
  }

  /**
   * Get the uuid field for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string|false
   *   Entity type langcode field name or FALSE if the entity type doesn't
   *   have this key.
   */
  protected function getEntityTypeUuidField($entity_type_id) {
    return $this->getEntityTypeKeyField($entity_type_id, 'uuid');
  }

  /**
   * Get the revision id field for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string|false
   *   Entity type uuid field name or FALSE if the entity type doesn't
   *   have this key.
   */
  protected function getEntityTypeRevisionIdField($entity_type_id) {
    return $this->getEntityTypeKeyField($entity_type_id, 'revision');
  }

  /**
   * Get the revision created field for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string|false
   *   Entity type revision created field name or FALSE if the entity type
   *   doesn't have this key.
   */
  protected function getEntityTypeRevisionCreatedField($entity_type_id) {
    return $this->getEntityTypeRevisionKeyField($entity_type_id, 'revision_created');
  }

  /**
   * Get the revision user field for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string|false
   *   Entity type revision user field name or FALSE if the entity type doesn't
   *   have this key.
   */
  protected function getEntityTypeRevisionUserField($entity_type_id) {
    return $this->getEntityTypeRevisionKeyField($entity_type_id, 'revision_user');
  }

  /**
   * Get the revision log message field for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   *
   * @return string|false
   *   Entity type revision log message field name or FALSE if the entity type
   *   doesn't have this key.
   */
  protected function getEntityTypeRevisionLogMessageField($entity_type_id) {
    return $this->getEntityTypeRevisionKeyField($entity_type_id, 'revision_log_message');
  }

  /**
   * Get a revision entity key field for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string $key
   *   One of the key returned by getRevisionMetadataKeys().
   *
   * @return string|false
   *   Entity type revision field name or FALSE if the entity type
   *   doesn't have this key.
   *
   * @see \Drupal\Core\Entity\ContentEntityInterface::getRevisionMetadataKeys()
   */
  protected function getEntityTypeRevisionKeyField($entity_type_id, $key) {
    $keys = $this->entityTypeManager
      ->getStorage($entity_type_id)
      ->getEntityType()
      ->getRevisionMetadataKeys();
    return $keys[$key] ?? FALSE;
  }

  /**
   * Get am entity key field for the entity type.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string $key
   *   One of the key returned by getKeys().
   *
   * @return string|false
   *   Entity type revision field name or FALSE if the entity type
   *   doesn't have this key.
   *
   * @see \Drupal\Core\Entity\EntityInterface::getKeys()
   */
  protected function getEntityTypeKeyField($entity_type_id, $key) {
    return $this->getEntityTypeStorage($entity_type_id)
      ->getEntityType()
      ->getKey($key);
  }

  /**
   * Get the base table name for a select query.
   *
   * The first table without a join type is the base table.
   *
   * @param \Drupal\Core\Database\Query\Select $query
   *   Query.
   *
   * @return string
   *   Base table alias.
   *
   * @see \Drupal\Core\Database\Query\Select::__construct()
   */
  protected function getQueryBaseTable(Select $query) {
    foreach ($query->getTables() as $info) {
      if (!isset($info['join type'])) {
        return $info['table'] ?? '';
      }
    }
    return '';
  }

  /**
   * Get the base table alias for a select query.
   *
   * The first table without a join type is the base table.
   *
   * @param \Drupal\Core\Database\Query\Select $query
   *   Query.
   *
   * @return string
   *   Base table alias.
   *
   * @see \Drupal\Core\Database\Query\Select::__construct()
   */
  protected function getQueryBaseTableAlias(Select $query) {
    foreach ($query->getTables() as $alias => $info) {
      if (!isset($info['join type'])) {
        return $alias;
      }
    }
    return '';
  }

}
