ReliefWeb - Drupal 9 version
============================

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
