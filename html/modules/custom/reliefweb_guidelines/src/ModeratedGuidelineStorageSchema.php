<?php

namespace Drupal\reliefweb_guidelines;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Defines the guideline schema handler.
 */
class ModeratedGuidelineStorageSchema extends SqlContentEntityStorageSchema {

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
        if (isset($fields['type'], $fields['moderation_status'])) {
          $schema[$table]['indexes'] += [
            'guideline__bundle_moderation_status' => [
              'type',
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
        $schema['fields']['moderation_status']['default'] = '';
        $this->addSharedTableFieldIndex($storage_definition, $schema, TRUE);
        break;
    }

    return $schema;
  }

}
