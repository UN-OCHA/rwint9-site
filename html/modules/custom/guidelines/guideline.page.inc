<?php

/**
 * @file
 * Contains guideline.page.inc.
 *
 * Page callback for Guideline entities.
 */

use Drupal\Core\Render\Element;

/**
 * Prepares variables for Guideline templates.
 *
 * Default template: guideline.html.twig.
 *
 * @param array $variables
 *   An associative array containing:
 *   - elements: An associative array containing the user information and any
 *   - attributes: HTML attributes for the containing element.
 */
function template_preprocess_guideline(array &$variables) {
  // Fetch Guideline Entity Object.
  $guideline = $variables['elements']['#guideline'];

  // Helpful $content variable for templates.
  foreach (Element::children($variables['elements']) as $key) {
    $variables['content'][$key] = $variables['elements'][$key];
  }
}
