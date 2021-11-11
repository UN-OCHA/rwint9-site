(function () {

  'use strict';

  /**
   * Create a button element.
   */
  function createButton(value, label, disabled) {
    var button = document.createElement('button');
    button.setAttribute('type', 'button');
    button.setAttribute('value', value);
    button.classList.add('rw-headlines-widget__button');
    button.classList.add('rw-headlines-widget__button--' + value);
    button.appendChild(document.createTextNode(label));
    button.disabled = disabled === true;
    return button;
  }

  /**
   * Toggle the display of the loading overlay on the container.
   */
  function toggleLoading(show) {
    if (show === true) {
      document.body.classList.add('rw-loading');
    }
    else {
      document.body.classList.remove('rw-loading');
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
        article.setAttribute('data-headlines-widget-selected', '');
      }
      else {
        article.removeAttribute('data-headlines-widget-selected');
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
      if (xhr.status == 200) {
        cleanManager();
      }
      else {
        showError('Unable to save the headline selection.');
      }
      toggleLoading(false);
    };
    xhr.onerror = function () {
      showError('Unable to save the headline selection.');
      toggleLoading(false);
    };
    toggleLoading(true);
    xhr.open('POST', '/admin/reliefweb_homepage/headlines/update?' + Date.now());
    xhr.send(JSON.stringify(getHeadlines()));
  }

  /**
   * Load the headline list.
   */
  function loadHeadlines() {
    var xhr = new XMLHttpRequest();
    xhr.onload = function () {
      if (xhr.responseText) {
        createHeadlines(xhr.responseText);
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
    xhr.open('GET', '/admin/reliefweb_homepage/headlines/retrieve?' + Date.now());
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
            articles[i].setAttribute('data-headlines-widget-duplicate', '');
          }
          duplicates = true;
        }
        else {
          selection[id][0].removeAttribute('data-headlines-widget-duplicate');
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
      replacement.removeAttribute('data-headlines-widget-dragged');
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
      document.body.removeAttribute('data-headlines-widget-drag-on');
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
        document.body.setAttribute('data-headlines-widget-drag-on', '');

        // Create the draggable element.
        draggable = target.cloneNode(true);
        draggable.removeAttribute('data-headlines-widget-selected');
        draggable.setAttribute('data-headlines-widget-dragged', '');
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
  function createManager(section) {
    section.classList.add('rw-headlines-widget-processed');
    // Handle click events.
    section.addEventListener('click', handleClick);

    // Get the container of the section headlines.
    var container = section.querySelector('article').parentNode;
    // Handle drag and drop of articles.
    handleDragDrop(container);

    // Create the widget.
    var edit = createButton('edit', 'Edit');
    var save = createButton('save', 'Save', true);
    var cancel = createButton('cancel', 'Cancel', true);

    var actions = document.createElement('div');
    actions.classList.add('rw-headlines-widget__actions');
    actions.appendChild(save);
    actions.appendChild(cancel);

    var content = document.createElement('div');
    content.classList.add('rw-headlines-widget__content');

    var widget = document.createElement('div');
    widget.classList.add('rw-headlines-widget');
    widget.appendChild(content);
    widget.appendChild(actions);

    // Add the edit button close to the title.
    var title = section.querySelector('.rw-river__title');
    title.appendChild(edit);

    var header = title.parentNode;
    if (header.tagName !== 'HEADER') {
      header = document.createElement('header');
      header = title.parentNode.insertBefore(header, title);
      header.appendChild(title);
    }
    header.classList.add('rw-headlines-widget-wrapper');
    header.appendChild(widget);

    return {
      backup: [],
      section: section,
      container: container,
      widget: widget,
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
    var error = manager.section.querySelector('.rw-headlines-widget__error');
    if (error) {
      manager.section.removeChild(error);
    }
    error = document.createElement('div');
    error.classList.add('rw-headlines-widget__error');
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
    manager.section.classList.remove('rw-headlines-widget-visible');
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
    manager.section.classList.add('rw-headlines-widget-visible');
    manager.open = true;
  }

  var manager;
  var headlines = document.querySelector('.rw-river--headlines:not(.rw-headlines-widget-processed)');
  if (headlines) {
    manager = createManager(headlines);
  }

})();
