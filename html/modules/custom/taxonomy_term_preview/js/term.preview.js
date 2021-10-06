(function ($, Drupal) {

  'use strict';

  Drupal.behaviors.termPreviewDestroyLinks = {
    attach: function (context) {
      function clickPreviewModal(event) {
        if (event.button === 0 && !event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey) {
          event.preventDefault();
          var $previewDialog = $('<div>'.concat(Drupal.theme('termPreviewModal'), '</div>')).appendTo('body');
          Drupal.dialog($previewDialog, {
            title: Drupal.t('Leave preview?'),
            buttons: [{
              text: Drupal.t('Cancel'),
              click: function () {
                $(this).dialog('close');
              }
            }, {
              text: Drupal.t('Leave preview'),
              click: function () {
                window.top.location.href = event.target.href;
              }
            }]
          }).showModal();
        }
      }

      var $preview = $(context).once('term-preview');

      if ($(context).find('.term-preview-container').length) {
        $preview.on('click.preview', 'a:not([href^="#"], .term-preview-container a)', clickPreviewModal);
      }
    },
    detach: function (context, settings, trigger) {
      if (trigger === 'unload') {
        var $preview = $(context).find('.content').removeOnce('term-preview');

        if ($preview.length) {
          $preview.off('click.preview');
        }
      }
    }
  };

  Drupal.behaviors.termPreviewSwitchViewMode = {
    attach: function (context) {
      var $autosubmit = $(context).find('[data-drupal-autosubmit]').once('autosubmit');

      if ($autosubmit.length) {
        $autosubmit.on('formUpdated.preview', function () {
          $(this.form).trigger('submit');
        });
      }
    }
  };

  Drupal.theme.termPreviewModal = function () {
    return '<p>'.concat(Drupal.t('Leaving the preview will cause unsaved changes to be lost. Are you sure you want to leave the preview?'), '</p><small class="description">').concat(Drupal.t('CTRL+Left click will prevent this dialog from showing and proceed to the clicked link.'), '</small>');
  };
})(jQuery, Drupal);
