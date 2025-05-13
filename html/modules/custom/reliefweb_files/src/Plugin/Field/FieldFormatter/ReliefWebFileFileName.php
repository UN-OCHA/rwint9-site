<?php

namespace Drupal\reliefweb_files\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'reliefweb_file' formatter.
 *
 * @FieldFormatter(
 *   id = "reliefweb_file_file_name",
 *   label = @Translation("ReliefWeb File - File name"),
 *   field_types = {
 *     "reliefweb_file"
 *   }
 * )
 */
class ReliefWebFileFileName extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    foreach ($items as $item) {
      $elements[] = ['#markup' => $item->getFileName()];
    }
    return $elements;
  }

}
