<?php

namespace Drupal\reliefweb_moderation;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\taxonomy\TermStorageSchema;

/**
 * Defines the taxonomy term schema handler.
 */
class ModeratedTermStorageSchema extends TermStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);

    $tables = [
      $this->storage->getDataTable(),
      $this->storage->getRevisionTable(),
    ];

    foreach ($tables as $table) {
      if (!empty($table)) {
        $fields = $schema[$table]['fields'] ?? [];
        if (isset($fields['vid'], $fields['moderation_status'])) {
          $schema[$table]['indexes'] += [
            'taxonomy_term__bundle_moderation_status' => [
              'vid',
              'moderation_status',
            ],
          ];
        }
      }
    }

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  protected function getSharedTableFieldSchema(FieldStorageDefinitionInterface $storage_definition, $table_name, array $column_mapping) {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);
    $field_name = $storage_definition->getName();

    switch ($field_name) {
      case 'moderation_status':
        $schema['fields']['moderation_status']['type'] = 'varchar_ascii';
        $schema['fields']['moderation_status']['not null'] = 'TRUE';
        $this->addSharedTableFieldIndex($storage_definition, $schema, TRUE);
        break;
    }

    return $schema;
  }

}
