<?php

namespace Drupal\reliefweb_guidelines\Services;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;

/**
 * Moderation service for the guidelines.
 */
class GuidelineModeration extends ModerationServiceBase {

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return 'field_guideline';
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
    return $this->t('Guidelines');
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
        'label' => $this->t('Guideline'),
        'type' => 'property',
        'specifier' => 'name',
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

      // Title.
      $data['title'] = Link::fromTextAndUrl($entity->label(), Url::fromUserInput('/guidelines', [
        'fragment' => $entity->getShortId(),
      ]));

      // Information.
      $info = [];
      $list = $entity->getGuidelineList();
      if (!empty($list)) {
        $info['list'] = $list->toLink()->toString();
      }
      $data['info'] = array_filter($info);

      $details = [];
      if (!$entity->field_field->isEmpty()) {
        $fields = [];
        foreach ($entity->field_field as $item) {
          $field_label = static::getTargetFieldName($item->value);
          if (!empty($field_label)) {
            $fields[] = $field_label;
          }
        }
        if (!empty($fields)) {
          $details['fields'] = $this->formatPlural(count($fields), '<strong>Field:</strong> @fields', '<strong>Fields:</strong> @fields', [
            '@fields' => implode(', ', $fields),
          ]);
        }
      }
      $data['details'] = array_filter($details);

      // Revision information.
      $data['revision'] = $this->getEntityRevisionData($entity);

      // Filter out empty data.
      $cells['data'] = array_filter($data);

      // Date cell.
      $cells['date'] = [
        'date' => $entity->getChangedTime(),
      ];

      $rows[] = $cells;
    }

    return $rows;
  }

  /**
   * Get the label of a guideline target field.
   *
   * @param string $value
   *   Target field in the form `entity_type.bunde.field_name`.
   *
   * @return string
   *   The field label.
   */
  protected static function getTargetFieldName($value) {
    if (empty($value)) {
      return '';
    }
    [$entity_type_id, $bundle, $field_name] = explode('.', $value);

    $bundle_info = \Drupal::service('entity_type.bundle.info')
      ->getBundleInfo($entity_type_id);

    $field_definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions($entity_type_id, $bundle);

    $field_label = $field_name;
    if (isset($field_definitions[$field_name])) {
      $field_label = $field_definitions[$field_name]->getLabel();
    }

    return implode(' > ', [
      $bundle_info[$bundle]['label'] ?? $bundle,
      $field_label,
    ]);
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

      case 'view_moderation_information':
        if ($account->hasPermission('view moderation information')) {
          $access = $account->hasPermission('edit guideline entities');
        }
        break;

      default:
        return AccessResult::neutral();
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
   * {@inheritdoc}
   */
  public function checkModerationPageAccess(AccountInterface $account) {
    return parent::checkModerationPageAccess($account)
      ->andIf(AccessResult::allowedIfHasPermission($account, 'edit guideline entities'));
  }

}
