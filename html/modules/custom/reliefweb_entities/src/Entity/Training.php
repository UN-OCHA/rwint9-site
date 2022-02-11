<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_entities\DocumentInterface;
use Drupal\reliefweb_entities\DocumentTrait;
use Drupal\reliefweb_entities\OpportunityDocumentInterface;
use Drupal\reliefweb_entities\OpportunityDocumentTrait;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Bundle class for training nodes.
 */
class Training extends Node implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, DocumentInterface, OpportunityDocumentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
  use EntityRevisionedTrait;
  use OpportunityDocumentTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return 'training';
  }

  /**
   * {@inheritdoc}
   */
  public static function addFieldConstraints(&$fields) {
    // The training end date cannot be before the start date.
    $fields['field_training_date']->addConstraint('DateEndAfterStart');

    // The training dates cannot be in the past.
    $fields['field_training_date']->addConstraint('DateNotInPast', [
      'statuses' => ['pending', 'published'],
      'permission' => 'edit any training content',
    ]);

    // The registration deadline cannot be in the past.
    $fields['field_registration_deadline']->addConstraint('DateNotInPast', [
      'statuses' => ['pending', 'published'],
      'permission' => 'edit any training content',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityMeta() {
    // Event URL.
    $event_url = $this->field_link->uri;
    if (!UrlHelper::isValid($event_url, TRUE)) {
      $event_url = '';
    }
    else {
      $event_url = UrlHelper::encodeUrl($event_url);
    }

    // Cost.
    $cost = [];
    if (!$this->field_cost->isEmpty()) {
      $cost[] = [
        'name' => $this->field_cost->value,
        'url' => RiverServiceBase::getRiverUrl($this->bundle(), [
          'advanced-search' => '(CO' . $this->field_cost->value . ')',
        ]),
      ];
    }

    return [
      'posted' => static::createDate($this->getCreatedTime()),
      'registration' => static::createDate($this->field_registration_deadline->value),
      'start' => static::createDate($this->field_training_date->value),
      'end' => static::createDate($this->field_training_date->end_value),
      'event_url' => $event_url,
      'country' => $this->getEntityMetaFromField('country', 'C'),
      'source' => $this->getEntityMetaFromField('source', 'S'),
      'city' => $this->field_city->value ?? '',
      'format' => $this->getEntityMetaFromField('training_format', 'F'),
      'category' => $this->getEntityMetaFromField('training_type', 'TY'),
      'professional_function' => $this->getEntityMetaFromField('career_categories', 'CC'),
      'theme' => $this->getEntityMetaFromField('theme', 'T'),
      'training_language' => $this->getEntityMetaFromField('training_language', 'TL'),
      'cost' => $cost,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    // Update the entity status based on the user posting rights.
    $this->updateModerationStatusFromPostingRights();

    // Update the entity statys based on the source(s) moderation status.
    $this->updateModerationStatusFromSourceStatus();

    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);

    // Make the sources active.
    $this->updateSourceModerationStatus();
  }

}
