ReliefWeb - Docker image
========================

The [docker](../docker) folder contains the Dockerfile and other necessary files to build the image of the ReliefWeb site.

## Nginx customizations

The image contains several additional nginx configuration files and lua scripts.

### Drupal

The [drupal.conf](/etc/nginx/apps/drupal/drupal.conf) contains some customizations, notably rules to serves the XML sitemap without hitting Drupal.

### File handling

ReliefWeb uses the `attachments/uuid/filename.ext` pattern for its file URLs. Those URLs are converted to internal paths by nginx which looks up for the corresponding `uuid.ext` file on disk or passes the request to Drupal.

This is handled by the [01_attachment_redirections.conf](etc/nginx/custom/01_attachment_redirections.conf) configuration file.

### Legacy file redirections

Legacy file and image redirections are handled by the [01_legacy_file_redirections.cong](etc/nginx/custom/01_legacy_file_redirections.conf) and associated [lua](etc/nginx/custom/lua) scripts.

Those notably converts URLs in the form `/sites/default/files/xxx/yyy.ext` to the new `attachments/uuid/filename.ext` pattern for attachments.

The lua `UUID` extension, used for the legacy file redirections is loaded via the [01_uuid.conf](etc/nginx/sites-enabled/01_uuid.conf).

### Legacy rewrites

The [02_legacy_rewrites.conf](etc/nginx/custom/02_legacy_rewrites.conf) handles rewrites for common paths from the previous versions of the sites.

### Disaster map

The [03_disaster_map.conf](etc/nginx/custom/03_disaster_map.conf) handles the route to serve the embeddable disaster maps, ensuring they are always served anonymously.

### Mapbox

The [03_mapbox.conf](etc/nginx/custom/03_mapbox.conf) and [02_mapbox_proxy_cache.conf](etc/nginx/sites-enabled/02_mapbox_proxy_cache.conf) are used to proxy and cache queries to mapbox tiles.

