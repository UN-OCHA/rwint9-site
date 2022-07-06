/**
 * Enhanced form handling.
 */
(function (Drupal) {

  'use strict';

  /**
   * Behavior for the main reliefweb form logic.
   */
  Drupal.behaviors.reliefwebFormMain = {
    attach: function (context, settings) {
      // Check support.
      if (!document.addEventListener) {
        return;
      }

      // Prevent form submission when "enter" is pressed outside of a textarea
      // or on a non submit focused button.
      // @todo review if that's still necessary.
      function disableUnwantedFormSubmission(form) {
        form.addEventListener('keydown', function (event) {
          var target = event.target;
          var disable = true;
          if (event.key === 'Enter') {
            if (target.nodeName === 'TEXTAREA') {
              disable = false;
            }
            else if (target.nodeName === 'BUTTON' && target.getAttribute('type') === 'submit') {
              disable = false;
            }
            if (disable) {
              event.preventDefault();
              return false;
            }
          }
        });
      }

      // Enhance the forms.
      var forms = context.querySelectorAll('form[data-enhanced]:not([data-enhanced-processed])');
      for (var i = 0, l = forms.length; i < l; i++) {
        var form = forms[i];
        form.setAttribute('data-enhanced-processed', '');
        disableUnwantedFormSubmission(form);
      }
    }
  };

})(Drupal);
