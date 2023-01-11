ReliefWeb - API module
======================

This module provides integration with the ReliefWeb API.

## Client

This module provides a client to perform request against the ReliefWeb API via the [reliefweb_api.client](src/Services/ReliefWebApiClient) service.

## Cache

This module also defines a `cache.reliefweb_api` cache bin used to store the results of the API requests.

Cache is cleared when creating, updating or deleting a node or taxonomy term.

For example, adding a new `report` will clear the cached queries against the `reports` resource in the API.

Creating, updating or deleting a taxonomy term clears all the cached queries because terms are shared across content.

## Drush commands

This modules also provides a set of [drush commands](src/Commands/ReliefWebApiCommands.php) to allow (re-)indexing content.

Ex `drush rapi-i --limit 100 reports` will re-index the 100 most recent reports.

## Settings

The [module configuration](config/install/reliefweb_api.settings.yml) should be overridden as needed in the `settings.php` to be able to communicate with the Elasticsearch backend and the ReliefWeb API site.
