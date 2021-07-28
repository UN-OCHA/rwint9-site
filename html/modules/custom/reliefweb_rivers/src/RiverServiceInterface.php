<?php

namespace Drupal\reliefweb_rivers;

/**
 * Interface for the river services.
 */
interface RiverServiceInterface {

  /**
   * Get the Api resource for the river.
   *
   * @return string
   *   Page title.
   */
  public function getResource();

  /**
   * Get the page title for the river.
   *
   * @return string
   *   Page title.
   */
  public function getPageTitle();

  /**
   * Get the page content for the river.
   *
   * @return array
   *   Render array.
   */
  public function getPageContent();

  /**
   * Get the river URL.
   *
   * @return string[
   *   The river URL.
   */
  public function getUrl();

  /**
   * Get the river parameter handler.
   *
   * @return \Drupal\reliefweb_rivers\Parameters
   *   River parameter handler.
   */
  public function getParameters();

  /**
   * Get the advanced search handler.
   *
   * @return \Drupal\reliefweb_rivers\AvancedSearch
   *   Sanitized advanced search parmeter.
   */
  public function getAdvancedSearch();

  /**
   * Get the list of views for the river.
   *
   * @return array
   *   List of views for the river.
   */
  public function getViews();

  /**
   * Get the default view for the river.
   *
   * @return string
   *   Default view id.
   */
  public function getDefaultView();

  /**
   * Get the currently selected view for the river.
   *
   * @return string
   *   Selected view (or default).
   */
  public function getSelectedView();

  /**
   * Get the sanitized search parameter.
   *
   * @return string
   *   Sanitized search parmeter.
   */
  public function getSearch();

  /**
   * Get the list of filters for the advaneced search.
   *
   * @return array
   *   List of filters keyed by code, with the following properties:
   *   - name: filter name
   *   - type: filter type
   *   - vocabulary: (optional) for reference type filters
   *   - field: api field for the filter
   *   - widget: filter widget info:
   *     - type: widget type (ex: autocomplete)
   *     - label: label for the widget input
   *     - resource: (optional) API resource for autocomplete widgets
   *     - parameters: (options) extra parameters for the autocomplet API query
   *   - operator: the default operator for the filter (OR or AND)
   *   - exclude: list of value to exclude from the list of filter values
   */
  public function getFilters();

  /**
   * Get the string used as sample of the available filters for the river.
   *
   * @return string
   *   Filter sample.
   */
  public function getFilterSample();

  /**
   * Get the river views build.
   *
   * @return array
   *   Render array with the river views. Each view has an id, title, url and
   *   selection flag.
   */
  public function getRiverViews();

  /**
   * Get the search render array.
   *
   * @return array
   *   Render array with the search information (path, parameters, label and
   *   query).
   */
  public function getRiverSearch();

  /**
   * Get the advanced search render array.
   *
   * @return array
   *   Render array with the advanced search information.
   */
  public function getRiverAdvancedSearch();

  /**
   * Get the river results render array.
   *
   * @param int $count
   *   Number of results for the current page.
   *
   * @return array
   *   Render array with the results including the total, and result range.
   */
  public function getRiverResults($count);

  /**
   * Get the pager for the river.
   *
   * @return array
   *   Pager render array.
   */
  public function getRiverPager();

  /**
   * Get the API/RSS links for the river.
   *
   * @return array
   *   List of links.
   */
  public function getRiverLinks();

  /**
   * Get the ReliefWeb API payload for the given river and view.
   *
   * @return array
   *   API payload.
   */
  public function getApiPayload($view = '');

  /**
   * Get the data from the ReliefWeb API for the given payload.
   *
   * @param int $limit
   *   Number of resources to return.
   *
   * @return array
   *   List of resource data as returned by ::parseApiData().
   */
  public function getApiData($limit = 20);

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
   * Perform a request against the API for the river's resource.
   *
   * Note: this function is simply to ease the modification of the payload
   * by inheriting classes before the actual request.
   *
   * @param array $payload
   *   Request payload.
   *
   * @return array|null
   *   API response's data.
   */
  public function requestApi(array $payload);

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

  /**
   * Generate a URL for the river with the given parameters.
   *
   * @param string $bundle
   *   Entity bundle associated with the river.
   * @param array $parameters
   *   Query parameters.
   *
   * @return string
   *   River URL.
   */
  public static function getRiverUrl($bundle, array $parameters = []);

  /**
   * Get the data of the river for given bundle and API data.
   *
   * @param string $bundle
   *   Entity bundle of a river.
   * @param array|null $data
   *   ReliefWeb API data for the river.
   * @param string $view
   *   River view.
   * @param array $exclude
   *   Properties to exclude from the river entities.
   *
   * @return array
   *   List of entities with data suitable for use in templates.
   */
  public static function getRiverData($bundle, ?array $data, $view = '', array $exclude = []);

  /**
   * Get the ReliefWeb API query payload for the bundle's river.
   *
   * @param string $bundle
   *   Entity bundle of a river.
   * @param string $view
   *   River view.
   * @param array $exclude
   *   Elements to remove from the payload.
   *
   * @return array
   *   ReliefWeb API payload.
   */
  public static function getRiverApiPayload($bundle, $view = '', array $exclude = ['query']);

  /**
   * Get a river service from its associated bundle.
   *
   * @param string $bundle
   *   Entity bundle associated with the river.
   *
   * @return Drupal\reliefweb_rivers\RiverServiceInterface|null
   *   The river service or NULL if not found.
   */
  public static function getRiverService($bundle);

}
