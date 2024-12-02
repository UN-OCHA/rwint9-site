Reliefweb - Import module
=========================

## Drush

This module provides [drush commands](src/Drush/Commands/ReliefWebImport.php) to allow importing content from API and feeds.

## Job feeds importer

The [JobFeedsImporter](src/Service/JobFeedsImporter.php) handles importing jobs from feeds.

See "Specifications for exporting jobs feeds into ReliefWeb" document for specifications.

The feeds information are set via a `ReliefWebImportInfo` field on the source entities.

Imported jobs are checked and sanitized before being created.

### Import jobs

```bash
drush reliefweb_import:jobs --verbose
```
