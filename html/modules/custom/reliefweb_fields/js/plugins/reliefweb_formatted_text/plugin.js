/* global CKEditor5, once */
(function (once, CKEditor5) {
  'use strict';

  // Check if an element has the max heading level attribute.
  function hasMaxHeadingLevel(element) {
    return element && element.hasAttribute('data-max-heading-level');
  }

  // Get the maximum heading level.
  function getMaxHeadingLevel(element) {
    let maxHeadingLevel = parseInt(element.getAttribute('data-max-heading-level'), 10);
    if (maxHeadingLevel < 1) {
      maxHeadingLevel = 1;
    }
    else if (maxHeadingLevel > 6) {
      maxHeadingLevel = 6;
    }
    return maxHeadingLevel;
  }

  // CKEditor 5 plugin that converts headings.
  class RWHeadingConverterPlugin {
    constructor(editor) {
      this.editor = editor;
    }

    init() {
      const editor = this.editor;
      const element = editor.sourceElement;
      const clipboardPipeline = editor.plugins.get('ClipboardPipeline');
      const callbacks = new Map();

      // Add heading conversion based on the max allowed heading level.
      if (hasMaxHeadingLevel(element)) {
        const maxHeadingLevel = getMaxHeadingLevel(element);

        // Add the heading conversion for each level.
        for (let i = 1; i <= 6; i++) {
          const level = i + maxHeadingLevel - 1;

          // Convert to another heading with a lower level in the hierarchy.
          if (level <= 6) {
            callbacks.set('element:h' + i, this['convertHeadingToHeading' + level].bind(this));
          }
          // Convert to bold paragraph.
          else {
            callbacks.set('element:h' + i, this.convertHeadingToParagraph.bind(this));
          }
        }

        // We register the heading conversions when data from the clipboard is
        // going to be transformed. We need to do that with a high priority to
        // ensure the conversions are applied before the headings plugin's ones.
        clipboardPipeline.on('inputTransformation', (event, data) => {
          callbacks.forEach((callback, event) => {
            editor.conversion.for('upcast').add(dispatcher => {
              dispatcher.on(event, callback, {priority: 'high'});
            });
          });
        }, {priority: 'high'});

        // Once the data is transformed and is ready to be inserted in the view,
        // we unregister the conversions so that they are not run when adding
        // headings another way than pasting content.
        clipboardPipeline.on('contentInsertion', (event, data) => {
          console.log('Content was inserted.');
          callbacks.forEach((callback, event) => {
            editor.conversion.for('upcast').add(dispatcher => {
              dispatcher.off(event, callback);
            });
          });
        }, {priority: 'low'});
      }
    }

    convertHeadingToHeading2(event, data, conversionApi) {
      this.convertHeadingToElement(data, conversionApi, 'heading2', false);
    }

    convertHeadingToHeading3(event, data, conversionApi) {
      this.convertHeadingToElement(data, conversionApi, 'heading3', false);
    }

    convertHeadingToHeading4(event, data, conversionApi) {
      this.convertHeadingToElement(data, conversionApi, 'heading4', false);
    }

    convertHeadingToHeading5(event, data, conversionApi) {
      this.convertHeadingToElement(data, conversionApi, 'heading5', false);
    }

    convertHeadingToHeading6(event, data, conversionApi) {
      this.convertHeadingToElement(data, conversionApi, 'heading6', false);
    }

    convertHeadingToParagraph(event, data, conversionApi) {
      this.convertHeadingToElement(data, conversionApi, 'paragraph', true);
    }

    convertHeadingToElement(data, conversionApi, element, bold = false) {
      // Check if the view item can be converted.
      if (!conversionApi.consumable.test(data.viewItem, {name: true})) {
        return;
      }

      // Create the paragraph to use as model for the heading.
      const modelElement = conversionApi.writer.createElement(element);

      // Try to safely insert a paragraph at the model cursor - it will try to
      // find an allowed parent for the current element.
      if (!conversionApi.safeInsert(modelElement, data.modelCursor)) {
        return;
      }

      // Consume the view item so that other converter can ignore it.
      if (conversionApi.consumable.consume(data.viewItem, {name: true})) {
        // Convert the children to models and add the bold attribute to them.
        const {modelRange} = conversionApi.convertChildren(data.viewItem, modelElement);
        if (bold) {
          for (let item of modelRange.getItems()) {
            conversionApi.writer.setAttribute('bold', true, item);
          }
        }

        // Update `modelRange` and `modelCursor` in the `data`.
        conversionApi.updateConversionResult(modelElement, data);
      }
    }
  }

  // Update heading configuration used to create an editor for the element.
  function updateEditorHeadingConfig(element, config) {
    if (hasMaxHeadingLevel(element)) {
      const maxHeadingLevel = getMaxHeadingLevel(element);
      const maxHeadingModel = 'heading' + maxHeadingLevel;

      // Restrict the selectable heading levels.
      config.heading.options = config.heading.options.filter(item => {
        return !item.model.startsWith('heading') || item.model >= maxHeadingModel;
      });

      // Add the heading conversion plugin.
      config.extraPlugins.push(RWHeadingConverterPlugin);
    }
    return config;
  }

  // Override the editor creation to be able to change the heading config.
  // Drupal doesn't provide any hook that would allow us to alter the config
  // before an editor is created so we need to do this.
  once('reliefweb-formatted-text', 'body').forEach(context => {
    const oldCreate = CKEditor5.editorClassic.ClassicEditor.create;

    CKEditor5.editorClassic.ClassicEditor.create = function (element, config) {
      // Update configuration for the headings, removing the ones above the
      // max allowed heading level and adding the conversion plugin.
      config = updateEditorHeadingConfig(element, config);
      return oldCreate.call(this, element, config);
    };
  });

})(once, CKEditor5);
