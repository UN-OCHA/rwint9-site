Reliefweb - Import module
=========================

This module provides a [drush command](src/Command/ReliefWebImportCommand.php) to allow importing jobs from feeds.

The feeds information are set via a `ReliefWebImportInfo` field on the source entities.

Imported jobs are checked and sanitized before being created.

## Import jobs

```bash
drush reliefweb_import:jobs --verbose
```
