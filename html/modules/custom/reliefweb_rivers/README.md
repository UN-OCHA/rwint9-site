ReliefWeb - Rivers module
=========================

This module provides handling of list of content (rivers).

## Services

This module provide [services](src/Services) to get lists of content (rivers):

- Reports
- Jobs
- Training
- Countries
- Disasters
- Sources
- Topics

Those services act both as a controller for the river pages and as a way to get and parse API data to use for river blocks on other pages for example (ex: latest updates on country pages).

### Search/filtering

This module also provides classes to parse search and filter parameters (`search` and `advanced-search`) and convert them into filters used in the ReliefWeb API queries that are used to populate those rivers.

- [Parameters](src/Parameters.php): handle query parameters and notably the conversion of the legacy river parameters
- [AdvancedSearch](src/AdvancedSearch.php): handle the parsing of the `advanced-search` parameter that contains filters to apply to the API query for the river

## Templates

This module provides [templates](templates) for the different components of the rivers.

## Search converter

This module provides a route and [controller](src/Controller/SearchConverter.php) for the search converter page (`/search-converter`) used to convert a ReliefWeb river URL into a ReliefWeb API payload.

This is notably useful for sites that want to display list of content from ReliefWeb as they can store a filtered river URL and retrieve the corresponding API payload.

## Search results

This module provide a route and [controller](src/Controller/SearchResults.php) for the global search result page (`/search/results`).
