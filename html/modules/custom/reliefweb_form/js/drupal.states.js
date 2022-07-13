/**
 * Extend Drupal states.
 **/
(function ($, Drupal) {

  'use strict';

  if (Drupal && Drupal.states) {
    // Remove if one day https://www.drupal.org/project/drupal/issues/1149078
    // is added.
    if (Drupal.states.Dependent &&
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

    // Extend the behavior for the required state to remove any optional marker.
    $(document).on('state:required', function (event) {
      if (event.trigger) {
        var target = event.target;
        var type = target.nodeName;
        if ((type === 'BUTTON' || type === 'INPUT' || type === 'SELECT' || type === 'TEXTAREA') && target.id) {
          target = document.querySelector('label[for="' + target.id + '"]');
        }

        if (!event.value) {
          var $elements = target.hasAttribute('data-optional') ? $(target) : $(target).find('[data-optional]');
          // Add the optional mark if not already there.
          $elements.each(function () {
            var $label = $(this);
            if (!$label.find('.form-optional').length) {
              $label.append('<span class="form-optional">' + $label.attr('data-optional') + '</span>');
            }
          });
        }
        else {
          // Store the text of the optional marker to preseve the translated
          // string so that it can be re-used if the marker is added back.
          $(target).find('.form-optional').each(function () {
            var $element = $(this);
            $element.parent().attr('data-optional', $element.text());
            $element.remove();
          });
        }
      }
    });
  }

})(jQuery, Drupal);
