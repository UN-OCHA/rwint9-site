# ReliefWeb - Drupal 9 version

This is the drupal 9 codebase for the [ReliefWeb](https://reliefweb.int) site.

> ReliefWeb is the largest humanitarian information portal in the world. Founded
in 1996, the portal now hosts more than 850,000 humanitarian situation reports,
press releases, evaluations, guidelines, assessments, maps and infographics.

## Content

The ReliefWeb site offers several type of content.

- `Reports`: humanitarian situation reports,
press releases, evaluations, guidelines, assessments, maps, infographics etc.
- `Jobs`: job opportunities in humanitarin fields
- `Training`: training opportunities in humanitarin fields
- `Countries`: humanitarian information on countries
- `Disasters`: humanitarian information on natural disasters (floods, epidemics etc.)
- `Sources`: organizations (NGOs, governments etc.) that provide humanitarian information or job/training opportunies
- `Topics`: curated pages dedicated to humanitarian themes and specific humanitarian crises
- `Blog`: ReliefWeb blog

## Codebase

To ReliefWeb site's codebase is highly customized to facilitate the work of the editorial team and display the various types of content.

### Custom modules

The [html/modules/custom](html/modules/custom) folder contains custom modules. Some of the most important ones are:

- [reliefweb_api](html/modules/custom/reliefweb_api) provides integration with the ReliefWeb API to power the lists of content
- [reliefweb_entities](html/modules/custom/reliefweb_entities) provides customization of the content entities (forms, display etc.)
- [reliefweb_fields](html/modules/custom/reliefweb_fields) provides customization of various fields
- [reliefweb_files](html/modules/custom/reliefweb_files) provides handling of the report attachments
- [reliefweb_form](html/modules/custom/reliefweb_form) provides form customizations
- [reliefweb_moderation](html/modules/custom/reliefweb_moderation) provides everything related to the moderation (editorial) workflow
- [reliefweb_revisions](html/modules/custom/reliefweb_revisions) handles the display of an entity history for the editorial workflow
- [reliefweb_rivers](html/modules/custom/reliefweb_rivers) handles the display of lists of content (ex: /updates)
- [reliefweb_utility](html/modules/custom/reliefweb_utility) provides various helpers, filters, twig extensions used by the other modules

### Theme

The `ReliefWeb` uses the [Common Design](https://github.com/UN-OCHA/common_design) theme and extends its subtheme.

The [html/themes/custom/common_design_subtheme](html/themes/custom/common_design_subtheme) folder contains various custom components and templates.

### Docker image

The [docker](docker) folder contains the docker file and customizations to build the ReliefWeb site image.

## Local development

For local development, see the stack repository.

Add this line to settings.local.php: `$config['config_split.config_split.config_dev']['status'] = TRUE;` to enable `config_split` and install the development modules.

After importing a fresh database, run `drush cim` to enable devel, database log and stage_file_proxy.

### Docksal

- `git clone --branch develop git@github.com:UN-OCHA/rwint9-site.git`
- `cd rwint9-site`
- `mkdir -p private_files`
- `fin start`
- `fin composer install`
- `cp .docksal/settings.local.php html/sites/default/`
- edit html/sites/default/settings.php and include snippet below
- `fin drush si --existing-config`

```php
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
  include $app_root . '/' . $site_path . '/settings.local.php';
}
```

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
