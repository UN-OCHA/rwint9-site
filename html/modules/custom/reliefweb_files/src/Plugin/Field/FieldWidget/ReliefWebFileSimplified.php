<?php

namespace Drupal\reliefweb_files\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'reliefweb_file_simplified' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_file_simplified",
 *   label = @Translation("ReliefWeb File Simplified"),
 *   field_types = {
 *     "reliefweb_file"
 *   }
 * )
 */
class ReliefWebFileSimplified extends ReliefWebFile {

  /**
   * {@inheritdoc}
   */
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {
    $elements = parent::formMultipleElements($items, $form, $form_state);
    $elements['add_more']['#not_required'] = FALSE;
    if (isset($elements['add_more']['files']['#description'])) {
      $description = $elements['add_more']['files']['#description'];
      unset($elements['add_more']['files']['#description']);
      $elements['add_more']['description'] = [
        '#type' => 'item',
        '#description' => $description,
      ];
    }
    $elements['#theme'] = 'reliefweb_file_widget__simplified';
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $element['_new_file_name']['#access'] = FALSE;
    $element['description']['#access'] = FALSE;
    $element['language']['#access'] = FALSE;
    $element['preview']['#access'] = FALSE;
    $element['preview_page']['#access'] = FALSE;
    $element['preview_rotation']['#access'] = FALSE;
    $element['operations']['#title'] = $this->t('Delete or Replace');
    $element['#theme'] = 'reliefweb_file_widget_item__simplified';
    return $element;
  }

}
