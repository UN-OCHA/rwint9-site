<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'reliefweb_post_api_key' formatter.
 *
 * @FieldFormatter(
 *   id = "reliefweb_post_api_key",
 *   label = @Translation("ReliefWeb POST API key"),
 *   field_types = {
 *     "reliefweb_post_api_key"
 *   }
 * )
 */
class ApiKeyFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $elements[$delta] = [
        '#type' => 'inline_template',
        '#template' => '{{ value|nl2br }}',
        '#context' => [
          'value' => empty($item->value) ? $this->t('API key missing') : $this->t('API key exists'),
        ],
      ];
    }

    return $elements;
  }

}
