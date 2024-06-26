/**
 * @file
 * ReliefWeb Markdown editor implementation of {@link Drupal.editors} API.
 */
((Drupal) => {

  'use strict';

  /**
   * Integration with the Drupal editor API.
   *
   * @namespace
   *
   * @see Drupal.editorAttach
   */
  Drupal.editors['reliefweb_markdown_editor'] = {

    /**
     * Editor attach callback.
     *
     * @param {HTMLElement} element
     *   The element to attach the editor to.
     * @param {string} format
     *   The text format for the editor.
     */
    attach(element, format) {
      // Ensure the content is considered changed so that the saved value is
      // the markdown content.
      // Otherwise when switching from the Rich editor to the markdown editor
      // without any change to the content then the original value is restored
      // upon saving. But this original value is in HTML (converted from
      // markdown to work with CKeditor).
      element.setAttribute('data-editor-value-is-changed', 'true');
    },

    /**
     * Editor detach callback.
     *
     * @param {HTMLElement} element
     *   The element to detach the editor from.
     * @param {string} format
     *   The text format used for the editor.
     * @param {string} trigger
     *   The event trigger for the detach.
     */
    detach(element, format, trigger) {
      // Nothing to do.
    },

    /**
     * Registers a callback which CKEditor 5 will call on change:data event.
     *
     * @param {HTMLElement} element
     *   The element where the change occurred.
     * @param {function} callback
     *   Callback called with the value of the editor.
     */
    onChange(element, callback) {
      // Nothing to do.
    }

  };

})(Drupal);
