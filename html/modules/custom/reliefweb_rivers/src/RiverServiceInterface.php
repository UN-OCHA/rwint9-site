<?php

namespace Drupal\reliefweb_rivers;

/**
 * Interface for the river services.
 */
interface RiverServiceInterface {

  /**
   * Get the river name.
   *
   * @return string
   *   River.
   */
  public function getRiver();

  /**
   * Get the entity bundle associated with the river.
   *
   * @return string
   *   Entity bundle.
   */
  public function getBundle();

  /**
   * Get the entity type id associated with the river.
   *
   * @return string
   *   Entity type id.
   */
  public function getEntityTypeId();

  /**
   * Get the Api resource for the river.
   *
   * @return string
   *   Page title.
   */
  public function getResource();

  /**
   * Get the default page title for the river.
   *
   * @return string
   *   Page title.
   */
  public function getDefaultPageTitle();

  /**
   * Get the page title for the river.
   *
   * @return string
   *   Page title.
   */
  public function getPageTitle();

  /**
   * Check it we should use the default title or customize it.
   *
   * @return bool
   *   TRUE if we should use the default tile (for example if there is a search
   *   query).
   */
  public function useDefaultTitle();

  /**
   * Get allowed filter types for custom titles and canonical URLs.
   *
   * @return array
   *   List of allowed filter types.
   */
  public function getAllowedFilterTypesForTitle();

  /**
   * Get excluded filter codes for custom titles and canonical URLs.
   *
   * @return array
   *   List of excluded filter codes
   */
  public function getExcludedFilterCodesForTitle();

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
   * Get the canonical URL for the river.
   *
   * @return string
   *   Page title.
   */
  public function getCanonicalUrl();

  /**
   * Get the river parameter handler.
   *
   * @return \Drupal\reliefweb_rivers\Parameters
   *   River parameter handler.
   */
  public function getParameters();

  /**
   * Set the river parameter handler.
   *
   * @param \Drupal\reliefweb_rivers\Parameters $parameters
   *   River parameter handler.
   */
  public function setParameters(Parameters $parameters);

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
   * Set the selected view for the river.
   *
   * @param string $view
   *   The view to select. If it's invalid, the current selected view or the
   *   default view will be used.
   */
  public function setSelectedView($view);

  /**
   * Validate a view against the allowed views for the river.
   *
   * @param string $view
   *   View.
   *
   * @return string|null
   *   The view if valid or NULL othewise.
   */
  public function validateView($view);

  /**
   * Get the label for a view.
   *
   * @param string $view
   *   View.
   *
   * @return string
   *   View label or empty string if the view doesn't exist.
   */
  public function getViewLabel($view);

  /**
   * Get the sanitized search parameter.
   *
   * @return string
   *   Sanitized search parmeter.
   */
  public function getSearch();

  /**
   * Set the sanitized search parameter.
   *
   * @param string $search
   *   Sanitized search parmeter.
   */
  public function setSearch($search);

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
   * Get the river content render array.
   *
   * @return array
   *   Render array with the river content including:
   *   - id
   *   - title
   *   - results (see ::getRiverResults())
   *   - entities
   *   - pager (see ::getRiverPager())
   *   - empty message.
   */
  public function getRiverContent();

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
   * Get the link to the RSS feed for the river.
   *
   * @return string
   *   Link to the RSS feed for the river.
   */
  public function getRssLink();

  /**
   * Get the link to the API search converter.
   *
   * @return string
   *   Link to the search converter.
   */
  public function getApiLink();

  /**
   * Get the link to the user subscriptions page.
   *
   * @return string
   *   Link to the subscriptions page.
   */
  public function getSubscribeLink();

  /**
   * Get the base ReliefWeb API payload for the given river and view.
   *
   * @return array
   *   API payload.
   */
  public function getApiPayload($view = '');

  /**
   * Prepare the API payload with the river parameters.
   *
   * @param int $limit
   *   Number of resources to return.
   * @param bool $paginated
   *   If TRUE, add an offset to the payload based on the current page
   *   parameter.
   * @param string|null $view
   *   Optional view override.
   *
   * @return array
   *   Payload ready for an API request.
   */
  public function prepareApiRequest($limit = 20, $paginated = TRUE, $view = NULL);

  /**
   * Get the data from the ReliefWeb API.
   *
   * @param int $limit
   *   Number of resources to return.
   * @param bool $paginated
   *   If TRUE, add an offset to the payload based on the current page
   *   parameter.
   * @param array|null $payload
   *   Optional payload override. If NULL, the payload generated by
   *   ::prepareApiRequest() will be used for the query to the API.
   * @param string|null $view
   *   Optional view override.
   *
   * @return array
   *   List of resource data as returned by ::parseApiData().
   */
  public function getApiData($limit = 20, $paginated = TRUE, array $payload = NULL, $view = NULL);

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
   * Get the RSS content for the river.
   *
   * @return array
   *   Render array.
   */
  public function getRssContent();

  /**
   * Get the data for the RSS feeds from the ReliefWeb API.
   *
   * @param int $limit
   *   Number of resources to return.
   *
   * @return array
   *   List of resource data as returned by ::parseApiDataForRss().
   */
  public function getApiDataForRss($limit = 20);

  /**
   * Get the ReliefWeb API payload for the given river RSS feed and view.
   *
   * @return array
   *   API payload.
   */
  public function getApiPayloadForRss($view = '');

  /**
   * Parse the data from the ReliefWeb API to use in river RSS feeds.
   *
   * @param array $data
   *   Data returned by the ReliefWeb API.
   * @param string $view
   *   Current river view.
   *
   * @return array
   *   Parsed data, ready to use in river RSS templates.
   */
  public function parseApiDataForRss(array $data, $view = '');

  /**
   * Get the river description based on the filters and search query.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   River page description based on the search and filters or the default
   *   river page description otherwise.
   */
  public function getRiverDescription();

  /**
   * Get the default river description.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The default river page description.
   */
  public function getDefaultRiverDescription();

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
   * @param string $title
   *   Title to use as parameter in the river URL.
   * @param bool $partial_title
   *   If TRUE, then the name of the river will be appended to the given $title
   *   if not empty.
   * @param bool $absolute
   *   If TRUE, return an absolute URL.
   *
   * @return string
   *   River URL.
   */
  public static function getRiverUrl($bundle, array $parameters = [], $title = '', $partial_title = FALSE, $absolute = FALSE);

  /**
   * Generate a river title from the given entity bundle and title prefix.
   *
   * @param string $bundle
   *   Entity bundle of the river.
   * @param string $prefix
   *   Title prefix.
   *
   * @return string
   *   River title.
   */
  public static function getRiverUrlTitle($bundle, $prefix);

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

  /**
   * Get the river path to river data mapping.
   *
   * This notably handles the legacy river paths like "maps".
   *
   * @return array
   *   Mapping with the river path as key and an array with the entity bundle
   *   associated with the river and an optional view for legacy paths.
   *
   * @todo check if nginx handles the redirections correctly.
   */
  public static function getRiverMapping();

  /**
   * Get the river service from a river URL.
   *
   * @param string $url
   *   River url.
   *
   * @return Drupal\reliefweb_rivers\RiverServiceInterface|null
   *   The river service or NULL if not found.
   */
  public static function getRiverServiceFromUrl($url);

  /**
   * Get the cache tags for the river.
   *
   * @return array
   *   Cache tags.
   */
  public function getRiverCacheTags();

}
