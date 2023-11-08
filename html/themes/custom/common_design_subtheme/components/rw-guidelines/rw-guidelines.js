/* global once */
/**
 * Guidelines tooltip handling.
 */
(function (Drupal, once) {

  'use strict';

  Drupal.behaviors.Guidelines = {
    attach: function (context, settings) {
      // Skip for IE8 and lower.
      if (!document.addEventListener || !window.XMLHttpRequest) {
        return;
      }

      var t = Drupal.t;

      /**
       * Main logic.
       */

      // Remove guidelines elements from the form.
      function cleanGuidelines(form, removeButtons, removeLabelClasses) {
        var selector = '.rw-guideline';

        if (removeButtons) {
          selector += ', .rw-guideline__open-button';
        }
        var elements = form.querySelectorAll(selector);
        for (var i = elements.length - 1; i >= 0; i--) {
          var element = elements[i];
          element.parentNode.removeChild(element);
        }

        if (removeLabelClasses) {
          var elements = form.querySelectorAll('.rw-guideline__label');
          for (var i = elements.length - 1; i >= 0; i--) {
            elements[i].classList.remove('.rw-guideline__label');
          }
        }
      }

      // Hide the guideline popup.
      function hideGuideline(form) {
        cleanGuidelines(form, false, false);
      }

      // Show the guideline popup.
      function showGuideline(form, card) {
        hideGuideline(form);

        var close = document.createElement('button');
        close.setAttribute('type', 'button');
        close.setAttribute('value', 'close');
        close.classList.add('rw-guideline__close-button');
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
        link.appendChild(document.createTextNode(card.title));

        var heading = document.createElement('h3');
        heading.classList.add('rw-guideline__heading');
        heading.appendChild(link);

        var content = document.createElement('div');
        content.classList.add('rw-guideline__content');
        content.innerHTML = card.content;

        var container = document.createElement('div');
        container.classList.add('rw-guideline__container');
        container.appendChild(heading);
        container.appendChild(content);

        var popup = document.createElement('div');
        popup.classList.add('rw-guideline');
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
        cleanGuidelines(form, true, true);

        for (var fieldName in guidelines) {
          if (guidelines.hasOwnProperty(fieldName)) {
            var selector = 'edit-' + fieldName.replaceAll('_', '-');

            var field = document.querySelector('[data-drupal-selector="' + selector + '"], [name^="' + fieldName + '["]');
            if (!field) {
              continue;
            }

            // Try to get the classic label associated with the field.
            var label = null;
            if (field.nodeName === 'FIELDSET') {
              label = field.querySelector('.fieldset-legend');
            }
            else if (field.hasAttribute('id')) {
              // Compatibility with the reliefweb_form scripts.
              var id = field.getAttribute('id').replace(/--element$/, '');
              label = document.querySelector('label[for="' + id + '"]');
            }

            // If the label is visually hidden, we need to find another one.
            if (label && isLabelHidden(label)) {
              label = null;

              // Try to find a suitable label or legend among the field's
              // parents.
              var parent = field.parentNode;
              while (parent && parent !== form) {
                var candidate = null;
                if (parent.hasAttribute('data-drupal-selector') && parent.getAttribute('data-drupal-selector') === selector + '-wrapper') {
                  candidate = parent.querySelector('.fieldset-legend, label');
                }
                else if (parent.nodeName === 'FIELDSET') {
                  candidate = parent.querySelector('.fieldset-legend, legend');
                }
                if (candidate && !isLabelHidden(candidate)) {
                  label = candidate;
                  break;
                }
                parent = parent.parentNode;
              }
            }

            if (label) {
              addGuidelineOpenButton(fieldName, label, form, guidelines[fieldName]);
            }
          }
        }
      }

      // Check if a label/legend element is visually hidden.
      function isLabelHidden(label) {
        if (label) {
          if (label.classList.contains('visually-hidden')) {
            return true;
          }
          else if (label.classList.contains('fieldset-legend')) {
            return label.parentNode.classList.contains('visually-hidden');
          }
        }
        return false;
      }

      // Add the button to open the guideline popup next to a field's label.
      function addGuidelineOpenButton(fieldName, label, form, guideline) {
        var labelText = label.textContent.trim();

        var button = document.createElement('button');
        button.setAttribute('type', 'button');
        button.setAttribute('data-guideline', fieldName);
        button.classList.add('rw-guideline__open-button');
        button.appendChild(document.createTextNode(t('View guidelines for ' + labelText)));

        button.addEventListener('click', function (event) {
          showGuideline(form, guideline);
        });

        // Wrap the legend into a span so we can have consistent behavior.
        if (label.nodeName === 'LEGEND') {
          var container = document.createElement('span');
          // We also need to move the form-required class to the span so that
          // the mark appears before the guidelines button.
          if (label.classList.contains('form-required')) {
            container.classList.add('form-required');
            label.classList.remove('form-required');
          }
          while (label.firstChild) {
            container.appendChild(label.firstChild);
          }
          label.appendChild(container);
          label = container;
        }

        label.classList.add('rw-guideline__label');
        label.parentNode.insertBefore(button, label.nextSibling);
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
      once('guidelines', 'form[data-with-guidelines]', context).forEach(function (element) {
        loadGuidelines(element);
      });
    }
  };
})(Drupal, once);
