(function (Drupal) {
  'use strict';

  var t = Drupal.t;

  /**
   * Object constructor to handle the field's logic.
   */
  function ReliefWebLinksField(field) {
    this.field = field;

    // Field containing the serialized data.
    this.data = field.querySelector('input[data-drupal-selector$="-data"]');

    // Store the links data (url, title, image)
    //
    // The store will grow when adding, updating, archiving and
    // unarchiving links. Each link row in the active and archive tables
    // will have a unique index.
    this.links = [];
  }

  /**
   * Prototype of the field logic handler.
   */
  ReliefWebLinksField.prototype = {

    /**
     * Placeholders for the input fields.
     */
    placeholders: {
      external: {
        url: t('External URL (must start with http or https)'),
        title: t('Link title (mandatory)'),
        image: t('URL of the logo image to display for this link (optional, but must be https)')
      },
      internal: {
        url: t('ReliefWeb document URL'),
        title: '',
        image: ''
      }
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
    createActiveLinkMove: function () {
      var container = document.createElement('td');
      container.setAttribute('data-drag-handle', '');

      return container;
    },

    /**
     * Create a display cell for an active link.
     */
    createActiveLinkDisplay: function (link, settings) {
      var container = document.createElement('td');
      container.setAttribute('data-link-display', '');

      var title = document.createElement('a');
      title.setAttribute('href', link.url);
      title.setAttribute('target', '_blank');

      // Restrict image display to external links and internal links
      // when use cover is set.
      var internal = settings.internal;
      if ((!internal || settings.useCover) && link.image) {
        var imageUrl = link.image.replace(/^public:\//, settings.baseImageUrl);

        var image = document.createElement('img');
        image.setAttribute('src', imageUrl);
        image.setAttribute('alt', link.title);

        title.appendChild(image);

        // For external links, we just display the image.
        if (internal) {
          title.appendChild(document.createTextNode(link.title));
        }
      }
      else {
        title.appendChild(document.createTextNode(link.title));
      }

      container.appendChild(title);

      return container;
    },

    /**
     * Create an edit form cell for an active link.
     */
    createActiveLinkForm: function (link, settings) {
      var container = document.createElement('td');
      container.setAttribute('data-link-form', '');

      var internal = settings.internal;
      container.appendChild(this.createActiveLinkFormComponent('url', link.url, internal));
      container.appendChild(this.createActiveLinkFormComponent('title', link.title, internal, internal));
      container.appendChild(this.createActiveLinkFormComponent('image', link.image, internal, internal));

      // If keep archives is set, we add an archive button as main action
      // and a toggler to display the other actions (update and delete).
      if (settings.keepArchives) {
        container.appendChild(this.createButton('archive', t('Archive'), true));
        container.appendChild(this.createButton('other-actions', t('Show other actions')));
      }
      container.appendChild(this.createButton('update', t('Update'), true));
      container.appendChild(this.createButton('delete', t('Delete'), true));

      return container;
    },

    /**
     * Create an input form compoment for the given property.
     */
    createActiveLinkFormComponent: function (name, value, internal, hidden) {
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
        var placeholder = this.placeholders[internal === true ? 'internal' : 'external'][name];
        input.setAttribute('placeholder', placeholder);
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
    createActiveLinkEdit: function () {
      var container = document.createElement('td');
      container.appendChild(this.createButton('edit', t('Edit')));
      return container;
    },

    /**
     * Create a table for a field's active links.
     */
    createActiveList: function (links, settings) {
      var container = document.createElement('table');
      container.setAttribute('data-list-active', '');

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
     * Create a display cell for an archived link.
     */
    createArchiveDisplay: function (link) {
      var container = document.createElement('td');

      var title = document.createElement('a');
      title.setAttribute('href', link.url);
      title.setAttribute('target', '_blank');
      title.appendChild(document.createTextNode(link.title));

      container.appendChild(title);

      return container;
    },

    /**
     * Create a cell with a button to unarchive for an archived link.
     */
    createArchiveUnarchive: function () {
      var container = document.createElement('td');
      container.appendChild(this.createButton('unarchive', t('Unarchive')));
      return container;
    },

    /**
     * Create a table for a field's archive list.
     */
    createArchiveList: function (links, settings) {
      var container = document.createElement('table');
      container.setAttribute('data-list-archives', '');

      // Add a caption to the table with a button to toggle visibility.
      var caption = document.createElement('caption');
      caption.appendChild(this.createButton('show', t('Show Archives')));

      var body = document.createElement('tbody');
      for (var i = 0, l = links.length; i < l; i++) {
        body.appendChild(this.createLink(links[i], settings, false));
      }

      container.appendChild(caption);
      container.appendChild(body);

      return container;
    },

    /**
     * Create a new link edit form for a field.
     */
    createNewLinkForm: function (settings) {
      var container = document.createElement('div');
      container.setAttribute('data-link-form', '');

      var internal = settings.internal;
      container.appendChild(this.createActiveLinkFormComponent('url', '', internal));
      container.appendChild(this.createActiveLinkFormComponent('title', '', internal, internal));
      container.appendChild(this.createActiveLinkFormComponent('image', '', internal, internal));
      container.appendChild(this.createButton('add', t('Add'), true));
      container.appendChild(this.createButton('clear', t('Clear')));

      return container;
    },

    /**
     * Create an active or archive link.
     */
    createLink: function (link, settings, active) {
      var container = document.createElement('tr');

      // Set the id of the link to be retrieved from the store.
      container.setAttribute('data-id', this.createLinkId(link));

      if (active === true) {
        container.appendChild(this.createActiveLinkMove());
        container.appendChild(this.createActiveLinkDisplay(link, settings));
        container.appendChild(this.createActiveLinkForm(link, settings));
        container.appendChild(this.createActiveLinkEdit());
      }
      else {
        container.appendChild(this.createArchiveDisplay(link));
        container.appendChild(this.createArchiveUnarchive());
      }

      return container;
    },

    /**
     * Create a unique link ID.
     */
    createLinkId: function (link) {
      return this.links.push(link) - 1;
    },

    /**
     * Create a fieldset with new link, active and archive lists for a field.
     */
    createFieldForm: function () {
      var links = this.getFieldData();
      var settings = this.getFieldSettings();

      var container = document.createElement('fieldset');
      container.setAttribute('data-internal', settings.internal);

      // Link to the save button.
      var link = document.createElement('a');
      link.setAttribute('href', '#edit-actions');
      link.appendChild(document.createTextNode(t('Jump to save button')));

      var legend = document.createElement('legend');
      legend.appendChild(document.createTextNode(settings.label));

      container.appendChild(legend);
      container.appendChild(link);
      container.appendChild(this.createNewLinkForm(settings));
      container.appendChild(this.createActiveList(links.active, settings));

      if (settings.keepArchives) {
        container.appendChild(this.createArchiveList(links.archives, settings));
      }

      // Add the form to the field.
      this.field.appendChild(container);

      // Handle click events on the different buttons in the form.
      this.field.addEventListener('click', this.handleEvents.bind(this));

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
    resetError: function (field) {
      // Remove existing error messages in this container.
      var messages = field.querySelectorAll('[data-error-message]');
      for (var i = 0, l = messages.length; i < l; i++) {
        field.removeChild(messages[i]);
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
        message.setAttribute('data-error-message', '');
        message.setAttribute('class', 'messages error');
        message.appendChild(document.createTextNode(error));

        // Add the message at the top of the fieldset.
        var sibling = field.querySelector('div[data-link-form]');
        field.insertBefore(message, sibling);

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
    checkDuplicate: function (field, element, url) {
      var types = [['active', t('active')], ['archives', t('archived')]];

      for (var i = 0, l = types.length; i < l; i++) {
        var type = types[i][0];
        var state = types[i][1];

        var links = field.querySelectorAll('table[data-list-' + type + '] tr[data-id]');
        for (var j = 0, m = links.length; j < m; j++) {
          var link = links[j];
          if (link !== element && this.getLinkData(link).url === url) {
            return t('An @state link with the same URL already exists in this field.', {'@state': state});
          }
        }
      }
      return '';
    },

    /**
     * Validate a link, calling a validation endpoint for the field.
     */
    validateLink: function (data, field, element, callback) {
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
          error = data.error || self.checkDuplicate(field, element, data.url);
        }
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
      // Send the link data as JSON to the validation url for the field.
      xhr.open('POST', settings.validateUrl);
      xhr.send(JSON.stringify(data));
    },

    /**
     * Extract the links data for a given field.
     */
    parseLinks: function (urls, type) {
      // Active is a string so that it can be compared with what is loaded
      // from the database.
      var active = type === 'active' ? '1' : '0';
      var rows = this.form.querySelectorAll('table[data-list-' + type + '] tr');
      var links = [];

      for (var i = 0, l = rows.length; i < l; i++) {
        var link = this.getLinkData(rows[i]);
        // Silently ignore duplicates. Unless there is a bug somewhere, there
        // shouldn't be any as we check for duplicates when adding or updating
        // a link.
        if (!urls.hasOwnProperty(link.url)) {
          link.active = active;
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
      var data = {
        active: this.parseLinks(urls, 'active'),
        archives: this.parseLinks(urls, 'archives')
      };
      // Update the data.
      this.setFieldData(data);
      // Restore use of the form.
      this.disableForm(false);
    },

    /**
     * Empty a new link form.
     */
    emptyNewLinkForm: function (element) {
      element.querySelector('input[data-name="url"]').value = '';
      element.querySelector('input[data-name="title"]').value = '';
      element.querySelector('input[data-name="image"]').value = '';

      if (element.hasAttribute('data-error')) {
        this.resetError(this.getParentElement(element, 'FIELDSET'));
      }
    },

    /**
     * Reset the edit form of an active link.
     */
    resetActiveLinkForm: function (element) {
      var data = this.getLinkData(element);
      element.querySelector('input[data-name="url"]').value = data.url;
      element.querySelector('input[data-name="title"]').value = data.title;
      element.querySelector('input[data-name="image"]').value = data.image;

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
          title: element.querySelector('input[data-name="title"]').value,
          image: element.querySelector('input[data-name="image"]').value
        };
      }
      else {
        return this.links[parseInt(element.getAttribute('data-id'), 10)];
      }
    },

    /**
     * Retrieve a field's settings.
     */
    getFieldSettings: function (field) {
      if (!this.settings) {
        this.settings = {
          field: this.data.getAttribute('data-settings-field'),
          label: this.data.getAttribute('data-settings-label'),
          internal: this.data.getAttribute('data-settings-internal') === 'true',
          keepArchives: this.data.getAttribute('data-settings-keep-archives') === 'true',
          useCover: this.data.getAttribute('data-settings-use-cover') === 'true',
          baseImageUrl: this.data.getAttribute('data-settings-base-image-url'),
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
    handleEvents: function (event) {
      var target = event.target;

      if (target && target.tagName === 'BUTTON' && typeof target.value !== 'undefined') {
        event.stopPropagation();
        event.preventDefault();

        switch (target.value) {
          // Archive a link.
          case 'archive':
            var row = this.getParentElement(target, 'TR');
            var field = this.getParentElement(row, 'FIELDSET');
            var link = this.createLink(this.getLinkData(row), this.getFieldSettings(), false);
            var container = field.querySelector('table[data-list-archives] tbody');
            // Create a new row at the top of the archive list.
            if (container.firstElementChild) {
              container.insertBefore(link, container.firstElementChild);
            }
            else {
              container.appendChild(link);
            }
            // Delete the row in the active list.
            row.parentNode.removeChild(row);
            this.updateData();
            break;

          // Unarchive a link.
          case 'unarchive':
            var row = this.getParentElement(target, 'TR');
            var field = this.getParentElement(row, 'FIELDSET');
            var link = this.createLink(this.getLinkData(row), this.getFieldSettings(), true);
            var container = field.querySelector('table[data-list-active] tbody');
            // Create a new row at the bottom of the active list.
            container.appendChild(link);
            // Remove the row form the archive list.
            row.parentNode.removeChild(row);
            this.updateData();
            break;

          // Create a new active link.
          case 'add':
            var element = this.getParentElement(target, 'DIV');
            var field = this.getParentElement(element, 'FIELDSET');
            // Validate the link data.
            this.validateLink(this.getLinkData(element, true), field, element, function (data) {
              // If valid, add new row at the top of the active list.
              if (data !== null) {
                var link = this.createLink(data, this.getFieldSettings(), true);
                var container = field.querySelector('table[data-list-active] tbody');
                if (container.firstElementChild) {
                  container.insertBefore(link, container.firstElementChild);
                }
                else {
                  container.appendChild(link);
                }

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
            var field = this.getParentElement(row, 'FIELDSET');
            // Validate the link data.
            this.validateLink(this.getLinkData(row, true), field, row, function (data) {
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
            this.resetActiveLinkForm(row);
            break;

          // Display a archive list content.
          case 'show':
            var row = this.getParentElement(target, 'TABLE');
            row.setAttribute('data-visible', '');
            target.setAttribute('value', 'hide');
            target.textContent = t('Hide Archives');
            break;

          // Hide a archive list content.
          case 'hide':
            var row = this.getParentElement(target, 'TABLE');
            row.removeAttribute('data-visible');
            target.setAttribute('value', 'show');
            target.textContent = t('Show Archives');
            break;
        }
      }
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
  Drupal.behaviors.ReliefWebLinks = {
    attach: function (context, settings) {
      var fields = document.querySelectorAll('.field--type-reliefweb-links:not([data-processed])');

      for (var i = 0, l = fields.length; i < l; i++) {
        var field = fields[i];
        field.setAttribute('data-processed', '');
        var handler = new ReliefWebLinksField(field);
        handler.initialize();
      }
    }
  };
})(Drupal);
