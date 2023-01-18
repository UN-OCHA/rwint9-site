/**
 * Handle enabling/disabling submit buttons when an ajax request is performed.
 *
 * Note: this can conflict with other things enabling/disabling submit buttons
 * like form states.
 *
 * @todo Possibly refactor to add an event handler that disables the form
 * submission with a message instead of disabling the form elements but that
 * may also conflict with other event handlers.
 *
 * @see core/misc/ajax.js
 */
(function (Drupal) {

  'use strict';

  // Store the Drupal core ajax methods so we can call them in the overrides.
  var beforeSend = Drupal.Ajax.prototype.beforeSend;
  var success = Drupal.Ajax.prototype.success;
  var error = Drupal.Ajax.prototype.error;

  // Disable/Enable form submit buttons when an ajax event is performed.
  Drupal.Ajax.prototype.disableSubmitButtons = function (disable) {
    if (this.element && this.element.form) {
      // Store a counter that is incremented when an ajax request is performed
      // and decremented when the request is finished (success or error).
      // We only re-enable the submit buttons when there are no ajax request
      // being performed anymore. This prevents premature re-enabling when there
      // are several concurrent ajax requests.
      var counter = 0;
      if (this.element.form.hasAttribute('data-ajax-disable-submit-buttons-counter')) {
        counter = parseInt(this.element.form.getAttribute('data-ajax-disable-submit-buttons-counter'), 10);
      }
      if (disable === true) {
        counter++;
      }
      else if (counter > 0) {
        counter--;
      }
      this.element.form.setAttribute('data-ajax-disable-submit-buttons-counter', counter);

      // Disable/enable the buttons.
      if (disable === true || counter === 0) {
        // We only disable the form submit buttons so that it's still possible
        // to perform parallel requests like uploading files in different
        // fields.
        var selector = '.form-actions .form-submit';
        var elements = this.element.form.querySelectorAll(selector);
        for (var i = 0, l = elements.length; i < l; i++) {
          elements[i].disabled = disable;
        }
      }
    }
  };

  // Disable form submit buttons before sending the ajax request.
  Drupal.Ajax.prototype.beforeSend = function (xmlhttprequest, options) {
    this.disableSubmitButtons(true);
    return beforeSend.call(this, xmlhttprequest, options);
  };

  // Enable form submit buttons before handling the response's success.
  Drupal.Ajax.prototype.success = function (xmlhttprequest, options) {
    this.disableSubmitButtons(false);
    return success.call(this, xmlhttprequest, options);
  };

  // Enable form submit buttons before handling the response's error.
  Drupal.Ajax.prototype.error = function (xmlhttprequest, options) {
    this.disableSubmitButtons(false);
    return error.call(this, xmlhttprequest, options);
  };

})(Drupal);
