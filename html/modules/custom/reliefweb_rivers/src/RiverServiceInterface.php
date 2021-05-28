<?php

namespace Drupal\reliefweb_rivers;

/**
 * Interface for the river services.
 */
interface RiverServiceInterface {

  /**
   * Parse the data from the ReliefWeb API to use in rivers.
   *
   * @param array $data
   *   Data returned by the ReliefWeb API.
   * @param string $view
   *   Current river view.
   *
   * @return array
   *   Parsed data, ready to use in river templates.
   */
  public function parseApiData(array $data, $view = '');

  /**
   * Get the ISO 639-1 language code for the entity.
   *
   * Defaults to English if not defined.
   *
   * @param array $data
   *   Entity data.
   *
   * @return string
   *   ISO 639-1 language code.
   */
  public static function getLanguageCode(array &$data = NULL);

  /**
   * Convert a ISO 6901 date to a \DateTime object.
   *
   * @param string $date
   *   ISO 6901 date.
   *
   * @return \DateTime
   *   Date object.
   */
  public static function createDate($date);

}
