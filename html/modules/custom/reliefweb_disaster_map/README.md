ReliefWeb - Disaster Map module
===============================

This module provides the disaster maps used on the `/disasters` page and some topic pages.

## Mapbox

The disaster map is rendered via [mapbox](https://mapbox.com).

## Service

This modules provides the [reliefweb_disaster_map.service](src/DisasterMapService.php) service that can be used to generate the data to render a disaster map.

## Disaster map tokens

This module provides a set of tokens that can be used in some text field that have use a text format that allows token replacements. Those tokens are converted to the corresponding disaster map.

They are in the form `disaster-map:XX` where `XX` is a disaster type code or a disaster ID.

Several disaster types/IDs can be selected by separating them with a dash `-`.

## Embeddable disaster map

This module provides 2 routes that can be used to embed disaster maps in an iframe:

- `/disaster-map` returns the "Alert and Ongoing Disasters" map
- `/disaster-map/XX` returns a map similar to the tokens mentioned above where `XX` is a disaster type code or a disaster ID.

This is managed by the [DisasterMap](src/Controller/DisasterMap.php) controller.

## Tile caching

Requests to get the map tiles are proxied and cached via [nginx rules](../../../../docker/etc/nginx/custom/03_mapbox.conf).
