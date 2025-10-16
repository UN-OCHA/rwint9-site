<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_moderation\Traits\UserPostingRightsTrait;
use Drupal\user\EntityOwnerInterface;

/**
 * Plugin implementation of the 'reliefweb_source_restricted' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_source_restricted",
 *   module = "reliefweb_fields",
 *   label = @Translation("ReliefWeb Source restricted by posting rights"),
 *   multiple_values = true,
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class ReliefWebSourceRestricted extends ReliefWebSource {

  use UserPostingRightsTrait;

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $element['#type'] = 'checkboxes';
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function getOptions(FieldableEntityInterface $entity) {
    $options = parent::getOptions($entity);

    if (!empty($this->optionAttributes)) {
      foreach ($options as $id => $label) {
        if (isset($this->optionAttributes[$id]['data-shortname'])) {
          $shortname = $this->optionAttributes[$id]['data-shortname'];
          if (!empty($shortname) && $shortname !== $label) {
            $options[$id] = $this->t('@label (@shortname)', [
              '@label' => $label,
              '@shortname' => $shortname,
            ]);
          }
          // Remove the attribute to prevent the shortname from being added
          // again, for example when the form is rebuilt after some errors.
          unset($this->optionAttributes[$id]['data-shortname']);
        }
      }
      $this->options = $options;
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEmptyLabel() {
    return NULL;
  }

  /**
   * Get the list of allowed content types.
   *
   * @return array
   *   Array keyed by the content type bundle and with the correspoding field
   *   numeric value as value.
   *
   * @see field.storage.taxonomy_term.field_allowed_content_types.yml
   */
  protected function getAllowedContentTypes() {
    return [
      'job' => 0,
      'report' => 1,
      'training' => 2,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function executeOptionQuery(SelectInterface $query, FieldableEntityInterface $entity) {
    // Allowed content types.
    $bundles = $this->getAllowedContentTypes();

    // Skip if the entity to which this field is attached has a bundle not
    // in the allowed content types.
    $bundle = $entity->bundle();
    if (isset($bundles[$bundle])) {
      $entity_type_id = $this->getReferencedEntityTypeId();

      // Get the datbase info for the referenced entity type.
      $table = $this->getEntityTypeDataTable($entity_type_id);
      $id_field = $this->getEntityTypeIdField($entity_type_id);
      $langcode_field = $this->getEntityTypeLangcodeField($entity_type_id);

      // Allowed content types field.
      $field_name = 'field_allowed_content_types';
      $field_table = $this->getFieldTableName($entity_type_id, $field_name);
      $field_field = $this->getFieldColumnName($entity_type_id, $field_name, 'value');
      $field_table_alias = $query->leftJoin($field_table, $field_table, implode(' AND ', [
        "%alias.entity_id = {$table}.{$id_field}",
        "%alias.langcode = {$table}.{$langcode_field}",
      ]));

      // Limit to the content type matching the referencing entity bundle.
      $query->condition($field_table_alias . '.' . $field_field, $bundles[$bundle], '=');

      // Restrict to the sources the entity's owner is allowed to post for.
      if ($entity instanceof EntityOwnerInterface) {
        $owner = $entity->getOwner();
        $sources = $this->getUserPostingRightsManager()->getSourcesWithPostingRightsForUser($owner, [$bundle => [2, 3]]);
        if (!empty($sources)) {
          $query->condition($table . '.' . $id_field, array_keys($sources), 'IN');
        }
        else {
          $query->alwaysFalse();
        }
      }
      else {
        $query->alwaysFalse();
      }
    }

    return parent::executeOptionQuery($query, $entity);
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    // Limit to fields references source terms.
    $settings = $field_definition->getSetting('handler_settings');
    $bundles = $settings['target_bundles'] ?? [];
    return count($bundles) === 1 && in_array('source', $bundles);
  }

}
