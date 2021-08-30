(function () {

  'use strict';

  /**
   * Create a button element.
   */
  function createButton(value, label, disabled) {
    var button = document.createElement('button');
    button.setAttribute('type', 'button');
    button.setAttribute('value', value);
    button.appendChild(document.createTextNode(label));
    button.disabled = disabled === true;
    return button;
  }

  /**
   * Toggle the display of the loading overlay on the container.
   */
  function toggleLoading(show) {
    if (show === true) {
      document.body.setAttribute('data-loading', '');
    }
    else {
      document.body.removeAttribute('data-loading');
    }
  }

  /**
   * Empty the headline list.
   */
  function emptyHeadlines() {
    var articles = manager.content.getElementsByTagName('article');
    for (var i = articles.length - 1; i >= 0; i--) {
      manager.content.removeChild(articles[i]);
    }
  }

  /**
   * Create the headline list.
   */
  function createHeadlines(html) {
    var temporary = document.createElement('div');
    temporary.innerHTML = html;

    emptyHeadlines();

    var articles = temporary.getElementsByTagName('article');
    for (var i = articles.length - 1; i >= 0; i--) {
      manager.content.insertBefore(articles[i], manager.content.firstChild);
    }

    updateSelected();
    manager.save.disabled = false;
  }

  /**
   * Update selected headlines in the headline list.
   */
  function updateSelected() {
    // Get the list of selected headlines as a map for easy lookup below.
    var headlines = {};
    var articles = manager.container.getElementsByTagName('article');
    for (var i = 0, l = articles.length; i < l; i++) {
      var article = articles[i];
      if (article.parentNode === manager.container) {
        var id = article.getAttribute('data-id');
        headlines[id] = id;
      }
    }

    // Update the selected status of the articles in the headline list.
    var articles = manager.content.getElementsByTagName('article');
    for (var i = articles.length - 1; i >= 0; i--) {
      var article = articles[i];
      var id = article.getAttribute('data-id');
      if (headlines[id] === id) {
        article.setAttribute('data-selected', '');
      }
      else {
        article.removeAttribute('data-selected');
      }
    }
  }

  /**
   * Get the headline selection.
   */
  function getHeadlines() {
    var headlines = [];
    var articles = manager.container.getElementsByTagName('article');
    for (var i = 0, l = articles.length; i < l; i++) {
      var article = articles[i];
      if (article.parentNode === manager.container) {
        headlines.push(parseInt(article.getAttribute('data-id'), 10));
      }
    }
    return headlines;
  }

  /**
   * Save the headline selection.
   */
  function saveHeadlines() {
    // Do not save if there are duplicates.
    if (checkDuplicates()) {
      return;
    }
    var xhr = new XMLHttpRequest();
    xhr.onload = function () {
      toggleLoading(false);
      cleanManager();
    };
    xhr.onerror = function () {
      showError('Unable to save the headline selection.');
      toggleLoading(false);
    };
    toggleLoading(true);
    xhr.open('POST', '/admin/reliefweb/data/headlines?' + Date.now());
    xhr.send(JSON.stringify(getHeadlines()));
  }

  /**
   * Load the headline list.
   */
  function loadHeadlines() {
    var xhr = new XMLHttpRequest();
    xhr.onload = function () {
      if (xhr.responseText) {
        var html = JSON.parse(xhr.responseText);
        if (typeof html === 'string') {
          createHeadlines(html);
        }
        else {
          showError('Invalid headlines data.');
        }
      }
      else {
        showError('No headlines loaded.');
      }
      toggleLoading(false);
    };
    xhr.onerror = function () {
      showError('An error occurred while attempting to load the headlines.');
      toggleLoading(false);
    };
    toggleLoading(true);
    xhr.open('GET', '/admin/reliefweb/data/headlines?' + Date.now());
    xhr.send();
  }

  /**
   * Look for duplicates and mark then as such.
   */
  function checkDuplicates() {
    var duplicates = false;
    var selection = {};

    // Get the list of headlines and group them id.
    var articles = manager.container.getElementsByTagName('article');
    for (var i = articles.length - 1; i >= 0; i--) {
      var article = articles[i];
      if (article.parentNode === manager.container) {
        var id = article.getAttribute('data-id');
        if (typeof selection[id] === 'undefined') {
          selection[id] = [];
        }
        selection[id].push(article);
      }
    }
    for (var id in selection) {
      if (selection.hasOwnProperty(id)) {
        // Several articles with the same id, we mark then as dup
        if (selection[id].length > 1) {
          var articles = selection[id];
          for (var i = 0, l = articles.length; i < l; i++) {
            articles[i].setAttribute('data-duplicate', '');
          }
          duplicates = true;
        }
        else {
          selection[id][0].removeAttribute('data-duplicate');
        }
      }
    }
    return duplicates;
  }

  /**
   * Handle drag and drop of articles inside the headlines container.
   */
  function handleDragDrop(container, enable) {
    var draggable = null;
    var replaced = null;
    var replacement = null;

    // Find the hovered article if any.
    function findHovered() {
      var articles = container.getElementsByTagName('article');
      for (var i = articles.length - 1; i >= 0; i--) {
        var article = articles[i];
        // Not an article from the headline selection, skip.
        if (article.parentNode === container && article.matches(':hover')) {
          return article;
        }
      }
      return null;
    }

    // Swap the dragged element with a sibling.
    function swap(event) {
      if (!draggable) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      var id = draggable.getAttribute('data-id');

      // Update the position of the dragged element.
      updatePosition(event.pageX, event.pageY);

      // Find the hovered article.
      var article = findHovered();
      if (!article) {
        return;
      }

      // Skip if the hovered article has the same id as the draggable.
      if (article.getAttribute('data-id') === id) {
        return;
      }

      // Swap back the previously replaced article.
      if (replaced !== null && replacement !== null) {
        container.replaceChild(replaced, replacement);
      }

      // Replace the currently hovered article with the dragged one.
      replacement = draggable.cloneNode(true);
      replacement.removeAttribute('data-dragged');
      replacement.removeAttribute('style');
      replaced = container.replaceChild(replacement, article);

      checkDuplicates();
      updateSelected();
    }

    // Stop the drag handling, swapping the rows and untracking events.
    function stop(event) {
      // Ensure we are up to date.
      swap(event);

      // Clean up.
      clean();
    }

    // Clean the elements.
    function clean() {
      // Clear the drag elements.
      if (draggable) {
        draggable.parentNode.removeChild(draggable);
      }
      draggable = null;
      replaced = null;
      replacement = null;

      // Clean the document.
      document.body.removeAttribute('data-drag-on');
      document.removeEventListener('mousemove', swap);
      document.removeEventListener('mouseup', stop);
    }

    // Update the position of the draggable element.
    function updatePosition(x, y) {
      if (draggable) {
        draggable.setAttribute('style', 'left:' + x + 'px;top:' + y + 'px');
      }
    }

    // Find the article parent element.
    function findArticle(element) {
      while (element && element !== container) {
        if (element.tagName === 'ARTICLE') {
          return element;
        }
        element = element.parentNode;
      }
      return null;
    }

    // Handle mousedown on article elements to start drag/drop.
    function handleMousedown(event) {
      if (manager.open === false) {
        return;
      }

      var target = findArticle(event.target);
      if (target) {
        event.stopPropagation();
        event.preventDefault();

        // Clean up first.
        clean();

        // Used to ensure the cursor stays consistent when dragging
        // across the page.
        document.body.setAttribute('data-drag-on', '');

        // Create the draggable element.
        draggable = target.cloneNode(true);
        draggable.removeAttribute('data-selected');
        draggable.setAttribute('data-dragged', '');
        updatePosition(event.pageX, event.pageY);
        document.body.appendChild(draggable);

        // We need to track mouse events on the whole page.
        document.addEventListener('mousemove', swap);
        document.addEventListener('mouseup', stop);
      }
    }

    // Start the drag and drop handling when there is a mousedown event
    // on an article
    document.body.addEventListener('mousedown', handleMousedown);
  }

  /**
   * Handle clicks on the buttons.
   */
  function handleClick(event) {
    var target = event.target;
    if (target && target.tagName === 'BUTTON' && typeof target.value !== 'undefined') {
      event.stopPropagation();
      event.preventDefault();

      switch (target.value) {
        case 'edit':
          openManager();
          break;

        case 'cancel':
          closeManager(false);
          break;

        case 'save':
          closeManager(true);
          break;
      }
    }
  }

  /**
   * Create the headline manager
   */
  function createManager(container) {
    container.setAttribute('data-with-editor', '');

    // Handle click events.
    container.addEventListener('click', handleClick);
    // Handle drag and drop of articles.
    handleDragDrop(container);

    var edit = createButton('edit', 'Edit');
    var save = createButton('save', 'Save', true);
    var cancel = createButton('cancel', 'Cancel', true);

    var actions = document.createElement('div');
    actions.setAttribute('data-actions', '');
    actions.appendChild(save);
    actions.appendChild(cancel);

    var content = document.createElement('div');
    content.setAttribute('data-headlines', '');

    var wrapper = document.createElement('div');
    wrapper.setAttribute('data-wrapper', '');
    wrapper.appendChild(content);
    wrapper.appendChild(actions);

    var title = container.querySelector('h2');
    title.appendChild(edit);

    var header = document.createElement('header');
    header.appendChild(title.cloneNode(true));
    header.appendChild(wrapper);

    title.parentNode.replaceChild(header, title);

    return {
      backup: [],
      container: container,
      wrapper: wrapper,
      content: content,
      edit: edit,
      save: save,
      cancel: cancel,
      open: false
    };
  }

  /**
   * Create a backup from the headline selection before edition.
   */
  function createBackup() {
    var articles = manager.container.getElementsByTagName('article');
    for (var i = 0, l = articles.length; i < l; i++) {
      var article = articles[i];
      if (article.parentNode === manager.container) {
        manager.backup.push(articles[i].cloneNode(true));
      }
    }
  }

  /**
   * Restore the backup of the headline selection before edition.
   */
  function restoreBackup() {
    if (manager.backup.length > 0) {
      var index = manager.backup.length - 1;
      var articles = manager.container.getElementsByTagName('article');
      for (var i = articles.length - 1; i >= 0; i--) {
        var article = articles[i];
        if (article.parentNode === manager.container) {
          if (typeof manager.backup[index] !== 'undefined') {
            article.parentNode.replaceChild(manager.backup[index], article);
          }
          index--;
        }
      }
    }
  }

  /**
   * Show an error message and disable the 'save' button.
   */
  function showError(message) {
    var error = manager.container.querySelector('[data-error]');
    if (error) {
      manager.container.removeChild(error);
    }
    error = document.createElement('div');
    error.setAttribute('data-error', '');
    error.appendChild(document.createTextNode(message + ' Please reload the page and try again.'));

    manager.content.parentNode.replaceChild(error, manager.content);

    // Disable the save button.
    manager.save.disabled = true;
  }

  /**
   * Clean the manager.
   */
  function cleanManager() {
    manager.edit.disabled = false;
    manager.container.removeAttribute('data-visible');
    emptyHeadlines();
  }

  /**
   * Close the headline manager, either saving the selection or restoring the
   * backup.
   */
  function closeManager(save) {
    manager.open = false;
    if (save) {
      saveHeadlines();
    }
    else {
      restoreBackup();
      cleanManager();
    }
  }

  /**
   * Open the headline manager.
   */
  function openManager() {
    manager.edit.disabled = true;
    manager.cancel.disabled = false;
    createBackup();
    loadHeadlines();
    manager.container.setAttribute('data-visible', '');
    manager.open = true;
  }

  var manager = createManager(document.querySelector('#main-content #headlines'));

})();
