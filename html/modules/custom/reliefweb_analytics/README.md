# ReliefWeb - Analytics module

This module provides analytics customizations.

## Path alias

For the moment this module does path lookups, once dimension 21 is deployed, it can be removed.

## Jobs

Make sure to set `GOOGLE_APPLICATION_CREDENTIALS` and `reliefweb_analytics_property_id`

```php
$settings['reliefweb_analytics_property_id'] = '291027553';
putenv('GOOGLE_APPLICATION_CREDENTIALS=/var/www/credentials.json');
```

### Every hour on even hours

```bash
drush reliefweb_analytics:homepage
drush reliefweb_analytics:disasters
```

### Every hour on odd hours

```bash
drush reliefweb_analytics:homepage
drush reliefweb_analytics:countries
```
