<?php

namespace Drupal\reliefweb_guidelines\Plugin\Filter;

use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Provides a filter to convert guideline links.
 *
 * Note: this should be run before the markdown filter.
 *
 * @Filter(
 *   id = "filter_guideline_link",
 *   title = @Translation("Guideline Link Filter"),
 *   description = @Translation("Convert guideline links"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_MARKUP_LANGUAGE,
 * )
 */
class GuidelineLinkFilter extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    // Convert links in the form `/guidelines/abcdefgh` to
    // `[](/guidelines/abcdefgh)` so they can be converted to links by the
    // markdown filter.
    $text = preg_replace('#(^|\s)(/guideline/[0-9a-zA-Z]{8})([^0-9a-zA-Z]|$)#', '$1[$2]($2)$3', $text);

    $result = new FilterProcessResult();
    $result->setProcessedText($text);
    return $result;
  }

}
