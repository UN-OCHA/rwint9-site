# ReliefWeb - Analytics module

This module provides analytics customizations.

## Path alias

For the moment this module does path lookups, once dimension 21 is deployed, it can be removed.

## Jobs

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
