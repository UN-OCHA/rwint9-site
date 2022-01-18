#!/bin/sh -e

docker exec -it -u appuser rwint9-site drush -v -d mim --group=reliefweb_base
docker exec -it -u appuser rwint9-site drush -v -d mim --group=reliefweb_image
docker exec -it -u appuser rwint9-site drush -v -d mim --group=reliefweb_media
docker exec -it -u appuser rwint9-site drush -v -d mim --group=reliefweb_term

docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node__announcement
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node__blog_post
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node__book
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node__topic
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node__training
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node__job
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node__report

docker exec -it -u appuser rwint9-site drush -v -d mim --group=reliefweb_extra

docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node_revision__announcement
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node_revision__blog_post
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node_revision__book
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node_revision__topic
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node_revision__training
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node_revision__job
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_node_revision__report

docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_taxonomy_term_revision
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_taxonomy_term_revision__country
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_taxonomy_term_revision__disaster
docker exec -it -u appuser rwint9-site drush -v -d mim reliefweb_taxonomy_term_revision__source
