# ReliefWeb - Drupal 9 version

This is the drupal 9 codebase for the [ReliefWeb](https://reliefweb.int) site.

> ReliefWeb is the largest humanitarian information portal in the world. Founded
in 1996, the portal now hosts more than 850,000 humanitarian situation reports,
press releases, evaluations, guidelines, assessments, maps and infographics.

## Docksal

- `git clone --branch develop git@github.com:UN-OCHA/rwint9-site.git`
- `cd rwint9-site`
- `mkdir -p private_files`
- `fin start`
- `fin composer install`
- `cp .docksal/settings.local.php html/sites/default/`
- edit html/sites/default/settings.php and include snippet
- `fin drush si --existing-config`

```php
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
  include $app_root . '/' . $site_path . '/settings.local.php';
}
```

## Local development

For local development, add this line to settings.local.php:
`$config['config_split.config_split.config_dev']['status'] = TRUE;`
After importing a fresh database, run `drush csim` to enable devel, database
log and stage_file_proxy.

## Testing [![Coverage Status](https://coveralls.io/repos/github/UN-OCHA/rwint9-site/badge.svg)](https://coveralls.io/github/UN-OCHA/rwint9-site)

```bash
# with coverage
XDEBUG_MODE=coverage ./vendor/bin/phpunit --testsuite Unit
XDEBUG_MODE=coverage ./vendor/bin/phpunit --testsuite Existing

# without coverage
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Existing
```

or run all test in custom

```bash
# with coverage
XDEBUG_MODE=coverage vendor/bin/phpunit

# without coverage
vendor/bin/phpunit
```
