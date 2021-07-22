ReliefWeb - Utility module
==========================

This module provides various utility helpers:

- `HtmlOutliner`: helper to fix the heading hierarchy of HTML.
- `HtmlSanitizer`: helper to sanitize HTML content.
- `HtmlSummarizer`: helper to summarize some HTML content.
- `LocalizationHelper`: helper to sort and format content in a given language.
- `UrlHelper`: helper extending the functionalities of the Drupal UrlHelper.

This modules also provides hook implementations for logic that applies to many
parts of the site and don't belong to a more specialized module:

- `reliefweb_utility_preprocess`: initialize the `attributes` and
  `title_attributes` variables for the `reliefweb_*` templates.
- `reliefweb_utility_file_update`: acts on files that have just been saved to
  update their URIs using their UUIDs.
