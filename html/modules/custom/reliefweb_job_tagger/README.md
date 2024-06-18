# Reliefweb job tagger

## Test instructions

### Clear index

```bash
drush rw-job:clear
```

### Index jobs

```bash
drush rw-job:index
```

### Evaluate

```bash
drush eval "reliefweb_job_tagger_test_accuracy()"
```

Will create a csv file `stats.csv` containing the analysis of all jobs.
