<?php

namespace Drupal\reliefweb_guidelines\Services;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;

/**
 * Moderation service for the guideline lists.
 */
class GuidelineListModeration extends ModerationServiceBase {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return 'guideline_list';
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return 'guideline';
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    return $this->t('Guideline Lists');
  }

  /**
   * {@inheritdoc}
   */
  public function getHeaders() {
    return [
      'edit' => [
        'label' => '',
      ],
      'data' => [
        'label' => $this->t('Guideline List'),
        'type' => 'property',
        'specifier' => 'name',
        'sortable' => TRUE,
      ],
      'role' => [
        'label' => $this->t('Role'),
        'type' => 'field',
        'specifier' => 'field_role',
        'sortable' => TRUE,
      ],
      'date' => [
        'label' => $this->t('Updated'),
        'type' => 'property',
        'specifier' => 'changed',
        'sortable' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRows(array $results) {
    if (empty($results['entities'])) {
      return [];
    }

    /** @var \Drupal\reliefweb_moderation\EntityModeratedInterface[] $entities */
    $entities = $results['entities'];

    // Prepare the table rows' data from the entities.
    $rows = [];
    foreach ($entities as $entity) {
      $cells = [];

      // Edit link + status cell.
      $cells['edit'] = $this->getEntityEditAndStatusData($entity);

      // Entity data cell.
      $data = [];

      // Add link to the sort form.
      $children = $entity->getChildren();
      if (!empty($children)) {
        $label = $this->formatPlural(count($children), '1 child guideline', '@count child guidelines');
        // Sigh... we cannot use `$entity->toLink($label, 'sort-form')` because
        // that would make Drupal look for a `entity.guideline.sort_form` route
        // which doesn't exist because the route for the sort form is
        // defined as `entity.{entity_type_id}.sort`...
        $url = Url::fromRoute('entity.' . $entity->getEntityTypeId() . '.sort', [
          'guideline' => $entity->id(),
        ]);
        $data['info']['sort'] = Link::fromTextAndUrl($label, $url);
      }

      // Title.
      $data['title'] = $entity->toLink()->toString();

      // Revision information.
      $data['revision'] = $this->getEntityRevisionData($entity);

      // Filter out empty data.
      $cells['data'] = array_filter($data);

      // User role.
      $cells['role'] = $entity->field_role?->entity?->label() ?? 'Editor';

      // Date cell.
      $cells['date'] = [
        'date' => $entity->getChangedTime(),
      ];

      $rows[] = $cells;
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatuses() {
    return [
      'draft' => $this->t('Draft'),
      'published' => $this->t('Published'),
      'archive' => $this->t('Archived'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityFormSubmitButtons($status, EntityModeratedInterface $entity) {
    return [
      'draft' => [
        '#value' => $this->t('Save as draft'),
      ],
      'published' => [
        '#value' => $this->t('Publish'),
      ],
      'archive' => [
        '#value' => $this->t('Archive'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function entityAccess(EntityModeratedInterface $entity, $operation = 'view', ?AccountInterface $account = NULL) {
    $account = $account ?: $this->currentUser;

    $access = FALSE;

    $status = $entity->getModerationStatus();

    $viewable = $this->isViewableStatus($status, $account);

    $editable = $this->isEditableStatus($status, $account);

    switch ($operation) {
      case 'view':
        if ($account->hasPermission('view published guideline entities')) {
          $access = $viewable || $account->hasPermission('view unpublished guideline entities');
        }
        break;

      case 'create':
        $access = $account->hasPermission('add guideline entities');
        break;

      case 'update':
        $access = $account->hasPermission('edit guideline entities') && $editable;
        break;

      case 'delete':
        $access = $account->hasPermission('delete guideline entities');
        break;
    }

    return $access ? AccessResult::allowed() : AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  protected function initFilterDefinitions(array $filters = []) {
    $definitions = parent::initFilterDefinitions([
      'status',
      'name',
      'created',
    ]);

    $definitions['role'] = [
      'type' => 'other',
      'label' => $this->t('Role'),
      'field' => 'field_role',
      'form' => 'role',
      // No specific widget as the join is enough.
      'widget' => 'none',
      'join_callback' => 'joinRole',
      'values' => reliefweb_guidelines_get_user_roles(),
    ];

    $definitions['changed'] = [
      'type' => 'property',
      'field' => 'changed',
      'label' => $this->t('Modification date'),
      'shortcut' => 'ch',
      'form' => 'omnibox',
      'widget' => 'datepicker',
    ];
    return $definitions;
  }

  /**
   * Users roles join callback.
   *
   * @see ::joinField()
   */
  protected function joinRole(Select $query, array $definition, $entity_type_id, $entity_base_table, $entity_id_field, $or = FALSE, $values = []) {
    // Join the role field table.
    $table = 'guideline__field_role';
    $query->innerJoin($table, $table, "%alias.entity_id = {$entity_base_table}.{$entity_id_field} AND %alias.field_role_target_id IN (:roles[])", [
      ':roles[]' => $values,
    ]);

    // No field to return as the inner join is enough.
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function checkModerationPageAccess(AccountInterface $account) {
    return parent::checkModerationPageAccess($account)
      ->andIf(AccessResult::allowedIfHasPermission($account, 'edit guideline entities'));
  }

}
