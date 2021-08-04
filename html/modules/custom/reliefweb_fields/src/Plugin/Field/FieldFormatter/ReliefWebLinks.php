<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Url;

/**
 * Plugin implementation of the 'reliefweb_links' formatter.
 *
 * @FieldFormatter(
 *   id = "reliefweb_links",
 *   label = @Translation("ReliefWeb Links formatter"),
 *   field_types = {
 *     "reliefweb_links"
 *   }
 * )
 */
class ReliefWebLinks extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $settings = $this->fieldDefinition->getSettings();
    $internal = !empty($settings['internal']);

    // Simple link for active elements.
    $links = [];
    foreach ($items as $item) {
      $active = $item->get('active')->getValue();
      if (!empty($active)) {
        $links[] = [
          'title' => $item->get('title')->getValue(),
          'url' => Url::fromUri(($internal ? 'internal:' : '') . $item->get('url')->getValue()),
        ];
      }
    }

    // Reverse the links to have the most recent a the beginning.
    return [
      '#theme' => 'links',
      '#links' => array_reverse($links),
    ];
  }

}
