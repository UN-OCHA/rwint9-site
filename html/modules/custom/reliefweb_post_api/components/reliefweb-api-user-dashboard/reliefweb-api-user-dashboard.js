(function (Drupal) {
  'use strict';

  /**
   * Attaches the behavior for adding a "Copy to Clipboard" button.
   */
  Drupal.behaviors.reliefwebApiUserDashboard = {
    attach: function (context) {
      // Ensure this behavior runs only once per element.
      const apiKeyContainer = context.querySelector('.reliefweb-api-user-key');
      console.log(apiKeyContainer);
      if (!apiKeyContainer || apiKeyContainer.hasAttribute('data-clipboard-initialized')) {
        return;
      }

      // Mark the container as initialized.
      apiKeyContainer.setAttribute('data-clipboard-initialized', 'true');

      // Get the API key element.
      const apiKeyElement = apiKeyContainer.querySelector('strong');
      if (!apiKeyElement) {
        console.error('API key element not found.');
        return;
      }

      // Create the "Copy to Clipboard" button.
      const copyButton = document.createElement('button');
      copyButton.textContent = Drupal.t('Copy to Clipboard');
      copyButton.className = 'copy-to-clipboard-button';

      // Append the button after the API key.
      apiKeyElement.insertAdjacentElement('afterend', copyButton);

      // Add click event listener to the button.
      copyButton.addEventListener('click', function () {
        const apiKey = apiKeyElement.textContent.trim();

        // Use the Clipboard API to copy the text.
        navigator.clipboard.writeText(apiKey)
        .then(() => {
          alert(Drupal.t('API key copied to clipboard.'));
        })
        .catch((err) => {
          console.error('Failed to copy API key:', err);
          alert(Drupal.t('Failed to copy API key. Please try again.'));
        });
      });
    }
  };
})(Drupal);
