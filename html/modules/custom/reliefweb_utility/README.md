ReliefWeb - Utility module
==========================

This module provides various utility helpers, traits and plugins.

## Helpers

This module provides a set of helpers used by other ReliefWeb custom modules:

- [ClassHelper](src/Helpers/ClassHelper.php): check class existence and help converting to camelCase.
- [DateHelper](src/Helpers/DateHelper.php): get timestamp from a date (string, object etc.) and format date.
- [EntityHelper](src/Helpers/DateHelper.php): get entity from a route, get bundle label etc.
- [HtmlOutliner](src/Helpers/HtmlOutliner.php): helper to fix the heading hierarchy of HTML.
- [HtmlSanitizer](src/Helpers/HtmlSanitizer.php): helper to sanitize HTML content.
- [HtmlSummarizer](src/Helpers/HtmlSummarizer.php): helper to summarize some HTML content.
- [LegacyHelper](src/Helpers/LegacyHelper.php): get new file/image URI/UUID from legacy URLs.
- [LocalizationHelper](src/Helpers/LocalizationHelper.php): helper to sort and format content in a given language.
- [MailHelper](src/Helpers/MailHelper.php): get better formatted plain text version of an HTML email.
- [MarkdownHelper](src/Helpers/MailHelper.php): convert markdown to HTML.
- [MediaHelper](src/Helpers/MediaHelper.php): get image from media entity.
- [TaxonomyHelper](src/Helpers/TaxonomyHelper.php): check if term is referenced by another entity, get source term shortname.
- [TextHelper](src/Helpers/TextHelper.php): clean text, strip embedded content, get diff.
- [UrlHelper](src/Helpers/UrlHelper.php): helper extending the functionalities of the Drupal UrlHelper.
- [UserHelper](src/Helpers/UserHelper.php): check if a user has roles.

## CommonMark converter

This module provides a CommonMark [converter](src/HtmlToMarkdown/Converters/TextConverter.php) to be used instead of the text converter from the CommonMark library and that prevents unwanted escaping of URLs notably.

## Text filters

This module provides some filters that can be used in text format settings:

- [IFrameFilter](src/Plugin/Filter/IFrameFilter.php): converts special `[iframe:widthxheight title](link)` syntax to iframe HTML markup. This is notably used to prevent white screen of death when an iframe is rendered in a form (ex: preview on same page).
- [MarkdownFilter](src/Plugin/Filter/MarkdownFilter.php): converts markdown to HTML. This is to be used with CKEditor.
- [ReliefWebTokenFilter](src/Plugin/Filter/ReliefWebTokenFilter.php): converts disaster map tokens (see `reliefwen_disaster_map`).

## Response

This modules provides a [wrapper](src/Response/JsonResponse) around a Guzzle Response object to ease working with the response from the docstore.

## Traits

This module provides a [trait](src/Traits/EntityDatabaseInfoTrait.php) with various methods to get entity and field database information (table, field etc.).

## Twig extension

This module provides a [twig extension](src/TwigExtension.php) that adds useful filters and functions to help getting or transforming data in the ReliefWeb templates.

## Hook implementations

This modules also provides hook implementations for logic that applies to many parts of the site and don't belong to a more specialized module:

- `reliefweb_utility_preprocess`: initialize the `attributes` and `*_attributes` variables for the `reliefweb_*` templates.
- `reliefweb_utility_file_update`: acts on files that have just been saved to update their URIs using their UUIDs.

