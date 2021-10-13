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

### SanitizeText

"plain_text" should be changed to "markdown". D7 "markdown" format was the "plain_text" one. In D9 plain_text is really plain text and "markdown" is the real markdown. That may be the reason of the mess up in the escaping when running check_markup.

### Convert to html

Text from the feeds can be in markdown format, in which case we need to convert it HTML to do the sanitation. It's possible the escaping issue you experienced was due to the wrong text format id as it has changed in D9 (see comment above: "plain_text" => "markdown").
