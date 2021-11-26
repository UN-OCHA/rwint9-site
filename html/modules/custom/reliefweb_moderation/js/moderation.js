/* global SimpleAutocomplete SimpleDatePicker */
(function (Drupal) {

  'use strict';

  Drupal.behaviors.reliefwebModeration = {
    attach: function (context, settings) {

      /**
       * Add the filter to the filter selection.
       */
      function createFilter(filter, select, selection) {
        // Skip any invalid filter.
        if (!filter.label || !filter.value) {
          return;
        }

        var option = select.options[select.selectedIndex];
        var name = 'selection[' + option.value + '][]';
        var value = filter.value;
        if (option.getAttribute('data-widget') !== 'search') {
          value += ':' + filter.label;
        }

        // Skip if a filter with the same value already exists.
        if (selection.querySelector('[name="' + name + '"][value="' + value + '"]')) {
          return;
        }

        // Store the selection value with its label in a hidden input so
        // its sent when submitting the form.
        var hidden = document.createElement('input');
        hidden.setAttribute('type', 'hidden');
        hidden.setAttribute('name', name);
        hidden.setAttribute('value', value);

        // Button to remove the filter.
        var button = document.createElement('button');
        button.setAttribute('type', 'button');
        button.setAttribute('tabindex', -1);
        button.appendChild(document.createTextNode(t('Remove')));

        // Field name.
        var field = document.createElement('span');
        field.className = 'field';
        field.appendChild(document.createTextNode(option.textContent + ': '));

        // Filter value label.
        var label = document.createElement('span');
        label.className = 'label';
        label.appendChild(document.createTextNode(filter.label));

        // Filter wrapper element.
        var element = document.createElement('div');
        element.setAttribute('data-value', value);
        element.appendChild(field);
        element.appendChild(label);
        element.appendChild(hidden);
        element.appendChild(button);

        selection.appendChild(element);
      }

      /**
       * Create a datapicker widget and add it to given container element.
       */
      function createSingleDatePickerWidget(container) {
        // Create the datepicker widget.
        var widget = SimpleDatePicker.datepicker({
          container: container,
          // @todo pass that as parameter to the file?
          namespace: 'rw-datepicker'
        });

        // Input to enter the date manually.
        var input = document.createElement('input');
        input.setAttribute('type', 'text');
        input.setAttribute('placeholder', t('YYYY/MM/DD'));
        input.className = 'rw-datepicker-input';

        // Add the input at the beginning of the widget container.
        widget.container.insertBefore(input, widget.container.firstChild);

        // Listen to keyboard inputs in the input, parse the date and
        // update the widget.
        input.addEventListener('keyup', function (event) {
          var time = /(\d{4})\/(\d{2})\/(\d{2})/.exec(this.value);
          if (time) {
            widget.setSelection([time[1] + '/' + time[2] + '/' + time[3] + ' UTC']);
          }
        });

        // Listen to date selection in the widget and update the input.
        widget.on('select', function (event) {
          if (event.data && event.data.length > 0) {
            widget.updateCalendar(widget.calendars[0], event.data[0].clone());
            input.value = event.data[0].format(t('YYYY/MM/DD'));
          }
          else {
            widget.clear();
            input.value = '';
          }
        });

        // Override the widget clear function to also clear the input value.
        var clear = widget.clear;
        widget.clear = function () {
          input.value = '';
          return clear();
        };

        return widget;
      }

      /**
       * Create the widget with the double datepicker.
       */
      function createDatePickerWidget(omnibox, select, selection) {
        // Create the container for the 'from' and to 'datepicker' widgets.
        var container = document.createElement('div');
        container.setAttribute('data-datepicker', '');
        container.setAttribute('data-hidden', '');
        container.className = 'rw-datepicker-dual-widget';

        // Create a wrapper for the calendar widgets.
        var wrapper = document.createElement('div');
        wrapper.className = 'rw-datepicker-dual-widget__wrapper';
        container.appendChild(wrapper);

        // Create the 'from' and 'to' widgets.
        var widgets = [
          createSingleDatePickerWidget(wrapper),
          createSingleDatePickerWidget(wrapper)
        ];

        // Create the select button.
        var button = document.createElement('button');
        button.setAttribute('type', 'button');
        button.className = 'rw-datepicker-select';
        button.appendChild(document.createTextNode(t('Select')));
        wrapper.appendChild(button);

        // Add the widget after the omnibox.
        omnibox.parentNode.insertBefore(container, omnibox.nextSibling);

        // Datepicker widget.
        var widget = {
          active: false,
          enabled: false,
          visible: false,

          // Handle the widget visibility.
          show: function () {
            container.removeAttribute('data-hidden');
            this.visible = true;
          },
          hide: function (focus) {
            container.setAttribute('data-hidden', '');
            widgets[0].clear();
            widgets[1].clear();
            this.visible = false;
            if (focus !== false) {
              omnibox.focus();
            }
          },
          toggle: function (focus) {
            if (this.visible) {
              this.hide(focus);
            }
            else {
              this.show();
            }
          },

          // Create a filter from the selected dates in the widget.
          selectDate: function () {
            var dates = [];
            var value = [];
            var label = '';

            for (var i = 0; i < 2; i++) {
              var dateSelection = widgets[i].getSelection();
              dates.push(dateSelection.length ? dateSelection[0] : null);
              value.push(dateSelection.length ? dateSelection[0].unix() : '');
            }

            if (dates[0] !== null && dates[1] !== null) {
              // Permute the dates if the 'to' date is before the 'from' date.
              if (value[0] > value[1]) {
                value = [value[1], value[0]];
                dates = [dates[1], dates[0]];
              }

              dates[0] = dates[0].format('YYYY/MM/DD');
              dates[1] = dates[1].format('YYYY/MM/DD');
              label = dates[0] === dates[1] ? 'on ' + dates[0] : dates.join(' to ');
            }
            else if (dates[0] !== null) {
              label = 'after ' + dates[0].add('date', -1).format('YYYY/MM/DD');
            }
            else if (dates[1] !== null) {
              label = 'before ' + dates[1].add('date', 1).format('YYYY/MM/DD');
            }

            // Create a filter with the selected date(s).
            if (label !== '') {
              createFilter({value: value.join('-'), label: label}, select, selection);
            }

            // Close the datepicker.
            this.hide(true);
          },

          // Enable or disable the widget.
          enable: function () {
            this.show();
            this.enabled = true;
          },
          disable: function () {
            this.hide(true);
            this.enabled = false;
          }
        };

        // Select the date(s) when pressing enter inside the widget container.
        container.addEventListener('keypress', function (event) {
          var key = event.witch || event.keyCode;
          if (key === 13) {
            widget.selectDate();
          }
        });

        // Handle click outside of the datepicker to close it.
        document.addEventListener('click', function (event) {
          if (widget.active) {
            switch (event.target) {
              // Create a filter from the date selection.
              case button:
                widget.selectDate();
                break;

              // Toggle visiblity when clicking on the omnibox.
              case omnibox:
                if (widget.enabled) {
                  // The widget is already enabled so we just toggle visibility.
                  widget.toggle(false);
                }
                else {
                  widget.enable();
                }
                break;

              // Otherwise if the widget is enabled, check if the click is outside.
              default:
                if (widget.visible) {
                  var target = event.target;
                  var body = document.body || document.getElementsByTagName('body')[0];
                  var outside = true;
                  while (outside && target && target !== body) {
                    outside = target !== container;
                    target = target.parentNode;
                  }
                  // Outside click, hide the widget.
                  if (outside) {
                    widget.hide(false);
                  }
                }
            }
          }
        });

        return widget;
      }

      /**
       * Create the autocomplete widget.
       */
      function createAutocompletWidget(omnibox, select, selection) {
        var url = omnibox.getAttribute('data-autocomplete-url');
        var removeDiacritics = SimpleAutocomplete.removeDiacritics;

        var parent = omnibox.parentNode;
        // @todo maybe not needed.
        parent.setAttribute('data-autocomplete', '');
        // @todo pass that as parameter to the file?
        parent.classList.add('rw-autocomplete');

        var autocomplete = SimpleAutocomplete.autocomplete(omnibox, url, {
          // @todo pass that as parameter to the file?
          namespace: 'rw-autocomplete',
          cacheKey: function (query) {
            var option = select.options[select.selectedIndex];
            var cacheKey = 'query_' + option.value;
            if (option.getAttribute('data-widget') === 'autocomplete') {
              return cacheKey + '_' + removeDiacritics(query);
            }
            return cacheKey;
          },
          prepare: function (query, source) {
            var option = select.options[select.selectedIndex];
            if (option.getAttribute('data-widget') === 'autocomplete') {
              return url + option.value + '?query=' + encodeURIComponent(removeDiacritics(query));
            }
            return {value: query, label: query};
          },
          render: function (query, suggestion) {
            return this.highlight(query.replace(/^[!+-]/, '').replace(/&/g, ' '), suggestion.label);
          },
          select: function (suggestion) {
            if (this.selectorIsOpen()) {
              createFilter(suggestion, select, selection);
              this.clear();
            }
          }
        });

        return {
          active: false,
          enabled: false,
          enable: function () {
            autocomplete.enable();
            this.enabled = true;
          },
          disable: function () {
            autocomplete.disable().hideSelector();
            this.enabled = false;
          }
        };
      }

      /**
       * Create the search widget.
       */
      function createSearchWidget(omnibox, select, selection) {
        var widget = {
          active: false,
          enabled: false,
          enable: function () {
            this.enabled = true;
          },
          disable: function () {
            this.enabled = false;
          }
        };

        // Add the content of the omnibox as filter when pressing enter.
        omnibox.addEventListener('keydown', function (event) {
          var key = event.witch || event.keyCode;
          if (widget.active && key === 13) {
            var value = omnibox.value;
            if (value) {
              createFilter({value: value, label: value}, select, selection);
            }
            omnibox.value = '';
          }
        });

        return widget;
      }

      /**
       * Change the widget.
       */
      function changeWidget(omnibox, select, active, enable) {
        var widget = null;

        // Get the widget for the selected filter option.
        var option = select.options[select.selectedIndex];
        switch (option.getAttribute('data-widget')) {
          case 'datepicker':
            widget = datepicker;
            break;

          case 'autocomplete':
            widget = autocomplete;
            break;

          case 'search':
            widget = search;
            break;
        }

        // Skip if the widget is already the active one.
        if (widget !== active) {
          // Clear the omnibox.
          omnibox.value = '';

          // Disable the active widget if any.
          if (active) {
            active.disable();
            active.active = false;
          }

          // Change the active widget.
          active = widget;
          active.active = true;
        }

        // Display the widget if requested.
        if (enable) {
          if (!widget.enabled) {
            widget.enable();
          }
          // Focus the omnibox.
          omnibox.focus();
        }

        return active;
      }

      console.log('here I am');

      /**
       * Logic.
       */
      // Minimal support checking.
      if (typeof document.querySelector === 'undefined') {
        return;
      }

      // Retrieve the form.
      var form = document.getElementById('reliefweb-moderation-page-filter-form');

      // Skip if the form couldn't be found or was already processed.
      if (!form || form.hasAttribute('data-processed')) {
        return;
      }

      // Mark the form as processed.
      form.setAttribute('data-processed', '');

      // For translation.
      var t = Drupal.t;

      // Get the form elements.
      var select = form.querySelector('select[name="omnibox[select]"]');
      var omnibox = form.querySelector('input[name="omnibox[input]"]');
      var selection = form.querySelector('[data-selection]');

      // Create the widgets.
      var datepicker = createDatePickerWidget(omnibox, select, selection);
      var autocomplete = createAutocompletWidget(omnibox, select, selection);
      var search = createSearchWidget(omnibox, select, selection);
      var active = null;

      // Prepare the shortcuts to switch between filter options.
      var shortcuts = {};
      var options = select.options;
      for (var i = 0, l = options.length; i < l; i++) {
        var option = options[i];
        shortcuts[option.getAttribute('data-shortcut')] = i;
      }

      // Handle filter selection, selecting the appropriate widget.
      select.addEventListener('change', function (event) {
        active = changeWidget(omnibox, select, active, true);
      });

      // Handle shortcuts to switch between filter options.
      omnibox.addEventListener('keyup', function (event) {
        var value = this.value;

        // If the text in the omnibox starts with a ':', then it means the
        // user is trying to use a shortcut to switch the filter option.
        if (value.charAt(0) === ':') {
          // Disable the widgets so they don't interfere while we analyze
          // what the user is typing.
          datepicker.disable();
          autocomplete.disable();

          // If there is another ':' further in the omnibox text, we extract
          // the text between both ':' and check if that matches a shortcut.
          var position = value.indexOf(':', 1);
          if (position !== -1) {
            // If there is a valid shortcut, we switch the filter, and
            // fill the omnibox with the rest of text after the second ':'.
            var index = shortcuts[value.substr(1, position - 1)];
            if (typeof index !== 'undefined') {
              select.selectedIndex = index;
              this.value = value.substr(position + 1);
              active = changeWidget(omnibox, select, active, true);
            }
          }
        }
      });

      // Remove a selected filter when clicking on its remove button.
      selection.addEventListener('click', function (event) {
        var target = event.target;
        if (target && target.nodeName === 'BUTTON') {
          target.parentNode.parentNode.removeChild(target.parentNode);
        }
      });

      // Prevent form submission when pressing 'enter'.
      form.addEventListener('keydown', function (event) {
        var key = event.witch || event.keyCode;
        var target = event.target;
        if (key === 13 && target && target.nodeName !== 'BUTTON') {
          event.preventDefault();
          return false;
        }
      });

      // Initialize the widget.
      active = changeWidget(omnibox, select, active, false);
    }
  };

})(Drupal);
