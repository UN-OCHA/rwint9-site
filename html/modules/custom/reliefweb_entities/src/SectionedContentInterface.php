<?php

namespace Drupal\reliefweb_entities;

/**
 * Interface for entities with sectioned content.
 */
interface SectionedContentInterface {

  /**
   * Get the content of the full entity page for the bundle.
   *
   * Note: this may differs from one bundle to another.
   *
   * @return array
   *   Get the page content.
   *
   * @todo we probably need intermediary classes/interfaces.
   */
  public function getPageContent();

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
   * Get payload for the key content reports.
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
  public function getKeyContentApiQuery($code = 'PC', $limit = 3);

  /**
   * Get payload for the appeals and response plans.
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
  public function getAppealsResponsePlansApiQuery($code = 'PC', $limit = 3);

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

  /**
   * Get the section with the useful links for the entity (country/disaster).
   *
   * @preturn array
   *   Render array for the useful links section.
   */
  public function getUsefulLinksSection();

  /**
   * Get the country/disaster profile.
   *
   * This includes the Key Content, Appeals and Response Plans and useful links.
   *
   * @see \Drupal\reliefweb_entities::getProfileFields()
   *
   * @todo replace API query with logic using the actual profile fields on the
   * entity once ported.
   */
  public function getProfileFields();

}
