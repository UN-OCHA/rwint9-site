(function () {

  'use strict';

  var t = Drupal.t;

  /**
   * Object constructor to handle the field's logic.
   */
  function ReliefWebUserPostingRightsField(field) {
    this.field = field;

    // Field containing the serialized data.
    this.data = field.querySelector('input[data-drupal-selector$="-data"]');
  }

  /**
   * Prototype of the field logic handler.
   */
  ReliefWebUserPostingRightsField.prototype = {

    /**
     * Create a button element.
     */
    createButton: function (value, label, action, name, disabled) {
      var button = document.createElement('button');
      button.setAttribute('type', 'button');
      button.setAttribute('value', value);
      if (action) {
        button.setAttribute('data-action', '');
      }
      if (name) {
        button.setAttribute('name', name);
      }
      if (disabled) {
        button.setAttribute('disabled', '');
      }
      button.appendChild(document.createTextNode(label));

      return button;
    },

    /**
     * Create a select element.
     */
    createSelect: function (name, selected, disabled, all) {
      var select = document.createElement('select');
      select.setAttribute('data-name', name);

      var span = document.createElement('span');
      span.appendChild(document.createTextNode(name));

      var label = document.createElement('label');
      label.appendChild(span);

      // Add "empty" and "any" options.
      if (all === true) {
        var option = document.createElement('option');
        option.appendChild(document.createTextNode(t('Any')));
        option.setAttribute('value', 'all');
        option.setAttribute('selected', '');
        select.appendChild(option);
        selected = 'all';
      }

      var options = [t('Unverified'), t('Blocked'), t('Allowed'), t('Trusted')];
      for (var i = 0, l = options.length; i < l; i++) {
        var option = document.createElement('option');
        option.appendChild(document.createTextNode(options[i]));
        option.setAttribute('value', i);
        if (selected === i) {
          option.setAttribute('selected', '');
        }
        select.appendChild(option);
      }

      if (disabled) {
        select.setAttribute('disabled', '');
      }

      label.appendChild(select);
      label.className = name;

      return label;
    },

    /**
     * Create a filter for users.
     */
    createUserSelect: function () {
      let data = this.getFieldData();
      let name = 'name';

      var select = document.createElement('select');
      select.setAttribute('data-name', name);

      var span = document.createElement('span');
      span.appendChild(document.createTextNode(name));

      var label = document.createElement('label');
      label.appendChild(span);

      var option = document.createElement('option');
      option.appendChild(document.createTextNode(t('Any')));
      option.setAttribute('value', 'all');
      option.setAttribute('selected', '');
      select.appendChild(option);

      // List content in reverse order (most recently added first).
      for (var i = data.length - 1; i >= 0; i--) {
        var option = document.createElement('option');
        option.appendChild(document.createTextNode(data[i].name));
        option.setAttribute('value', data[i].name);
        select.appendChild(option);
      }

      label.appendChild(select);
      label.className = name;

      return label;
    },

    /**
     * Create the user notes field.
     */
    createEntryNotes: function (data, disabled) {
      var textarea = document.createElement('textarea');
      textarea.setAttribute('rows', 1);
      textarea.appendChild(document.createTextNode(data.notes));

      if (disabled) {
        textarea.setAttribute('disabled', '');
      }

      var label = document.createElement('label');
      label.appendChild(document.createTextNode(t('Notes')));
      label.setAttribute('data-notes', '');
      label.appendChild(textarea);

      return label;
    },

    /**
     * Create the user info row (id, name, rights).
     */
    createEntry: function (data) {
      var container = document.createElement('li');
      container.setAttribute('data-id', data.id);

      var disabled = false;

      // Rights info.
      container.setAttribute('data-status', data.status ? 'active' : 'blocked');
      container.setAttribute('data-job', data.job);
      container.setAttribute('data-training', data.training);
      container.setAttribute('data-report', data.report);
      container.setAttribute('data-name', data.name);

      // User info.
      var info = document.createElement('div');
      info.setAttribute('data-info', '');

      var id = document.createElement('a');
      id.setAttribute('href', '/user/' + data.id);
      id.appendChild(document.createTextNode(data.id));
      info.appendChild(id);

      var name = document.createElement('span');
      name.appendChild(document.createTextNode(data.name));
      name.setAttribute('data-label', 'name');
      info.appendChild(name);

      var mail = document.createElement('span');
      mail.appendChild(document.createTextNode(data.mail));
      mail.setAttribute('data-label', 'mail');
      info.appendChild(mail);

      // Disable the edit components if the user is blocked (status == 0).
      disabled = !data.status;

      // Actions (change rights, remove entry).
      var actions = document.createElement('div');
      actions.setAttribute('data-actions', '');

      // Rights.
      actions.appendChild(this.createSelect('job', data.job, disabled));
      actions.appendChild(this.createSelect('training', data.training, disabled));
      actions.appendChild(this.createSelect('report', data.report, disabled));

      // Remove.
      actions.appendChild(this.createButton('remove', t('Remove'), true, '', disabled));

      container.appendChild(info);
      container.appendChild(actions);

      // Notes.
      container.appendChild(this.createEntryNotes(data, disabled));

      return container;
    },

    /**
     * Create a field's user list.
     */
    createList: function (data) {
      var container = document.createElement('ul');

      // List content in reverse order (most recently added first).
      for (var i = data.length - 1; i >= 0; i--) {
        container.appendChild(this.createEntry(data[i]));
      }

      return container;
    },

    /**
     * Create a new user edit form for a field.
     */
    createNewEntryForm: function () {
      var container = document.createElement('div');
      container.setAttribute('data-new-form', '');

      var input = document.createElement('input');
      input.setAttribute('type', 'text');
      input.setAttribute('data-name', 'new');
      input.setAttribute('placeholder', t('User ID or email address'));

      var label = document.createElement('label');
      label.appendChild(document.createTextNode('user'));
      label.appendChild(input);

      container.appendChild(label);
      container.appendChild(this.createButton('add', t('Add'), true));
      container.appendChild(this.createButton('clear', t('Clear')));

      return container;
    },

    /**
     * Create the filters to filter the list of users.
     */
    createFilters: function () {
      var container = document.createElement('div');
      container.setAttribute('data-filters', '');
      container.setAttribute('data-job', 'all');
      container.setAttribute('data-training', 'all');
      container.setAttribute('data-report', 'all');
      container.setAttribute('data-name', 'all');

      var title = document.createElement('span');
      title.appendChild(document.createTextNode(t('Filter: ')));
      container.appendChild(title);

      // Rights filters.
      container.appendChild(this.createSelect('job', '', false, true));
      container.appendChild(this.createSelect('training', '', false, true));
      container.appendChild(this.createSelect('report', '', false, true));

      // User filter.
      container.appendChild(this.createUserSelect());

      return container;
    },

    /**
     * Create a fieldset with new entry form and a list for a field.
     */
    createFieldForm: function () {
      var list = this.getFieldData();
      var settings = this.getFieldSettings();

      var container = document.createElement('div');
      container.setAttribute('data-field', settings.field);
      container.setAttribute('data-label', settings.label);
      container.setAttribute('data-settings-validate-url', settings.validateUrl);

      // Link to the save button after the legend.
      var link = document.createElement('a');
      link.setAttribute('href', '#actions');
      link.appendChild(document.createTextNode(t('jump to save button')));

      var legend = this.field.querySelector('legend');
      if (legend) {
        legend.parentNode.insertBefore(link, legend.nextElementSibling);
      }
      else {
        container.appendChild(link);
      }

      // Creater the form content.
      container.appendChild(this.createFilters());
      container.appendChild(this.createNewEntryForm());
      container.appendChild(this.createList(list));

      // Add the form to the field.
      this.data.parentNode.insertBefore(container, this.data.nextElementSibling);

      // Handle click events on the different buttons in the form.
      container.addEventListener('click', this.handleClick.bind(this));

      // Handle change events on the different select elements in the form.
      container.addEventListener('change', this.handleChange.bind(this));

      // Handle focus out events from notes fields.
      container.addEventListener('focusout', this.handleFocusOut.bind(this));

      // Save the container.
      return container;
    },

    /**
     * Remove error messages for the given field.
     */
    resetError: function (field) {
      // Remove existing error messages in this container.
      var messages = field.querySelectorAll('[data-error-message]');
      for (var i = 0, l = messages.length; i < l; i++) {
        var message = messages[i];
        message.parentNode.removeChild(message);
      }

      // Remove existing highligted errors.
      var elements = field.querySelectorAll('[data-error]');
      for (var i = 0, l = elements.length; i < l; i++) {
        elements[i].removeAttribute('data-error');
      }
    },

    /**
     * Display an error message at the top of a field fieldset.
     */
    displayError: function (field, element, error) {
      // Remove existing error messages in this container.
      this.resetError(field);

      // Display the error message and highlight the faulty element.
      if (typeof error === 'string' && error !== '') {
        var message = document.createElement('div');
        message.setAttribute('class', 'messages error cd-form__error-message');
        message.setAttribute('data-error-message', '');
        message.appendChild(document.createTextNode(error));

        // Add the message at the top of the fieldset.
        var sibling = field.querySelector('div[data-new-form]');
        if (sibling) {
          sibling.parentNode.insertBefore(message, sibling);
        }

        // Mark the element as erroneous.
        element.setAttribute('data-error', '');
      }
    },

    /**
     * Enable/disable the form fieldsets, preventing any action.
     */
    disableForm: function (disable) {
      if (disable === true) {
        this.form.disabled = true;
        this.field.setAttribute('data-loading', '');
      }
      else {
        this.field.removeAttribute('data-loading');
        this.form.disabled = false;
      }
    },

    /**
     * Check if the given field already contains the user.
     */
    checkDuplicate: function (field, element, id) {
      var entries = field.querySelectorAll('li[data-id]');
      for (var i = 0, l = entries.length; i < l; i++) {
        var entry = entries[i];
        if (entry !== element && entry.getAttribute('data-id') == id) {
          return t('The user already exists in this field.');
        }
      }
      return '';
    },

    /**
     * Validate a user, calling a validation endpoint for the field.
     */
    validateData: function (data, field, element, callback) {
      var self = this;
      var settings = this.getFieldSettings();
      // Disable the form while validating the user data.
      this.disableForm(true);
      // Validate the user.
      var xhr = new XMLHttpRequest();
      // Process the response.
      xhr.addEventListener('load', function () {
        var data = null;
        var error = '';
        try {
          data = JSON.parse(xhr.responseText);
          // Error message from the validation endpoint or from duplication.
          error = data.error || self.checkDuplicate(field, element, data.id);
        }
        // eslint-disable-next-line no-unused-vars
        catch (exception) {
          error = t('Unable to parse response.');
        }
        self.displayError(field, element, error);
        callback.call(self, error === '' ? data : null);
        self.disableForm(false);
      });
      // Display error message in case of failure.
      xhr.addEventListener('error', function () {
        self.displayError(field, element, t('Request failed.'));
        callback.call(self, null);
        self.disableForm(false);
      });
      // Send the data as JSON to the validation url for the field.
      xhr.open('POST', settings.validateUrl);
      xhr.send(JSON.stringify(data));
    },

    /**
     * Extract the user list for a given field.
     */
    parseList: function (field) {
      var entries = this.field.querySelectorAll('li[data-id]');

      // List of users.
      var list = [];

      // List of already added users to avoid duplicates.
      var added = {};

      // Parse the list items in reverse order to restore the original order.
      for (var i = entries.length - 1; i >= 0; i--) {
        var data = this.getEntryData(entries[i]);
        // Silently ignore duplicates. Unless there is a bug somewhere, there
        // shouldn't be any as we check for duplicates when adding or updating
        // a user.
        if (!added.hasOwnProperty(data.id)) {
          list.push(data);
          added[data.id] = true;
        }
      }

      return list;
    },

    /**
     * Empty a new user form.
     */
    emptyNewEntryForm: function (element) {
      element.querySelector('input[data-name="new"]').value = '';

      if (element.hasAttribute('data-error')) {
        this.resetError(this.getParentElement(element, 'FIELDSET'));
      }
    },

    /**
     * Extract the user data from the store.
     */
    getEntryData: function (element) {
      return {
        id: element.getAttribute('data-id'),
        job: Math.max(element.querySelector('select[data-name="job"]').selectedIndex, 0),
        training: Math.max(element.querySelector('select[data-name="training"]').selectedIndex, 0),
        report: Math.max(element.querySelector('select[data-name="report"]').selectedIndex, 0),
        notes: element.querySelector('textarea').value.trim()
      };
    },

    /**
     * Retrieve a field's settings.
     */
    getFieldSettings: function () {
      if (!this.settings) {
        this.settings = {
          field: this.data.getAttribute('data-settings-field'),
          label: this.data.getAttribute('data-settings-label'),
          validateUrl: this.data.getAttribute('data-settings-validate-url')
        };
      }
      return this.settings;
    },

    /**
     * Get the first parent that matches the tag name.
     */
    getParentElement: function (element, tagName) {
      while (element.tagName !== tagName) {
        element = element.parentNode;
      }
      return element;
    },

    /**
     * Handle click events on the different buttons in the form.
     */
    handleClick: function (event) {
      var target = event.target;

      if (target && target.tagName === 'BUTTON' && typeof target.value !== 'undefined') {
        event.stopPropagation();
        event.preventDefault();

        switch (target.value) {
          // Create a new user.
          case 'add':
            var element = this.getParentElement(target, 'DIV');
            var field = this.getParentElement(element, 'FIELDSET');
            var value = element.querySelector('input[data-name="new"]').value.trim();
            // Validate the user data.
            this.validateData({value: value}, field, element, function (data) {
              // If valid, add new entry at the bottom of the list.
              if (data !== null) {
                var container = field.querySelector('ul');
                var entry = this.createEntry(data, this.getFieldSettings());
                entry.setAttribute('data-modified', '');
                if (container.firstElementChild) {
                  container.insertBefore(entry, container.firstElementChild);
                }
                else {
                  container.appendChild(entry);
                }

                // Empty the new entry form.
                this.emptyNewEntryForm(element);
                this.updateData();
              }
            });
            break;

          // Empty a new entry form (empty the input fields).
          case 'clear':
            var element = this.getParentElement(target, 'DIV');
            this.emptyNewEntryForm(element);
            break;

          // Remove a user.
          case 'remove':
            var row = this.getParentElement(target, 'LI');
            if (confirm(t('Do you really want to remove this record?'))) {
              row.parentNode.removeChild(row);
            }
            this.updateData();
            break;
        }
      }
    },

    /**
     * Handle change events on the different select elements in the form.
     */
    handleChange: function (event) {
      var target = event.target;

      if (target && target.tagName === 'SELECT') {
        var name = target.getAttribute('data-name');

        // Update the rights attributes of the user row.
        if (name === 'job' || name === 'training' || name === 'report' || name === 'name') {
          var parent = target.parentNode.parentNode;

          // If the parent is not the filter container, then it's a select
          // from a user row and we get the list element.
          if (!parent.hasAttribute('data-filters')) {
            parent = this.getParentElement(target, 'LI');
          }

          // Set the attribute to the value of the select element.
          parent.setAttribute('data-' + name, target.value);
          parent.setAttribute('data-modified', '');
          this.updateData();

          // Filter on user name.
          if (parent.hasAttribute('data-filters') && name === 'name') {
            let grandParent = parent.parentNode;
            if (grandParent.querySelector('li[data-user-filtered]')) {
              grandParent.querySelector('li[data-user-filtered]').removeAttribute('data-user-filtered');
            }
            if (target.value !== 'all') {
              grandParent.querySelector('li[data-name="' + target.value + '"]').setAttribute('data-user-filtered', '');
            }
          }
        }
      }
    },

    /**
     * Handle focus out events from notes fields.
     */
    handleFocusOut: function (event) {
      var target = event.target;

      if (target && target.tagName === 'TEXTAREA') {
        // Update the data if the notes have changed.
        if (target.value !== target.defaultValue) {
          var parent = this.getParentElement(target, 'LI');
          parent.setAttribute('data-modified', '');
          this.updateData();
        }
      }
    },

    /**
     * Update the links data.
     */
    updateData: function () {
      // Temporily disable the form while processing the data.
      this.disableForm(true);
      // Parse the field entries and build the data to save.
      var data = this.parseList();
      // Update the data.
      this.setFieldData(data);
      // Restore use of the form.
      this.disableForm(false);
    },

    /**
     * Get the field's data.
     */
    getFieldData: function () {
      return this.data ? JSON.parse(this.data.value) : [];
    },

    /**
     * Update the field's data.
     */
    setFieldData: function (data) {
      this.data.value = data ? JSON.stringify(data) : '';
    },

    /**
     * Initialize the field.
     */
    initialize: function () {
      // Create the field form.
      this.form = this.createFieldForm();

      // Enable the form (starting state is disabled).
      this.disableForm(false);
    }
  };

  /**
   * Drupal behavior.
   */
  Drupal.behaviors.ReliefWebUserPostingRights = {
    attach: function (context, settings) {
      var fields = document.querySelectorAll('.field--type-reliefweb-user-posting-rights:not([data-processed])');

      for (var i = 0, l = fields.length; i < l; i++) {
        var field = fields[i];
        field.setAttribute('data-processed', '');
        var handler = new ReliefWebUserPostingRightsField(field);
        handler.initialize();
      }
    }
  };
})(Drupal);
