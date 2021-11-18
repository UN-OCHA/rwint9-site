<?php

namespace Drupal\reliefweb_docstore\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Url;

/**
 * Plugin implementation of the 'reliefweb_file' formatter.
 *
 * @FieldFormatter(
 *   id = "reliefweb_file",
 *   label = @Translation("ReliefWeb File"),
 *   field_types = {
 *     "reliefweb_file"
 *   }
 * )
 */
class ReliefWebFile extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    // Simple link for active elements.
    $links = [];
    foreach ($items as $item) {
      // DEBUG.
      $url = 'https://docstore.test/files/' . $item->get('uuid')->getValue();

      $links[] = [
        'title' => $item->get('file_name')->getValue(),
        'url' => Url::fromUri($url),
      ];
    }

    // Reverse the links to have the most recent a the beginning.
    return [
      '#theme' => 'links',
      '#links' => array_reverse($links),
    ];
  }

}
