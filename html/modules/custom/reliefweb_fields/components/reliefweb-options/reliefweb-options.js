/**
 * Term description tooltip handling.
 */
(function (Drupal) {

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
        var elements = form.querySelectorAll('fieldset.data-with-term-descriptions');
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
            });
          }

          // Attributes to keep.
          let attributes = [];
          if (element.hasAttribute('data-with-selection-limit')) {
            attributes.push({
              name: 'data-with-selection-limit',
              value: element.getAttribute('data-with-selection-limit')
            });
          }

          wrappers[fieldName] = {
            element: element,
            label: label,
            terms: terms,
            attributes: attributes
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
        close.classList.add('rw-options__close-button');
        close.appendChild(document.createTextNode(t('Close')));
        close.addEventListener('click', function (event) {
          hideTermDescriptions(form);
        });

        form.addEventListener('keydown', function (event) {
          const key = event.key;
          if (key === 'Escape') {
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

          var source = document.getElementById(term.for);
          var checkbox = document.createElement('input');
          checkbox.setAttribute('type', source.getAttribute('type'));
          if (source.checked) {
            checkbox.setAttribute('checked', 'checked');
          }
          checkbox.setAttribute('name', 'shadow-' + source.getAttribute('name'));
          checkbox.setAttribute('id', 'shadow-' + term.for);
          checkbox.setAttribute('data-for', term.for);
          checkbox.addEventListener('change', function (e) {
            let real = document.getElementById(this.getAttribute('data-for'));
            real.checked = this.checked;
            real.dispatchEvent(new Event('change', {
              bubbles: true
            }));
          });

          var label = document.createElement('label');
          label.setAttribute('for', 'shadow-' + term.for);
          label.innerText = term.label;

          var dt = document.createElement('dt');
          dt.classList.add('form-type-radio');
          dt.appendChild(checkbox);
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

        // Add attributes.
        if (data.attributes.length > 0) {
          for (var d = 0, ld = data.attributes.length; d < ld; d++) {
            let attrib = data.attributes[d];
            container.setAttribute(attrib.name, attrib.value);
          }
        }

        form.appendChild(popup);
        Drupal.attachBehaviors(popup);

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

      let forms = context.querySelectorAll('form');
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
})(Drupal);
