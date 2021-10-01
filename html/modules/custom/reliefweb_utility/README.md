ReliefWeb - Utility module
==========================

This module provides various utility helpers, traits and plugins:

- `Helpers/HtmlOutliner`: helper to fix the heading hierarchy of HTML.
- `Helpers/HtmlSanitizer`: helper to sanitize HTML content.
- `Helpers/HtmlSummarizer`: helper to summarize some HTML content.
- `Helpers/LocalizationHelper`: helper to sort and format content in a given
  language.
- `Helpers/UrlHelper`: helper extending the functionalities of the Drupal
  UrlHelper.
- `Filter/Markdown`: simple markdown filter to use in text formats.
- `Traits/EntityDatabaseInfoTrait`: various methods to get entity and field
   database information (table, field etc.).

This modules also provides hook implementations for logic that applies to many
parts of the site and don't belong to a more specialized module:

- `reliefweb_utility_preprocess`: initialize the `attributes` and
  `*_attributes` variables for the `reliefweb_*` templates.
- `reliefweb_utility_file_update`: acts on files that have just been saved to
  update their URIs using their UUIDs.

