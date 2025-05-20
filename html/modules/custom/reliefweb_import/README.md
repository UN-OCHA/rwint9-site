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

## Inoreader importer

Imports tagged items from the [automation_production](https://www.inoreader.com/folder/automation_production)

### Supported tags

| tag | mandatory | multiple | example |
| - | - | - | - |
| source | Yes | No | [source:1242] |
| pdf | Yes | No | [pdf:canonical] |
| content | No | No | [content:clear] |
| title | No | No | [title:filename] |
| follow | No | No | [follow:https://wedocs.unep.org] |
| wrapper | No | Yes | [wrapper:div.content_sidebar] |
| url | No | Yes | [url:/docs/] |
| puppeteer | No | No | [puppeteer:ds-file-download-link a] |
| puppeteer-attrib | No | No | [puppeteer-attrib:href] |

#### `source` tag

This is mandatory and has to be numeric.

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

#### `puppeteer-attribute` tag

Defines the attribute to extract from the element.

## UNHCR importer

Imports reports from [their API](https://data.unhcr.org)

## WFP Logistics Cluster importer

Imports reports from [their API](https://api.logcluster.org/1.0.0/en/documents)
