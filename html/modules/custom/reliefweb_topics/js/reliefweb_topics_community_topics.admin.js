(function () {

  'use strict';

  Drupal.behaviors.reliefWebTopicsCommunityTopicsAdmin = {
    attach: function (context, settings) {
      // Skip if there is no support for the basic features.
      if (typeof document.querySelectorAll === 'undefined') {
        return;
      }

      var t = Drupal.t;

      /**
       * Create a button element.
       */
      function createButton(value, label, action, name) {
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
      }

      /**
       * Create a drag handle cell for a topic link.
       */
      function createLinkMove() {
        var container = document.createElement('td');
        container.setAttribute('data-drag-handle', '');

        return container;
      }

      /**
       * Create a display cell for a topic link.
       */
      function createLinkDisplay(link, settings) {
        var container = document.createElement('td');
        container.setAttribute('data-link-display', '');

        var title = document.createElement('a');
        title.setAttribute('href', link.url);
        title.setAttribute('target', '_blank');
        title.appendChild(document.createTextNode(link.title));

        container.appendChild(title);

        return container;
      }

      /**
       * Create an edit form cell for a topic link.
       */
      function createLinkForm(link, settings) {
        var container = document.createElement('td');
        container.setAttribute('data-link-form', '');
        container.appendChild(createLinkFormComponent('url', link.url));
        container.appendChild(createLinkFormComponent('title', link.title));
        container.appendChild(createLinkFormComponent('description', link.description, true));
        container.appendChild(createButton('update', t('Update'), true));
        container.appendChild(createButton('delete', t('Delete'), true));

        return container;
      }

      /**
       * Create a input/textarea form compoment for the given property.
       */
      function createLinkFormComponent(name, value, textarea) {
        var placeholders = {
          url: t('River URL'),
          title: t('Link title (mandatory)'),
          override: t('Node Id to promote to beginning of list')
        };

        var element;

        if (textarea) {
          element = document.createElement('textarea');
          element.setAttribute('maxlength', 1000);
          element.appendChild(document.createTextNode(value));
        }
        else {
          element = document.createElement('input');
          element.setAttribute('value', value || '');
          element.setAttribute('type', 'text');
          element.setAttribute('maxlength', 1000);
        }

        element.setAttribute('data-name', name);
        element.setAttribute('placeholder', placeholders[name] || '');

        var label = document.createElement('label');
        label.appendChild(document.createTextNode(name));
        label.appendChild(element);

        return label;
      }

      /**
       * Create a cell with a button to toggle the edit form for a topic link.
       */
      function createLinkEdit() {
        var container = document.createElement('td');
        container.appendChild(createButton('edit', t('Edit')));
        return container;
      }

      /**
       * Create a new topic link edit form.
       */
      function createNewLinkForm(settings) {
        var container = document.createElement('div');
        container.setAttribute('data-link-form', '');

        container.appendChild(createLinkFormComponent('url', ''));
        container.appendChild(createLinkFormComponent('title', ''));
        container.appendChild(createLinkFormComponent('description', '', true));
        container.appendChild(createButton('add', t('Add'), true));
        container.appendChild(createButton('clear', t('Clear')));

        return container;
      }

      /**
       * Create a topic link.
       */
      function createLink(link, settings) {
        var container = document.createElement('tr');

        // Set the id of the link to be retrieved from the store.
        container.setAttribute('data-id', linkStore.push(link) - 1);
        container.appendChild(createLinkMove());
        container.appendChild(createLinkDisplay(link, settings));
        container.appendChild(createLinkForm(link, settings));
        container.appendChild(createLinkEdit());

        return container;
      }

      /**
       * Create a table with the topic links.
       */
      function createList(links, settings) {
        var container = document.createElement('table');
        container.setAttribute('data-list', '');

        var body = document.createElement('tbody');
        for (var i = 0, l = links.length; i < l; i++) {
          body.appendChild(createLink(links[i], settings));
        }

        container.appendChild(body);

        // Add re-ordering handling (drag events) to the container.
        handleTableReordering(container);

        return container;
      }

      /**
       * Update the form, adding the list of links.
       */
      function updateForm(form, data) {
        var container = form.querySelector('[data-settings-validate-url]');
        container.appendChild(createNewLinkForm(data.settings));
        container.appendChild(createList(data.links, data.settings));
        return container;
      }

      /**
       * Handle re-ordering the links in a list.
       */
      function handleTableReordering(table) {
        var row = null;
        var body = document.querySelector('body');

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
        }

        // Start the re-ordering handling when there is a mousedown
        // event of a drag handle element.
        table.addEventListener('mousedown', function (event) {
          var target = event.target;
          if (target.hasAttribute('data-drag-handle')) {
            event.stopPropagation();
            event.preventDefault();

            row = getParentElement(target, 'TR');
            row.setAttribute('data-dragged', '');
            // Used to ensure the cursor stays consistent when dragging
            // outside the table.
            body.setAttribute('data-drag-on', '');

            // We need to track mouse events on the whole page.
            document.addEventListener('mousemove', swap);
            document.addEventListener('mouseup', stop);
          }
        });
      }

      /**
       * Remove error messages from the main container.
       */
      function resetError() {
        // Remove existing error messages in this container.
        var messages = mainContainer.querySelectorAll('[data-error-message]');
        for (var i = 0, l = messages.length; i < l; i++) {
          mainContainer.removeChild(messages[i]);
        }

        // Remove existing highligted errors.
        var elements = mainContainer.querySelectorAll('[data-error]');
        for (var i = 0, l = elements.length; i < l; i++) {
          elements[i].removeAttribute('data-error');
        }
      }

      /**
       * Display an error message at the top of the main container.
       */
      function displayError(element, error) {
        // Remove existing error messages in this container.
        resetError(mainContainer);

        // Display the error message and highlight the faulty element.
        if (typeof error === 'string' && error !== '') {
          var message = document.createElement('div');
          message.setAttribute('data-error-message', '');
          message.appendChild(document.createTextNode(error));

          // Add the message at the top of the fieldset.
          var sibling = mainContainer.querySelector('div[data-link-form]');
          mainContainer.insertBefore(message, sibling);

          // Mark the element as erroneous.
          element.setAttribute('data-error', '');
        }
      }

      /**
       * Enable/disable the form fieldsets, preventing any action.
       */
      function disableFieldsets(disable) {
        var fieldsets = form.getElementsByTagName('fieldset');
        for (var i = 0, l = fieldsets.length; i < l; i++) {
          fieldsets[i].disabled = disable === true;
        }
        if (disable === true) {
          form.setAttribute('data-loading', '');
        }
        else {
          form.removeAttribute('data-loading');
        }
      }

      /**
       * Check if a link with the same url already exists.
       */
      function checkDuplicate(element, url) {
        var links = mainContainer.querySelectorAll('table[data-list] tr[data-id]');
        for (var j = 0, m = links.length; j < m; j++) {
          var link = links[j];
          if (link !== element && getLinkData(link).url === url) {
            return t('A topic link with the same URL already exists.');
          }
        }
        return '';
      }

      /**
       * Validate a link, calling a validation endpoint.
       */
      function validateLink(data, element, callback) {
        // Disable the form while validating the link data.
        disableFieldsets(true);
        // Validate the link.
        var xhr = new XMLHttpRequest();
        // Process the response.
        xhr.addEventListener('load', function () {
          var data = null;
          var error = '';
          try {
            data = JSON.parse(xhr.responseText);
            // Error message from the validation endpoint or from duplication.
            error = data.error || checkDuplicate(element, data.url);
          }
          catch (exception) {
            error = t('Unable to parse response.');
          }
          displayError(element, error);
          callback(error === '' ? data : null);
          disableFieldsets(false);
        });

        // Display error message in case of failure.
        xhr.addEventListener('error', function () {
          displayError(element, t('Request failed.'));
          callback(null);
          disableFieldsets(false);
        });

        // Send the link data as JSON to the validation url.
        xhr.open('POST', mainContainer.getAttribute('data-settings-validate-url'));
        xhr.send(JSON.stringify(data));
      }

      /**
       * Extract the links data.
       */
      function parseLinks() {
        var urls = {};
        var links = [];
        var rows = mainContainer.querySelectorAll('table[data-list] tr');

        for (var i = 0, l = rows.length; i < l; i++) {
          var link = getLinkData(rows[i]);
          // Silently ignore duplicates. Unless there is a bug somewhere, there
          // shouldn't be any as we check for duplicates when adding or updating
          // a link.
          if (!urls.hasOwnProperty(link.url)) {
            links.push(link);
            urls[link.url] = true;
          }
        }

        return links;
      }

      /**
       * Empty a new link form.
       */
      function emptyNewLinkForm(element) {
        element.querySelector('[data-name="url"]').value = '';
        element.querySelector('[data-name="title"]').value = '';
        element.querySelector('[data-name="description"]').value = '';

        if (element.hasAttribute('data-error')) {
          resetError(getParentElement(element, 'FIELDSET'));
        }
      }

      /**
       * Reset the edit form of a topic link.
       */
      function resetLinkForm(element) {
        var data = getLinkData(element);
        element.querySelector('[data-name="url"]').value = data.url;
        element.querySelector('[data-name="title"]').value = data.title;
        element.querySelector('[data-name="description"]').value = data.description;

        if (element.hasAttribute('data-error')) {
          resetError(getParentElement(element, 'FIELDSET'));
        }
      }

      /**
       * Extract the link data from either the link row or the link edit form.
       */
      function getLinkData(element, edited) {
        if (edited === true) {
          return {
            url: element.querySelector('[data-name="url"]').value,
            title: element.querySelector('[data-name="title"]').value,
            description: element.querySelector('[data-name="description"]').value
          };
        }
        else {
          return linkStore[parseInt(element.getAttribute('data-id'), 10)];
        }
      }

      /**
       * Retrieve the settings.
       */
      function getSettings() {
        return {
          validateUrl: mainContainer.getAttribute('data-settings-validate-url')
        };
      }

      /**
       * Get the first parent that matches the tag name.
       */
      function getParentElement(element, tagName) {
        while (element.tagName !== tagName) {
          element = element.parentNode;
        }
        return element;
      }

      /**
       * Handle click events on the different buttons in the form.
       */
      function handleEvents(event) {
        var target = event.target;

        if (target && (target.tagName === 'BUTTON' || target.tagName === 'INPUT') && typeof target.value !== 'undefined') {
          event.stopPropagation();
          event.preventDefault();

          switch (target.value.toLowerCase()) {
            // Create a new topic link.
            case 'add':
              var element = getParentElement(target, 'DIV');
              // Validate the link data.
              validateLink(getLinkData(element, true), element, function (data) {
                // If valid, add new row at the top of the topic list.
                if (data !== null) {
                  var link = createLink(data, getSettings());
                  var container = mainContainer.querySelector('table[data-list] tbody');
                  if (container.firstElementChild) {
                    container.insertBefore(link, container.firstElementChild);
                  }
                  else {
                    container.appendChild(link);
                  }

                  // Empty the new link inputs.
                  emptyNewLinkForm(element);
                }
              });
              break;

            // Empty a new link form (empty the input fields).
            case 'clear':
              var element = getParentElement(target, 'DIV');
              emptyNewLinkForm(element);
              break;

            // Update a topic link.
            case 'update':
              var row = getParentElement(target, 'TR');
              // Validate the link data.
              validateLink(getLinkData(row, true), row, function (data) {
                // Replace the link with the validated data.
                if (data !== null) {
                  var link = createLink(data, getSettings());
                  row.parentNode.replaceChild(link, row);
                }
              });
              break;

            // Remove a topic link.
            case 'delete':
              var row = getParentElement(target, 'TR');
              row.parentNode.removeChild(row);
              break;

            // Toggle the display of the other actions in a topic link form.
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
              var row = getParentElement(target, 'TR');
              row.setAttribute('data-edited', '');
              target.setAttribute('value', 'cancel');
              target.textContent = t('Cancel');
              break;

            // Hide a link edit form (show the display).
            case 'cancel':
              var row = getParentElement(target, 'TR');
              row.removeAttribute('data-edited');
              target.setAttribute('value', 'edit');
              target.textContent = t('Edit');
              // Reset the input form for the link.
              resetLinkForm(row);
              break;

            // Submit the changes made to the form.
            case 'save':
              // Parse the topic links and build the data to save.
              var data = parseLinks();
              if (data) {
                console.log(data);
                form.querySelector('[name="data"]').value = JSON.stringify(data);
              }
              form.submit();
              break;
          }
        }
      }

      // Form.
      var form = document.getElementById('community-topics-form');

      // Prevent the form from being processed several times.
      if (!form || form.hasAttribute('data-processed')) {
        return;
      }
      form.setAttribute('data-processed', '');

      // Settings for the community topics, including the list of links.
      var topicsSettings = JSON.parse(form.querySelector('[name="data"]').value);

      // Store the links data (url, title, image)
      //
      // The store will grow when adding, removing or updating links. Each link
      // row in the table will have a unique index.
      var linkStore = [];

      // Create the link table and update the container.
      var mainContainer = updateForm(form, topicsSettings);

      // Clear some memory.
      settings.reliefwebTopicsCommunityTopics = null;
      delete settings.reliefwebTopicsCommunityTopics;

      // Prevent default submission.
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        event.stopPropagation();
      });

      // Handle click events on the different buttons in the form.
      form.addEventListener('click', handleEvents);

      // Enable the form (starting state is disabled).
      disableFieldsets(false);
    }
  };

})();
