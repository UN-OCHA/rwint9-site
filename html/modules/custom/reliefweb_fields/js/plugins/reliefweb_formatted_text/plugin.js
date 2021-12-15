/* global CKEDITOR */
(function (CKEDITOR) {
  'use strict';

  // Filter transformation for headings.
  function createHeadingTransformation(element, tag) {
    return [
      {
        element: element,
        right: function (element, tools) {
          tools.transform(element, tag);

          // Wrap headings changed to `<strong>` elements into `<p>` elements
          // so they are not displayed inline.
          if (tag === 'strong' && typeof element.clone !== 'undefined') {
            element.wrapWith(new CKEDITOR.htmlParser.element('p')); // eslint-disable-line new-cap
          }
        }
      }
    ];
  }

  // Add a plugin to apply transformations to the "reliefweb_formatted_text"
  // fields.
  CKEDITOR.plugins.add('reliefweb_formatted_text', {
    // Disable text styles and remove unallowed headings.
    // We need to do that in the `beforeInit` function otherwise changes don't
    // apply.
    beforeInit: function (editor) {
      var element = editor.element;
      if (element && element.hasAttribute('data-max-heading-level')) {
        // Get the maximum heading level allowed for the field.
        var maxHeadingLevel = parseInt(element.getAttribute('data-max-heading-level'), 10);

        // Get the list of tags available as text styles.
        var formatTags = editor.config.format_tags.split(';');

        // Disable heading text styles and remove unallowed headings.
        for (var i = 1; i <= 6; i++) {
          if (i < maxHeadingLevel) {
            // Ensure the heading before the max level are not allowed.
            delete editor.config.allowedContent['h' + i];

            // Remove them from the list of available text styles.
            var index = formatTags.indexOf('h' + i);
            if (index !== -1) {
              formatTags.splice(index, 1);
            }
          }
        }

        // Update the list of available text styles.
        editor.config.format_tags = formatTags.join(';'); // eslint-disable-line camelcase
      }

      // This will enable the editor.parseFilter across browsers and allow
      // to add the heading transformations. By default it's only enabled in
      // webkit and blink browsers.
      editor.config.pasteFilter = 'semantic-content';
    },

    // Add heading transformations.
    // We need to do that in the `init` function so that the `parseFilter`
    // editor property is available to add the transformations to.
    init: function (editor) {
      var element = editor.element;
      if (element && element.hasAttribute('data-max-heading-level')) {
        // Get the maximum heading level allowed for the field.
        var maxHeadingLevel = parseInt(element.getAttribute('data-max-heading-level'), 10);

        // Add the heading transformations.
        var transformations = [];
        for (var i = 1; i <= 6; i++) {
          var level = i + maxHeadingLevel - 1;
          var tag = level > 6 ? 'strong' : 'h' + level;

          // Add the heading transformation.
          transformations.push(createHeadingTransformation('h' + i, tag));
        }
        editor.pasteFilter.addTransformations(transformations);
      }
    }
  });

})(CKEDITOR);
