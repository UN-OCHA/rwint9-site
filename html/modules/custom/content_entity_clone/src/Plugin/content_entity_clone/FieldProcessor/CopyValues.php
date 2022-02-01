<?php

namespace Drupal\content_entity_clone\Plugin\content_entity_clone\FieldProcessor;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\content_entity_clone\Plugin\FieldProcessorPluginBase;

/**
 * Simple field processor that copies the field values in the entity clone.
 *
 * @ContentEntityCloneFieldProcessor(
 *   id = "copy_values",
 *   label = @Translation("Copy values"),
 *   description = @Translation("Copy a field's values."),
 * )
 */
class CopyValues extends FieldProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function process(FieldItemListInterface $field) {
    // No processing to do.
  }

}
