<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_utility\Helpers\LocalizationHelper;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;

/**
 * Plugin implementation of the 'reliefweb_links' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_entity_reference_select",
 *   module = "reliefweb_fields",
 *   label = @Translation("ReliefWeb Entity Reference Select"),
 *   multiple_values = true,
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class ReliefWebEntityReferenceSelect extends OptionsSelectWidget {

  use EntityDatabaseInfoTrait;

  /**
   * List of allowed fields to add as extra data to the select options.
   *
   * @var array
   */
  private $extraDataFields;

  /**
   * List of option attributes.
   *
   * @var array
   */
  private $optionAttributes;

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    // Add a setting with a list of extra information to retrieve from
    // the fields of the referenced entity bundles.
    return [
      'extra_data' => [],
      'sort' => 'label',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = [];

    $sort_options = $this->getSortOptions();
    $element['sort'] = [
      '#type' => 'radios',
      '#title' => $this->t('Sort'),
      '#options' => $sort_options,
      '#default_value' => $this->getSetting('sort'),
    ];

    $fields = $this->getExtraDataAllowedFields();
    $element['extra_data'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Extra data'),
      '#description' => $this->t('Select the fields from which to retrieve additional information to be added as data attributes to the select options'),
      '#options' => $fields,
      '#default_value' => array_intersect_key($this->getSetting('extra_data'), $fields),
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $sort_options = $this->getSortOptions();
    $summary[] = $this->t('Sort: @sort', [
      '@sort' => $sort_options[$this->getSetting('sort')],
    ]);

    $fields = $this->getExtraDataAllowedFields();
    $fields = array_intersect_key($fields, array_filter($this->getSetting('extra_data')));
    if (!empty($fields)) {
      $summary[] = $this->t('Extra data: @list', [
        '@list' => new FormattableMarkup('<ul><li>' . implode('</li><li>', $fields) . '</li></ul>', []),
      ]);
    }
    else {
      $summary[] = $this->t('No extra data');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $element += [
      '#option_attributes' => $this->getOptionAttributes(),
    ];
    return $element;
  }

  /**
   * Get the sort options.
   *
   * @return array
   *   Sort options.
   */
  protected function getSortOptions() {
    return [
      'label' => $this->t('Label - Ascending'),
      'id' => $this->t('ID - Descending'),
    ];
  }

  /**
   * Get the referenced entity type id.
   *
   * @return string
   *   Entity type id.
   */
  protected function getReferencedEntityTypeId() {
    return $this->fieldDefinition->getSetting('target_type');
  }

  /**
   * Get the referenced entity bundles.
   *
   * @return array
   *   List of referenced bundles.
   */
  protected function getReferencedBundles() {
    $settings = $this->fieldDefinition->getSetting('handler_settings');
    return $settings['target_bundles'] ?? [];
  }

  /**
   * Get the allowed fields to add as extra data to the select options.
   *
   * @return array
   *   List of fields keyed by field machine names and with field label prefixed
   *   by the bundle as values.
   */
  protected function getExtraDataAllowedFields() {
    if (!isset($this->extraDataFields)) {
      // Retrieve the referenced entity type id and bundle.
      $entity_type_id = $this->getReferencedEntityTypeId();
      $bundles = $this->getReferencedBundles();

      // Get the available field definitions for the referenced entity type
      // and bundles.
      // Note: only the main property of the field will be returned.
      $entity_type_manager = $this->getEntityTypeManager();
      $entity_field_manager = $this->getEntityFieldManager();
      $entity_type = $entity_type_manager
        ->getStorage($entity_type_id)
        ->getEntityType();

      // We cannot easily get computed fields so we limit to fields with a
      // storage definition for the entity type.
      $field_storage_definitions = $entity_field_manager
        ->getFieldStorageDefinitions($entity_type_id);

      $fields = [];
      foreach ($bundles as $bundle) {
        $bundle_label = $entity_type_manager
          ->getStorage($entity_type->getBundleEntityType())
          ->load($bundle)
          ->label();

        $field_definitions = $entity_field_manager
          ->getFieldDefinitions($entity_type_id, $bundle);

        foreach ($field_definitions as $field_name => $field_definition) {
          // The moderation state field is a particular case. We can retrieve it
          // easily so we allow it.
          if (isset($field_storage_definitions[$field_name]) || $field_name === 'moderation_state') {
            $fields[$field_name] = $this->t('@bundle > @field', [
              '@bundle' => $bundle_label,
              '@field' => $field_definition->getLabel(),
            ]);
          }
        }
      }
      $this->extraDataFields = $fields;
    }

    return $this->extraDataFields;
  }

  /**
   * Returns the array of options for the widget.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity for which to return options.
   *
   * @return array
   *   The array of options for the widget.
   */
  protected function getOptions(FieldableEntityInterface $entity) {
    if (!isset($this->options)) {
      // Retrieve the referenced entity type id and bundle.
      $entity_type_id = $this->getReferencedEntityTypeId();
      $bundles = $this->getReferencedBundles();

      // Get the datbase info for the referenced entity type.
      $table = $this->getEntityTypeDataTable($entity_type_id);
      $id_field = $this->getEntityTypeIdField($entity_type_id);
      $label_field = $this->getEntityTypeLabelField($entity_type_id);
      $bundle_field = $this->getEntityTypeBundleField($entity_type_id);
      $langcode_field = $this->getEntityTypeLangcodeField($entity_type_id);

      // @todo Inject the language manager.
      // Get the current and default language codes.
      $language_manager = \Drupal::languageManager();
      $current_langcode = $language_manager->getCurrentLanguage()->getId();
      $default_langcode = $language_manager->getDefaultLanguage()->getId();
      $langcodes = array_unique([$current_langcode, $default_langcode]);

      // Retrieve the list of options.
      $query = $this->getDatabase()->select($table, $table);
      $query->addField($table, $id_field, 'id');
      $query->addField($table, $label_field, 'label');
      $query->addField($table, $langcode_field, 'langcode');
      $query->condition($table . '.' . $bundle_field, $bundles, 'IN');
      $query->condition($table . '.' . $langcode_field, $langcodes, 'IN');

      // Add the extra information.
      $extra_data_fields = $this->getSetting('extra_data');
      $field_storage_definitions = $this->getEntityFieldManager()
        ->getFieldStorageDefinitions($entity_type_id);

      $group = FALSE;
      $extra_data = [];
      foreach ($field_storage_definitions as $field_name => $definition) {
        if (empty($extra_data_fields[$field_name])) {
          continue;
        }

        if ($definition->isBaseField()) {
          $field_alias = $query->addField($table, $field_name);
        }
        else {
          $column = $definition->getMainPropertyName();
          $field_table = $this->getFieldTableName($entity_type_id, $field_name);
          $field_field = $this->getFieldColumnName($entity_type_id, $field_name, $column);
          $field_table_alias = $query->leftJoin($field_table, $field_table, implode(' AND ', [
            "%alias.entity_id = {$table}.{$id_field}",
            "%alias.langcode = {$table}.{$langcode_field}",
          ]));

          if ($definition->isMultiple()) {
            $field_alias = $query->addExpression("GROUP_CONCAT({$field_table_alias}.{$field_field} SEPARATOR ',')");
            $group = TRUE;
          }
          else {
            $field_alias = $query->addField($field_table_alias, $field_field);
          }

          $field_name = preg_replace('#^field_#', '', $field_name);
        }

        // Keep track of the property to be returned.
        $extra_data[$field_alias] = $field_name;
      }

      // Special handling of the moderation state.
      if (!empty($extra_data_fields['moderation_state'])) {
        $status_table = $this->getEntityTypeDataTable('content_moderation_state');
        $status_table_alias = $query->leftJoin($status_table, $status_table, implode(' AND ', [
          "%alias.content_entity_type_id = :entity_type_id",
          "%alias.content_entity_id = {$table}.{$id_field}",
          "%alias.langcode = {$table}.{$langcode_field}",
        ]), [
          ':entity_type_id' => $entity_type_id,
        ]);
        $status_field_alias = $query->addField($status_table_alias, 'moderation_state');
        // @todo This could be removed if we use `moderation-state`
        // everywhere.
        $extra_data[$status_field_alias] = 'moderation-status';
      }

      // Group by id and langcode if are adding a field with multiple values.
      if ($group) {
        $query->groupBy($table . '.' . $id_field);
        $query->groupBy($table . '.' . $langcode_field);
      }

      // Create the list of options.
      $options = [];
      $option_attributes = [];
      $records = $query->execute() ?? [];
      foreach ($records as $record) {
        $id = (int) $record->id;
        // The record in the current language takes precedence over the default
        // language verion.
        if ($record->langcode === $current_langcode || !isset($options[$id])) {
          $options[$id] = $record->label;
          // Add the extra data as data attributes.
          $attributes = [];
          foreach ($extra_data as $field => $attribute) {
            if (!is_null($record->{$field})) {
              $attributes['data-' . $attribute] = $record->{$field};
            }
          }
          $option_attributes[$id] = $attributes;
        }
      }

      // Sort the options.
      if ($this->getSetting('sort') === 'label') {
        LocalizationHelper::collatedAsort($options, NULL, $current_langcode);
      }
      else {
        krsort($options);
      }

      // Add an empty option if the widget needs one.
      if ($empty_label = $this->getEmptyLabel()) {
        $options = [
          '_none' => $empty_label,
        ] + $options;
      }

      // Allow other modules to alter the list.
      $module_handler = \Drupal::moduleHandler();
      $context = [
        'fieldDefinition' => $this->fieldDefinition,
        'entity' => $entity,
      ];
      $module_handler->alter('entity_reference_select_option_list', $options, $option_attributes, $context);

      // Sanitize the option labe, stripping HMTL tags etc.
      array_walk_recursive($options, [$this, 'sanitizeLabel']);

      // Options might be nested ("optgroups"). If the widget does not support
      // nested options, flatten the list.
      if (!$this->supportsGroups()) {
        $options = OptGroup::flattenOptions($options);
      }

      $this->options = $options;
      $this->optionAttributes = $option_attributes;
    }
    return $this->options;
  }

  /**
   * Get the attributes for the options.
   *
   * The attributes are computed when getting the widget options.
   *
   * @return array
   *   Option attributes.
   */
  protected function getOptionAttributes() {
    return $this->optionAttributes ?? [];
  }

}
