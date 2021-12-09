<?php

/**
 * @file
 * Hooks.
 */

use Drupal\guidelines\Entity\Guideline;

/**
 * Alter description exposed by the JSON controller.
 *
 * @param array $description
 *   Description with basic fields present.
 * @param \Drupal\guidelines\Entity\Guideline $guideline
 *   The full guideline.
 * @param array $context
 *   Array containing entity type and bundle.
 *
 * @see src/Controller/GuidelineJsonController.php
 */
function hook_guideline_json_fields_alter(array &$description, Guideline $guideline, array $context) {
  // Change the link on nodes.
  if ($context['entity_type'] === 'node') {
    if ($guideline->hasField('field_short_link')) {
      $description['link'] = '/guidelines#' . $guideline->field_short_link->value;
    }
  }
}
