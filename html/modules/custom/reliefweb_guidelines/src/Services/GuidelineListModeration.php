<?php

namespace Drupal\reliefweb_guidelines\Services;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;

/**
 * Moderation service for guideline list taxonomy terms.
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
    return 'taxonomy_term';
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
      $children = [];
      if ($entity instanceof GuidelineList) {
        $children = $entity->getChildren();
      }
      if (!empty($children)) {
        $label = $this->formatPlural(count($children), '1 child guideline', '@count child guidelines');
        // Sigh... we cannot use `$entity->toLink($label, 'sort-form')` because
        // that would make Drupal look for a `entity.taxonomy_term.sort_form`
        // route which doesn't exist; the sort route is
        // reliefweb_guidelines.guideline.sort.
        $url = Url::fromRoute('reliefweb_guidelines.guideline.sort', [
          'taxonomy_term' => $entity->id(),
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
  public function entityAccess(EntityModeratedInterface $entity, string $operation = 'view', ?AccountInterface $account = NULL): AccessResultInterface {
    $account = $account ?: $this->currentUser;
    $access = FALSE;
    $status = $entity->getModerationStatus();
    $viewable = $this->isViewableStatus($status, $account);
    $editable = $this->isEditableStatus($status, $account);

    switch ($operation) {
      case 'view':
        if ($account->hasPermission('access editorial guidelines')) {
          $access = $viewable
            || $account->hasPermission('view any content')
            || $account->hasPermission('edit terms in guideline_list');
        }
        break;

      case 'create':
        $access = $account->hasPermission('create terms in guideline_list');
        break;

      case 'update':
        $access = $account->hasPermission('edit terms in guideline_list') && $editable;
        break;

      case 'delete':
        $access = $account->hasPermission('delete terms in guideline_list');
        break;

      case 'view_moderation_information':
        if ($account->hasPermission('view moderation information')) {
          $access = $account->hasPermission('edit terms in guideline_list');
        }
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
    $table = 'taxonomy_term__field_role';
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
      ->andIf(AccessResult::allowedIfHasPermission($account, 'edit terms in guideline_list'));
  }

}
