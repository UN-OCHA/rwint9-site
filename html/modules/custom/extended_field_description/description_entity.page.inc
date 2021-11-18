<?php

/**
 * @file
 * Contains description_entity.page.inc.
 *
 * Page callback for Description entity entities.
 */

use Drupal\Core\Render\Element;

/**
 * Prepares variables for Description entity templates.
 *
 * Default template: description_entity.html.twig.
 *
 * @param array $variables
 *   An associative array containing:
 *   - elements: An associative array containing the user information and any
 *   - attributes: HTML attributes for the containing element.
 */
function template_preprocess_description_entity(array &$variables) {
  // Fetch DescriptionEntity Entity Object.
  $description_entity = $variables['elements']['#description_entity'];

  // Helpful $content variable for templates.
  foreach (Element::children($variables['elements']) as $key) {
    $variables['content'][$key] = $variables['elements'][$key];
  }
}
