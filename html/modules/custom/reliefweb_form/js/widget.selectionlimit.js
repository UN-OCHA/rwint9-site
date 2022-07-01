/**
 * Selection limit widget handling.
 */
(function (Drupal) {

  'use strict';

  /**
   * Behavior for the main reliefweb form logic.
   */
  Drupal.behaviors.reliefwebFormSelectionLimit = {
    attach: function (context, settings) {
      // Check if the number of checked options in a checkboxes element reached
      // the limit and disable the other options.
      function checkSelectionLimit(element, limit) {
        var inputs = element.getElementsByTagName('input');
        var checked = 0;
        // Count the number of selected checkboxes.
        for (var i = 0, l = inputs.length; i < l; i++) {
          var input = inputs[i];
          if (input.type && input.type === 'checkbox' && input.checked) {
            checked++;
          }
        }

        // Disable all the other checkboxes if we reached the limit.
        var disabled = checked >= limit;
        for (var i = 0, l = inputs.length; i < l; i++) {
          var input = inputs[i];
          if (input.type && input.type === 'checkbox' && !input.checked) {
            if (disabled) {
              input.setAttribute('disabled', 'disabled');
            }
            else {
              input.removeAttribute('disabled');
            }
          }
        }
      }

      // Enable the selection limit for the a checkbox field.
      function enableSelectionLimit(element) {
        // Get the limit and set the handler.
        var limit = parseInt(element.getAttribute('data-with-selection-limit'), 10);
        if (limit > 1) {
          element.addEventListener('change', function (event) {
            var target = event.target;
            if (target && target.getAttribute('type') === 'checkbox') {
              checkSelectionLimit(element, limit);
            }
          });

          // Initial state.
          checkSelectionLimit(element, limit);

          // Listen for disabled changes.
          var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
              if (mutation.attributeName === 'disabled') {
                checkSelectionLimit(element, limit);
              }
            });
          });

          // Only listen to attribute changes.
          var config = { attributes: true };

          // Start observing.
          observer.observe(element, config);
        }
      }

      // Enable selection limit on relevant checkboxes fields.
      var elements = context.querySelectorAll('fieldset[data-with-selection-limit]:not([data-with-selection-limit-processed])');
      for (var i = 0, l = elements.length; i < l; i++) {
        var element = elements[i];
        element.setAttribute('data-with-selection-limit-processed', '');
        enableSelectionLimit(element);
      }
    }
  };

})(Drupal);
