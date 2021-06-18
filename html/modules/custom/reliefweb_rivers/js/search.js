/**
 * Script to preserve the search query when switching the river view.
 *
 * This also removes empty hidden inputs to keep the URL clean.
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

  // Prevent event default behavior.
  function preventDefault(event) {
    if (typeof event.preventDefault !== 'undefined') {
      event.preventDefault();
    }
    else {
      event.returnValue = false;
    }
  }

  // Set the parameter value of a form, create one if it doesn't exist.
  function setFormParameter(form, name, value) {
    var parameter = form.querySelector('input[name="' + name + '"]');
    if (!parameter) {
      parameter = document.createElement('input');
      parameter.setAttribute('type', 'hidden');
      parameter.setAttribute('name', name);
      form.insertBefore(parameter, form.firstChild);
    }
    parameter.value = value;
  }

  // Update and submit the form.
  function submitForm(form) {
    // Disable empty parameters so that they are not submitted and the url
    // stays clean.
    var parameters = form.querySelectorAll('input[name]');
    for (var i = 0, l = parameters.length; i < l; i++) {
      var parameter = parameters[i];
      if (!parameter.hasAttribute('value') || parameter.value === '') {
        parameter.disabled = true;
      }
    }
    form.submit();
  }

  // Get the main search form, abort if not found.
  var form = document.getElementById('river-search-form');
  if (!form) {
    return;
  }

  // Process form submission after clearing the parameters.
  addEventListener(form, 'submit', function (event) {
    preventDefault(event);
    submitForm(form);
  });

  // Handle view change. We want to preserve any search or filter selection the
  // user may have made.
  var viewsList = document.getElementById('river-views');
  if (viewsList) {
    addEventListener(viewsList, 'click', function (event) {
      var target = event.target;
      if (target && target.nodeName === 'A') {
        preventDefault(event);
        setFormParameter(form, 'view', target.parentNode.id.substr(5));
        submitForm(form);
      }
    });
  }

})();
