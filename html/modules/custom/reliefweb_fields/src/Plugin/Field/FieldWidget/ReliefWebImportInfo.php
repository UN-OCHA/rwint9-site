<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'reliefweb_import_info' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_import_info",
 *   module = "reliefweb_fields",
 *   label = @Translation("Relief web import info"),
 *   field_types = {
 *     "reliefweb_import_info"
 *   }
 * )
 */
class ReliefWebImportInfo extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element += [
      '#type' => 'fieldset',
    ];

    $element['feed_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Feed Url'),
      '#default_value' => isset($items[$delta]->feed_url) ? $items[$delta]->feed_url : NULL,
      '#description' => $this->t('For automatic job imports. This URL is a feed url of an updated list of the job offers for the organization. It must start with "https://" or "http://".'),
    ];

    $element['base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base Url'),
      '#default_value' => isset($items[$delta]->base_url) ? $items[$delta]->base_url : NULL,
      '#description' => $this->t('Job postings published by this organization must match this URL. It must start with "https://" or "http://".'),
    ];

    $element['uid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User Id'),
      '#default_value' => isset($items[$delta]->uid) ? $items[$delta]->uid : NULL,
      '#description' => $this->t('ID of user for automated job imports from this source.'),
    ];

    return $element;
  }

}
