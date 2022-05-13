<?php

namespace Drupal\reliefweb_files\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

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
    $list = [];
    foreach ($items as $item) {
      $url = $item->getFileUrl();
      if (!empty($url)) {
        $languages = reliefweb_files_get_languages();

        $version = '';
        if (isset($languages[$item->getFileLanguage()])) {
          $version = $this->t('@language version', [
            '@language' => $languages[$item->getFileLanguage()],
          ]);
        }

        $list[] = [
          'item' => $item,
          'url' => $url->toString(),
          'name' => $item->getFileName(),
          'preview' => $item->renderPreview('small', TRUE),
          'label' => $item->getFileName(),
          'description' => '(' . implode(' | ', array_filter([
            mb_strtoupper($item->getFileExtension()),
            format_size($item->getFileSize()),
            implode(' - ', array_filter([
              $item->getFileDescription(),
              $version,
            ])),
          ])) . ')',
        ];
      }
    }

    // Reverse the links to have the most recent a the beginning.
    return empty($list) ? [] : [
      '#theme' => 'reliefweb_file_list',
      '#list' => $list,
    ];
  }

}
