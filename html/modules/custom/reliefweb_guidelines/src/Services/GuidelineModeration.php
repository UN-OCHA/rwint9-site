<?php

namespace Drupal\reliefweb_guidelines\Services;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\reliefweb_guidelines\Plugin\Field\FieldWidget\GuidelineFieldTargetSelectWidget;
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
      $data['title'] = $entity->toLink()?->toString();

      // Information.
      $info = [];
      $list = $entity->getGuidelineList();
      if (!empty($list)) {
        $info['list'] = $list->toLink($list->getRoleAndLabel())->toString();
      }
      $info['link'] = $entity->getLinkToGuidelines($this->t('<em>guidelines</em>'));
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
    $definitions['list'] = [
      'type' => 'field',
      'field' => 'parent',
      'column' => 'target_id',
      'label' => $this->t('Guideline List'),
      'shortcut' => 'gl',
      'form' => 'omnibox',
      'widget' => 'autocomplete',
      'autocomplete_callback' => 'getGuidelineListAutocompleteSuggestions',
      'operator' => 'OR',
      'allow_no_value' => TRUE,
    ];
    $definitions['field'] = [
      'type' => 'field',
      'field' => 'field_field',
      'column' => 'value',
      'label' => $this->t('Form field'),
      'shortcut' => 'ff',
      'form' => 'omnibox',
      'widget' => 'autocomplete',
      'autocomplete_callback' => 'getGuidelineTargetFieldAutocompleteSuggestions',
      'operator' => 'OR',
      'allow_no_value' => TRUE,
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

  /**
   * Get guideline list suggestions for the given term.
   *
   * @param string $filter
   *   Filter name.
   * @param string $term
   *   Autocomplete search term.
   * @param string $conditions
   *   Stringified database conditions in the form:
   *   "(@field = 'bar' AND @field = 'bar')".
   *   This conditions will be duplicated for each passed field and combined
   *   into a OR condition.
   * @param array $replacements
   *   Value replacements for the search condition.
   *
   * @return array
   *   List of suggestions. Each suggestion is an object with a value, label
   *   and optional abbreviation (abbr).
   */
  protected function getGuidelineListAutocompleteSuggestions($filter, $term, $conditions, array $replacements) {
    $entity_type_id = $this->getEntityTypeId();

    $table = $this->getEntityTypeDataTable($entity_type_id);
    $alias = $table;
    $id_field = $this->getEntityTypeIdField($entity_type_id);
    $label_field = $this->getEntityTypeLabelField($entity_type_id);
    $bundle_field = $this->getEntityTypeBundleField($entity_type_id);

    // List of fields used for the condition replacements.
    $fields = [$alias . '.' . $label_field];

    // Base query.
    $query = $this->getDatabase()->select($table, $alias);
    $query->addField($alias, $id_field, 'value');
    $query->addField($alias, $label_field, 'label');
    $query->condition($alias . '.' . $bundle_field, 'guideline_list', '=');
    $query->range(0, 10);
    $query->distinct();

    // Add conditions.
    $conditions = $this->buildFilterConditions($conditions, $fields);
    $query->where($conditions, $replacements);

    // Sort by name.
    $query->orderBy($alias . '.name', 'ASC');

    return $query->execute()?->fetchAll() ?? [];
  }

  /**
   * Get guideline target field suggestions for the given term.
   *
   * @param string $filter
   *   Filter name.
   * @param string $term
   *   Autocomplete search term.
   * @param string $conditions
   *   Stringified database conditions in the form:
   *   "(@field = 'bar' AND @field = 'bar')".
   *   This conditions will be duplicated for each passed field and combined
   *   into a OR condition.
   * @param array $replacements
   *   Value replacements for the search condition.
   *
   * @return array
   *   List of suggestions. Each suggestion is an object with a value, label
   *   and optional abbreviation (abbr).
   */
  protected function getGuidelineTargetFieldAutocompleteSuggestions($filter, $term, $conditions, array $replacements) {
    $form_fields = static::getAvailableTargetFormFields();
    if (empty($form_fields)) {
      return [];
    }

    $parts = explode(' ', $term);

    $suggestions = [];
    foreach ($form_fields as $value => $label) {
      foreach ($parts as $part) {
        if (mb_strpos($label, $part) === FALSE && mb_strpos($value, $part) === FALSE) {
          continue 2;
        }
      }
      $suggestions[] = (object) [
        'value' => $value,
        'label' => $label,
      ];
    }

    return $suggestions;
  }

  /**
   * Get the list of available target form fields.
   *
   * @return array
   *   Associative array of available form fields with keys in the form
   *   `entity_type_id.bundle.field_name` and with labels as values.
   */
  protected static function getAvailableTargetFormFields() {
    static $fields;

    if (!isset($fields)) {
      $component = \Drupal::service('entity_display.repository')
        ->getFormDisplay('guideline', 'field_guideline', 'default')
        ?->getComponent('field_field') ?? [];

      $enabled_entities = $component['settings']['enabled_entities'] ?? [];

      $fields = GuidelineFieldTargetSelectWidget::getAvailableFormFields($enabled_entities);
    }

    return $fields;
  }

}
