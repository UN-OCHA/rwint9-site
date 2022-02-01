<?php

namespace Drupal\content_entity_clone\Plugin;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Interface for the field processor plugin manager.
 */
interface FieldProcessorPluginManagerInterface {

  /**
   * Get the available field processor plugins for the given field definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   */
  public function getAvailablePlugins(FieldDefinitionInterface $field_definition);

  /**
   * Process a field with the given field processor plugin.
   *
   * @param string $plugin_id
   *   Field processor plugin id.
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   Field to process.
   */
  public function processField($plugin_id, FieldItemListInterface $field);

}
