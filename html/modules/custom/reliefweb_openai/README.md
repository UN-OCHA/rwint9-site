# POC Open AI

Various test integrations using ChatGPT and AWS Comprehend.

## Config

File `html/sites/default/settings.local.php`

```php
$config['reliefweb_openai.settings']['token'] = '';
$config['reliefweb_openai.settings']['aws_access_key'] = '';
$config['reliefweb_openai.settings']['aws_secret_key'] = '';
$config['reliefweb_openai.settings']['aws_region'] = 'eu-central-1';
$config['reliefweb_openai.settings']['aws_endpoint_theme_classifier'] = 'arn:aws:comprehend:eu-central-1:694216630861:document-classifier-endpoint/rw-themes';
$config['reliefweb_openai.settings']['azure_endpoint'] = 'https://tst003.openai.azure.com/openai/deployments/tst003/chat/completions?api-version=2023-03-15-preview';
$config['reliefweb_openai.settings']['azure_apikey'] = '';
```

## Drush commands

```
drush reliefweb_openai:train_jobs           Train jobs.
drush reliefweb_openai:status               Jobs status.
drush reliefweb_openai:results              Jobs status.
drush reliefweb_openai:test_jobs            Test it
drush reliefweb_openai:job_categories       Job categories from API.
drush reliefweb_openai:job_categories_test  Job categories from API.
drush reliefweb_openai:summarize_pdf        Summarize a PDF.
drush reliefweb_openai:aws_endpoints:list   List endpoints.
drush reliefweb_openai:aws_endpoints:create Create endpoint.
drush reliefweb_openai:aws_endpoints:delete Delete endpoint.
```

## Forms

Extra buttons added to jobs and reports to ask AI for a list of humanitarian themes
based on the body text.

## Jobs

When a job posters marks the job is being ready for review (state pending), the job description
is send to ChatGPT to determine if the job is a tender or not.
