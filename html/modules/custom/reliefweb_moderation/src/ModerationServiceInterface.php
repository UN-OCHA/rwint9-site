<?php

namespace Drupal\reliefweb_moderation;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;

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
   * Get the moderatrion status submit buttons for the entity form.
   *
   * @param string $status
   *   Current entity status.
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   Entity.
   *
   * @return array
   *   List of buttons keyed by their name and with properties to merge
   *   with the base submit button form element properties.
   */
  public function getEntityFormSubmitButtons($status, EntityModeratedInterface $entity);

  /**
   * Check if an entity with the given status is viewable for the account.
   *
   * @param string $status
   *   Entity moderation status.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   User account. Defaults to the current user if not provided.
   *
   * @return bool
   *   TRUE if the entity is viewable.
   */
  public function isViewableStatus($status, ?AccountInterface $account = NULL);

  /**
   * Check if an entity with the given status is editable for the account.
   *
   * @param string $status
   *   Entity moderation status.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   User account. Defaults to the current user if not provided.
   *
   * @return bool
   *   TRUE if the entity is editable.
   */
  public function isEditableStatus($status, ?AccountInterface $account = NULL);

  /**
   * Check if the entity has the given status.
   *
   * @param string $status
   *   Moderation status.
   *
   * @return bool
   *   TRUE if the entity has the given status.
   */
  public function hasStatus($status);

  /**
   * Check if the nofitications should be disable depending on the status.
   *
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   Entity for which to disable notifications.
   * @param string $status
   *   Moderation status.
   */
  public function disableNotifications(EntityModeratedInterface $entity, $status);

  /**
   * Handle changes to the entity before saving.
   *
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   Entity.
   */
  public function entityPresave(EntityModeratedInterface $entity);

  /**
   * Check the if the entity is accessible for view or edition etc.
   *
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   Entity.
   * @param string $operation
   *   Operation: view, create, edit or delete.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   User account accessing the entity. Defaults to the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function entityAccess(EntityModeratedInterface $entity, $operation = 'view', ?AccountInterface $account = NULL);

  /**
   * Alter the entity form, to add the moderation status submit buttons.
   *
   * @param array $form
   *   Entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function alterEntityForm(array &$form, FormStateInterface $form_state);

  /**
   * Validate the moderation status.
   *
   * @param array $element
   *   Status button form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateEntityStatus(array $element, FormStateInterface $form_state);

  /**
   * Submit handler to alter the moderation status.
   *
   * @param array $form
   *   Entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function handleEntitySubmission(array $form, FormStateInterface $form_state);

  /**
   * Get the final entity status based on the rest of the form.
   *
   * @param string $status
   *   Current status.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return string
   *   The final moderation status.
   */
  public function alterSubmittedEntityStatus($status, FormStateInterface $form_state);

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

  /**
   * Check access to the moderation pages.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user accessing the page.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function checkModerationPageAccess(AccountInterface $account);

  /**
   * Get the entity creation URL for the bundle.
   *
   * @param string $bundle
   *   The entity bundle for which to get the creation URL.
   *
   * @return \Drupal\Core\Url|null
   *   URL to create an entity of the service's bundle.
   */
  public function getBundleCreationUrl($bundle);

}
