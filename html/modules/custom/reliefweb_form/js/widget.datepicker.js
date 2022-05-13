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

      // Update the datepicker date based on the input value.
      function updateDatepicker(datepicker, value) {
        // Set the selected date from the value in the input field if valid.
        if (value && value.match(/^\d{4}([/-]\d{2}){0,2}$/)) {
          value = value.length === 4 ? value + '-01-01' : value;
          value = value.length === 7 ? value + '-01' : value;
          value = value.replaceAll('-', '/');
          var date = datepicker.createDate(value + ' UTC');
          if (!date.invalid()) {
            var calendar = datepicker.calendars[0];
            datepicker.setSelection([date], false).updateCalendar(calendar, date);
          }
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
        var localizedFormat = t('YYYY-MM-DD');

        // Add the datepicker widget.
        var datepicker = new DatePicker({
          element: element,
          container: element.parentNode,
          namespace: 'rw-datepicker',
          visible: false,
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
          focusedElement = element;
          updateDatepicker(datepicker, element.value);
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
          if (event.key === 'Esc' || event.key === 'Escape' || event.key === 'Enter') {
            datepicker.hide().clear();
          }
          else {
            updateDatepicker(datepicker, element.value);
          }
        });
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

        var DatePicker = SimpleDatePicker.DatePicker.extend({
          show: function () {
            if (!this.visible()) {
              this.fire('show').fire('opened');
              this.container.setAttribute('data-hidden', 'false');

              // Focus the first selectable date in the datepicker.
              var days = this.retrieveDaysIn();
              if (days.length > 0) {
                days[0].setAttribute('tabindex', '0');
                days[0].focus();
              }
            }
            return this;
          },
          hide: function () {
            if (this.visible()) {
              this.container.setAttribute('data-hidden', 'true');
              this.fire('hide').fire('closed');
            }
            return this;
          },
          toggle: function () {
            if (this.visible()) {
              this.hide();
            }
            else {
              this.show();
            }
            return this;
          },
          visible: function () {
            return this.container.getAttribute('data-hidden') !== 'true';
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
        var elements = document.querySelectorAll('[data-with-datepicker]:not([data-with-datepicker-processed])');
        for (var i = 0, l = elements.length; i < l; i++) {
          var element = elements[i];
          element.setAttribute('data-with-datepicker-processed', '');
          enableDatepicker(element);
        }
      }
    }
  };

})(Drupal);
