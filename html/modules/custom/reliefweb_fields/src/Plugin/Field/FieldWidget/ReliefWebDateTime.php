<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\datetime\Plugin\Field\FieldWidget\DateTimeDefaultWidget;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Plugin implementation of the 'reliefweb_datetime' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_datetime",
 *   label = @Translation("ReliefWeb Date and Time"),
 *   field_types = {
 *     "datetime"
 *   }
 * )
 */
class ReliefWebDateTime extends DateTimeDefaultWidget {

  /**
   * {@inheritdoc}
   */
  public function errorElement(array $element, ConstraintViolationInterface $error, array $form, FormStateInterface $form_state) {
    $path_parts = explode('.', $error->getPropertyPath());
    if (is_numeric($path_parts[0])) {
      if (isset($element['#delta']) && $element['#delta'] == $path_parts[0]) {
        $path_parts = array_slice($path_parts, 1);
      }
    }
    $error_element = NestedArray::getValue($element, $path_parts);
    return $error_element ?: $element;
  }

}
