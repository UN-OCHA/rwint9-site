/**
 * Add button to rename a file.
 */
(function (Drupal) {

  'use strict';

  Drupal.behaviors.reliefwebFilesRename = {
    attach: function (context, settings) {
      var elements = document.querySelectorAll('.rw-file-widget-item__information:not([data-reliefweb-files-rename-processed])');
      for (var i = 0, l = elements.length; i < l; i++) {
        this.addFileNameValidation(elements[i]);
      }
    },
    addFileNameValidation: function (wrapper) {
      var link = wrapper.querySelector('[data-file-link]');
      var input = wrapper.querySelector('[data-file-new-name]');

      // Reset the custom error message when typing.
      input.addEventListener('input', function (event) {
        input.setCustomValidity('');
      });

      // Set a custom error message when validating of the input's content.
      input.addEventListener('invalid', function (event) {
        if (input.validity.valueMissing) {
          input.setCustomValidity(Drupal.t('File name cannot be empty.'));
        }
        else if (input.validity.tooShort) {
          input.setCustomValidity(Drupal.t('File name too short.'));
        }
        else if (input.validity.tooLong) {
          input.setCustomValidity(Drupal.t('File name too long.'));
        }
        else if (!input.validity.valid) {
          input.setCustomValidity(Drupal.t('Invalid characters or file extension.'));
        }
      });

      // Update the file link when the input loses focus.
      input.addEventListener('blur', function (event) {
        if (input.reportValidity()) {
          link.textContent = link.textContent.trim().replace(/.+(\([^)]+\)+)$/, input.value.trim() + ' $1');
          // Remove any error message.
          input.classList.remove('error');
          input.parentNode.classList.remove('form-item--error');
          var error = input.parentNode.querySelector('.form-item--error-message');
          error.parentNode.removeChild(error);
        }
      });

      wrapper.setAttribute('data-reliefweb-files-rename-processed', '');
    }
  };

})(Drupal);
