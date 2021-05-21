<?php

namespace Drupal\reliefweb_rivers\Services;

/**
 * Interface for the river services.
 */
interface RiverInterface {

  /**
   * Parse the data from the ReliefWeb API to use in rivers.
   *
   * @param array $data
   *   Data returned by the ReliefWeb API.
   *
   * @return array
   *   Parsed data, ready to use in river templates.
   */
  public function parseData(array $data);

}
