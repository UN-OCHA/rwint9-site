# POC Open AI

Enabled for themes on reports.

## Config

Add `$config['reliefweb_openai.settings']['token'] = '';` to `html/sites/default/settings.local.php`

## Usage

`drush reliefweb_openai:train_jobs career_categories 100` will submit learning and validation data
 and returns fine tune id

`drush reliefweb_openai:status ft-PAEjOd62YHk2FRpUy3YQj3gD` returns status of learning

`drush reliefweb_openai:result ft-PAEjOd62YHk2FRpUy3YQj3gD` return results file

`drush reliefweb_openai:test_jobs ada:ft-un-ocha:rw-jobs-career-categories-2023-03-03-10-49-12 career_categories 10`
will test model using submitted data
