<?php

namespace Drupal\reliefweb_files\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'reliefweb_file' formatter.
 *
 * @FieldFormatter(
 *   id = "reliefweb_file_file_url",
 *   label = @Translation("ReliefWeb File - File URL"),
 *   field_types = {
 *     "reliefweb_file"
 *   }
 * )
 */
class ReliefWebFileFileUrl extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    foreach ($items as $item) {
      $elements[] = ['#markup' => $item->getFileUrl()?->toString()];
    }
    return $elements;
  }

}
