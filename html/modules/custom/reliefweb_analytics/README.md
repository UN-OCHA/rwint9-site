ReliefWeb - Analytics module
============================

This module provides integration with Google Analytics.

## Google Tag Manager

This module generates dimensions like entity type, terms etc. that are added to the page in the DataLayer object used by Google Tag Manager.

For legacy reasons, the dimensions are named `dimensionX`.

## Most read content

This module also generates `csv` files containing the most read reports per country and disaster via drush commands performing requests against the Google Analytics API.

This data can then be consumed by the home, country and disaster pages to display the most read content.

### Commands

The [drush commands](src/Command/ReliefwebMostReadCommand.php) are:

- `reliefweb_analytics:homepage`: get the most read reports in the last 24 hours for display on the homepage
- `reliefweb_analytics:countries-all`: get the most read reports by country
- `reliefweb_analytics:disasters-all`: get the most read reports by disaster

The country and disaster commands use a decaying formula to boost recent documents:

`1 / (number of days since publication)^1.5`

### Path alias

For the moment this module uses the URL path of a document as key and this compared against the `url_alias` in the ReliefWeb API with retrieving the data for the most read blocks on the home, country and disaster pages.

This could be replaced by the entity ID if it is added a dimension in the data layer (ex: `dimension21`).

### Settings to run the commands

Make sure to set `GOOGLE_APPLICATION_CREDENTIALS` env variable and define the `reliefweb_analytics_property_id` setting:

```php
$settings['reliefweb_analytics_property_id'] = '291027553';
putenv('GOOGLE_APPLICATION_CREDENTIALS=/var/www/credentials.json');
```
