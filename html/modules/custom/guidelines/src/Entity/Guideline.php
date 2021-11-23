<?php

namespace Drupal\guidelines\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\user\UserInterface;

/**
 * Defines the Guideline entity.
 *
 * @ingroup guidelines
 *
 * @ContentEntityType(
 *   id = "guideline",
 *   label = @Translation("Guideline"),
 *   bundle_label = @Translation("Guideline type"),
 *   handlers = {
 *     "storage" = "Drupal\guidelines\GuidelineStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\guidelines\GuidelineListBuilder",
 *     "views_data" = "Drupal\guidelines\Entity\GuidelineViewsData",
 *     "translation" = "Drupal\guidelines\GuidelineTranslationHandler",
 *
 *     "form" = {
 *       "default" = "Drupal\guidelines\Form\GuidelineForm",
 *       "add" = "Drupal\guidelines\Form\GuidelineForm",
 *       "edit" = "Drupal\guidelines\Form\GuidelineForm",
 *       "delete" = "Drupal\guidelines\Form\GuidelineDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\guidelines\GuidelineHtmlRouteProvider",
 *     },
 *     "access" = "Drupal\guidelines\GuidelineAccessControlHandler",
 *   },
 *   base_table = "guideline",
 *   data_table = "guideline_field_data",
 *   revision_table = "guideline_revision",
 *   revision_data_table = "guideline_field_revision",
 *   translatable = TRUE,
 *   permission_granularity = "bundle",
 *   admin_permission = "administer guideline entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "vid",
 *     "bundle" = "type",
 *     "label" = "name",
 *     "uuid" = "uuid",
 *     "uid" = "user_id",
 *     "langcode" = "langcode",
 *     "published" = "status",
 *     "weight" = "weight",
 *   },
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_user",
 *     "revision_created" = "revision_created",
 *     "revision_log_message" = "revision_log",
 *   },
 *   links = {
 *     "canonical" = "/admin/structure/guideline/{guideline}",
 *     "add-page" = "/admin/structure/guideline/add",
 *     "add-form" = "/admin/structure/guideline/add/{guideline_type}",
 *     "edit-form" = "/admin/structure/guideline/{guideline}/edit",
 *     "delete-form" = "/admin/structure/guideline/{guideline}/delete",
 *     "version-history" = "/admin/structure/guideline/{guideline}/revisions",
 *     "revision" = "/admin/structure/guideline/{guideline}/revisions/{guideline_revision}/view",
 *     "revision_revert" = "/admin/structure/guideline/{guideline}/revisions/{guideline_revision}/revert",
 *     "revision_delete" = "/admin/structure/guideline/{guideline}/revisions/{guideline_revision}/delete",
 *     "translation_revert" = "/admin/structure/guideline/{guideline}/revisions/{guideline_revision}/revert/{langcode}",
 *     "collection" = "/admin/structure/guideline",
 *   },
 *   bundle_entity_type = "guideline_type",
 *   field_ui_base_route = "entity.guideline_type.edit_form"
 * )
 */
class Guideline extends EditorialContentEntityBase implements GuidelineInterface {

  use EntityChangedTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage_controller, array &$values) {
    parent::preCreate($storage_controller, $values);
    $values += [
      'user_id' => \Drupal::currentUser()->id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function urlRouteParameters($rel) {
    $uri_route_parameters = parent::urlRouteParameters($rel);

    if ($rel === 'revision_revert' && $this instanceof RevisionableInterface) {
      $uri_route_parameters[$this->getEntityTypeId() . '_revision'] = $this->getRevisionId();
    }
    elseif ($rel === 'revision_delete' && $this instanceof RevisionableInterface) {
      $uri_route_parameters[$this->getEntityTypeId() . '_revision'] = $this->getRevisionId();
    }

    return $uri_route_parameters;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    foreach (array_keys($this->getTranslationLanguages()) as $langcode) {
      $translation = $this->getTranslation($langcode);

      // If no owner has been set explicitly, make the anonymous user the owner.
      if (!$translation->getOwner()) {
        $translation->setOwnerId(0);
      }
    }

    // If no revision author has been set explicitly,
    // make the guideline owner the revision author.
    if (!$this->getRevisionUser()) {
      $this->setRevisionUserId($this->getOwnerId());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->get('name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setName($name) {
    $this->set('name', $name);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    return $this->get('user_id')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    return $this->get('user_id')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    $this->set('user_id', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account) {
    $this->set('user_id', $account->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight() {
    return $this->get('weight')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setWeight($weight) {
    $this->set('weight', $weight);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getParents() {
    return $this->get('parent')->referencedEntities();
  }

  /**
   * {@inheritdoc}
   */
  public function getParentIds() {
    $ids = [];
    foreach ($this->get('parent')->referencedEntities() as $parent) {
      $ids[] = $parent->id();
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function setParents($parent) {
    $this->set('parent', $parent);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Add the published field.
    $fields += static::publishedBaseFieldDefinitions($entity_type);

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Authored by'))
      ->setDescription(t('The user ID of author of the Guideline entity.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'author',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('The name of the Guideline entity.'))
      ->setRevisionable(TRUE)
      ->setSettings([
        'max_length' => 250,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setRequired(TRUE);

    $fields['status']->setDescription(t('A boolean indicating whether the Guideline is published.'))
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => -3,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    $fields['revision_translation_affected'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Revision translation affected'))
      ->setDescription(t('Indicates if the last edit of a translation belongs to current revision.'))
      ->setReadOnly(TRUE)
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Weight'))
      ->setDescription(t('The weight of this guideline in relation to other guidelines.'))
      ->setDefaultValue(0);

    $fields['parent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Guideline parents'))
      ->setDescription(t('The parents of this term.'))
      ->setSetting('target_type', 'guideline')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function loadByEntity($entity) {
    $entity_type_repository = \Drupal::service('entity_type.repository');
    $entity_type_manager = \Drupal::entityTypeManager();
    $storage = $entity_type_manager->getStorage($entity_type_repository->getEntityTypeFromClass(static::class));

    $query = $storage->getQuery();
    $query->condition('field_field', $entity . '.', 'STARTS_WITH');

    $result = $query->execute();
    return $result ? $storage->loadMultiple($result) : [];
  }

}
