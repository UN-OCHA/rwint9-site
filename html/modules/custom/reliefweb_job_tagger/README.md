# Reliefweb job tagger

## Test instructions

### Clear index

drush eval "reliefweb_job_tagger_index_clear()"

### Index jobs

drush eval "reliefweb_job_tagger_index_jobs()"

### Evaluate job.

drush eval "reliefweb_job_tagger_get_similar_jobs(4039303)"
