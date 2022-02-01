<?php

/**
 * @file
 * Content entity clone hooks.
 */

/**
 * Alter the field processor plugin definitions.
 *
 * @param array $definitions
 *   The field processor plugin definitions.
 *
 * @see Drupal\content_entity_clone\Annotation\ContentEntityCloneFieldProcessor
 * @see Drupal\content_entity_clone\Plugin\FieldProcessorPluginInterface
 */
function hook_content_entity_clone_field_processor_info_alter(array &$definitions) {
  // Add an uppercase field processor.
  //
  // This is just to illustrate the available properties. Processors should be
  // added in "Drupal\mymodule\Plugin\content_entity_clone\FieldProcessor" and
  // use the "@ContentEntityCloneFieldProcessor" plugin annotation.
  $definitions['uppercase'] = [
    "fieldTypes" => ['text', 'string'],
    "id" => "uppercase",
    "label" => t('Uppercase'),
    "description" => t('Make the field values uppercase.'),
    "class" => "Drupal\mymodule\Plugin\content_entity_clone\FieldProcessor\Uppercase",
    "provider" => "mymodule",
  ];
}
