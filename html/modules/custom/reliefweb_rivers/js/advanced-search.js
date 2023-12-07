/* global reliefweb, SimpleAutocomplete, SimpleDatePicker */
/**
 * Script to handle the advanced search filters for the rivers.
 *
 * @todo with the drop of support for IE, we can simplify this script.
 * @todo review the ids.
 */
(function () {
  'use strict';

  // Minimum support.
  if (typeof document.querySelector !== 'function') {
    return;
  }

  /**
   * Utils.
   */

  // Key codes.
  var KeyCodes = {
    TAB: 9,
    ENTER: 13,
    ESC: 27,
    END: 35,
    HOME: 36,
    LEFT: 37,
    UP: 38,
    RIGHT: 39,
    DOWN: 40
  };

  // Add an event to an element. Return the event handler function.
  function addEventListener(element, eventName, handler) {
    if (typeof element.addEventListener === 'function') {
      element.addEventListener(eventName, handler, false);
    }
    // IE compatibility.
    else {
      var callback = handler;
      handler = function (event) {
        event.target = event.target || event.srcElement;
        callback.call(this, event);
      };

      if (typeof element.attachEvent === 'function') {
        element.attachEvent('on' + eventName, handler);
      }
      else {
        element['on' + eventName] = handler;
      }
    }
    return handler;
  }

  // Trigger an event on an element.
  function triggerEvent(element, eventName) {
    if (typeof element.dispatchEvent === 'function') {
      element.dispatchEvent(new Event(eventName));
    }
    // IE compatibility.
    else if (typeof element.fireEvent === 'function') {
      element.fireEvent('on' + eventName);
    }
  }

  // Prevent event default behavior.
  function preventDefault(event) {
    if (typeof event.preventDefault !== 'undefined') {
      event.preventDefault();
    }
    else {
      event.returnValue = false;
    }
  }

  // Stop event propagation.
  function stopPropagation(event) {
    if (typeof event.stopPropagation !== 'undefined') {
      event.stopPropagation();
    }
    else {
      event.cancelBubble = true;
    }
  }

  // Get the previous element sibling of a DOM element.
  function getPreviousElementSibling(element) {
    if (typeof element.previousElementSibling !== 'undefined') {
      return element.previousElementSibling;
    }
    element = element.previousSibling;
    while (element) {
      if (element.nodeType === 1) {
        return element;
      }
      element = element.previousSibling;
    }
    return null;
  }

  // Get the next element sibling of a DOM element.
  function getNextElementSibling(element) {
    if (typeof element.nextElementSibling !== 'undefined') {
      return element.nextElementSibling;
    }
    element = element.nextSibling;
    while (element) {
      if (element.nodeType === 1) {
        return element;
      }
      element = element.nextSibling;
    }
    return null;
  }

  // Create a DOM element with the given attributes and content.
  function createElement(tag, attributes, content) {
    var element = document.createElement(tag);
    if (typeof attributes === 'object') {
      for (var attribute in attributes) {
        if (attributes.hasOwnProperty(attribute)) {
          element.setAttribute(attribute, attributes[attribute]);
        }
      }
    }
    switch (typeof content) {
      case 'string':
        element.appendChild(document.createTextNode(content));
        break;

      case 'object':
        if (content.nodeType) {
          element.appendChild(content);
        }
        // Assume is a list of dom elements.
        else if (typeof content.length !== 'undefined') {
          for (var i = 0, l = content.length; i < l; i++) {
            element.appendChild(content[i]);
          }
        }
        break;
    }
    return element;
  }

  // Create a label element.
  function createLabel(target, label, attributes) {
    attributes = attributes || {};
    attributes.for = target;
    return createElement('label', attributes, label);
  }

  // Create an option element.
  function createOption(value, label, attributes) {
    attributes = attributes || {};
    attributes.value = value;
    return createElement('option', attributes, label);
  }

  // Create a button element.
  function createButton(attributes, content) {
    attributes = attributes || {};
    attributes.type = 'button';
    return createElement('button', attributes, content);
  }

  // Create a document fragment.
  function createFragment() {
    var fragment = document.createDocumentFragment();
    for (var i = 0, l = arguments.length; i < l; i++) {
      fragment.appendChild(arguments[i]);
    }
    return fragment;
  }

  // Check if an element is contained in another.
  function contains(parent, node) {
    while (node && node !== parent) {
      node = node.parentNode;
    }
    return node === parent;
  }

  // Trim a string.
  function trim(string) {
    return string.replace(/^[\s\uFEFF\xA0]+|[\s\uFEFF\xA0]+$/g, '');
  }

  // Set element text.
  function setText(element, text) {
    if ('textContent' in element) {
      element.textContent = text;
    }
    else {
      element.innerText = text;
    }
  }

  // Get element text.
  function getText(element, trimmed) {
    var text = 'textContent' in element ? element.textContent : element.innerText;
    return trimmed !== false ? trim(text) : text;
  }

  // Create a date object.
  function createDate(date) {
    date = date.replace(/(\d{4})(\d{2})(\d{2})/, '$1-$2-$3T00:00:00+00:00');
    return SimpleDatePicker.date(date).utc();
  }

  // Format date range filter label.
  function formatDateRangeLabel(from, to, labels) {
    var label = '';
    var start = '';
    var end = '';
    if (from && to) {
      if (from === to) {
        label = labels.on;
        start = createDate(from).format('YYYY/MM/DD');
      }
      else if (from > to) {
        label = labels.range;
        start = createDate(to).format('YYYY/MM/DD');
        end = createDate(from).format('YYYY/MM/DD');
      }
      else {
        label = labels.range;
        start = createDate(from).format('YYYY/MM/DD');
        end = createDate(to).format('YYYY/MM/DD');
      }
    }
    else if (from) {
      label = labels.after;
      start = createDate(from).substract('day', 1).format('YYYY/MM/DD');
    }
    else if (to) {
      label = labels.before;
      end = createDate(to).add('day', 1).format('YYYY/MM/DD');
    }
    return label ? label.replace('_start_', start).replace('_end_', end) : '';
  }

  // Format date range filter value.
  function formatDateRangeValue(from, to) {
    if (from && to) {
      if (from === to) {
        return from;
      }
      else if (from > to) {
        return to + '-' + from;
      }
      else {
        return from + '-' + to;
      }
    }
    else if (from) {
      return from + '-';
    }
    else if (to) {
      return '-' + to;
    }
    return '';
  }

  // Get the the operator options based on the given operator.
  function getOperatorOptions(advancedMode, operator) {
    var operators = {};
    if (!advancedMode) {
      if (operator === 'and') {
        operators['and'] = true;
      }
      else if (operator === 'or') {
        operators['or'] = true;
      }
      else {
        operators['all'] = true;
        operators['any'] = true;
      }
    }
    else {
      if (!operator) {
        operators['with'] = true;
        operators['without'] = true;
      }
      else {
        if (operator === 'and' || operator === 'or') {
          operators[operator] = true;
        }
        else {
          operators['and'] = true;
          operators['or'] = true;
        }
        operators['and-with'] = true;
        operators['and-without'] = true;
        operators['or-with'] = true;
        operators['or-without'] = true;
      }
    }
    return operators;
  }

  // Toggle the visibility of a dialog.
  function toggleDialog(advancedSearch, focusTarget) {
    if (advancedSearch.activeToggler) {
      var toggler = advancedSearch.activeToggler;
      var dialog = advancedSearch.dialog;
      var close = dialog.getAttribute('data-hidden') !== 'true';
      dialog.setAttribute('data-hidden', close);
      toggler.setAttribute('data-hidden', !close);

      if (close) {
        toggler.removeAttribute('tabindex');
        toggler.focus();
        advancedSearch.activeToggler = null;
      }
      else if (focusTarget) {
        toggler.setAttribute('tabindex', -1);
        focusTarget.focus();
      }
    }
  }

  /**
   * Widgets.
   */

  // Create an autocomplete widget.
  function createAutocomplete(advancedSearch, element) {
    var url = element.getAttribute('data-with-autocomplete');

    var parent = element.parentNode;
    parent.setAttribute('data-autocomplete', '');
    parent.classList.add(advancedSearch.widgetClassPrefix + 'autocomplete');

    var autocomplete = SimpleAutocomplete.autocomplete(element, url, {
      namespace: advancedSearch.widgetClassPrefix + 'autocomplete',
      filter: function (query, data) {
        data = typeof data === 'string' ? JSON.parse(data) : data;
        var suggestions = [];
        if (data && data.data) {
          data = data.data;
          for (var i = 0, l = data.length; i < l; i++) {
            var item = data[i];
            var fields = item.fields;
            var name = fields.name;
            var shortname = fields.shortname;
            var label = name;
            if (shortname && shortname !== name) {
              label += ' (' + shortname + ')';
            }
            suggestions.push({
              value: item.id,
              label: label
            });
          }
        }
        return suggestions;
      }
    });

    // Override the focus function so that we don't focus the input after
    // selecting a value as we close the dialog and focus the toggling button
    // instead.
    autocomplete.focus = function () {
      // No op.
    };

    // Add to the selection.
    autocomplete.on('selected', function (event) {
      var data = event.data;
      if (data) {
        element.setAttribute('data-value', data.value);
        element.setAttribute('data-label', data.label);
        element.value = data.label;
        triggerEvent(advancedSearch.dialogAdd, 'click');
      }
      else {
        element.value = '';
      }
    });

    return {
      clear: function () {
        element.removeAttribute('data-value');
        element.removeAttribute('data-label');
        autocomplete.clear();
      },
      value: function () {
        return element.getAttribute('data-value') || '';
      },
      label: function () {
        return element.getAttribute('data-label') || '';
      }
    };
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
        return date;
      }
    }
    return null;
  }

  // Create a datepicker widget.
  function createDatepicker(advancedSearch, input) {
    var parent = input.parentNode;
    parent.setAttribute('data-datepicker', '');
    parent.classList.add(advancedSearch.widgetClassPrefix + 'datepicker');
    input.classList.add(advancedSearch.widgetClassPrefix + 'datepicker-input');
    input.setAttribute('pattern', '^\\d{4}([\\/-]\\d{2}){0,2}$');

    // Create the button to show the datepicker.
    var toggleLabel = createElement('span', {
      'class': advancedSearch.widgetClassPrefix + 'datepicker-toggle-label visually-hidden'
    }, advancedSearch.labels.chooseDate);
    var toggle = createButton({
      'data-datepicker-toggle': '',
      'class': advancedSearch.widgetClassPrefix + 'datepicker-toggle'
    }, toggleLabel);
    parent.insertBefore(toggle, input.nextSibling);

    // @todo change the simpledatepicker upstream to use some namespace like
    // the autocomplete widget rather than individual classes?
    var datepicker = SimpleDatePicker.datepicker({
      namespace: advancedSearch.widgetClassPrefix + 'datepicker',
      input: toggle,
      container: parent
    }).hide();

    // Fix the position of the datepicker so that it's just after the input.
    parent.insertBefore(datepicker.container, toggle.nextSibling);

    // Button to cancel or select the date.
    var cancel = createButton({'data-cancel': ''}, advancedSearch.labels.cancel);
    var select = createButton({'data-select': ''}, advancedSearch.labels.select);
    var buttonContainer = createElement('div', {'class': advancedSearch.widgetClassPrefix + 'datepicker-button-container'});
    buttonContainer.appendChild(cancel);
    buttonContainer.appendChild(select);
    datepicker.calendars[0].calendar.appendChild(buttonContainer);

    // Update the toggle label based on the selected date.
    function updateToggleLabel(date) {
      var label = advancedSearch.labels.chooseDate;
      if (date) {
        label = advancedSearch.labels.changeDate.replace('_date_', date.format('dddd D MMMM YYYY'));
      }
      SimpleDatePicker.setText(toggleLabel, label);
    }

    // Update the date input with the selected date.
    function updateDateInput(date) {
      if (date) {
        input.value = date.format('YYYY/MM/DD');
        input.setAttribute('data-value', date.format('YYYYMMDD'));
      }
      else {
        input.value = '';
        input.removeAttribute('date-value');
      }
      updateToggleLabel(date);
      datepicker.hide();
      toggle.focus();
    }

    // Cancel the filter addition, clear the widget and close the dialog.
    addEventListener(cancel, 'click', function (event) {
      datepicker.hide().clear();
      toggle.focus();
    });

    // Add a filter, clear the widget and close the dialog.
    addEventListener(select, 'click', function (event) {
      var focused = datepicker.getFocusedDay();
      if (focused) {
        datepicker.select(focused);
      }
      else {
        var selection = datepicker.getSelection();
        updateDateInput(selection.length ? selection[0] : null);
      }
    });

    // Update the date of the datepicker based on the value from the input.
    datepicker.on('opened', function (event) {
      updateDatepicker(datepicker, input.value);
    });

    // Update the input text when selecting a date.
    datepicker.on('select', function (event) {
      updateDateInput(event.data && event.data.length ? event.data[0] : null);
    });

    // Show/Hide the datepicker.
    addEventListener(toggle, 'click', function (event) {
      datepicker.toggle();
    });

    // Update the toggle label.
    addEventListener(input, 'input', function (event) {
      var date = updateDatepicker(datepicker, input.value);
      updateToggleLabel(date);
      if (date) {
        input.setAttribute('data-value', date.format('YYYYMMDD'));
      }
      else {
        input.removeAttribute('data-value');
      }
      if (!date && input.value.length > 0) {
        input.setCustomValidity(advancedSearch.labels.dates.invalid);
      }
      else {
        input.setCustomValidity('');
      }
      input.reportValidity();
    });

    // Logic to keep the focus inside the dialog when it's open.
    var firstButton = datepicker.container.querySelector('button');
    addEventListener(select, 'keydown', function (event) {
      var key = event.which || event.keyCode;
      if (key === KeyCodes.TAB && !event.shiftKey) {
        preventDefault(event);
        stopPropagation(event);
        firstButton.focus();
      }
    });
    addEventListener(firstButton, 'keydown', function (event) {
      var key = event.which || event.keyCode;
      if (key === KeyCodes.TAB && event.shiftKey) {
        preventDefault(event);
        stopPropagation(event);
        select.focus();
      }
    });

    return {
      widget: datepicker,
      clear: function () {
        input.value = '';
        input.removeAttribute('data-value');
        datepicker.hide().clear();
      },
      value: function () {
        return input.getAttribute('data-value') || '';
      }
    };
  }

  // Create a widget.
  function createWidget(advancedSearch, element) {
    var widget = {
      element: element,
      active: function () {
        return this.element.hasAttribute('data-active');
      },
      enable: function () {
        this.element.removeAttribute('disabled');
        this.element.removeAttribute('tabindex');
        this.element.setAttribute('data-active', '');
      },
      disable: function () {
        this.clear();
        this.element.setAttribute('disabled', '');
        this.element.setAttribute('tabindex', -1);
        this.element.removeAttribute('data-active');
      },
      hide: function () {
        // Noop for most widgets.
      }
    };

    switch (element.getAttribute('data-widget')) {
      case 'autocomplete':
        widget.autocomplete = createAutocomplete(advancedSearch, element.querySelector('[data-with-autocomplete]'));
        widget.clear = function () {
          this.autocomplete.clear();
        };
        widget.value = function () {
          return this.autocomplete.value();
        };
        widget.label = function () {
          return this.autocomplete.label();
        };
        break;

      case 'date':
        widget.from = createDatepicker(advancedSearch, element.querySelector('[data-from]'));
        widget.to = createDatepicker(advancedSearch, element.querySelector('[data-to]'));

        // Close the other datepicker when opening a datepicker.
        widget.from.widget.on('opened', widget.to.widget.hide);
        widget.to.widget.on('opened', widget.from.widget.hide);

        widget.hide = function () {
          widget.from.widget.hide();
          widget.to.widget.hide();
        };
        widget.clear = function () {
          this.from.clear();
          this.to.clear();
        };
        widget.value = function () {
          return formatDateRangeValue(this.from.value(), this.to.value());
        };
        widget.label = function () {
          return formatDateRangeLabel(this.from.value(), this.to.value(), advancedSearch.labels.dates);
        };
        break;

      case 'options':
        widget.select = element.querySelector('select');
        // Add the filter after selection when pressing enter.
        addEventListener(widget.select, 'keyup', function (event) {
          var key = event.which || event.keyCode;
          if (key === KeyCodes.ENTER && this.value) {
            triggerEvent(advancedSearch.dialogAdd, 'click');
          }
        });
        widget.clear = function () {
          this.select.selectedIndex = 0;
        };
        widget.value = function () {
          return this.select.value;
        };
        widget.label = function () {
          var options = this.select.querySelectorAll('option');
          return getText(options[this.select.selectedIndex]);
        };
        break;

      case 'keyword':
        widget.input = element.querySelector('input');
        widget.clear = function () {
          this.input.value = '';
        };
        widget.value = function () {
          return trim(this.input.value);
        };
        widget.label = function () {
          return trim(this.input.value);
        };
        break;
    }

    // Initial state is disabled.
    widget.disable();
    return widget;
  }

  // Active the widget corresponding to the given code.
  function switchWidget(advancedSearch, announce) {
    var field = getSelectedField(advancedSearch.fieldSelector);
    var code = field.code;
    var widgets = advancedSearch.widgets;
    var active = null;
    for (var i = 0, l = widgets.length; i < l; i++) {
      var widget = widgets[i];
      if (widget.element.getAttribute('data-code') === code) {
        widget.enable();
        active = widget;
      }
      else {
        widget.disable();
      }
    }

    if (announce === true) {
      var live = document.getElementById('river-advanced-search-filter-announcement');
      live.innerHTML = advancedSearch.announcements.changeFilter
      .replace('_name_', field.name);
    }

    return active;
  }

  // Get active widget.
  function getActiveWidget(widgets) {
    for (var i = 0, l = widgets.length; i < l; i++) {
      var widget = widgets[i];
      if (widget.active()) {
        return widget;
      }
    }
    return null;
  }

  // Get the current field.
  function getSelectedField(fieldSelector) {
    var fields = fieldSelector.querySelectorAll('option');
    var name = getText(fields[fieldSelector.selectedIndex]);
    return {
      name: name,
      code: fieldSelector.value
    };
  }

  /**
   * Selection.
   */

  // Update the count of the filters in the selection and hide/show the actions.
  function updateFilterSelection(advancedSearch) {
    var filterSelection = advancedSearch.filterSelection;
    var count = filterSelection.querySelectorAll('[data-value]').length;
    filterSelection.setAttribute('data-selection', count);

    // Remove the initial empty state from the filter Selection which is just
    // there to hide the action buttons.
    if (count > 0) {
      advancedSearch.container.removeAttribute('data-empty');
    }

    // Get the filter selection and update the advanced-search parameter.
    var selection = parseFilterSelection(filterSelection);
    advancedSearch.parameter.value = selection;

    // In case of changes to the selection, update the apply button to notify
    // the user to click on it to update the list.
    advancedSearch.apply.setAttribute('data-apply', selection !== advancedSearch.originalSelection);
  }

  // Get the last operaror in the filter selection.
  function getLastOperator(advancedSearch) {
    var operators = advancedSearch.filterSelection.querySelectorAll('[data-operator]');
    if (operators.length) {
      return operators[operators.length - 1].getAttribute('data-operator');
    }
    return '';
  }

  // Create an operator switcher.
  function createOperatorSwitcher(advancedSearch, element) {
    var options = advancedSearch.labels.operators;
    var id = 'river-advanced-search-operator-' + advancedSearch.id++;

    if (typeof element === 'string') {
      element = createElement('div', {
        'data-operator': element
      }, options[element]);
    }

    var operator = element.getAttribute('data-operator');

    var label = options[operator];

    var button = createButton({
      'id': id + '-button',
      'aria-haspopup': 'listbox',
      'aria-label': advancedSearch.labels.switchOperator.replace('_operator_', label),
      'aria-expanded': false
    }, label);

    var list = createElement('ul', {
      'role': 'listbox',
      'tabIndex': -1,
      'data-hidden': true
    });

    for (var option in options) {
      if (options.hasOwnProperty(option)) {
        list.appendChild(createElement('li', {
          'id': id + '-' + option,
          'role': 'option',
          'data-option': option
        }, options[option]));
      }
    }

    // Show/hide the list when the button is clicked or select list item when
    // it is clicked.
    // @todo prevent selection of options with aria-disabled set to true.
    addEventListener(element, 'click', function (event) {
      var target = event.target;
      if (target.nodeName === 'BUTTON') {
        var expand = target.getAttribute('aria-expanded') === 'false';
        toggleOperatorSwitcher(button, list, expand);
      }
      else if (target.nodeName === 'LI') {
        updateOperatorSwitcher(advancedSearch, element, target);
        toggleOperatorSwitcher(button, list, false);
        // Ensure the other operators and the operator selector have
        // consistent values.
        updateOperatorSwitchers(advancedSearch);
      }
    });

    // Basic keyboard support.
    // @todo handle looking for item with first letters.
    addEventListener(element, 'keydown', function (event) {
      var target = event.target;
      var key = event.which || event.keyCode;

      if (target.nodeName === 'UL') {
        preventDefault(event);
        var selection = null;

        switch (key) {
          case KeyCodes.UP:
            selection = getPreviousElementSibling(element.querySelector('[aria-selected]'));
            break;

          case KeyCodes.DOWN:
            selection = getNextElementSibling(element.querySelector('[aria-selected]'));
            break;

          case KeyCodes.HOME:
            selection = list.firstChild;
            break;

          case KeyCodes.END:
            selection = list.lastChild;
            break;

          case KeyCodes.ENTER:
          case KeyCodes.ESC:
            toggleOperatorSwitcher(button, list, false);
            return;
        }
        if (selection) {
          toggleOperatorSwitcher(button, list, true);
          updateOperatorSwitcher(advancedSearch, element, selection);
        }
      }
      else if (target.nodeName === 'BUTTON' && key === KeyCodes.ENTER) {
        preventDefault(event);
        toggleOperatorSwitcher(button, list, true);
      }
    });

    element.setAttribute('id', id);
    if (element.firstChild) {
      element.replaceChild(button, element.firstChild);
    }
    else {
      element.appendChild(button);
    }
    element.appendChild(list);

    updateOperatorSwitcher(advancedSearch, element, operator);
    return element;
  }

  // Toggle the visibility of an operator switcher.
  function toggleOperatorSwitcher(button, list, expand) {
    if (expand === true) {
      if (button.getAttribute('aria-expanded') === 'false') {
        button.setAttribute('aria-expanded', true);
        list.setAttribute('data-hidden', false);
        list.focus();
      }
    }
    else {
      if (button.getAttribute('aria-expanded') === 'true') {
        list.setAttribute('data-hidden', true);
        button.setAttribute('aria-expanded', false);
        button.focus();
      }
    }
  }

  // Update the selected value of an operator switcher.
  function updateOperatorSwitcher(advancedSearch, element, selection, previous) {
    if (typeof selection === 'string') {
      selection = element.querySelector('[data-option="' + selection + '"]');
    }

    var options = element.querySelectorAll('[data-option]');
    var selected = element.querySelector('[aria-selected]');
    var operator = selection.getAttribute('data-option');
    var button = element.querySelector('button');
    var list = element.querySelector('ul');

    if (typeof previous !== 'undefined') {
      var operators = getOperatorOptions(advancedSearch.advancedMode, previous);

      // Disable/enable options of the operator switcher.
      for (var i = 0, l = options.length; i < l; i++) {
        var option = options[i];
        if (operators[option.getAttribute('data-option')] === true) {
          option.removeAttribute('aria-disabled');
        }
        else {
          option.setAttribute('aria-disabled', true);
        }
      }
    }

    // Update the selected option in the operator switcher.
    if (selected !== selection) {
      element.setAttribute('data-operator', operator);
      if (selected) {
        selected.removeAttribute('aria-selected');
      }
      selection.setAttribute('aria-selected', true);
      list.setAttribute('aria-activedescendant', selection.id);
      // Update button label.
      var label = getText(selection);
      setText(button, label);
      button.setAttribute('aria-label', advancedSearch.labels.switchOperator.replace('_operator_', label));
    }
  }

  // Make sure the operators in the filter selection are valid.
  function updateOperatorSwitchers(advancedSearch) {
    var elements = advancedSearch.filterSelection.querySelectorAll('[data-operator]');
    var defaults = advancedSearch.defaultOperators;
    var advancedMode = advancedSearch.advancedMode;
    var previousOperator = '';
    var previousField = '';

    for (var i = 0, l = elements.length; i < l; i++) {
      var element = elements[i];
      var field = element.parentNode.getAttribute('data-field');
      var operator = element.getAttribute('data-operator');
      var replacement = operator;

      if (advancedMode) {
        if (i === 0 && operator !== 'with' && operator !== 'without') {
          replacement = operator.indexOf('without') > 0 ? 'without' : 'with';
        }
        else if (operator === 'and' && previousOperator === 'or') {
          replacement = 'and-with';
        }
        else if (operator === 'or' && previousOperator === 'and') {
          replacement = 'or-with';
        }
        else if (operator === 'any' || operator === 'all') {
          replacement = 'and-with';
        }

        updateOperatorSwitcher(advancedSearch, element, replacement, previousOperator);
      }
      else {
        // First filter for the field.
        if (field !== previousField) {
          if (operator !== 'any' && operator !== 'all') {
            replacement = defaults[field] === 'and' ? 'all' : 'any';
            // We need to have a peek at the next element to see if there is
            // more than 1 selected value for the field. In that case we adjust
            // the operator based on the next filter's operator.
            if (i + 1 < l) {
              var nextElement = elements[i + 1];
              if (nextElement.parentNode.getAttribute('data-field') === field) {
                replacement = nextElement.getAttribute('data-operator') === 'or' ? 'any' : 'all';
              }
            }
          }
        }
        else if (previousOperator === 'any' && operator !== 'or') {
          replacement = 'or';
        }
        else if (previousOperator === 'all' && operator !== 'and') {
          replacement = 'and';
        }
        // In the simplified mode, we don't adjust the switcher options based on
        // the previous operator but based on the current operator instead.
        if (replacement !== operator) {
          updateOperatorSwitcher(advancedSearch, element, replacement, replacement);
        }
      }

      previousField = field;
      previousOperator = replacement;
    }

    // Update the operator selector with the allowed operators.
    updateOperatorSelector(advancedSearch);

    // Update the filter selection count and hide/show the actions.
    updateFilterSelection(advancedSearch);
  }

  // Update options for the operator selector based on the last operator
  // in the filter selection.
  function updateOperatorSelector(advancedSearch) {
    var options = advancedSearch.operatorSelector.querySelectorAll('option');
    var last = getLastOperator(advancedSearch);
    var operators = getOperatorOptions(advancedSearch.advancedMode, last);

    var optgroup = null;
    var selected = false;
    for (var i = 0, l = options.length; i < l; i++) {
      var option = options[i];
      if (!operators.hasOwnProperty(option.value)) {
        option.setAttribute('disabled', '');
        option.disabled = true;
      }
      else {
        option.removeAttribute('disabled');
        option.disabled = false;
        if (!selected) {
          selected = true;
          option.selected = true;
        }
      }

      // Disable group by default.
      if (optgroup !== option.parentNode) {
        optgroup = option.parentNode;
        optgroup.disabled = true;
        optgroup.setAttribute('disabled', '');
      }
      // Mark the group as enabled if at least on of its options is enabled.
      if (option.disabled === false) {
        optgroup.removeAttribute('disabled', '');
        optgroup.disabled = false;
      }
    }
  }

  // Add the remove button to the initial selected filters.
  function updateSelectedFilters(advancedSearch) {
    var elements = advancedSearch.filterSelection.querySelectorAll('[data-value]');
    for (var i = 0, l = elements.length; i < l; i++) {
      var element = elements[i];
      if (element.querySelector('button') === null) {
        element.appendChild(createButton({'class': 'remove'}, advancedSearch.labels.remove));
      }
    }
  }

  // Get the selected filter before a new filter with the given field and value.
  // This is used to find where to insert the new filter in simplified mode.
  function getPreviousSimplifiedFilter(advancedSearch, field, value) {
    var filterSelection = advancedSearch.filterSelection;

    // Skip if the filter already exists.
    if (filterSelection.querySelector('[data-value="' + field.code + value + '"]') !== null) {
      return false;
    }

    // Store the order of the filters so that the added values are in the
    // same order, which helps readability of the filter selection in simplified
    // mode.
    var filters = advancedSearch.filters;
    var indices = {};
    for (var i = 0, l = filters.length; i < l; i++) {
      indices[filters[i].code] = i;
    }

    var currentFieldIndex = indices[field.code];

    // Find the element after which to insert the new filter.
    var elements = filterSelection.querySelectorAll('[data-field]');
    for (var i = elements.length - 1; i >= 0; i--) {
      var element = elements[i];
      if (indices[element.getAttribute('data-field')] <= currentFieldIndex) {
        return element;
      }
    }
    return null;
  }

  // Create a filter selection.
  function createSelectedFilter(advancedSearch, field, value, label, operator) {
    var filterSelection = advancedSearch.filterSelection;
    var previous = null;

    // In simplified mode, get the filter after which to insert the new one.
    if (!advancedSearch.advancedMode) {
      var previous = getPreviousSimplifiedFilter(advancedSearch, field, value);
      // If null, then it means this new filter is the first selected one.
      if (previous === null) {
        operator = 'with';
      }
      // Otherwise if the previous filter doesn't have the same field code,
      // we create a new group.
      else if (previous && previous.getAttribute('data-field') !== field.code) {
        operator = 'and-with';
      }
    }

    // If previous is false, then it means the filter already exists and
    // we skip the creation.
    if (previous !== false) {
      var operator = createOperatorSwitcher(advancedSearch, operator);

      var filter = createElement('div', {
        'data-value': field.code + value
      }, [
        createElement('span', {'class': 'field'}, field.name + ': '),
        createElement('span', {'class': 'label'}, label),
        createButton({'class': 'remove'}, advancedSearch.labels.remove)
      ]);

      var container = createElement('div', {
        'data-field': field.code,
        'aria-label': field.name
      }, [operator, filter]);

      // In simplified mode, insert the new filter after the previous one or at
      // the beginning.
      if (!advancedSearch.advancedMode) {
        filterSelection.insertBefore(container, previous ? previous.nextSibling : filterSelection.firstChild);
      }
      else {
        filterSelection.appendChild(container);
      }

      // Ensure the other operators and the operator selector have
      // consistent values.
      updateOperatorSwitchers(advancedSearch);
    }

    // Announce the added filter and the resulting full selection.
    var live = document.getElementById('river-advanced-search-selection-announcement');
    live.innerHTML = advancedSearch.announcements.addFilter
    .replace('_field_', field.name)
    .replace('_label_', label)
    .replace('_selection_', readSelection(advancedSearch.filterSelection));
  }

  // Detect filter mode (simplified or advanced).
  function isAdvancedMode(filterSelection) {
    var element = filterSelection.firstChild;
    var previous = null;
    while (element) {
      if (element.hasAttribute && element.hasAttribute('data-field')) {
        var operator = element.querySelector('[data-operator]').getAttribute('data-operator');
        var field = element.getAttribute('data-field');

        // Same fields separated by a group starting operator.
        if (field === previous && operator.indexOf('with') !== -1) {
          return true;
        }

        switch (operator) {
          // Those operators are only available in advanced mode.
          case 'or-with':
          case 'or-without':
          case 'and-without':
          case 'without':
            return true;

          // Mixed fields inside a group is only available in advanced mode.
          case 'or':
          case 'and':
            if (field !== previous) {
              return true;
            }
            break;
        }
        previous = field;
      }
      element = element.nextSibling;
    }
    // Simplified mode.
    return false;
  }

  // Generate the advanced search parameter from the filter selection.
  function parseFilterSelection(filterSelection, nested, first) {
    var element = filterSelection.firstChild;
    var result = '';
    while (element) {
      if (element.hasAttribute) {
        if (element.hasAttribute('data-field')) {
          result += parseFilterSelection(element, true, result === '');
        }
        else if (element.hasAttribute('data-operator')) {
          switch (element.getAttribute('data-operator')) {
            case 'and':
              result += '_';
              break;
            case 'or':
              result += '.';
              break;
            case 'or-with':
              result += ').(';
              break;
            case 'or-without':
              result += ').!(';
              break;
            case 'and-with':
              result += ')_(';
              break;
            case 'and-without':
              result += ')_!(';
              break;
            case 'with':
              result += '(';
              break;
            case 'without':
              result += '!(';
              break;
            case 'any':
            case 'all':
              result += first ? '(' : ')_(';
              break;
          }
        }
        else if (element.hasAttribute('data-value')) {
          result += element.getAttribute('data-value');
        }
      }
      element = element.nextSibling;
    }
    var suffix = nested !== true ? ')' : '';
    return result ? result + suffix : '';
  }

  // Parse the selection into a readable message.
  function readSelection(selection, first) {
    var element = selection.firstChild;
    var parts = [];
    while (element) {
      if (element.hasAttribute) {
        if (element.hasAttribute('data-field')) {
          parts.push(readSelection(element, parts.length === 0));
        }
        else if (element.hasAttribute('data-operator')) {
          var operator = element.getAttribute('data-operator');
          if (operator === 'any' || operator === 'all') {
            operator = first ? 'with' : 'and-with';
          }
          parts.push(operator.replace('-', ' '));
        }
        else if (element.hasAttribute('data-value')) {
          parts.push(getText(element.querySelector('.field')));
          parts.push(getText(element.querySelector('.label')));
        }
      }
      element = element.nextSibling;
    }
    return parts.join(' ');
  }

  /**
   * Form creation.
   */

  // Create the operator selector.
  function createOperatorSelector(advancedSearch) {
    var id = 'river-advanced-search-operator-selector';

    var select = createElement('select', {
      'id': id,
      'class': advancedSearch.classPrefix + 'operator-selector'
    });
    var label = createLabel(id, advancedSearch.labels.operatorSelector, {
      'class': advancedSearch.classPrefix + 'operator-selector-label'
    });

    var labels = advancedSearch.labels.operators;
    var groups = advancedSearch.operators;
    for (var i = 0, l = groups.length; i < l; i++) {
      var group = groups[i];
      var options = group.options;
      var optgroup = createElement('optgroup', {label: group.label});
      for (var j = 0, m = options.length; j < m; j++) {
        var option = options[j];
        optgroup.appendChild(createOption(option, labels[option]));
      }
      select.appendChild(optgroup);
    }

    // Keep track of the operator selector as it's used in many places.
    advancedSearch.operatorSelector = select;

    return createFragment(label, select);
  }

  // Create the field selector.
  function createFieldSelector(advancedSearch) {
    var id = 'river-advanced-search-field-selector';

    var options = [];
    var filters = advancedSearch.filters;
    for (var i = 0, l = filters.length; i < l; i++) {
      var filter = filters[i];
      options.push(createOption(filter.code, filter.name));
    }
    var select = createElement('select', {
      'id': id,
      'class': advancedSearch.classPrefix + 'field-selector'
    }, options);
    var label = createLabel(id, advancedSearch.labels.fieldSelector, {
      'class': advancedSearch.classPrefix + 'field-selector-label'
    });

    // Keep track of the field selector as it's used in many places.
    advancedSearch.fieldSelector = select;

    // Switch widget when changing the field.
    addEventListener(select, 'change', function (event) {
      switchWidget(advancedSearch, true);
    });

    return createFragment(label, select);
  }

  // Create widget wrapper.
  function createWidgetWrapper(advancedSearch, filter, content) {
    content.unshift(createElement('legend', {
      'class': 'visually-hidden'
    }, advancedSearch.labels.filter.replace('_filter_', filter.name)));

    return createElement('fieldset', {
      'data-code': filter.code,
      'data-widget': filter.widget.type
    }, content);
  }

  // Create autocomplete widget.
  function createAutocompleteWidget(advancedSearch, filter) {
    var id = 'river-advanced-search-autocomplete-widget-' + filter.code;

    var input = createElement('input', {
      'id': id,
      'type': 'search',
      'autocomplete': 'off',
      'placeholder': advancedSearch.placeholders.autocomplete,
      'data-with-autocomplete': filter.widget.url
    });

    var label = createLabel(id, filter.widget.label);

    return createWidgetWrapper(advancedSearch, filter, [label, input]);
  }

  // Create keyword widget.
  function createKeywordWidget(advancedSearch, filter) {
    var id = 'river-advanced-search-keyword-widget-' + filter.code;

    var input = createElement('input', {
      'id': id,
      'type': 'search',
      'autocomplete': 'off',
      'placeholder': advancedSearch.placeholders.keyword
    });

    var label = createLabel(id, filter.widget.label);

    return createWidgetWrapper(advancedSearch, filter, [label, input]);
  }

  // Create options widget.
  function createOptionsWidget(advancedSearch, filter) {
    var id = 'river-advanced-search-options-widget-' + filter.code;
    var values = filter.widget.options;

    var options = [createOption('', advancedSearch.labels.emptyOption, {
      selected: 'selected'
    })];
    for (var i = 0, l = values.length; i < l; i++) {
      var value = values[i];
      options.push(createOption(value.id, value.name));
    }

    var select = createElement('select', {'id': id}, options);

    var label = createLabel(id, filter.widget.label);

    return createWidgetWrapper(advancedSearch, filter, [label, select]);
  }

  // Create date widget.
  function createDateWidget(advancedSearch, filter) {
    var id = 'river-advanced-search-date-widget-' + filter.code;

    var content = [
      createElement('legend', {}, filter.widget.label),
      // From date selector.
      createLabel(id + '-from', advancedSearch.labels.dateFrom),
      createElement('input', {
        'id': id + '-from',
        'type': 'text',
        'autocomplete': 'off',
        'placeholder': advancedSearch.placeholders.dateFrom,
        'data-from': '',
        'data-with-datepicker': '',
        'aria-label': filter.name + ' - ' + advancedSearch.labels.dateFrom
      }),
      // To date selector.
      createLabel(id + '-to', advancedSearch.labels.dateTo),
      createElement('input', {
        'id': id + '-to',
        'type': 'text',
        'autocomplete': 'off',
        'placeholder': advancedSearch.placeholders.dateTo,
        'data-to': '',
        'data-with-datepicker': '',
        'aria-label': filter.name + ' - ' + advancedSearch.labels.dateTo
      })
    ];

    return createElement('fieldset', {
      'data-code': filter.code,
      'data-widget': filter.widget.type
    }, content);
  }

  // Create the list of widget form elements.
  function createWidgetList(advancedSearch) {
    var container = createElement('div', {
      'id': 'river-advanced-search-widget-list'
    });

    var filters = advancedSearch.filters;
    for (var i = 0, l = filters.length; i < l; i++) {
      var filter = filters[i];
      switch (filter.widget.type) {
        case 'autocomplete':
          container.appendChild(createAutocompleteWidget(advancedSearch, filter));
          break;

        case 'keyword':
          container.appendChild(createKeywordWidget(advancedSearch, filter));
          break;

        case 'options':
          container.appendChild(createOptionsWidget(advancedSearch, filter));
          break;

        case 'date':
          container.appendChild(createDateWidget(advancedSearch, filter));
          break;
      }
    }

    // Keep track of widget list as it's used in many places.
    advancedSearch.widgetList = container;

    return container;
  }

  // Create the filter selector container.
  function createFilterSelector(advancedSearch) {
    // Button to cancel or add a filter.
    var cancel = createButton({'data-cancel': ''}, advancedSearch.labels.cancel);
    var add = createButton({'data-add': ''}, advancedSearch.labels.add);

    // Filter selector dialog container.
    var dialog = createElement('div', {
      'id': 'river-advanced-search-filter-selector',
      'role': 'dialog',
      'aria-modal': false,
      'aria-labelledby': 'river-advanced-search-filter-selector-title',
      'data-hidden': true,
      'class': advancedSearch.classPrefix + 'filter-selector'
    }, [
      createElement('h' + (advancedSearch.headingLevel + 1), {
        'id': 'river-advanced-search-filter-selector-title',
        'class': advancedSearch.classPrefix + 'filter-selector__title'
      }, advancedSearch.labels.filterSelector),
      createOperatorSelector(advancedSearch),
      createFieldSelector(advancedSearch),
      createWidgetList(advancedSearch),
      createElement('div', {}, [cancel, add])
    ]);

    // Keep track of the buttons and dialog.
    advancedSearch.dialog = dialog;
    advancedSearch.dialogAdd = add;
    advancedSearch.dialogCancel = cancel;

    // Cancel the filter addition, clear the widget and close the dialog.
    addEventListener(cancel, 'click', function (event) {
      var widget = getActiveWidget(advancedSearch.widgets);

      widget.clear();
      toggleDialog(advancedSearch);
    });

    // Add a filter, clear the widget and close the dialog.
    addEventListener(add, 'click', function (event) {
      var widget = getActiveWidget(advancedSearch.widgets);
      var value = widget.value();
      var label = widget.label();

      if (value) {
        var operator = advancedSearch.operatorSelector.value;
        var field = getSelectedField(advancedSearch.fieldSelector);
        createSelectedFilter(advancedSearch, field, value, label, operator);
      }

      widget.clear();
      toggleDialog(advancedSearch);
    });

    // Cancel when pressing escape and wrap focus inside the dialog.
    addEventListener(dialog, 'keydown', function (event) {
      var key = event.which || event.keyCode;

      // Close the dialog when pressing escape.
      if (key === KeyCodes.ESC) {
        preventDefault(event);
        triggerEvent(cancel, 'click');
      }
      // Wrap the focus inside the dialog.
      else if (key === KeyCodes.TAB) {
        var firstInteractiveElement;
        if (advancedSearch.advancedMode) {
          firstInteractiveElement = dialog.querySelector('input, select');
        }
        else {
          firstInteractiveElement = getActiveWidget(advancedSearch.widgets).element.querySelector('input, select');
        }
        if (!event.shiftKey && event.target === add) {
          preventDefault(event);
          stopPropagation(event);
          firstInteractiveElement.focus();
        }
        else if (event.shiftKey && event.target === firstInteractiveElement) {
          preventDefault(event);
          stopPropagation(event);
          add.focus();
        }
      }
    });

    return dialog;
  }

  // Create combined filter selector toggler.
  function createCombinedFilter(advancedSearch) {
    // Button to display the visibility of the filter selector.
    var toggler = createButton({
      'data-toggler': 'combined',
      'data-hidden': false
    }, [
      createElement('span', {'class': 'label'}, advancedSearch.labels.addFilter),
      createElement('span', {'class': 'label-suffix'}, advancedSearch.labels.addFilterSuffix)
    ]);

    // Show/hide the dialog and the toggler.
    addEventListener(toggler, 'click', function (event) {
      var focusTarget = advancedSearch.operatorSelector;
      if (!advancedSearch.advancedMode) {
        var focusTarget = advancedSearch.fieldSelector;
      }
      // Move the dialog to the parent container.
      toggler.parentNode.appendChild(advancedSearch.dialog);

      advancedSearch.activeToggler = toggler;
      toggleDialog(advancedSearch, focusTarget);
    });

    return toggler;
  }

  // Create simplified filters which are buttons that open the filter dialog.
  function createSimplifiedFilters(advancedSearch) {
    var container = createElement('div', {
      'id': 'river-advanced-search-simplified-filters',
      'class': advancedSearch.classPrefix + 'simplified-filters'
    });

    var label = advancedSearch.labels.simplifiedFilter;
    var filters = advancedSearch.filters;
    for (var i = 0, l = filters.length; i < l; i++) {
      var filter = filters[i];

      var button = createButton({
        'aria-label': label.replace('_filter_', filter.name),
        'data-hidden': false,
        'data-toggler': 'single',
        'data-field': filter.code,
        'data-operator': (filter.operator || 'or').toLowerCase()
      }, [
        createElement('span', {'class': 'label'}, filter.name)
      ]);

      container.appendChild(createElement('div', {}, button));
    }

    // Handle button clicks.
    addEventListener(container, 'click', function (event) {
      var target = event.target;

      if (target.nodeName === 'SPAN') {
        target = target.parentNode;
      }

      if (target.nodeName === 'BUTTON' && target.getAttribute('data-toggler') === 'single') {
        // Hide the dialog and show the previous toggler.
        toggleDialog(advancedSearch);

        // Move the dialog to the parent container.
        target.parentNode.appendChild(advancedSearch.dialog);

        // Change the field selector.
        advancedSearch.fieldSelector.value = target.getAttribute('data-field');

        // Change the operator selector depending on the mode.
        if (!advancedSearch.advancedMode) {
          advancedSearch.operatorSelector.value = target.getAttribute('data-operator');
        }
        else {
          updateOperatorSelector(advancedSearch);
        }

        // Switch the widget.
        var widget = switchWidget(advancedSearch);

        // Focus the operator selector or the field input/select in simplified
        // mode.
        var focusTarget = advancedSearch.operatorSelector;
        if (!advancedSearch.advancedMode) {
          focusTarget = widget.element.querySelector('input, select');
        }

        // Keep track of the active toggler for the dialog.
        advancedSearch.activeToggler = target;

        // Show the dialog.
        toggleDialog(advancedSearch, focusTarget);
      }
    });

    advancedSearch.simplifiedFilters = container;

    return container;
  }

  // Create form actions.
  function createActions(advancedSearch) {
    var clear = createButton({'data-clear': ''}, advancedSearch.labels.clear);
    var apply = createButton({'data-apply': ''}, advancedSearch.labels.apply);
    var title = createElement('h' + (advancedSearch.headingLevel + 1), {
      class: 'visually-hidden'
    }, advancedSearch.labels.formActions);

    // Keep track of the action buttons so they can be hidden/shown depending
    // on the filter selection.
    advancedSearch.clear = clear;
    advancedSearch.apply = apply;

    // Hide/show the buttons.
    updateFilterSelection(advancedSearch);

    // Clear advanced search.
    addEventListener(clear, 'click', function (event) {
      advancedSearch.parameter.value = '';
      triggerEvent(advancedSearch.submitForm, 'submit');
    });

    // Apply advanced search filter selection.
    addEventListener(apply, 'click', function (event) {
      triggerEvent(advancedSearch.submitForm, 'submit');
    });

    return createElement('section', {
      'class': advancedSearch.classPrefix + 'actions'
    }, [title, clear, apply]);
  }

  // Create the checkbox to active advanced search mode.
  function createAdvancedModeSwitch(advancedSearch) {
    // Check if the advanced mode is on based on the filter selection.
    var enabled = isAdvancedMode(advancedSearch.filterSelection);

    // Keep track of the mode.
    advancedSearch.container.setAttribute('data-advanced-mode', enabled);
    advancedSearch.advancedMode = enabled;

    var checkbox = createElement('input', {
      'id': 'river-advanced-search-advanced-mode-switch',
      'type': 'checkbox'
    });
    checkbox.checked = enabled;

    var label = createElement('label', {
      'for': 'river-advanced-search-advanced-mode-switch'
    }, advancedSearch.labels.advancedMode);

    var link = advancedSearch.container.querySelector('[href="/search-help"]').cloneNode(true);
    setText(link, getText(link) + ' - ' + advancedSearch.labels.advancedMode);
    link.setAttribute('href', link.getAttribute('href') + '#advanced');

    var container = createElement('div', {
      'id': 'river-advanced-search-advanced-mode-switch-container',
      'class': advancedSearch.classPrefix + 'advanced-mode-switch-container'
    }, [checkbox, label, link]);

    addEventListener(checkbox, 'click', function (event) {
      var enabled = checkbox.checked;

      // Clear the selection when switching to simplified mode as it's not
      // compatible with the complex queries of the advanced mode.
      if (!enabled && !advancedSearch.container.hasAttribute('data-empty')) {
        if (window.confirm(advancedSearch.labels.changeMode)) {
          triggerEvent(advancedSearch.clear, 'click');
        }
        else {
          preventDefault(event);
        }
      }
      else {
        // Update the operator selectors when switching to advanced mode.
        advancedSearch.container.setAttribute('data-advanced-mode', enabled);
        advancedSearch.advancedMode = enabled;
        createOperatorSwitchers(advancedSearch);
      }
    });

    return container;
  }

  // Create the actual advanced search form content.
  function createFormContent(advancedSearch) {
    var content = document.querySelector('#river-advanced-search-form-content');
    content.appendChild(createCombinedFilter(advancedSearch));
    content.appendChild(createActions(advancedSearch));
    content.appendChild(createSimplifiedFilters(advancedSearch));
    content.appendChild(createFilterSelector(advancedSearch));
    content.appendChild(createAdvancedModeSwitch(advancedSearch));
    content.removeAttribute('hidden');
    return content;
  }

  // Create the autocomplete and datepicker widgets.
  function createWidgets(advancedSearch) {
    var widgets = [];
    var elements = advancedSearch.widgetList.querySelectorAll('[data-code]');
    if (elements) {
      for (var i = 0, l = elements.length; i < l; i++) {
        widgets.push(createWidget(advancedSearch, elements[i]));
      }
    }

    // Hide widgets when clicking outside of their containers.
    addEventListener(document, 'click', function (event) {
      var target = event.target;
      for (var i = 0, l = widgets.length; i < l; i++) {
        var widget = widgets[i];
        if (widget.active() && !contains(widget.element, target)) {
          widget.hide();
        }
      }
    });

    advancedSearch.widgets = widgets;
  }

  // Create the operator switchers for the operators in the current selection.
  function createOperatorSwitchers(advancedSearch) {
    // Update the operator switchers in the selection.
    var elements = advancedSearch.filterSelection.querySelectorAll('[data-operator]');
    if (elements) {
      for (var i = 0, l = elements.length; i < l; i++) {
        createOperatorSwitcher(advancedSearch, elements[i]);
      }
    }

    // Update the operators.
    updateOperatorSwitchers(advancedSearch);
  }

  // Update advanced search, adding the form.
  function updateAdvancedSearch(advancedSearch) {
    var container = document.getElementById('river-advanced-search');
    var form = document.getElementById('river-advanced-search-form');
    var filterSelection = document.getElementById('river-advanced-search-selection');

    // Skip if we are missing any mandatory element.
    if (!form || !container || !filterSelection) {
      return;
    }

    advancedSearch.form = form;
    advancedSearch.container = container;
    advancedSearch.filterSelection = filterSelection;

    // Ensure the class prefixes are defined.
    advancedSearch.classPrefix = advancedSearch.classPrefix || '';
    advancedSearch.widgetClassPrefix = advancedSearch.widgetClassPrefix || '';

    // If the search form exists, we use it for submission, otherwise we use
    // the advanced search form.
    advancedSearch.submitForm = document.getElementById('river-search-form') || form;

    // Get or create the advanced search parameter input.
    var parameter = advancedSearch.submitForm.querySelector('input[name="advanced-search"]');
    if (!parameter) {
      parameter = createElement('input', {type: 'hidden', name: 'advanced-search'});
      // Try to preserve the order of the parameters.
      var view = advancedSearch.submitForm.querySelector('input[name="view"]');
      advancedSearch.submitForm.insertBefore(parameter, view ? view.nextSibling : advancedSearch.submitForm.firstChild);
    }
    advancedSearch.parameter = parameter;

    // Store the original advanced search selection.
    advancedSearch.originalSelection = parameter.value || '';

    // Map the fields to their default operator.
    var defaults = {};
    var filters = advancedSearch.filters;
    for (var i = 0, l = filters.length; i < l; i++) {
      var filter = filters[i];
      defaults[filter.code] = (filter.operator || 'or').toLowerCase();
    }
    advancedSearch.defaultOperators = defaults;

    // Global ID increment.
    advancedSearch.id = 0;

    // Add the form with the operator, field and filter selectors.
    createFormContent(advancedSearch);

    // Add the remove buttons to the initial selected filters.
    updateSelectedFilters(advancedSearch);

    // Create the autocomplete and datepicker widgets.
    createWidgets(advancedSearch);

    // Create the operator switchers.
    createOperatorSwitchers(advancedSearch);

    // Enable the first widget.
    switchWidget(advancedSearch);

    // Remove a selected filter when clicking on its remove button
    // and update the operators to ensure consistency.
    addEventListener(filterSelection, 'click', function (event) {
      var target = event.target;
      if (target.nodeName === 'BUTTON' && target.parentNode.hasAttribute('data-value')) {
        var filter = target.parentNode;
        var field = getText(filter.querySelector('.field'));
        var label = getText(filter.querySelector('.label'));

        filterSelection.removeChild(target.parentNode.parentNode);
        updateOperatorSwitchers(advancedSearch);

        // Announce the filter removal and the resulting selection.
        var live = document.getElementById('river-advanced-search-selection-announcement');

        if (filterSelection.querySelector('[data-value]') !== null) {
          live.innerHTML = advancedSearch.announcements.removeFilter
          .replace('_field_', field)
          .replace('_label_', label)
          .replace('_selection_', readSelection(advancedSearch.filterSelection));
        }
        else {
          live.innerHTML = advancedSearch.announcements.removeFilterEmpty
          .replace('_field_', field)
          .replace('_label_', label);
        }
      }
    });
  }

  /**
   * Main logic.
   */
  if (reliefweb && reliefweb.advancedSearch) {
    updateAdvancedSearch(reliefweb.advancedSearch);
  }
})();
