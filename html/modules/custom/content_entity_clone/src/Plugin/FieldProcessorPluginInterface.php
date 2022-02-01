<?php

namespace Drupal\content_entity_clone\Plugin;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Interface for the content entity clone field processor plugins.
 */
interface FieldProcessorPluginInterface {

  /**
   * Get the plugin label.
   *
   * @return string
   *   The plugin label.
   */
  public function getPluginLabel();

  /**
   * Check if the plugin can be used for the given field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   *
   * @return bool
   *   TRUE if the plugin supports the given field type.
   */
  public function supports(FieldDefinitionInterface $field_definition);

  /**
   * Do something with the given field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   Field.
   */
  public function process(FieldItemListInterface $field);

}
