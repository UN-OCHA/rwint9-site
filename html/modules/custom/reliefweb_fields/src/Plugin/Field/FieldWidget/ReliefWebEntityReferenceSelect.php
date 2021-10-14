<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_utility\Helpers\LocalizationHelper;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;

/**
 * Plugin implementation of the 'reliefweb_entity_reference_select' widget.
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
            $fields[$bundle . ':' . $field_name] = $this->t('@bundle > @field', [
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

      // Extra data fields to for the option attributes.
      $extra_data_fields = $this->getExtraDataFields();

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
      $query->addField($table, $bundle_field, 'bundle');
      $query->condition($table . '.' . $bundle_field, $bundles, 'IN');
      $query->condition($table . '.' . $langcode_field, $langcodes, 'IN');

      // Add any extra data from the base table.
      foreach ($extra_data_fields['base'] as $field_info) {
        $query->addField($table, $field_info['definition']->getName());
      }

      // Execute the query, giving the opportunity for classes extending this
      // one to alter it.
      $records = $this->executeOptionQuery($query, $entity);

      // Extract the ids. Use a map to ensure uniqueness in case of mulitple
      // languages.
      $ids = [];
      foreach ($records as $record) {
        $ids[$record->bundle][$record->id] = $record->id;
      }

      // Retrieve the extra data for non-base fields.
      $extra_data = $this->getExtraData($extra_data_fields, $ids, $langcodes);

      // Create the list of options.
      $options = [];
      $option_attributes = [];
      foreach ($records as $record) {
        $id = (int) $record->id;
        $langcode = $record->langcode;
        $bundle = $record->bundle;

        // The record in the current language takes precedence over the default
        // language version.
        if ($langcode === $current_langcode || !isset($options[$id])) {
          $options[$id] = $record->label;
          $attributes = [];

          // Add the extra data base properties as data attributes.
          foreach ($extra_data_fields['base'] as $field_info) {
            $field_name = $field_info['definition']->getName();

            if (isset($record->{$field_name})) {
              $attributes[$field_info['attribute']] = $record->{$field_name};
            }
          }

          // Add the extra data bundle fields as data attributes.
          foreach ($extra_data_fields['bundle'] as $field_info) {
            $field_name = $field_info['definition']->getName();

            if (isset($extra_data[$bundle][$field_name][$langcode][$id])) {
              $attribute_value = implode(',', $extra_data[$bundle][$field_name][$langcode][$id]);
              $attributes[$field_info['attribute']] = $attribute_value;
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
   * Get the list of fields from which to get the option extra data.
   *
   * @return array
   *   Associative array keyed by field name and with their associated data
   *   attribute and the field definition.
   */
  protected function getExtraDataFields() {
    $entity_type_id = $this->getReferencedEntityTypeId();

    $fields = [
      'base' => [],
      'bundle' => [],
    ];
    foreach ($this->getSetting('extra_data') as $field => $selected) {
      if (!empty($selected) && strpos($field, ':') !== FALSE) {
        list($bundle, $field_name) = explode(':', $field);

        $definitions = $this->getEntityFieldManager()
          ->getFieldDefinitions($entity_type_id, $bundle);

        if (isset($definitions[$field_name])) {
          $definition = $definitions[$field_name];
          $type = $definition->getFieldStorageDefinition()->isBaseField() ? 'base' : 'bundle';

          // The moderation is a special case. It's a base field but its not
          // using the entity type's table. We add it to the list of bundle
          // fields and execute the appropriate query in `getExtraData`.
          if ($field_name === 'moderation_state') {
            $attribute = 'data-moderation-status';
            $type = 'bundle';
          }
          else {
            $attribute = 'data-' . preg_replace('#^field_#', '', $field_name);
          }

          $fields[$type][$field] = [
            'attribute' => $attribute,
            'definition' => $definition,
          ];
        }
      }
    }

    return $fields;
  }

  /**
   * Get the extra data to add as optiona attributes.
   *
   * @param array $extra_data_fields
   *   Entity fields.
   * @param array $ids
   *   List of entity ids.
   * @param array $langcodes
   *   Langcodes for which to retrieve data.
   *
   * @return array
   *   Nested associative array keyed by bundle then field name, then langcode
   *   then id and finally with the field values as values.
   */
  protected function getExtraData(array $extra_data_fields, array $ids, array $langcodes) {
    $entity_type_id = $this->getReferencedEntityTypeId();
    $data = [];

    // Retrieve the data for each extra field.
    foreach ($extra_data_fields['bundle'] as $field_info) {
      $definition = $field_info['definition'];
      $bundle = $definition->getTargetBundle();
      $field_name = $definition->getName();

      // Skip if there are not entity ids for this bundle.
      if (empty($ids[$bundle])) {
        continue;
      }

      // Special handling of the moderation status as it's not an entity field.
      if ($field_name === 'moderation_state') {
        $table = $this->getEntityTypeDataTable('content_moderation_state');

        $query = $this->getDatabase()
          ->select($table, $table)
          ->condition($table . '.content_entity_type_id', $entity_type_id, '=')
          ->condition($table . '.content_entity_id', $ids[$bundle], 'IN')
          ->condition($table . '.langcode', $langcodes, 'IN');
        $query->addField($table, 'content_entity_id', 'id');
        $query->addField($table, 'langcode', 'langcode');
        $query->addField($table, 'moderation_state', 'value');
      }
      else {
        $column = $definition->getFieldStorageDefinition()->getMainPropertyName();
        $table = $this->getFieldTableName($entity_type_id, $field_name);
        $field = $this->getFieldColumnName($entity_type_id, $field_name, $column);

        $query = $this->getDatabase()
          ->select($table, $table)
          ->condition($table . '.entity_id', $ids[$bundle], 'IN')
          ->condition($table . '.langcode', $langcodes, 'IN')
          ->isNotNull($table . '.' . $field);
        $query->addField($table, 'entity_id', 'id');
        $query->addField($table, 'langcode', 'langcode');
        $query->addField($table, $field, 'value');
      }

      foreach ($query->execute() ?? [] as $record) {
        $data[$bundle][$field_name][$record->langcode][$record->id][] = $record->value;
      }
    }
    return $data;
  }

  /**
   * Execute the query to get the options.
   *
   * This mainly to give a chance to classes extending this one to modify
   * the query before it's executed.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   Select query to get the options.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity for which to return options.
   *
   * @return array
   *   The list of records (objects).
   */
  protected function executeOptionQuery(SelectInterface $query, FieldableEntityInterface $entity) {
    return $query->execute()?->fetchAll() ?? [];
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

  /**
   * {@inheritdoc}
   */
  protected function getEmptyLabel() {
    return !$this->required ? $this->t('- None -') : $this->t('- Select a value -');
  }

}
