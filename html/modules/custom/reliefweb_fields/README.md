ReliefWeb - Fields module
=========================

This module provides custom field types, widgets, formatters and related plugins.

## Field types

- [ReliefWebImportInfo](src/Plugin/Field/FieldType/ReliefWebImportInfo.php): used to enter feeds information for the automated job import.
- [ReliefWebLinks](src/Plugin/Field/FieldType/ReliefWebLinks.php): used for the Key Content, Appeals and Response Plans and Useful Links of country/disaster entities.
- [ReliefWebSectionLinks](src/Plugin/Field/FieldType/ReliefWebSectionLinks.php): widget used for the custom rivers on topic pages for example.
- [ReliefWebUserPostingRights](src/Plugin/Field/FieldType/ReliefWebSectionLinks.php): used for the user posting rights of source entities.

## Field widgets

- [ReliefWebDateRange](src/Plugin/Field/FieldWidget/ReliefWebDateRange.php): widget extending the core date range widget to more precisely display errors of sub fields.
- [ReliefWebDateTime](src/Plugin/Field/FieldWidget/ReliefWebDateTime.php): widget extending the core date time widget to more precisely display errors of sub fields.
- [ReliefWebDisaster](src/Plugin/Field/FieldWidget/ReliefWebDisaster.php): widget extending the `ReliefWebEntityReferenceSelect` widget, that hides external disasters for non external disaster managers.
- [ReliefWebEntityReferenceSelect](src/Plugin/Field/FieldWidget/ReliefWebEntityReferenceSelect.php): widget extending the core options select widget for entity references with much more memory efficient population of options (i.e. does not load all the terms...). It also provides options add extra information (ex: entity status) as `data` attributes on the option elements.
- [ReliefWebFormattedText](src/Plugin/Field/FieldWidget/ReliefWebFormattedText.php): widget extending the core textarea widget, used for fields that use CKEditor in order to convert the text between HTML and markdown.
- [ReliefWebImportInfo](src/Plugin/Field/FieldWidget/ReliefWebImportInfo.php): widget for the `ReliefWebImportInfo` field type.
- [ReliefWebLinks](src/Plugin/Field/FieldWidget/ReliefWebLinks.php): widget for the `ReliefWebLinks` field type.
- [ReliefWebOptions](src/Plugin/Field/FieldWidget/ReliefWebOptions.php): widget extending the core options buttons widget, for fields referencing taxonomy terms, to allow adding the term definitions.
- [ReliefWebSectionLinks](src/Plugin/Field/FieldWidget/ReliefWebSectionLinks.php): widget for the `ReliefWebSectionLinks` field type.
- [ReliefWebSource](src/Plugin/Field/FieldWidget/ReliefWebSource.php): widget extending the `ReliefWebEntityReferenceSelect` widget, that only display sources that are allowed to be used to tag the current content type (ex: report only sources).
- [ReliefWebUserPostingRights](src/Plugin/Field/FieldWidget/ReliefWebUserPostingRights.php): widget for the `ReliefWebUserPostingRights` field type.

## Field formatters

- [ReliefWebImportInfo](src/Plugin/Field/FieldFormatter/ReliefWebImportInfo.php): formatter for the `ReliefWebImportInfo` field type.
- [ReliefWebLinks](src/Plugin/Field/FieldFormatter/ReliefWebLinks.php): formatter for the `ReliefWebLinks` field type.
- [ReliefWebSectionLinks](src/Plugin/Field/FieldFormatter/ReliefWebSectionLinks.php): formatter for the `ReliefWebSectionLinks` field type.
- [ReliefWebUserPostingRights](src/Plugin/Field/FieldFormatter/ReliefWebSectionLinks.php): formatter for the `ReliefWebUserPostingRights` field type.

## CKEditor plugin

This module provides a [CKEditor 5 plugin](js/plugins/reliefweb_formatted_text/plugin.js) enabled when using a `ReliefWebFormattedText` widget to limit selectable headings and handle their conversion.

## Forms

This module provides 2 entity form controllers for the `user_posting_rights` form view mode of sources and the `profile` form view mode of countries and disasters.

- [TaxonomyTermProfile](src/Form/TaxonomyTermProfile.php): used to manage a country/disaster profile's links (key content, appeals and useful links).
- [TaxonomyTermUserPostingRights](src/Form/TaxonomyTermUserPostingRights.php): used to manage a source's user posting rights.
