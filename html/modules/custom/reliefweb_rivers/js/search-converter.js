/* global once */
(function () {
  'use strict';

  Drupal.behaviors.searchConverter = {
    attach: function (context, settings) {
      once('rw-search-converter__results', '.rw-search-converter__results', context).forEach(element => {
        // Set up copy to clipboard buttons.
        this.copyToClipboard();
      });
    },

    /**
     * Copy to Clipboard
     *
     * Needs to be run every time the form reloads. It will find all the copy
     * buttons and attach an event listener that copies individual answers to
     * the user's clipboard.
     *
     * Adapted from CD Social Links in CD v9.4.0
     *
     * @see https://github.com/UN-OCHA/common_design/blob/v9.4.0/libraries/cd-social-links/cd-social-links.js
     */
    copyToClipboard: function () {
      // Collect all "copy" URL buttons.
      const copyButtons = document.querySelectorAll('.results-button--copy');

      // Process links so they copy URL to clipboard.
      copyButtons.forEach(function (el) {

        // Event listener so people can copy to clipboard.
        //
        // As of hook_update_10005() the button is hooked up to the Drupal form
        // so that it can submit and record that the copy button was pressed.
        // Drupal handles displaying success feedback to the user. This code is
        // still showing feedback in case of failure to copy.
        el.addEventListener('mousedown', function (ev) {
          var tempInput = document.createElement('input');
          var textToCopy = document.querySelector('#' + el.getAttribute('data-to-copy')).innerText.replaceAll('<br>', '\n');
          var status = el.parentNode.querySelector('[role=status]');
          var message = Drupal.t('Copied');

          try {
            if (navigator.clipboard) {
              // Easy way possible?
              navigator.clipboard.writeText(textToCopy);
            }
            else {
              // Legacy method
              document.body.appendChild(tempInput);
              tempInput.value = textToCopy;
              tempInput.select();
              document.execCommand('copy');
              document.body.removeChild(tempInput);
            }
          }
          catch (err) {
            // Log errors to console.
            console.error(err);
            var message = Drupal.t('Unable to copy');
          }

          // Show user feedback and remove after some time.
          status.removeAttribute('hidden');
          status.innerText = message;

          // Hide message.
          setTimeout(function () {
            status.setAttribute('hidden', '');
          }, 2500);
          // After message is hidden, remove status contents.
          setTimeout(function () {
            status.innerText = '';
          }, 3000);

        });
      });
    },
  };
})();
