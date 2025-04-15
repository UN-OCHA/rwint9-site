<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'reliefweb_text_with_summary' formatter.
 *
 * @FieldFormatter(
 *   id = "reliefweb_text_with_summary",
 *   label = @Translation("ReliefWeb Text with Summary"),
 *   field_types = {
 *     "text_with_summary",
 *   }
 * )
 */
class ReliefWebTextWithSummary extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    // The ProcessedText element already handles cache context & tag bubbling.
    // @see \Drupal\filter\Element\ProcessedText::preRenderText()
    foreach ($items as $delta => $item) {
      $element = [];

      // @todo add settings for this formatted to decide whether to display
      // the text or the summary.
      if (!empty($item->value)) {
        $element['#text'] = [
          '#type' => 'processed_text',
          '#text' => $item->value,
          '#format' => $item->format,
          '#langcode' => $item->getLangcode(),
        ];
      }

      if (!empty($item->summary)) {
        $element['#summary'] = [
          '#type' => 'processed_text',
          '#text' => strip_tags($item->summary),
          // We assume markdown for the summary.
          '#format' => 'markdown',
          '#langcode' => $item->getLangcode(),
        ];
      }

      if (!empty($element)) {
        $elements[$delta] = [
          '#theme' => 'reliefweb_text_with_summary',
        ] + $element;
      }
    }

    return $elements;
  }

}
