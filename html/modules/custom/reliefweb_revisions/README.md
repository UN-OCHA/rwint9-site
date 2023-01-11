ReliefWeb - Revisions module
============================

This module provides a [service](src/Services/EntityHistory.php) to display an entity's history with detailed changes between revisions.

## Revisioned entities

This module provides an [interface](src/EntityRevisionedInterface.php) and a [trait](src/EntityRevisionedTrait.php) to make help retrieve an entity's history.

This interface is used by the [reliefweb_entities](../reliefweb_entities) module for the content entities.

## Loading and caching

Calculating the history can be quite intensive so it's cached and cleared only when the entity is changed or a term is changed.

It's also loaded asynchronously to avoid blocking the display of the form or entity page.
