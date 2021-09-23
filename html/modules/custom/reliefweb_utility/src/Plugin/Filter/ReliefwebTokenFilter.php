<?php

namespace Drupal\reliefweb_utility\Plugin\Filter;

use Drupal\token_filter\Plugin\Filter\TokenFilter;

/**
 * Provides a filter that replaces some tokens with their values.
 *
 * @Filter(
 *   id = "reliefweb_token_filter",
 *   title = @Translation("RW: Replaces some tokens with their values"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 *   settings = {
 *     "replace_empty" = FALSE
 *   }
 * )
 */
class ReliefwebTokenFilter extends TokenFilter {

  /**
   * Token callback to limit allowed tokens.
   */
  public static function tokenCallback(&$replacements, $data, $options, $bubbleable_metadata) {
    foreach ($replacements as $key => $replacement) {
      if (strpos($key, '[disaster-map') === FALSE) {
        unset($replacements[$key]);
      }
    }
  }

}
