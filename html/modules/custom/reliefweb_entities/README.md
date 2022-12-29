ReliefWeb - Entities module
===========================

This module provides customization to content entities.

## Bundle entities

This module provides [classes per entity bundle](src/Entity) to extend the Node and Term classes.

Those classes, in addition to interfaces and traits, allow to have custom logic per bundle and provide additional functionalities to customize the handling of content entities on the site.

This notably allows to have handle `presave` customizations per bundle without the need for complex hooks.

### Page type traits

This module provides traits for 2 main types of pages on the ReliefWeb site:

- [Document](src/DocumentTrait.php) for document pages (ex: job page, report page)
- [SectionedContent](src/SectionedContentTrait.php) for pages with multiple sections (ex: country page, topic page)

Those traits provides logic and facilities to help build those pages and are added to the relevant entity bundle classes.

### Custom storages

This module extends the node and term storages to add an `after_save` hook that is notably used to index an entity in the API after its data has been saved to the database.

- [Bundle node storage](src/BundleNodeStorage.php)
- [Bundle term storage](src/BundleTaxonomyTermStorage.php)

## Validation constraints

This module provides a series of [Validation constraint plugins](src/Plugin/Validation/Constraint). For example, max number of values, start date before end date etc..

## Entity Reference selection

This module provides a more memory efficient [entity reference selection plugin](src/Plugin/EntityReferenceSelection/AnyTermSelection.php) that also allow to select "unplished" content.

For, example, it allows tagging a report with a `draft` disaster.

## Entity form alter service

This module provides [entity form alter services](src/Services) for the content entity bundles.

Those services provide form alteration to handle the ReliefWeb editorial workflow. Several of those alterations are specific to the form workflow and so didn't make sense as validation constraints always applied to the entities.

## Templates

This modules provides [templates](templates) for many of the components used when rendering entities.

## Taxonomy term creation date field

This module adds a `created` base field to taxonomy terms because core still doesn't provide one...

See https://www.drupal.org/project/drupal/issues/2869432

## Media source file deletion

The [reliefweb_entities.module](reliefweb_entities.module) ensures that the source file of a media entity is deleted when the media is deleted.

## Cron

The [reliefweb_entities.module](reliefweb_entities.module) implements a cron hook that handles the publication of embargoed reports and the expiration of jobs and training.

