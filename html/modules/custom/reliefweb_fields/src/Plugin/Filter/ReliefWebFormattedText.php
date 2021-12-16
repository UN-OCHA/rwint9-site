<?php

namespace Drupal\reliefweb_fields\Plugin\Filter;

use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Provides a filter flag for ReliefWeb formatted text manipulations.
 *
 * @Filter(
 *   id = "reliefweb_formatted_text",
 *   title = @Translation("ReliefWeb Formatted Text"),
 *   description = @Translation("Flag to enable ReliefWeb formatted text manipulations."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_MARKUP_LANGUAGE
 * )
 */
class ReliefWebFormattedText extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    // We don't do anything as this filter is just to enable the ReliefWeb
    // formatted text manipulations.
    return new FilterProcessResult($text);
  }

}
