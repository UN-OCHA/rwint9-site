/**
 * Text formatting handling.
 */
(function (Drupal) {

  'use strict';

  Drupal.behaviors.reliefwebFormFormatting = {
    attach: function (context, settings) {
      // Check support.
      if (!document.addEventListener) {
        return;
      }

      // Translations.
      var t = Drupal.t;

      /**
       * Text formatting.
       */

      // Make the first letter of the word upper case and the rest lower case.
      function wordCase(text) {
        return text.charAt(0).toUpperCase() + text.substring(1).toLowerCase();
      }
      // Change the case of the active DOM element's value.
      function changeCase(element, action) {
        if (document.activeElement === element) {
          var text = element.value;
          var selectionStart = element.selectionStart;
          var selectionEnd = element.selectionEnd;
          var selection = text.substring(selectionStart, selectionEnd);
          if (action === 'UPPER') {
            selection = selection.toUpperCase();
          }
          else if (action === 'lower') {
            selection = selection.toLowerCase();
          }
          else if (action === 'Word') {
            selection = wordCase(selection);
          }
          text = text.substring(0, selectionStart) + selection + text.substring(selectionEnd);
          element.value = text;
          element.setSelectionRange(selectionStart, selectionEnd);
        }
      }
      function changeToUpperCase(element) {
        changeCase(element, 'UPPER');
      }
      function changeToLowerCase(element) {
        changeCase(element, 'lower');
      }
      function changeToWordCase(element) {
        changeCase(element, 'Word');
      }
      // Make the first letter of the title upper case and the rest lower case.
      function formatTitle(element) {
        var text = element.value;
        text = wordCase(text);
        element.value = text;
        element.focus();
        element.select();
      }

      /**
       * Text correction.
       */

      function normalizeText(text) {
        // Remove exponents.
        text = text.replace(/\n\d{1,2}\n/g, '');
        // Correct truncated words.
        text = text.replace(/(\S-)\n/g, '$1');
        // Remove non-break space.
        text = text.replace(/[\xa0]/g, ' ');
        // Remove extra spaces.
        text = text.replace(/[ ]+/g, ' ');
        return text;
      }
      function guessParagraphs(text) {
        // 2 line breaks eventually separated by white characters.
        text = text.replace(/\n\s*\n/g, '#P#');
        // 1 line break between white characters.
        text = text.replace(/\s+\n\s+/g, '#P#');
        return text;
      }
      // TODO: add some common bullet characters.
      function guessLists(text) {
        // Line breaks before (a) or a) or a.
        text = text.replace(/([,;:?!.)])((\s*\n\s*)+)((\(?[A-z0-9]{1,3}[).])|([-*\u2022\u2024\u00b7\u2027\u25e6\u22c5]))/g, '$1#L#$4');
        // Line breaks followed by an unknown character.
        text = text.replace(new RegExp('\n' + String.fromCharCode(61623), 'g'), '#L#-');
        return text;
      }
      function guessValidLineBreaks(text) {
        // Line breaks between punctuation and Capital letter.
        text = text.replace(/([,;:?!.)'"])\s*\n\s*([A-Z])/g, '$1#B#$2');
        return text;
      }
      function formatText(text) {
        // Invalid line breaks.
        text = text.replace(/\s*\n\s*/g, ' ');
        // Paragraphs.
        text = text.replace(/#P#/g, '\n\n');
        // Lists.
        text = text.replace(/#L#/g, '\n\n');
        // Valid line breaks.
        text = text.replace(/#B#/g, '  \n');

        return text;
      }
      // Correct a given text in a textarea or input text.
      function correctText(element, focus) {
        var text = element.value;

        // Clean the text.
        text = normalizeText(text);

        // Guess format.
        text = guessParagraphs(text);
        text = guessLists(text);
        text = guessValidLineBreaks(text);

        // Correct format.
        text = formatText(text);

        element.value = text;
        if (focus) {
          element.focus();
          element.select();
        }
      }

      /**
       * Buttons handling.
       */
      function addButton(element, label, callback, type) {
        var button = document.createElement('button');
        button.setAttribute('type', 'button');
        button.setAttribute('data-formatter', type);
        button.appendChild(document.createTextNode(label));

        button.addEventListener('mousedown', function (event) {
          event.preventDefault();
          callback(element);
          element.focus();
        });

        element.parentNode.insertBefore(button, element.nextSibling);
      }

      // Enable formatting.
      function enableFormatting(element) {
        var type = element.getAttribute('data-with-formatting');

        // Find the textarea/input child if the attribute is on a wrapper.
        if (element.nodeName !== 'TEXTAREA' && element.nodeName !== 'INPUT') {
          element = element.querySelector('textarea, input[type="text"]');
        }

        if (element) {
          switch (type) {
            case 'text':
              addButton(element, t('Format title'), formatTitle, type);
              addButton(element, t('UPPERCASE'), changeToUpperCase, type);
              addButton(element, t('lowercase'), changeToLowerCase, type);
              addButton(element, t('Word'), changeToWordCase, type);
              break;

            case 'pdf':
              addButton(element, t('Fix PDF Text'), correctText, type);
              break;
          }
        }
      }

      // Enable formatting on relevant textarea fields.
      var elements = context.querySelectorAll('[data-with-formatting]:not([data-with-formatting-processed])');
      for (var i = 0, l = elements.length; i < l; i++) {
        var element = elements[i];
        element.setAttribute('data-with-formatting-processed', '');
        enableFormatting(element);
      }
    }
  };

})(Drupal);
