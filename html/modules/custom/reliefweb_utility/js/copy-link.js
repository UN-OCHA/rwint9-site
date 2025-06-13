/**
 * Copy URL to clipboard functionality.
 */
(function (Drupal) {
  'use strict';

  /**
   * Drupal behavior for copy link functionality.
   */
  Drupal.behaviors.reliefWebUtilityCopyLink = {
    attach: function (context, settings) {
      // Attach click event listeners to copy link buttons.
      const copyButtons = context.querySelectorAll('.copy-link-button');
      console.log(copyButtons);
      copyButtons.forEach(function (button) {
        // Only attach if not already processed.
        if (!button.hasAttribute('data-copy-processed')) {
          button.setAttribute('data-copy-processed', 'true');
          button.addEventListener('click', function (event) {
            event.preventDefault();
            const url = this.dataset.copyUrl;
            if (url) {
              copyToClipboard(this, url);
            }
          });
        }
      });
    }
  };

  /**
   * Copy a url to the clipboard.
   */
  function copyToClipboard(element, url) {
    if (navigator.clipboard && window.isSecureContext) {
      // Use the modern Clipboard API.
      navigator.clipboard.writeText(url).then(function () {
        showCopyFeedback(element, true);
      }).catch(function () {
        showCopyFeedback(element, false);
      });
    }
    else {
      // Fallback for older browsers.
      const textArea = document.createElement('textarea');
      textArea.value = url;
      textArea.style.position = 'fixed';
      textArea.style.opacity = '0';
      document.body.appendChild(textArea);
      textArea.select();

      try {
        const successful = document.execCommand('copy');
        showCopyFeedback(element, successful);
      }
      catch (error) {
        console.log(error);
        showCopyFeedback(element, false);
      }

      document.body.removeChild(textArea);
    }
  }

  /**
   * Show feedback when copy operation completes.
   */
  function showCopyFeedback(element, success) {
    const message = success ? Drupal.t('Link copied to clipboard!') : Drupal.t('Failed to copy link');

    // Remove existing feedback elements before showing new one.
    const existingFeedbacks = document.querySelectorAll('.copy-feedback');
    existingFeedbacks.forEach(feedback => feedback.remove());

    // Create temporary feedback element.
    const feedback = document.createElement('div');
    feedback.classList.add('copy-feedback');
    feedback.textContent = message;

    // Add ARIA attributes for accessibility.
    feedback.setAttribute('role', 'alert');
    feedback.setAttribute('aria-live', 'polite');

    // Get button position and dimensions.
    const buttonRect = element.getBoundingClientRect();
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;

    feedback.style.cssText = `
      position: absolute;
      top: ${buttonRect.bottom + scrollTop + 5}px;
      left: ${buttonRect.left + scrollLeft}px;
      background: ${success ? '#4caf50' : '#f44336'};
      color: white;
      padding: 8px 12px;
      border-radius: 4px;
      z-index: 9999;
      font-size: 12px;
      white-space: nowrap;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    `;

    // Append to document body (not element parent) for proper positioning.
    document.body.appendChild(feedback);

    // Remove after 3 seconds.
    setTimeout(() => {
      if (feedback.parentNode) {
        feedback.parentNode.removeChild(feedback);
      }
    }, 3000);
  }

})(Drupal);
