/**
 * Text length checker widget handling.
 */
(function (Drupal) {

  'use strict';

  Drupal.behaviors.reliefwebFormLengthChecker = {
    attach: function (context, settings) {
      // Check support.
      if (!document.addEventListener) {
        return;
      }

      // Translations.
      var t = Drupal.t;

      // Prepare the messages with the range values.
      function prepareMessages(range) {
        var messages = {
          length: t('Length: _length_ characters'),
          short: t('The text appears to be too short. It is recommended to have between _low_ and _high_ characters.'),
          long: t('The text appears to be too long. It is recommended to have between _low_ and _high_ characters.'),
          ok: t('The text is in the recommended range of _low_ and _high_ characters. Thank you.')
        };
        var statuses = ['short', 'long', 'ok'];
        for (var i = 0, l = statuses.length; i < l; i++) {
          var status = statuses[i];
          messages[status] = messages[status]
          .replace('_low_', range[0])
          .replace('_high_', range[1]);
        }
        return messages;
      }

      // Check the length against the range and return its status.
      function getLengthStatus(length, low, high) {
        if (length < low) {
          return 'short';
        }
        if (length > high) {
          return 'long';
        }
        return 'ok';
      }

      // Create an indicator to show the status of the text length.
      function createIndicator(element, messages, low, high) {
        // Get the initial length and status.
        var length = element.value.length;
        var status = getLengthStatus(length, low, high);

        // Text node to store the length status message.
        var message = document.createTextNode(messages[status]);

        // Split the length so we can simply update the length text node to
        // improve performances.
        var offset = messages.length.indexOf('_length_');
        var prefix = messages.length.substr(0, offset);
        var suffix = messages.length.substr(offset + 8);

        // Text node to store the length.
        var indicator = document.createTextNode(length);

        // Indicator container.
        var container = document.createElement('div');
        container.setAttribute('aria-assertive', 'polite');
        container.setAttribute('data-lengthchecker-status', status);

        // Create the text elements.
        container.appendChild(document.createTextNode(prefix));
        container.appendChild(indicator);
        container.appendChild(document.createTextNode(suffix));
        container.appendChild(document.createTextNode(' - '));
        container.appendChild(message);

        // Insert the indicator after the textarea/input element.
        element.parentNode.insertBefore(container, element.nextSibling);

        // Update the message when the text changes.
        element.addEventListener('input', function (event) {
          var length = event.target.value.length;
          var status = getLengthStatus(length, low, high);

          // Update the length indicator.
          indicator.nodeValue = length;

          // Update the status and message only if the status changed.
          if (container.getAttribute('data-lengthchecker-status') !== status) {
            container.setAttribute('data-lengthchecker-status', status);
            message.nodeValue = messages[status];
          }
        });
      }

      // Enable length checker.
      function enableLengthChecker(element) {
        var range = element.getAttribute('data-with-lengthchecker').split('-');
        var messages = prepareMessages(range);

        // Find the first text input or textarea if element is not one.
        if (element.nodeName !== 'INPUT' && element.nodeName !== 'TEXTAREA') {
          element = element.querySelector('input[type="text"], textarea');
        }

        // Create the length indicator.
        createIndicator(element, messages, parseInt(range[0], 10), parseInt(range[1], 10));
      }

      // Enable length checker on relevant textarea fields.
      var elements = context.querySelectorAll('[data-with-lengthchecker]:not([data-with-lengthchecker-processed])');
      for (var i = 0, l = elements.length; i < l; i++) {
        var element = elements[i];
        element.setAttribute('data-with-lengthchecker-processed', '');
        enableLengthChecker(element);
      }
    }
  };

})(Drupal);
