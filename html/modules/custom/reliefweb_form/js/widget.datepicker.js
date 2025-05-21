/* global SimpleDatePicker */

/**
 * Datepicker widget handling.
 */
(function (Drupal) {

  'use strict';

  Drupal.behaviors.reliefwebFormDatePicker = {
    attach: function (context, settings) {
      // Check support.
      if (!document.addEventListener) {
        return;
      }

      // Translations.
      var t = Drupal.t;

      // Trigger an event on an element.
      function triggerEvent(element, eventName) {
        element.dispatchEvent(new Event(eventName));
      }

      /**
       * Helpers.
       */
      function hasAttribute(element, attribute) {
        if (typeof element.hasAttribute !== 'function') {
          return false;
        }
        return element.hasAttribute(attribute);
      }

      // Parse a date from the input in different format.
      function parseDateValue(datepicker, value, format) {
        value = value.trim();
        if (!value) {
          return null;
        }

        value = value.replaceAll('/', '-');
        format = format.replaceAll('/', '-');

        var year = datepicker.createDate(null, true).format('YYYY');
        var month = '01';
        var day = '01';

        // YYYY, YYYY-MM and YYYY-MM-DD formats.
        if (value.match(/^\d{4}([-]\d{2}){0,2}$/)) {
          if (value.length === 4) {
            year = value;
          }
          else if (value.length === 7) {
            [year, month] = value.split('-');
          }
          else {
            [year, month, day] = value.split('-');
          }
          value = [year, month, day].join('-');
          console.log(format, value);
          return datepicker.createDate(value + 'T00:00:00+00:00');
        }
        // DD, DD-MM, DD-MM-YYYY formats.
        else if (format === 'DD-MM-YYYY' && value.match(/^\d{2}([-]\d{2}([-]\d{4})?)?$/)) {
          if (value.length === 2) {
            day = value;
          }
          else if (value.length === 5) {
            [day, month] = value.split('-');
          }
          else {
            [day, month, year] = value.split('-');
          }
          value = [year, month, day].join('-');
          console.log(format, value);
          return datepicker.createDate(value + 'T00:00:00+00:00');
        }
        // MM, MM-DD, MM-DD-YYYY formats.
        else if (format === 'MM-DD-YYYY' && value.match(/^\d{2}([-]\d{2}([-]\d{4})?)?$/)) {
          if (value.length === 2) {
            month = value;
          }
          else if (value.length === 5) {
            [month, day] = value.split('-');
          }
          else {
            [month, day, year] = value.split('-');
          }
          value = [year, month, day].join('-');
          console.log(format, value);
          return datepicker.createDate(value + 'T00:00:00+00:00');
        }
        // D, D MMM, D MMM YYYY format.
        else if (format === 'D MMM YYYY') {
          // D format.
          if (value.match(/^\d{1,2} ?$/)) {
            value = value.trim() + datepicker.createDate(null, true).format(' MMM YYYY');
          }
          // D MMM format.
          else if (value.match(/^\d{1,2} \D{3} ?$/)) {
            value = value.trim() + datepicker.createDate(null, true).format(' YYYY');
          }
          // D MMM YYYY format.
          else if (value.match(/^\d{1,2} \D{3} \d{4}$/)) {
            // Nothing to do. It's already in an accepted format.
          }
          console.log(format, value);
          return datepicker.createDate(value + ' UTC');
        }

        return null;
      }

      // Show a selection error message if the input contains an invalid date.
      function toggleInvalidDateMessage(datepicker, format, clear) {
        var input = datepicker.options.element;
        if (!input) {
          return;
        }

        var value = input.value;
        var date = parseDateValue(datepicker, value, format, false);
        var valid = date && !date.invalid() && date.format(format) === value;
        console.log(value);
        console.log(format);
        console.log(date ? date.format(format) : null);
        console.log(valid);

        // Show an error message if the input is not a valid date.
        if (clear !== true && !valid && !datepicker.visible()) {
          // Add the form error message.
          var errorContainer = input.parentNode.querySelector('.rw-datepicker-error');
          if (!errorContainer) {
            errorContainer = document.createElement('div');
            errorContainer.classList.add('rw-datepicker-error', 'form-item--error-message');
            input.parentNode.insertBefore(errorContainer, input.nextSibling);
          }

          // Set the error message.
          errorContainer.innerHTML = Drupal.t('Invalid date. Please enter a date in the format %format (e.g., %example).', {
            '%format': format,
            '%example': datepicker.createDate().format(format)
          });

          // Mark the input as having an error.
          input.classList.add('error');
        }
        // Clear any error state.
        else {
          var errorContainer = input.parentNode.querySelector('.rw-datepicker-error');
          if (errorContainer) {
            input.parentNode.removeChild(errorContainer);
          }
          input.classList.remove('error');
        }
      }

      // Update the datepicker date based on the input value.
      function updateDatepicker(datepicker, value, format, trigger) {
        var date = parseDateValue(datepicker, value, format);
        if (date && !date.invalid()) {
          // Update the selected date.
          datepicker.setSelection([date], trigger === true);
        }
        else if (trigger) {
          datepicker.hide();
        }
      }

      // Close all datepickers except the given one if any.
      function collapseAll(datepickers, index) {
        for (var i = 0, l = datepickers.length; i < l; i++) {
          if (i !== index) {
            datepickers[i].hide();
          }
          else {
            datepickers[i].toggle();
          }
        }
      }

      // Find the form parent of an element.
      function findFormParent(element) {
        var parent = element.parentNode;
        while (parent && parent.nodeName !== 'FORM') {
          parent = parent.parentNode;
        }
        return parent;
      }

      /**
       * Logic.
       */

      function enableDatepicker(element) {
        if (element.nodeName !== 'INPUT') {
          element = element.querySelector('input');
        }

        // Skip if we couldn't find the input.
        if (!element) {
          return;
        }

        // Change to a text input because the date input accessibility is still
        // not really good (as of 2021-10-15).
        element.setAttribute('type', 'text');

        // Prepare the datepicker input field.
        element.setAttribute('autocomplete', 'off');
        element.setAttribute('placeholder', t('Click to select a date...'));

        // Localized date format.
        var localizedFormat = t('D MMM YYYY');
        if (element.hasAttribute('data-date-format')) {
          localizedFormat = element.getAttribute('data-date-format');
        }

        // Add the datepicker widget.
        var datepicker = SimpleDatePicker.datepicker({
          element: element,
          container: element.parentNode,
          namespace: 'rw-datepicker',
          visible: false,
          focusDayOnOpen: false,
          dateFunction: function (date, options) {
            return new LocalizedDate(date, options);
          },
          navigation: {
            previousYear: {
              title: t('Previous year')
            },
            previousMonth: {
              title: t('Previous month')
            },
            nextMonth: {
              title: t('Next month')
            },
            nextYear: {
              title: t('Next year')
            }
          }
        })
        .hide();

        datepicker.on('opened', function (event) {
          toggleInvalidDateMessage(datepicker, localizedFormat, true);
          focusedElement = element;
          updateDatepicker(datepicker, element.value, localizedFormat);
        });

        datepicker.on('closed', function (event) {
          // Use a delay to ensure the datepicker is closed.
          setTimeout(function () {
            toggleInvalidDateMessage(datepicker, localizedFormat);
          }, 100);
        });

        datepicker.on('select', function (event) {
          if (event.data && event.data.length) {
            element.value = event.data[0].format(localizedFormat);
          }
          else {
            element.value = '';
          }
          datepicker.hide();
          triggerEvent(element, 'change');
        });


        // Ensure the datepicker is the next sibling of the input element.
        element.parentNode.insertBefore(datepicker.container, element.nextSibling);

        // Add the datepicker to the list.
        var index = datepickers.push(datepicker) - 1;

        // Add a flag to the datepicker and its input to more easily identify
        // them when checking for outside clicks.
        element.setAttribute('data-datepicker-input', index);
        datepicker.container.setAttribute('data-datepicker', index);

        // @todo hide widget when the widget or input loses focus.
        element.addEventListener('keyup', function (event) {
          if (event.key === 'Esc' || event.key === 'Escape') {
            datepicker.hide().clear();
          }
          else if (event.key === 'Enter') {
            // Update the datepicker selection, trigger the select event and
            // close the datepicker.
            updateDatepicker(datepicker, element.value, localizedFormat, true);
          }
          else {
            // Ensure the date picker is visible and update its selection.
            datepicker.show();
            updateDatepicker(datepicker, element.value, localizedFormat);
          }
        });

        // Current day.
        var now = datepicker.createDate(null, true).format(localizedFormat);

        // Replace the element description.
        var descriptionElement = document.getElementById(element.id + '--description');
        if (descriptionElement) {
          descriptionElement.textContent = t('Format: @format (e.g., @date)', {
            '@format': localizedFormat,
            '@date': now
          });
        }

        // Change the format in the error message if present.
        var errorElement = document.querySelector('#' + element.id.replace(/-date$/, '') + ' + .form-item--error-message');
        if (errorElement) {
          errorElement.innerHTML = errorElement.innerHTML.replace(/\d{4}[/-]\d{2}[/-]\d{2}/, now);
        }

        // Convert the element value to the localized format if it's the ISO
        // format expected by Drupal. It will be converted back on submission.
        var value = element.value.trim();
        if (value.match(/^\d{4}[/-]\d{2}[/-]\d{2}$/)) {
          value = value.replaceAll('-', '/');
          var date = datepicker.createDate(value + ' UTC');
          element.value = !date.invalid() ? date.format(localizedFormat) : value;
        }

        // Convert the date into the format required by Drupal.
        var form = findFormParent(element);
        if (form) {
          form.addEventListener('submit', function (event) {
            if (element.value) {
              var date = parseDateValue(datepicker, element.value, localizedFormat);
              element.value = date && !date.invalid() ? date.format('YYYY-MM-DD') : value;
            }
          });
        }
      }

      var body = document.querySelector('body');
      if (!body.hasAttribute('data-datepicker-processed')) {
        body.setAttribute('data-datepicker-processed', '');

        // Date handler with localized months and days.
        var LocalizedDate = SimpleDatePicker.Date.extend({
          options: {
            months: [
              t('January'),
              t('February'),
              t('March'),
              t('April'),
              t('May'),
              t('June'),
              t('July'),
              t('August'),
              t('September'),
              t('October'),
              t('November'),
              t('December')
            ],
            weekDays: [
              t('Sunday'),
              t('Monday'),
              t('Tuesday'),
              t('Wednesday'),
              t('Thursday'),
              t('Friday'),
              t('Saturday')
            ]
          }
        });

        // Store references to the datepickers.
        var datepickers = [];

        // Store the reference to the element that was focused when the
        // datepicker opened.
        var focusedElement = null;

        // Handle click outside of datepickers to close them.
        body.addEventListener('click', function (event) {
          var target = event.target;
          if (target) {

            // Click on the input.
            if (hasAttribute(target, 'data-datepicker-input')) {
              var index = parseInt(target.getAttribute('data-datepicker-input'), 10);
              collapseAll(datepickers, index);
            }
            else {
              // Loop until we find a parent which is a datepicker.
              while (target) {
                // Skip if the clicked element belong to a datepicker.
                if (hasAttribute(target, 'data-datepicker')) {
                  return;
                }
                target = target.parentNode;
              }
              collapseAll(datepickers);
            }
          }
        });

        body.addEventListener('keyup', function (event) {
          if (event.key === 'Esc' || event.key === 'Escape') {
            collapseAll(datepickers);
            if (focusedElement) {
              focusedElement.focus();
              focusedElement = null;
            }
          }
        });

        // Add a datepicker widget to the elements.
        var elements = context.querySelectorAll('[data-with-datepicker]:not([data-with-datepicker-processed])');
        for (var i = 0, l = elements.length; i < l; i++) {
          var element = elements[i];
          element.setAttribute('data-with-datepicker-processed', '');
          enableDatepicker(element);
        }
      }
    }
  };

})(Drupal);
