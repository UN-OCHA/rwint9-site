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
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\reliefweb_utility\Helpers\ReliefWebStateHelper;
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
        ], $this->field_cost->value === 'free' ? $this->t('Free') : $this->t('Fee-based'), TRUE),
      ];
    }

    return [
      'posted' => static::createDate($this->getCreatedTime()),
      'registration' => static::createDate($this->field_registration_deadline->value),
      'start' => static::createDate($this->field_training_date->value),
      'end' => static::createDate($this->field_training_date->end_value),
      'event_url' => $event_url,
      'country' => $this->getEntityMetaFromField('country'),
      'source' => $this->getEntityMetaFromField('source'),
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

    // Update the entity status based on the source(s) moderation status.
    $this->updateModerationStatusFromSourceStatus();

    // Update the entity status based on the expiration date.
    $this->updateModerationStatusFromExpirationDate();

    // Update the creation date when published for the first time so that
    // the opportunity can appear at the top of the opportunity river.
    $this->updateDateWhenPublished();

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

  /**
   * {@inheritdoc}
   */
  public function hasExpired() {
    if ($this->isOngoing()) {
      return FALSE;
    }
    $timestamp = DateHelper::getDateTimeStamp($this->field_registration_deadline->value);
    return empty($timestamp) || ($timestamp < gmmktime(0, 0, 0));
  }

  /**
   * Check if the training is an ongoing training (no dates).
   *
   * @return bool
   *   TRUE if the training is ongoing.
   */
  public function isOngoing() {
    return empty($this->field_registration_deadline->value) && empty($this->field_training_date->value);
  }

  /**
   * Get the list of themes that are irrelevant for training ads.
   *
   * @return array
   *   List of term ids.
   */
  public static function getTrainingIrrelevantThemes() {
    return ReliefWebStateHelper::getTrainingIrrelevantThemes();
  }

  /**
   * Get the list of languages that are irrelevant for training ads.
   *
   * @return array
   *   List of term ids.
   */
  public static function getTrainingIrrelevantLanguages() {
    return ReliefWebStateHelper::getTrainingIrrelevantLanguages();
  }

  /**
   * Get the list of training languages that are irrelevant for training ads.
   *
   * @return array
   *   List of term ids.
   */
  public static function getTrainingIrrelevantTrainingLanguages() {
    return ReliefWebStateHelper::getTrainingIrrelevantTrainingLanguages();
  }

}
