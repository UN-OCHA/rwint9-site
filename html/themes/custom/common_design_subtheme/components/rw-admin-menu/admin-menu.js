(function (Drupal) {
  'use strict';

  Drupal.behaviors.adminMenu = {
    attach: function (context, settings) {
      // Move Admin Menu section to the header.
      this.moveToHeader('admin-menu', 'cd-global-header__actions');
    },

    /**
     * Hide and move admin menu to the top of the header after the target.
     */
    moveToHeader: function (id, target) {
      var section = document.getElementById(id);
      var sibling = document.getElementById(target);
      if (section && sibling) {
        // Ensure the element is hidden before moving it to avoid flickering.
        this.toggleVisibility(section, true);
        sibling.parentNode.insertBefore(section, sibling);
      }
    },

    toggleVisibility: function (element, hide) {
      element.setAttribute('cd-data-hidden', hide === true);
    }
  };

})(Drupal);
