/**
 * Auto upload after choosing files.
 */
(function (Drupal) {

  'use strict';

  Drupal.behaviors.reliefwebFilesAutoupload = {
    attach: function (context, settings) {
      const elements = document.querySelectorAll('.rw-file-widget--simplified input.js-form-file:not([data-reliefweb-files-autoupload-processed])');
      for (let i = 0, l = elements.length; i < l; i++) {
        this.addFileAutoUpload(elements[i]);
      }
    },
    addFileAutoUpload: function (element) {
      element.setAttribute('data-reliefweb-files-autoupload-processed', '');

      const widget = element.closest('.rw-file-widget--simplified');
      widget.classList.add('rw-file-widget--autoupload');

      element.addEventListener('change', function (event) {
        const parent = event.target.closest('.rw-file-widget__add-more');
        if (parent) {
          const button = parent.querySelector('.form-submit');
          if (button) {
            const mouseEvent = new MouseEvent('mousedown', {
              bubbles: true,
              cancelable: true,
              view: window
            });
            button.dispatchEvent(mouseEvent);
          }
        }
      });
    }
  };

})(Drupal);
