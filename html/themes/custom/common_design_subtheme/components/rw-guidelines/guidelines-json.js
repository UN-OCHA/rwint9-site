/**
 * Guidelines tooltip handling.
 */
(function ($) {

  'use strict';

  Drupal.behaviors.Guidelines = {
    attach: function (context, settings) {
      // Skip for IE8 and lower.
      if (!document.addEventListener || !window.XMLHttpRequest) {
        return;
      }

      var t = Drupal.t;

      /**
       * Helpers.
       */

      // Trim a string.
      function trim(string) {
        if (typeof string.trim === 'function') {
          return string.trim();
        }
        return string.replace(/^[\s\uFEFF\xA0]+|[\s\uFEFF\xA0]+$/g, '');
      }

      /**
       * Main logic.
       */

      // Get the list of label/legend elements.
      function getLabelElements(form) {
        var labels = {};

        // Search for field labels to which attach the guideline toggler.
        var elements = form.querySelectorAll('fieldset[data-drupal-selector], div[data-drupal-selector]');
        for (var i = 0, l = elements.length; i < l; i++) {
          var element = elements[i];
          if (!element.querySelector('[name]')) {
            continue;
          }

          var fieldName = element.querySelector('[name]').name;
          if (fieldName.indexOf('[') > 0) {
            fieldName = fieldName.substring(0, fieldName.indexOf('['));
          }

          var label = '';
          var labelElement = element.querySelector('label, legend');
          if (labelElement) {
            label = trim(labelElement.textContent || labelElement.innerText);
          }

          labels[fieldName] = {
            element: element,
            label: label
          };
        }
        return labels;
      }

      // Remove guidelines elements from the form.
      function cleanGuidelines(form, popupOnly) {
        var selector = '.rw-guideline';

        if (!popupOnly) {
          selector += ', [data-guideline]';
        }
        var elements = form.querySelectorAll(selector);
        for (var i = elements.length - 1; i >= 0; i--) {
          var element = elements[i];
          element.parentNode.removeChild(element);
        }
      }

      // Hide the guideline popup.
      function hideGuideline(form) {
        cleanGuidelines(form, true);
      }

      // Show the guideline popup.
      function showGuideline(form, card) {
        hideGuideline(form);

        var close = document.createElement('button');
        close.setAttribute('type', 'button');
        close.setAttribute('value', 'close');
        close.appendChild(document.createTextNode(t('Close')));
        close.addEventListener('click', function (event) {
          hideGuideline(form);
        });

        form.addEventListener('keydown', function (event) {
          const key = event.key;
          if (key === 'Escape') {
            hideGuideline(form);
          }
        });

        var link = document.createElement('a');
        link.setAttribute('href', card.link);
        link.appendChild(document.createTextNode(card.name));

        var heading = document.createElement('h3');
        heading.appendChild(link);

        var content = document.createElement('div');
        content.className = 'rw-guideline__content';
        content.innerHTML = card.content;

        var container = document.createElement('div');
        container.appendChild(heading);
        container.appendChild(content);

        var popup = document.createElement('div');
        popup.className = 'rw-guideline';
        popup.appendChild(close);
        popup.appendChild(container);

        form.appendChild(popup);

        // Update the links in the popup to open in new pages/tabs.
        var links = popup.querySelectorAll('a');
        for (var i = 0, l = links.length; i < l; i++) {
          var link = links[i];
          link.setAttribute('target', '_blank');
          link.setAttribute('rel', 'noopener');
        }
      }

      // Prepare the guidelines card.
      function prepareGuidelines(data) {
        var guidelines = {};

        // Parse the guideline cards.
        if (Array.isArray(data)) {
          var cards = data;
          for (var i = 0, l = cards.length; i < l; i++) {
            var card = cards[i];
            // Remove blank image used for lazy loading.
            card.content = card.content.replace(/src="\/assets\/images\/blank\.gif"/g, '');
            // Add the card as guideline for each of the target fields.
            guidelines[card.label] = card;
          }
        }

        return guidelines;
      }

      // Add the guidelines to the page with a question mark icon to trigger the display.
      function setGuidelines(form, guidelines) {
        // Remove the existing guidelines from the page if any.
        cleanGuidelines(form);

        // Add a guideline toggler to each label element.
        var list = getLabelElements(form);
        for (var field in list) {
          if (list.hasOwnProperty(field) && guidelines.hasOwnProperty(field)) {
            var item = list[field];
            var element = item.element;
            var label = item.label;

            var button = document.createElement('button');
            button.setAttribute('type', 'button');
            button.setAttribute('data-guideline', field);
            button.appendChild(document.createTextNode(t('View guidelines for ' + label)));

            button.addEventListener('click', function (event) {
              showGuideline(form, guidelines[event.target.getAttribute('data-guideline')]);
            });

            // Drupal fields with either Legend or Label element ancestors.
            const labels = element.querySelectorAll('.form-wrapper .form-item > label');
            for (let i = 0; i < labels.length; i++) {
              labels[i].parentNode.insertBefore(button, labels[i].nextSibling);
            }
            const legends = element.querySelectorAll('[data-drupal-selector] > legend > span');
            for (let j = 0; j < legends.length; j++) {
              legends[j].appendChild(button);
            }
          }
        }
      }

      // Either load the guidelines from the guidelines site or use the cache.
      function loadGuidelines(form) {
        var entityId = form.getAttribute('data-guidelines-entity-type');
        var bundle = form.getAttribute('data-guidelines-entity-bundle');
        var url = '/guidelines/json/' + entityId + '/' + bundle;

        // Load the guidelines.
        var xhr = new XMLHttpRequest();
        // 10 seconds timeout.
        xhr.timeout = 10000;
        xhr.onreadystatechange = function () {
          if (xhr.readyState === 4 && xhr.status === 200) {
            try {
              setGuidelines(form, prepareGuidelines(JSON.parse(xhr.responseText)));
            }
            catch (error) {
              // Do nothing.
            }
          }
        };
        xhr.open('GET', url, true);
        xhr.send(null);
      }

      // Load the guidelines for the form.
      $('form[data-with-guidelines]', context).once('guidelines').each(function () {
        loadGuidelines(this);
      });
    }
  };
})(jQuery);
