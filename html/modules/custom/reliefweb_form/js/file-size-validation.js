/**
 * File size validation for file uploads.
 */
(function (Drupal) {

  'use strict';

  /**
   * Format file size into human-readable format.
   *
   * @param {number} bytes
   *   The file size in bytes.
   * @return {string}
   *   Human-readable file size.
   */
  function formatFileSize(bytes) {
    if (bytes === 0) {
      return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));

    return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + units[i];
  }

  /**
   * Validate file size when a file is selected.
   *
   * @param {Event} event
   *   The change event.
   */
  function validateFileSize(event) {
    const fileInput = event.target;
    const maxFileSize = parseInt(fileInput.getAttribute('data-max-filesize'), 10);
    const parentElement = fileInput.closest('.form-managed-file') || fileInput.parentNode;
    const managedFile = parentElement.classList.contains('form-managed-file');

    // Remove any previous error messages.
    var previousErrorElement;
    if (managedFile) {
      previousErrorElement = parentElement.parentNode.querySelector('.file-upload-js-error-size');
    }
    else {
      previousErrorElement = parentElement.querySelector('.file-upload-js-error-size');
    }
    if (previousErrorElement) {
      previousErrorElement.remove();
    }

    // Check if there are files and if we have a size limit.
    if (fileInput.files && fileInput.files.length > 0 && maxFileSize) {
      // Check each file.
      for (let i = 0; i < fileInput.files.length; i++) {
        const file = fileInput.files[i];

        if (file.size > maxFileSize) {
          // Stop all other event handlers from executing to prevent
          // unwanted submission due to autoupload for example.
          event.stopImmediatePropagation();

          // Create error message with proper Drupal styling.
          const errorMessage = Drupal.t('Unable to upload the file <em class="placeholder">@filename</em>. The file exceeds the maximum file size of <em class="placeholder">@maxsize</em>.', {
            '@filename': file.name,
            '@maxsize': formatFileSize(maxFileSize)
          });

          // Create error element with Drupal's error class.
          const errorElement = document.createElement('div');
          errorElement.className = 'form-item--error-message file-upload-js-error-size';
          errorElement.innerHTML = errorMessage;

          // Insert error after the file input.
          if (managedFile) {
            parentElement.parentNode.insertBefore(errorElement, parentElement.nextSibling);
          }
          else {
            parentElement.insertBefore(errorElement, fileInput.nextSibling);
          }

          // Clear the file input.
          fileInput.value = '';

          // Break the loop as we've already shown an error.
          break;
        }
      }
    }
  }

  /**
   * Behavior for file size validation.
   */
  Drupal.behaviors.reliefwebFormFileSizeValidation = {
    attach: function (context, settings) {
      // Find all file inputs with data-max-filesize attribute.
      const fileInputs = context.querySelectorAll('input[type="file"][data-max-filesize]:not([data-reliefweb-form-file-size-validation-processed])');

      fileInputs.forEach(function (fileInput) {
        fileInput.setAttribute('data-reliefweb-form-file-size-validation-processed', '');
        // Add the event listener.
        fileInput.addEventListener('change', validateFileSize);
      });
    }
  };

})(Drupal);
