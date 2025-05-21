(function ($, Drupal, drupalSettings) {

  "use strict";

  Drupal.behaviors.inoreaderYamlditor = {
    attach: function (context) {
      var initEditor = function () {
        const elements = once('data-inoreader-yaml-editor', 'textarea[data-inoreader-yaml-editor]', context);
        elements.forEach(function (e) {
          var $textarea = $(e);
          var $editDiv = $('<div>').insertBefore($textarea);
          $editDiv.css({ fontSize: 18 });

          $textarea.addClass('visually-hidden');

          // Init ace editor.
          var editor = ace.edit($editDiv[0]);
          editor.session.setValue($textarea.val());
          editor.session.setMode("ace/mode/yaml");
          editor.session.setTabSize(2);
          editor.setTheme('ace/theme/chrome');
          editor.setOptions({
            minLines: 10,
            maxLines: 30,
            enableAutoIndent: true,
          });

          // Update Drupal textarea value.
          editor.getSession().on('change', function () {
            $textarea.val(editor.getSession().getValue());
          });
        });
      };

      // Initialize editor.
      if (typeof ace !== 'undefined') {
        initEditor();
      }
    }
  };

})(jQuery, Drupal, drupalSettings);
