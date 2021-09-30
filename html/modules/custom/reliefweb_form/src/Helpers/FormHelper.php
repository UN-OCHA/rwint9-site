<?php

namespace Drupal\reliefweb_form\Helpers;

/**
 * Form helper with methods to ease form manipulation.
 */
class FormHelper {

  /**
   * Remove some values from a field's available options in a form.
   *
   * @param array $form
   *   Form to alter.
   * @param string $field
   *   Name of the field to alter.
   * @param array $values
   *   List of values to remove from the options.
   */
  public static function removeOptions(array &$form, $field, array $values) {
    if (!isset($form[$field]['widget']['#options']) || empty($values)) {
      return;
    }

    $widget = &$form[$field]['widget'];

    // Remove the term id from the field options.
    foreach ($values as $value) {
      unset($widget['#options'][$value]);
    }

    // Ensure the term id is also removed from the list of selected values.
    if (isset($widget['#default_value'])) {
      if (is_array($widget['#default_value'])) {
        foreach ($values as $value) {
          unset($widget['#default_value'][$value]);
        }
      }
      elseif ($widget['#default_value'] == $value) {
        $widget['#default_value'] = NULL;
      }
    }
  }

  /**
   * Order options by value.
   *
   * @param array $form
   *   Form to alter.
   * @param string $field
   *   Name of the field to alter.
   * @param bool $descending
   *   If TRUE sort by value descending.
   */
  public static function orderOptionsByValue(array &$form, $field, $descending = FALSE) {
    if (isset($form[$field]['widget']['#options'])) {
      if ($descending) {
        krsort($form[$field]['widget']['#options']);
      }
      else {
        ksort($form[$field]['widget']['#options']);
      }
    }
  }

}
