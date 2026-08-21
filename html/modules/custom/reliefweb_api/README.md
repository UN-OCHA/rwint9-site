ReliefWeb - API module
======================

This module provides integration with the ReliefWeb API.

## Client

This module provides a client to perform request against the ReliefWeb API via the [reliefweb_api.client](src/Services/ReliefWebApiClient) service.

It also provides [reliefweb_api.elasticsearch](src/Services/ReliefWebElasticsearchClient.php) for authenticated HTTPS requests to the Elasticsearch cluster used for indexing.

## Cache

This module also defines a `cache.reliefweb_api` cache bin used to store the results of the API requests.

Cache is cleared when creating, updating or deleting a node or taxonomy term.

For example, adding a new `report` will clear the cached queries against the `reports` resource in the API.

Creating, updating or deleting a taxonomy term clears all the cached queries because terms are shared across content.

## Drush commands

This modules also provides a set of [drush commands](src/Commands/ReliefWebApiCommands.php) to allow (re-)indexing content.

Ex `drush rapi-i --limit 100 report` will re-index the 100 most recent reports.

## Settings

The [module configuration](config/install/reliefweb_api.settings.yml) should be overridden as needed in the `settings.php` to be able to communicate with the Elasticsearch backend and the ReliefWeb API site.

Elasticsearch connection settings (`elasticsearch`, `elasticsearch_auth_type`, `elasticsearch_username`, `elasticsearch_password`, `elasticsearch_api_key`, `elasticsearch_verify_tls`, `elasticsearch_ca_file`, `elasticsearch_retry`) apply to both API indexing and other Drupal code that talks to the cluster via the `reliefweb_api.elasticsearch` service. Keep credentials out of exported config and set them in `settings.php`.

`elasticsearch_retry` is the number of retries after the first request when Elasticsearch returns HTTP 429 (square backoff; default 2).

Do not reuse `verify_ssl` for Elasticsearch; that flag is only for the public ReliefWeb API client.
