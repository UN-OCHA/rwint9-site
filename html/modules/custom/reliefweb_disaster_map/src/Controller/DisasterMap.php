<?php

namespace Drupal\reliefweb_disaster_map\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\reliefweb_disaster_map\DisasterMapService;

/**
 * Controller for the disaster-map route.
 */
class DisasterMap extends ControllerBase {

  /**
   * Get the embeddable map render array.
   *
   * Additional parameters can be passed as query parameters:
   * - title: custom title (other wise it will default to the title generated
   *   from the given type(s).
   * - link: if set, a link to the /disasters page will be provided.
   * - ratio: the width/height ratio.
   *
   * @param string $type
   *   Type of disaser map to return. This can be a disaster type code (ex: FL
   *   for floods) or a disaster ID or a list of those with each type/id
   *   separated by a `-`. If empty the map of the alert and ongoing disasters
   *   will be shown instead.
   *
   * @return array
   *   Render array for the disaster map.
   *
   * @todo maybe reduce the number of styles, scripts etc. and allow additional
   * query parameters like:
   * - title: custom title (other wise it will default to the title generated
   *   from the given type(s))
   * - link: to add a link to the /disasters page
   * - ratio: the width/height ratio
   * - since: to get a different date range for the disasters instead of a year.
   */
  public function getEmbeddableMap($type = '') {
    if (!empty($type)) {
      return reliefweb_disaster_map_get_disaster_map_token_replacement($type, NULL, FALSE);
    }
    else {
      return DisasterMapService::getAlertAndOngoingDisasterMap();
    }
  }

}
