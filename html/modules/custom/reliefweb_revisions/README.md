ReliefWeb - Revisions module
============================

This module provides a [service](src/Services/EntityHistory.php) to display an entity's history with detailed changes between revisions.

## Revisioned entities

This module provides an [interface](src/EntityRevisionedInterface.php) and a [trait](src/EntityRevisionedTrait.php) to help retrieve an entity's history and update revision log messages.

Use `EntityRevisionedInterface::updateRevisionLogMessage()` (append / prepend / replace, with optional skip-if-present and separator) instead of calling `setRevisionLogMessage()` directly so editorial notes are not duplicated across saves. When skip-if-present is enabled, a message is considered present only if it matches a full clause after normalization (not a substring).

String merge, deduplication, and display formatting live in [`RevisionLogHelper`](../reliefweb_utility/src/Helpers/RevisionLogHelper.php) (`reliefweb_utility`); the trait wraps `updateMessage()` for entity fields. Call the helper directly when you only have a string (for example form state values) or need `formatMessage()` for display.

This interface is used by the [reliefweb_entities](../reliefweb_entities) module for the content entities.

## Loading and caching

Calculating the history can be quite intensive so it's cached and cleared only when the entity is changed or a term is changed.

It's also loaded asynchronously to avoid blocking the display of the form or entity page.
