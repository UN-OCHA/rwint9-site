ReliefWeb - Guidelines module
=============================

This module provides editorial guidelines as `node:guideline` content and
`taxonomy_term:guideline_list` taxonomy terms.

## Guideline content

The module provides [entity bundle classes](src/Entity) and form alteration
and moderation [services](src/Services) to align guidelines with other ReliefWeb
content entities.

## Guidelines page

This module provides a route (`/guidelines`) and
[controller](src/Controller/GuidelineSinglePageController.php) to display the
guidelines on a single page.

## Form popups

Guidelines can be loaded in entity edit forms via JSON
(`/guidelines/json/{entity_type}/{bundle}`) and the `rw-guidelines` theme
component.
