/**
 * Term description tooltip handling.
 */
 (function ($) {

  'use strict';

  Drupal.behaviors.TermDescriptions = {
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
      function getWrapperElements(form) {
        var wrappers = {};

        // Search for wrappers containing data-term-description.
        var elements = form.querySelectorAll('fieldset');
        for (var i = 0, li = elements.length; i < li; i++) {
          var element = elements[i];
          if (element.querySelectorAll('[data-term-description]').length == 0) {
            continue;
          }

          if (!element.querySelector('[name]')) {
            continue;
          }

          // Mark form as having items.
          form.setAttribute('data-with-term-description', '');

          var fieldName = element.querySelector('[name]').name;
          if (fieldName.indexOf('[') > 0) {
            fieldName = fieldName.substring(0, fieldName.indexOf('['));
          }

          var label = '';
          var labelElement = element.querySelector('label, legend');
          if (labelElement) {
            label = trim(labelElement.textContent || labelElement.innerText);
          }

          var terms = [];
          var items = element.querySelectorAll('[data-term-description]');
          for (var j = 0, lj = items.length; j < lj; j++) {
            var item = items[j];
            var labelElement = item.querySelector('label');
            terms.push({
              label: trim(labelElement.textContent || labelElement.innerText),
              description: item.getAttribute('data-term-description'),
              for: labelElement.getAttribute('for')
            })
          }

          wrappers[fieldName] = {
            element: element,
            label: label,
            terms: terms
          };
        }
        return wrappers;
      }

      // Remove term description elements from the form.
      function cleanTermDescriptions(form, popupOnly) {
        var selector = '.with-term-description';
        if (!popupOnly) {
          selector += ', [data-with-term-description]';
        }
        var elements = form.querySelectorAll(selector);
        for (var i = elements.length - 1; i >= 0; i--) {
          var element = elements[i];
          element.parentNode.removeChild(element);
        }
      }

      // Hide the popup.
      function hideTermDescriptions(form) {
        cleanTermDescriptions(form, true);
      }

      // Show the popup.
      function showTermDescriptions(form, data) {
        hideTermDescriptions(form);

        var close = document.createElement('button');
        close.setAttribute('type', 'button');
        close.setAttribute('value', 'close');
        close.appendChild(document.createTextNode(t('Close')));
        close.addEventListener('click', function (event) {
          hideTermDescriptions(form);
        });

        form.addEventListener('keydown', function(event) {
          const key = event.key;
          if (key === "Escape") {
            hideTermDescriptions(form);
          }
        });

        var heading = document.createElement('h3');
        heading.innerText = data.label;

        var content = document.createElement('div');
        content.className = 'content';

        var dl = document.createElement('dl');
        for (var i = 0, l = data.terms.length; i < l; i++) {
          var term = data.terms[i];

          var label = document.createElement('label');
          label.setAttribute('for', term.for);
          label.innerText = term.label;

          var dt = document.createElement('dt');
          dt.appendChild(label);
          dl.appendChild(dt);

          var dd = document.createElement('dd');
          dd.innerText = term.description;
          dl.appendChild(dd);
        }

        content.appendChild(dl);

        var container = document.createElement('div');
        container.appendChild(heading);
        container.appendChild(content);

        var popup = document.createElement('div');
        popup.className = 'with-term-description';
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

      // Add the descriptions to the page with a question mark icon to trigger the display.
      function setTermDescriptions(form) {
        // Remove the existing ones from the page if any.
        cleanTermDescriptions(form);

        // Add a toggler to each label element.
        var list = getWrapperElements(form);
        for (var field in list) {
          if (list.hasOwnProperty(field)) {
            let item = list[field];
            let element = item.element;
            let label = item.label;

            let button = document.createElement('button');
            button.setAttribute('type', 'button');
            button.setAttribute('data-with-term-description', field);
            button.appendChild(document.createTextNode(t('View descriptions for ' + label)));

            button.addEventListener('click', function (event) {
              showTermDescriptions(form, item);
            });

            if (element.nodeName === 'LABEL') {
              element.parentNode.insertBefore(button, element.nextSibling);
            }
            else {
              element.appendChild(button);
            }
          }
        }
      }

      let forms = document.querySelectorAll('form');
      for (var i = 0, li = forms.length; i < li; i++) {
        let form = forms[i];

        // Skip when guidelines are active.
        if (form.hasAttribute('data-with-guidelines')) {
          continue;
        }

        if (form.querySelectorAll('[data-term-description]').length > 0) {
          setTermDescriptions(form);
        }
      }
    }
  };
})(jQuery);
