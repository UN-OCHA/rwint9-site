ReliefWeb - Moderation module
=============================

This module provides the editorial backend pages to moderate content as well as the logic to alter entity access based on the entity status.

## Services

This module provides moderation [services](src/Services) that handle access to moderated entities and provide the content of the moderation pages (ex: `/moderation/content/report`).

## Moderated entities

This module adds an `moderation_status` base field to `node` and `taxonomy_term` entities to allow a more fine grained management of the publication status of entities (ex: `draft`, `published`, `expired`).

To work with that, the module provides an [interface](src/EntityModeratedInterface.php) and a [trait](src/EntityModeratedTrait.php) to make an entity "moderated" (i.e. entity that can have different statuses).

This interface is used by the [reliefweb_entities](../reliefweb_entities) module for the content entities.

## Moderation pages

This module provides a route and [controller](src/Controller/ModerationPage.php) to moderation pages for all the main content entities (ex: `/moderation/content/job`)

Those pages contain a paginated and sortable list of entities and relevant filters to help with the ReliefWeb editorial workflow.

## Inactive sources

This module provides a [drush command](src/Commands/ReliefWebModerationCommands.php) that can be run to update the status of sources for which that has been no recently posted content.
