<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\datetime_range\Plugin\Field\FieldWidget\DateRangeDefaultWidget;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Plugin implementation of the 'reliefweb_daterange' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_daterange",
 *   label = @Translation("ReliefWeb Date Range"),
 *   field_types = {
 *     "daterange"
 *   }
 * )
 */
class ReliefWebDateRange extends DateRangeDefaultWidget {

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

  /**
   * {@inheritdoc}
   */
  public function validateStartEnd(array &$element, FormStateInterface $form_state, array &$complete_form) {
    // Nothing to do. It should be handle via validation constraints.
  }

}
