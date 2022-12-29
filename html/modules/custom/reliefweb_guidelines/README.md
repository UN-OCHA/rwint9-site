ReliefWeb - Guidelines module
=============================

This module extends the https://drupal.org/project/guidelines contrib module to better fit guideline entities into the ReliefWeb editorial workflow.

## Guideline entities

This modules provides [entity bundle classes](src/Entity) and form alteration and moderation [services](src/Services) to align guideline entities with other content entities.

This also adds the `moderation_status` base field to the guideline entities.

## Guidelines page

This module provides a route (`/guidelines`) and [controller](src/Controller/GuidelineSinglePageController.php) to display the guidelines in a single page.

## TODO

The https://drupal.org/project/guidelines doesn't really bring anything that couldn't be handled by nodes and requires quite a lot of customization to work with the ReliefWeb editorial workflow.

Maybe this could be simplified by converting them to nodes with a taxonomy vocabulary for the guideline lists.
