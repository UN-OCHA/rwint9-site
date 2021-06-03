<?php

namespace Drupal\reliefweb_entities;

/**
 * Interface for entities with sectioned content.
 */
interface SectionedContentInterface {

  /**
   * Get page sections.
   *
   * @return array
   *   List of sections as render arrays.
   */
  public function getPageSections();

  /**
   * Get page table of content.
   *
   * @return array
   *   Render array of the table of content.
   */
  public function getPageTableOfContents();

  /**
   * Get the base ReliefWeb API query payload for the resource.
   *
   * @param string $resource
   *   ReliefWeb API resource.
   *
   * @return array
   *   API payload.
   */
  public function getReliefWebApiPayload($resource);

  /**
   * Get the section data for the given ReliefWeb API queries.
   *
   * @param array $queries
   *   ReliefWeb API queries.
   *
   * @return array
   *   Associative array of render arrays for the sections matching the given
   *   queries keyed by section id.
   */
  public function getSectionsFromReliefWebApiQueries(array $queries);

  /**
   * Parse the data returned by the ReliefWeb API.
   *
   * @param string $bundle
   *   The entity bundle for the data.
   * @param array $data
   *   The ReliefWeb API data.
   * @param string $view
   *   Current river view.
   *
   * @return array
   *   List of articles to display.
   *
   * @see \Drupal\reliefweb_rivers\Services\RiverInterface.php
   */
  public function parseReliefWebApiData($bundle, array $data, $view = '');

  /**
   * Consolidate content sections.
   *
   * Remove empty sections from both the table of contents and the list of
   * sections and update the section labels as well.
   *
   * @param array $contents
   *   Table of contents.
   * @param array $sections
   *   Content sections.
   * @param array $labels
   *   Labels for the sections.
   *
   * @return array
   *   Render array with the table of contents and sections.
   */
  public function consolidateSections(array $contents, array $sections, array $labels);

  /**
   * Get payload for the most read documents.
   *
   * We cache the data for 3 hours as the query to get the most read reports
   * is quite expensive.
   *
   * @param string $code
   *   Filter code for the river link (ex: PC if the entity is a country, or
   *   D if it's a disaster).
   * @param int $limit
   *   Number of resource items to return.
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   */
  public function getMostReadApiQuery($code = 'PC', $limit = 5);

  /**
   * Get payload for latest updates.
   *
   * @param string $code
   *   Filter code for the river link (ex: PC if the entity is a country, or
   *   D if it's a disaster).
   * @param int $limit
   *   Number of resource items to return.
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   *
   * @todo move that to a trait or an ancestor class?
   */
  public function getLatestUpdatesApiQuery($code = 'PC', $limit = 3);

  /**
   * Get payload for maps and infographics.
   *
   * @param string $code
   *   Filter code for the river link (ex: PC if the entity is a country, or
   *   D if it's a disaster).
   * @param int $limit
   *   Number of resource items to return.
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   */
  public function getLatestMapsInfographicsApiQuery($code = 'PC', $limit = 3);

  /**
   * Get payload for latest jobs.
   *
   * @param string $code
   *   Filter code for the river link (ex: C if the entity is a country, or
   *   S if it's a source).
   * @param int $limit
   *   Number of resource items to return.
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   */
  public function getLatestJobsApiQuery($code = 'C', $limit = 3);

  /**
   * Get payload for latest training.
   *
   * @param string $code
   *   Filter code for the river link (ex: PC if the entity is a country, or
   *   S if it's a source).
   * @param int $limit
   *   Number of resource items to return.
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   */
  public function getLatestTrainingApiQuery($code = 'C', $limit = 3);

  /**
   * Get payload for latest alert and ongoing disasters.
   *
   * @param string $code
   *   Filter code for the river link (ex: PC if the entity is a country, or
   *   S if it's a source).
   * @param int $limit
   *   Number of resource items to return.
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   */
  public function getLatestDisastersApiQuery($code = 'C', $limit = 100);

}
