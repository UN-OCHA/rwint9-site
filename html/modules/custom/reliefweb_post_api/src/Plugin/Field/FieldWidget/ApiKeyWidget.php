<?php

namespace Drupal\reliefweb_post_api\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'reliefweb_post_api_key' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_post_api_key",
 *   label = @Translation("ReliefWeb POST API key"),
 *   field_types = {
 *     "reliefweb_post_api_key"
 *   }
 * )
 */
class ApiKeyWidget extends StringTextfieldWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['value'] = $element + [
      '#type' => 'textfield',
      '#default_value' => NULL,
      '#size' => $this->getSetting('size'),
      '#placeholder' => $this->getSetting('placeholder'),
      '#maxlength' => $this->getFieldSetting('max_length'),
    ];

    if ($form_state->getFormObject()?->getEntity()?->isNew() !== TRUE) {
      $element['value']['#description'] = $this->t('@description. <strong>Leave blank to keep the existing API key</strong>.', [
        '@description' => $element['value']['#description'],
      ]);
    }

    return $element;
  }

}
