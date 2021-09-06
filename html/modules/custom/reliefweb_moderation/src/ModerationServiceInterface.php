<?php

namespace Drupal\reliefweb_moderation;

use Drupal\Core\Session\AccountProxyInterface;

/**
 * Interface for the moderation services.
 */
interface ModerationServiceInterface {

  /**
   * Get the entity bundle assocatiated with the service.
   *
   * @return string
   *   Entity bundle.
   */
  public function getBundle();

  /**
   * Get the entity type id assocatiated with the service.
   *
   * @return string
   *   Entity type id.
   */
  public function getEntityTypeId();

  /**
   * Get the moderation page title.
   *
   * @return string
   *   Page title.
   */
  public function getTitle();

  /**
   * Get the moderation page table headers.
   *
   * @return array
   *   Associative array keyed by header id and with the following properties:
   *   - label: header label
   *   - sortable: flag indicating the header can be used to filter the content
   *   - type: when the header is sortable, then must be either "field" or
   *     "property" indicating if this targets an entity field or property
   *     (field on the entity's table).
   *   - specifier: when the header is sortable, then must be either a string
   *     with the property name of type is "property" or an array containing
   *     a field and column if type is "field".
   *   Only the label is mandatory if sortable is not TRUE, otherwise all the
   *   fields are mandatory.
   */
  public function getHeaders();

  /**
   * Get the statuses.
   *
   * @return array
   *   List of available statuses for the this bundle.
   */
  public function getStatuses();

  /**
   * Get the filter statuses.
   *
   * @return array
   *   List of statuses to use as filters on the moderation page
   *   for the this bundle.
   */
  public function getFilterStatuses();

  /**
   * Get the filter default statuses.
   *
   * @return array
   *   List of statuses for the this bundle to use as default
   *   of the moderation status filter.
   */
  public function getFilterDefaultStatuses();

  /**
   * Get the moderation content table.
   *
   * @param array $filters
   *   User selected filter.
   * @param int $limit
   *   Number of items to retrieve.
   *
   * @return array
   *   Render array with the result totals, table and pager.
   */
  public function getTable(array $filters, $limit = 30);

  /**
   * Get the filter definitions for this service.
   *
   * @return array
   *   List of filter definitions for the service.
   */
  public function getFilterDefinitions();

  /**
   * Get the filter definition for the given filter name.
   *
   * @param string $name
   *   The filter name.
   *
   * @return array|null
   *   The filter definition for the given filer name or NULL if not found.
   */
  public function getFilterDefinition($name);

  /**
   * Check if the given filter name exists in this handler filter definitions.
   *
   * @param string $name
   *   The filter name.
   *
   * @return bool
   *   TRUE if the service has the given filter.
   */
  public function hasFilterDefinition($name);

  /**
   * Check if the account has the current role.
   *
   * @param array $roles
   *   Role machine names.
   * @param \Drupal\Core\Session\AccountProxyInterface|null $account
   *   User account. Defaults to the current user if NULL.
   * @param bool $all
   *   If TRUE, then check that the user has all the given role otherwise
   *   only check if the user has one of the roles.
   *
   * @return bool
   *   TRUE if the user has the given role.
   */
  public function userHasRoles(array $roles, ?AccountProxyInterface $account = NULL, $all = FALSE);

  /**
   * Get the autocomplete suggestions for the filter and the query parameter.
   *
   * @param string $filter
   *   Filter name.
   *
   * @return array
   *   List of autocomplete suggestions. Each suggestion is an object containing
   *   a value (raw value) and a label.
   */
  public function getAutocompleteSuggestions($filter);

  /**
   * Get a moderation service from its associated bundle.
   *
   * @param string $bundle
   *   Entity bundle associated with the moderation.
   *
   * @return Drupal\reliefweb_moderation\ModerationServiceInterface|null
   *   The moderation service or NULL if not found.
   */
  public static function getModerationService($bundle);

}
