# Reliefweb Import

## Import jobs

```bash
drush reliefweb_import:jobs --verbose
```

## Todo

### Check uid

If $term->field_job_import_feed->first()->uid is not defined or not a valid "normal" user (i.e > 2) then this should throw an error.

Basically, each organization that can have their jobs automatically imported must have a regular user account that will be used as owner of those jobs.

The system user 2 is only used as user for the revisions. (I think it was the anonymous user in D7...)
