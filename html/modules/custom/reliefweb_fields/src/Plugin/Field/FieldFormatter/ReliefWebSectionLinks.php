<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'reliefweb_section_links' formatter.
 *
 * @FieldFormatter(
 *   id = "reliefweb_section_links",
 *   label = @Translation("Relief web section links"),
 *   field_types = {
 *     "reliefweb_section_links"
 *   }
 * )
 */
class ReliefWebSectionLinks extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {

    // Simple link.
    // @todo output river.
    $links = [];
    foreach ($items as $item) {
      $links[] = [
        'title' => $item->get('title')->getValue(),
        'url' => $item->get('url')->getValue(),
      ];
    }

    // Reverse the links to have the most recent a the beginning.
    return [
      '#theme' => 'links',
      '#links' => array_reverse($links),
    ];
  }

}
