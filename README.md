# ReliefWeb - Drupal 11 version

This is the drupal 11 codebase for the [ReliefWeb](https://reliefweb.int) site.

> ReliefWeb is the largest humanitarian information portal in the world. Founded
> in 1996, the portal now hosts more than 1,000,000 humanitarian situation reports,
> press releases, evaluations, guidelines, assessments, maps and infographics.

## Content

The ReliefWeb site offers several type of content.

- `Reports`: humanitarian situation reports,
press releases, evaluations, guidelines, assessments, maps, infographics etc.
- `Jobs`: job opportunities in humanitarian fields
- `Training`: training opportunities in humanitarian fields
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

`ReliefWeb` uses the [Common Design](https://github.com/UN-OCHA/common_design) theme and extends its subtheme.

The [html/themes/custom/common_design_subtheme](html/themes/custom/common_design_subtheme) folder contains various custom components and templates.

### Docker image

The [docker](docker) folder contains the docker file and customizations to build the ReliefWeb site image.

## Local development

For local development, see [local stack](local/README.md).

## Retagging

Sometimes, content on ReliefWeb needs to be retagged. For example when organizations are consolidated.

Most of the time, this can be achieved via a simple script. See [retagging](scripts/retagging/README.md) for more information.

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

## Testing [Coverage Status](https://coveralls.io/github/UN-OCHA/rwint9-site)

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

## Drush commands (custom modules)

Custom Drush commands from [html/modules/custom](html/modules/custom), with brief descriptions and example usage. Each command links to the file where it is defined.

### reliefweb_analytics


| Command                             | Description                                                  | Example                                   |
| ----------------------------------- | ------------------------------------------------------------ | ----------------------------------------- |
| `reliefweb_analytics:homepage`      | Retrieve most-read reports for the homepage.                 | `drush reliefweb_analytics:homepage`      |
| `reliefweb_analytics:countries`     | Retrieve most-read reports per country (paginated).          | `drush reliefweb_analytics:countries`     |
| `reliefweb_analytics:countries-all` | Retrieve most-read reports for all countries in one request. | `drush reliefweb_analytics:countries-all` |
| `reliefweb_analytics:disasters`     | Retrieve most-read reports per disaster (paginated).         | `drush reliefweb_analytics:disasters`     |
| `reliefweb_analytics:disasters-all` | Retrieve most-read reports for all disasters in one request. | `drush reliefweb_analytics:disasters-all` |


Defined in: [reliefweb_analytics/src/Command/ReliefwebMostReadCommand.php](html/modules/custom/reliefweb_analytics/src/Command/ReliefwebMostReadCommand.php)

### reliefweb_api


| Command                      | Description                                         | Example                                                                                                          |
| ---------------------------- | --------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `reliefweb-api:index`        | Index content in the ReliefWeb API (Elasticsearch). | `drush reliefweb-api:index report`, `drush reliefweb-api:index --id=123 report`, `drush rapi-i report --verbose` |
| `reliefweb-api:replace`      | Replace an old index with a new one (swap alias).   | `drush reliefweb-api:replace report 20141015 20140802`                                                           |
| `reliefweb-api:reindexqueue` | Re-index queued terms.                              | `drush reliefweb-api:reindexqueue`, `drush reliefweb-api:reindexqueue --display`                                 |


Defined in: [reliefweb_api/src/Commands/ReliefWebApiCommands.php](html/modules/custom/reliefweb_api/src/Commands/ReliefWebApiCommands.php)

### reliefweb_files (rw-files)


| Command                                 | Description                                             | Example                                                                                                 |
| --------------------------------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `rw-files:generate-redirection-symlink` | Create symlink for legacy file URL → new UUID.          | `drush rw-files:generate-redirection-symlink "https://reliefweb.int/.../resources/file.pdf" UUID`       |
| `rw-files:remove-redirection-symlink`   | Remove legacy URL symlink.                              | `drush rw-files:remove-redirection-symlink URL`                                                         |
| `rw-files:index-file-fingerprints`      | Index file fingerprints for similarity search.          | `drush rw-files:index-file-fingerprints --limit=100`, `drush rw-files:index-file-fingerprints --id=123` |
| `rw-files:find-similar-files`           | Find reports with similar file content to a given node. | `drush rw-files:find-similar-files 12345`                                                               |
| `rw-files:download-missing-files`       | Download missing files from a source (e.g. production). | `drush rw-files:download-missing-files`, `drush rw-files:download-missing-files --limit=100 --dry-run`  |


Defined in: [reliefweb_files/src/Commands/ReliefWebFilesCommands.php](html/modules/custom/reliefweb_files/src/Commands/ReliefWebFilesCommands.php)

### reliefweb_import


| Command                    | Description                                        | Example                                        |
| -------------------------- | -------------------------------------------------- | ---------------------------------------------- |
| `reliefweb_import:jobs`    | Import jobs from feeds.                            | `drush reliefweb_import:jobs`                  |
| `reliefweb_import:workday` | Import jobs from WorkDay (all configured tenants). | `drush reliefweb_import:workday`               |
| `reliefweb_import:content` | Import content using a given importer plugin.      | `drush reliefweb_import:content unhcr_data 10` |


**Available content importers** (for `reliefweb_import:content <plugin_id> [limit]`):


| Plugin ID                                                                                                            | Description                                                         |
| -------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| `[echo_flash_update](html/modules/custom/reliefweb_import/src/Plugin/ReliefWebImporter/EchoFlashUpdateImporter.php)` | ECHO Flash Update importer — reports from the ECHO Flash Update API |
| `[echo_map](html/modules/custom/reliefweb_import/src/Plugin/ReliefWebImporter/EchoMapImporter.php)`                  | Echo Map importer — reports from the Echo Map API                   |
| `[inoreader](html/modules/custom/reliefweb_import/src/Plugin/ReliefWebImporter/InoreaderImporter.php)`               | Inoreader importer — reports from Inoreader                         |
| `[unep](html/modules/custom/reliefweb_import/src/Plugin/ReliefWebImporter/UnepImporter.php)`                         | UNEP importer — reports from the UNEP API                           |
| `[unhcr_data](html/modules/custom/reliefweb_import/src/Plugin/ReliefWebImporter/UnhcrDataImporter.php)`              | UNHCR Data importer — reports from the UNHCR Data API               |
| `[wfp_logcluster](html/modules/custom/reliefweb_import/src/Plugin/ReliefWebImporter/WfpLogClusterImporter.php)`      | WFP Logcluster importer — reports from the WFP Logcluster API       |
| `[worldbank](html/modules/custom/reliefweb_import/src/Plugin/ReliefWebImporter/WorldbankImporter.php)`               | Worldbank importer — reports from the Worldbank API                 |


Defined in: [reliefweb_import/src/Drush/Commands/ReliefwebImport.php](html/modules/custom/reliefweb_import/src/Drush/Commands/ReliefwebImport.php)

### reliefweb_moderation


| Command                                        | Description                                                                     | Example                                                                                                              |
| ---------------------------------------------- | ------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `reliefweb_moderation:update-inactive-sources` | Mark inactive sources (no recent reports/jobs/training) as inactive or archive. | `drush reliefweb_moderation:update-inactive-sources`, `drush reliefweb_moderation:update-inactive-sources --dry-run` |


Defined in: [reliefweb_moderation/src/Commands/ReliefWebModerationCommands.php](html/modules/custom/reliefweb_moderation/src/Commands/ReliefWebModerationCommands.php)

### reliefweb_post_api


| Command                      | Description                                        | Example                                                                                                         |
| ---------------------------- | -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `reliefweb-post-api:process` | Process queued content submitted via the Post API. | `drush reliefweb-post-api:process --limit=5`, `drush reliefweb-post-api:process --limit=5 --bundles=report,job` |


Defined in: [reliefweb_post_api/src/Commands/ReliefWebPostApiCommands.php](html/modules/custom/reliefweb_post_api/src/Commands/ReliefWebPostApiCommands.php)

### reliefweb_reporting


| Command                                                | Description                                                                            | Example                                                                                                             |
| ------------------------------------------------------ | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `reliefweb_reporting:send-weekly-job-stats`            | Email weekly job posting stats to recipients.                                          | `drush reliefweb_reporting:send-weekly-job-stats "admin@example.com"`                                               |
| `reliefweb_reporting:send-weekly-report-stats`         | Email weekly report posting stats (CSV) to recipients.                                 | `drush reliefweb_reporting:send-weekly-report-stats "admin@example.com"`                                            |
| `reliefweb_reporting:send-weekly-ai-tagging-stats`     | Email weekly AI tagging stats to recipients.                                           | `drush reliefweb_reporting:send-weekly-ai-tagging-stats "admin@example.com"`                                        |
| `reliefweb_reporting:export-report-data`               | Export report data to TSV (optional GDrive upload). Alias: `rw-export-reports`.        | `drush reliefweb_reporting:export-report-data "2021-01-01" "now" --output=/tmp/reports.tsv`                         |
| `reliefweb_reporting:export-manually-retagged-content` | Export manually retagged content to TSV. Alias: `rw-export-manually-retagged-content`. | `drush reliefweb_reporting:export-manually-retagged-content node job "2021-01-01" "now" --output=/tmp/retagged.tsv` |


Defined in: [reliefweb_reporting/src/Commands/ReliefWebReportingCommands.php](html/modules/custom/reliefweb_reporting/src/Commands/ReliefWebReportingCommands.php)

### reliefweb_subscriptions


| Command                                         | Description                                      | Example                                                                                                  |
| ----------------------------------------------- | ------------------------------------------------ | -------------------------------------------------------------------------------------------------------- |
| `reliefweb_subscriptions:send`                  | Send queued subscription emails.                 | `drush reliefweb_subscriptions:send`                                                                     |
| `reliefweb_subscriptions:queue`                 | Queue a subscription notification.               | `drush reliefweb_subscriptions:queue SID --entity_type=node --entity_id=123`                             |
| `reliefweb_subscriptions:unsubscribe`           | Unsubscribe bounced/complaint emails (from ELK). | `drush reliefweb_subscriptions:unsubscribe 1w`, `drush reliefweb_subscriptions:unsubscribe 1w --dry-run` |
| `reliefweb_subscriptions:subscribe-users`       | Subscribe users from a file of emails to lists.  | `drush reliefweb_subscriptions:subscribe-users headlines,appeals /tmp/emails.txt`                        |
| `reliefweb_subscriptions:enable-link-tracking`  | Enable link tracking for subscription(s).        | `drush reliefweb_subscriptions:enable-link-tracking headlines,appeals`                                   |
| `reliefweb_subscriptions:disable-link-tracking` | Disable link tracking for subscription(s).       | `drush reliefweb_subscriptions:disable-link-tracking headlines,appeals`                                  |


Defined in: [reliefweb_subscriptions/src/Command/ReliefwebSubscriptionsSendCommand.php](html/modules/custom/reliefweb_subscriptions/src/Command/ReliefwebSubscriptionsSendCommand.php)

### reliefweb_xmlsitemap


| Command                                    | Description                                        | Example                                          |
| ------------------------------------------ | -------------------------------------------------- | ------------------------------------------------ |
| `reliefweb_xmlsitemap:generate`            | Generate the ReliefWeb XML sitemap.                | `drush reliefweb_xmlsitemap:generate`            |
| `reliefweb_xmlsitemap:prepare-directory`   | Create/prepare the sitemap directory.              | `drush reliefweb_xmlsitemap:prepare-directory`   |
| `reliefweb_xmlsitemap:clear-directory`     | Clear sitemap files (optionally delete directory). | `drush reliefweb_xmlsitemap:clear-directory`     |
| `reliefweb_xmlsitemap:copy-xsl-stylesheet` | Copy XSL stylesheet into sitemap directory.        | `drush reliefweb_xmlsitemap:copy-xsl-stylesheet` |
| `reliefweb_xmlsitemap:submit`              | Submit sitemap to search engines.                  | `drush reliefweb_xmlsitemap:submit`              |


Defined in: [reliefweb_xmlsitemap/src/Command/ReliefwebXmlsitemapCommand.php](html/modules/custom/reliefweb_xmlsitemap/src/Command/ReliefwebXmlsitemapCommand.php)

---

## For dev

```bash
drush rapi-i --alias job --verbose
drush rw-job:index --verbose

cget ocha_ai.settings --include-overridden
cget reliefweb_api.settings --include-overridden

cset ocha_ai.settings plugins.source.reliefweb.api_url https://rwint-api-local.test/v2
cset ocha_ai.settings plugins.source.reliefweb.converter_url https://rwint-local.test/search/converter/json
cset reliefweb_api.settings api_url https://rwint-api-local.test/v2
cset reliefweb_api.settings api_url_external https://rwint-api-local.test/v2
cset reliefweb_api.settings website: https:/rwint-local.test

queue:list
queue:run --verbose reliefweb_job_tagger

```

