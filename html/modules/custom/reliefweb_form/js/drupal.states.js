/**
 * Extend Drupal states.
 **/
(function ($, Drupal) {

  'use strict';

  // Remove if one day https://www.drupal.org/project/drupal/issues/1149078
  // is added.
  if (Drupal &&
      Drupal.states &&
      Drupal.states.Dependent &&
      Drupal.states.Dependent.comparisons &&
      !Drupal.states.Dependent.comparisons.Array) {

    // Allow the comparison of multiple selected values in a select element.
    Drupal.states.Dependent.comparisons.Array = function (reference, value) {
      if (!$.isArray(reference) || !$.isArray(value)) {
        return false;
      }
      // Check if the selected values contain the reference values.
      for (var i = 0, l = reference.length; i < l; i++) {
        // We need to cast the reference value as a string because the values
        // returned by jQuery's val() are strings. This way we can use a strict
        // comparison.
        var ref = reference[i].toString();
        var ok = false;
        // Check if the reference value is in the selected values.
        for (var j = 0, m = value.length; j < m; j++) {
          if (value[j] === ref) {
            ok = true;
            break;
          }
        }
        // Exit early if the reference value is not in the selection.
        if (ok === false) {
          return false;
        }
      }
      return true;
    };
  }

})(jQuery, Drupal);
