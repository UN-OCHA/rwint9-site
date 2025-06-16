Reliefweb - Import module
=========================

## Drush

This module provides [drush commands](src/Drush/Commands/ReliefWebImport.php) to allow importing content from API and feeds.

## Job feeds importer

The [JobFeedsImporter](src/Service/JobFeedsImporter.php) handles importing jobs from feeds.

See "Specifications for exporting jobs feeds into ReliefWeb" document for specifications.

The feeds information are set via a `ReliefWebImportInfo` field on the source entities.

Imported jobs are checked and sanitized before being created.

### Import jobs

```bash
drush reliefweb_import:jobs --verbose
```

## ECHO Flash updates

Imports ECHO Flash updates from [their API](https://erccportal.jrc.ec.europa.eu/API/ERCC/EchoFlash/GetPagedItems)

## ECHO Maps

Imports ECHO Maps from [their API](https://erccportal.jrc.ec.europa.eu/API/ERCC/Maps/GetPagedItems)

## Inoreader importer

Imports tagged items from the [automation_production](https://www.inoreader.com/folder/automation_production)

### Supported tags

| tag | alias | mandatory | multiple | example |
| - | - | - | - | - |
| source | - | Yes | No | [source:1242] |
| pdf | - | Yes | No | [pdf:canonical] |
| content | - | No | No | [content:clear] |
| title | - | No | No | [title:filename] |
| follow | - | No | No | [follow:https://wedocs.unep.org] |
| wrapper | w | No | Yes | [wrapper:div.content_sidebar] |
| url | u | No | Yes | [url:/docs/] |
| puppeteer | p | No | Yes | [puppeteer:ds-file-download-link a] |
| ~~puppeteer2~~ | p2 | No | No | [puppeteer:ds-file-download-link a] |
| puppeteer-attrib | pa | No | No | [puppeteer-attrib:href] |
| puppeteer-blob | pb | No | No | [puppeteer-attrib:href] |
| timeout | t | No | No | [timeout:30] |
| delay | d | No | No | [delay:5000]

#### `source` tag

This is mandatory, since multiple feeds can map to the same source id, but might need different settings,
it's allowed to add an extra identifier like `[source:1242-food]`, this identifier can be used to overwrite
tags defined at `/admin/config/reliefweb/content-importers/inoreader_extra_tags` like

```yaml
1242-food:
  wrapper:
    - div.dynamic-content__figure-container
```

#### `pdf` tag

This is mandatory and points to the location of the PDF file. For the moment only 1 file is supported.

| value | explanation |
| - | - |
| canonical | Inoreader item links directly to the PDF file |
| summary-link | There's a link in the summary in Inoreader to the PDF file |
| page-link | The importer will fetch the source page and will search for a link to the PDF file |
| page-object | The importer will fetch the source page and will search for an object tag with the PDF file |
| page-iframe-src | The importer will fetch the source page and will search for an iframe with an `src` attribute pointing to the PDF file |
| page-iframe-data-src | The importer will fetch the source page and will search for an iframe with an `data-src` attribute pointing to the PDF file |
| js | Uses puppeteer to render and analyze the page |
| content | Uses summary of inoreader as body |

#### `content` tag

| value | explanation |
| - | - |
| clear | Do not use the Inoreader summary as body |
| ignore | Do not use the Inoreader summary as body |

#### `title` tag

| value | explanation |
| - | - |
| filename | Use the filename as title for the report |
| canonical | Use the filename as title for the report |

#### `follow` tag

If set the HTML page will be fetched and will search for a link matching what is specified in the tag, if found that page will be loaded and used to find a PDF file.

#### `wrapper` tag

This will restrict searching in an HTML file to a certain region on the page.

#### `url` tag

This will filter possible links to PDF files to a certain pattern.

#### `puppeteer` tag

Used to select the html element containg the PDF link.

If you need to click on 2 elements, you can use a pipe (`|`) to separate the selectors.

#### `puppeteer-attribute` tag

Defines the attribute to extract from the element.

#### `puppeteer-blob` tag

Returns file data as part of json response.

#### `timeout` tag

Defines a custom timeout for fetching external data.

#### `delay` tag

Defines a custom delay (ms) for fetching external data.

### Override tags in the UI

You can go to and `/admin/config/reliefweb/content-importers/inoreader_extra_tags` add extra tags.

```yaml
2836:
  replace:
    - 'openknowledge.fao.org/bitstreams/:openknowledge.fao.org/server/api/core/bitstreams/'
    - '/download:/content'
  content: ignore
1980:
  wrapper:
    - div.dynamic-content__figure-container
```

### Fix sources

```shell
drush sqlq "UPDATE reliefweb_import_records SET source = SUBSTR(SUBSTRING_INDEX(json_extract(extra, \"$.inoreader.feed_name\"), \"[source:\", 1), 2) where extra is not null"
```

## UNHCR importer

Imports reports from [their API](https://data.unhcr.org)

## WFP Logistics Cluster importer

Imports reports from [their API](https://api.logcluster.org/1.0.0/en/documents)
