(function (Drupal) {
  'use strict';

  var t = Drupal.t;

  /**
   * Object constructor to handle the field's logic.
   */
  function ReliefWebSectionLinksField(field) {
    this.field = field;

    // Field containing the serialized data.
    this.data = field.querySelector('input[data-drupal-selector$="-data"]');

    // Store the links data (url, title, override)
    //
    // The store will grow when adding, updating, archiving and
    // unarchiving links. Each link row in the tables
    // will have a unique index.
    this.links = [];
  }

  /**
   * Prototype of the field logic handler.
   */
  ReliefWebSectionLinksField.prototype = {

    /**
     * Placeholders for the input fields.
     */
    placeholders: {
      url: t('River URL'),
      title: t('Link title'),
      override: t('Entity Id to promote to beginning of list (optional)')
    },

    /**
     * Create a button element.
     */
    createButton: function (value, label, action, name) {
      var button = document.createElement('button');
      button.setAttribute('type', 'button');
      button.setAttribute('value', value);
      if (action) {
        button.setAttribute('data-action', '');
      }
      if (name) {
        button.setAttribute('name', name);
      }
      button.appendChild(document.createTextNode(label));

      return button;
    },

    /**
     * Create a drag handle cell for an active link.
     */
    createLinkMove: function () {
      var container = document.createElement('td');
      container.setAttribute('data-drag-handle', '');

      return container;
    },

    /**
     * Create a display cell for an active link.
     */
    createLinkDisplay: function (link, settings) {
      var container = document.createElement('td');
      container.setAttribute('data-link-display', '');

      var title = document.createElement('a');
      title.setAttribute('href', link.url);
      title.setAttribute('target', '_blank');

      if (settings.useTitle && link.title) {
        title.appendChild(document.createTextNode(link.title));
      }
      else {
        title.appendChild(document.createTextNode(link.url));
      }

      if (settings.useOverride) {
        if (link.override && parseInt(link.override, 10)) {
          title.appendChild(document.createTextNode(' [override: ' + link.override + ']'));
        }
      }

      container.appendChild(title);

      return container;
    },

    /**
     * Create an edit form cell for an active link.
     */
    createLinkForm: function (link, settings) {
      var container = document.createElement('td');
      container.setAttribute('data-link-form', '');

      container.appendChild(this.createLinkFormComponent('url', link.url));

      if (settings.useTitle) {
        container.appendChild(this.createLinkFormComponent('title', link.title));
      }

      if (settings.useOverride) {
        container.appendChild(this.createLinkFormComponent('override', link.override, !settings.useOverride));
      }

      container.appendChild(this.createButton('update', t('Update'), true));
      container.appendChild(this.createButton('delete', t('Delete'), true));

      return container;
    },

    /**
     * Create an input form compoment for the given property.
     */
    createLinkFormComponent: function (name, value, hidden) {
      var input = document.createElement('input');
      input.setAttribute('value', value || '');
      input.setAttribute('data-name', name);

      // Use a hidden input if requested.
      if (hidden) {
        input.setAttribute('type', 'hidden');
        return input;
      }
      else {
        // Select the placeholder based on the type of link.
        input.setAttribute('placeholder', this.placeholders[name]);
        input.setAttribute('type', 'text');

        var label = document.createElement('label');
        label.appendChild(document.createTextNode(name));
        label.appendChild(input);

        return label;
      }
    },

    /**
     * Create a cell with a button to toggle the edit form for an active link.
     */
    createLinkEdit: function () {
      var container = document.createElement('td');
      container.appendChild(this.createButton('edit', t('Edit')));
      return container;
    },

    /**
     * Create a table for a field's active links.
     */
    createList: function (links, settings) {
      var container = document.createElement('table');
      container.setAttribute('data-list-active', '');
      container.setAttribute('data-link-count', links.length);

      // @todo add visually hidden table header and caption for accessibility.
      var body = document.createElement('tbody');
      for (var i = 0, l = links.length; i < l; i++) {
        body.appendChild(this.createLink(links[i], settings, true));
      }

      container.appendChild(body);

      // Add re-ordering handling (drag events) to the container.
      this.handleTableReordering(container);

      return container;
    },

    /**
     * Create a new link edit form for a field.
     */
    createNewLinkForm: function (settings) {
      var container = document.createElement('div');
      container.setAttribute('data-link-form', '');

      container.appendChild(this.createLinkFormComponent('url', ''));

      if (settings.useTitle) {
        container.appendChild(this.createLinkFormComponent('title', ''));
      }

      if (settings.useOverride) {
        container.appendChild(this.createLinkFormComponent('override', '', !settings.useOverride));
      }

      container.appendChild(this.createButton('add', t('Add'), true));
      container.appendChild(this.createButton('clear', t('Clear')));

      return container;
    },

    /**
     * Create a link.
     */
    createLink: function (link, settings, active) {
      var container = document.createElement('tr');

      // Set the id of the link to be retrieved from the store.
      container.setAttribute('data-id', this.createLinkId(link));

      if (settings.cardinality != 1) {
        container.appendChild(this.createLinkMove());
      }

      container.appendChild(this.createLinkDisplay(link, settings));
      container.appendChild(this.createLinkForm(link, settings));
      container.appendChild(this.createLinkEdit());

      return container;
    },

    /**
     * Create a unique link ID.
     */
    createLinkId: function (link) {
      return this.links.push(link) - 1;
    },

    /**
     * Create a fieldset with new link and lists for a field.
     */
    createFieldForm: function () {
      var links = this.getFieldData();
      var settings = this.getFieldSettings();

      var container = document.createElement('div');
      container.className = 'rw-section-links';

      // Create the form to add new links.
      this.newLinkForm = this.createNewLinkForm(settings);
      container.appendChild(this.newLinkForm);

      // Create the list of already added links.
      container.appendChild(this.createList(links, settings));

      // Hide the create new link form if necessary based on the cardinality.
      this.hideNewLinkForm(links.length);

      // Add the form to the field.
      this.data.parentNode.insertBefore(container, this.data.nextElementSibling);

      // Handle click events on the different buttons in the form.
      container.addEventListener('click', this.handleEvents.bind(this));

      return container;
    },

    /**
     * Handle re-ordering the links in an active list.
     */
    handleTableReordering: function (table) {
      var row = null;
      var body = document.querySelector('body');
      var self = this;

      // Check the hovered element is a sibling of the dragged element.
      // We use an offset of half the height of the element to prevent
      // abrupt swaps.
      function hovered(next, y) {
        if (next) {
          if (row.nextElementSibling) {
            var bounds = row.nextElementSibling.getBoundingClientRect();
            return y >= (bounds.top + (bounds.height / 2));
          }
        }
        else {
          if (row.previousElementSibling) {
            var bounds = row.previousElementSibling.getBoundingClientRect();
            return y <= (bounds.bottom - (bounds.height / 2));
          }
        }
        return false;
      }

      // Swap the dragged element with a sibling.
      function swap(event) {
        event.preventDefault();
        event.stopPropagation();

        if (hovered(false, event.clientY)) {
          row.parentNode.insertBefore(row, row.previousElementSibling);
        }
        else if (hovered(true, event.clientY)) {
          row.parentNode.insertBefore(row.nextElementSibling, row);
        }
      }

      // Stop the drag handling, swapping the rows and untracking events.
      function stop(event) {
        swap(event);
        body.removeAttribute('data-drag-on');
        row.removeAttribute('data-dragged');
        row = null;
        document.removeEventListener('mousemove', swap);
        document.removeEventListener('mouseup', stop);
        self.updateData();
      }

      // Start the re-ordering handling when there is a mousedown
      // event of a drag handle element.
      table.addEventListener('mousedown', function (event) {
        var target = event.target;
        if (target.hasAttribute('data-drag-handle')) {
          event.stopPropagation();
          event.preventDefault();

          row = self.getParentElement(target, 'TR');
          row.setAttribute('data-dragged', '');
          // Used to ensure the cursor stays consistent when dragging
          // outside the table.
          body.setAttribute('data-drag-on', '');

          // We need to track mouse events on the whole page.
          document.addEventListener('mousemove', swap);
          document.addEventListener('mouseup', stop);
        }
      });
    },

    /**
     * Remove error messages for the given field.
     */
    resetError: function () {
      // Remove existing error messages in this container.
      var messages = this.form.querySelectorAll('[data-error-message]');
      for (var i = 0, l = messages.length; i < l; i++) {
        var message = messages[i];
        message.parentNode.removeChild(message);
      }

      // Remove existing highligted errors.
      var elements = this.form.querySelectorAll('[data-error]');
      for (var i = 0, l = elements.length; i < l; i++) {
        elements[i].removeAttribute('data-error');
      }
    },

    /**
     * Display an error message at the top of a field fieldset.
     */
    displayError: function (element, error) {
      // Remove existing error messages in this container.
      this.resetError();

      // Display the error message and highlight the faulty element.
      if (typeof error === 'string' && error !== '') {
        var message = document.createElement('div');
        message.setAttribute('data-error-message', '');
        message.setAttribute('class', 'messages error cd-form__error-message');
        message.appendChild(document.createTextNode(error));

        // Add the message at the top of the fieldset.
        var sibling = this.form.querySelector('div[data-link-form]');
        this.form.insertBefore(message, sibling);

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
     * Check the given field already contains a link with the same url.
     */
    checkDuplicate: function (element, url) {
      var links = this.form.querySelectorAll('table[data-list-active] tr[data-id]');
      for (var j = 0, m = links.length; j < m; j++) {
        var link = links[j];
        if (link !== element && this.getLinkData(link).url === url) {
          return t('An link with the same URL already exists in this field.');
        }
      }

      return '';
    },

    /**
     * Validate a link, calling a validation endpoint for the field.
     */
    validateLink: function (data, element, callback) {
      var self = this;
      var settings = this.getFieldSettings();
      // Disable the form while validating the link data.
      this.disableForm(true);
      // Validate the link.
      var xhr = new XMLHttpRequest();
      // Process the response.
      xhr.addEventListener('load', function () {
        var data = null;
        var error = '';
        try {
          data = JSON.parse(xhr.responseText);
          // Error message from the validation endpoint or from duplication.
          error = data.error || self.checkDuplicate(element, data.url);
        }
        catch (exception) {
          error = t('Unable to parse response.');
        }
        self.displayError(element, error);
        callback.call(self, error === '' ? data : null);
        self.disableForm(false);
      });
      // Display error message in case of failure.
      xhr.addEventListener('error', function () {
        self.displayError(element, t('Request failed.'));
        callback.call(self, null);
        self.disableForm(false);
      });
      // Send the link data as JSON to the validation url for the field.
      xhr.open('POST', settings.validateUrl);
      xhr.send(JSON.stringify(data));
    },

    /**
     * Extract the links data for a given field.
     */
    parseLinks: function (urls, type) {
      var rows = this.form.querySelectorAll('table[data-list-active] tr');
      var links = [];

      for (var i = 0, l = rows.length; i < l; i++) {
        var link = this.getLinkData(rows[i]);
        // Silently ignore duplicates. Unless there is a bug somewhere, there
        // shouldn't be any as we check for duplicates when adding or updating
        // a link.
        if (!urls.hasOwnProperty(link.url)) {
          links.push(link);
          urls[link.url] = true;
        }
      }

      return links;
    },

    /**
     * Update the links data.
     */
    updateData: function () {
      // Temporily disable the form while processing the links.
      this.disableForm(true);
      // Map used to ensure uniqueness of the links.
      var urls = {};
      // Parse the field links and build the data to save.
      var data = this.parseLinks(urls, 'active');
      // Update the data.
      this.setFieldData(data);
      // Hide the new link form if necessary.
      this.hideNewLinkForm(data.length);
      // Update the list count.
      this.form.querySelector('table[data-list-active]').setAttribute('data-link-count', data.length);
      // Restore use of the form.
      this.disableForm(false);
    },

    /**
     * Empty a new link form.
     */
    emptyNewLinkForm: function (element) {
      element.querySelector('input[data-name="url"]').value = '';
      if (element.querySelector('input[data-name="title"]')) {
        element.querySelector('input[data-name="title"]').value = '';
      }
      if (element.querySelector('input[data-name="override"]')) {
        element.querySelector('input[data-name="override"]').value = '';
      }

      if (element.hasAttribute('data-error')) {
        this.resetError(this.getParentElement(element, 'FIELDSET'));
      }
    },

    /**
     * Hide new link form if needed.
     */
    hideNewLinkForm: function (linkCount) {
      var settings = this.getFieldSettings();
      // Hide the new link form if we already have the maximum number of links.
      if (settings.cardinality > 0 && settings.cardinality <= linkCount) {
        this.newLinkForm.style.display = 'none';
      }
      else {
        this.newLinkForm.style.display = '';
      }
    },

    /**
     * Reset the edit form of an active link.
     */
    resetLinkForm: function (element) {
      var data = this.getLinkData(element);
      element.querySelector('input[data-name="url"]').value = data.url;
      if (element.querySelector('input[data-name="title"]')) {
        element.querySelector('input[data-name="title"]').value = data.title;
      }
      if (element.querySelector('input[data-name="override"]')) {
        element.querySelector('input[data-name="override"]').value = data.override;
      }

      if (element.hasAttribute('data-error')) {
        this.resetError(this.getParentElement(element, 'FIELDSET'));
      }
    },

    /**
     * Extract the link data from either the link row or the link edit form.
     */
    getLinkData: function (element, edited) {
      if (edited === true) {
        return {
          url: element.querySelector('input[data-name="url"]').value,
          title: element.querySelector('input[data-name="title"]') ? element.querySelector('input[data-name="title"]').value : '',
          override: element.querySelector('input[data-name="override"]') ? element.querySelector('input[data-name="override"]').value : ''
        };
      }
      else {
        return this.links[parseInt(element.getAttribute('data-id'), 10)];
      }
    },

    /**
     * Retrieve a field's settings.
     */
    getFieldSettings: function () {
      if (!this.settings) {
        this.settings = {
          field: this.data.getAttribute('data-settings-field'),
          label: this.data.getAttribute('data-settings-label'),
          useOverride: this.data.getAttribute('data-settings-use-override') === 'true',
          useTitle: this.data.getAttribute('data-settings-use-title') === 'true',
          validateUrl: this.data.getAttribute('data-settings-validate-url'),
          cardinality: this.data.getAttribute('data-settings-cardinality')
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
    handleEvents: function (event) {
      var target = event.target;

      if (target && target.tagName === 'BUTTON' && typeof target.value !== 'undefined') {
        event.stopPropagation();
        event.preventDefault();

        switch (target.value) {
          // Create a new link.
          case 'add':
            var element = this.getParentElement(target, 'DIV');
            // Validate the link data.
            this.validateLink(this.getLinkData(element, true), element, function (data) {
              // If valid, add new row at the top of the active list.
              if (data !== null) {
                var link = this.createLink(data, this.getFieldSettings(), true);
                var container = this.form.querySelector('table[data-list-active] tbody');
                container.insertBefore(link, container.firstElementChild);
                // Empty the new link inputs.
                this.emptyNewLinkForm(element);
                this.updateData();
              }
            });
            break;

          // Empty a new link form (empty the input fields).
          case 'clear':
            var element = this.getParentElement(target, 'DIV');
            this.emptyNewLinkForm(element);
            break;

          // Update an active link.
          case 'update':
            var row = this.getParentElement(target, 'TR');
            // Validate the link data.
            this.validateLink(this.getLinkData(row, true), row, function (data) {
              // Replace the link with the validated data.
              if (data !== null) {
                var link = this.createLink(data, this.getFieldSettings(), true);
                row.parentNode.replaceChild(link, row);
                this.updateData();
              }
            });
            break;

          // Remove an active link.
          case 'delete':
            var element = this.getParentElement(target, 'DIV');
            var row = this.getParentElement(target, 'TR');
            row.parentNode.removeChild(row);
            this.updateData();
            break;

          // Toggle the display of the other actions in an active link form.
          case 'other-actions':
            if (target.hasAttribute('data-toggled')) {
              target.removeAttribute('data-toggled');
              target.textContent = t('Show other actions');
            }
            else {
              target.setAttribute('data-toggled', '');
              target.textContent = t('Hide other actions');
            }
            break;

          // Show a link edit form (hide the display).
          case 'edit':
            var row = this.getParentElement(target, 'TR');
            row.setAttribute('data-edited', '');
            target.setAttribute('value', 'cancel');
            target.textContent = t('Cancel');
            break;

          // Hide a link edit form (show the display).
          case 'cancel':
            var row = this.getParentElement(target, 'TR');
            row.removeAttribute('data-edited');
            target.setAttribute('value', 'edit');
            target.textContent = t('Edit');
            // Reset the input form for the link.
            this.resetLinkForm(row);
            break;

        }
      }
    },

    /**
     * Get the field's data.
     */
    getFieldData: function () {
      return this.data.value ? JSON.parse(this.data.value) : [];
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
  Drupal.behaviors.ReliefWebLinks = {
    attach: function (context, settings) {
      var fields = document.querySelectorAll('.field--type-reliefweb-section-links:not([data-processed])');

      for (var i = 0, l = fields.length; i < l; i++) {
        var field = fields[i];
        field.setAttribute('data-processed', '');
        var handler = new ReliefWebSectionLinksField(field);
        handler.initialize();
      }
    }
  };
})(Drupal);
