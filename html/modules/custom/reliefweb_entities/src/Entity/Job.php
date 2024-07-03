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
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\reliefweb_utility\Helpers\ReliefWebStateHelper;

/**
 * Bundle class for job nodes.
 */
class Job extends Node implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, DocumentInterface, OpportunityDocumentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
  use EntityRevisionedTrait;
  use OpportunityDocumentTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return 'jobs';
  }

  /**
   * {@inheritdoc}
   */
  public static function addFieldConstraints(&$fields) {
    // The city field cannot have a value if the country field is empty.
    $fields['field_city']->addConstraint('EmptyIfOtherFieldEmpty', [
      'otherFieldName' => 'field_country',
    ]);

    // Restrict the city length if not empty.
    $fields['field_city']->addConstraint('TextLengthWithinRange', [
      'skipIfEmpty' => TRUE,
      'min' => 3,
      'max' => 255,
    ]);

    // Closing date cannot be in the past.
    // @todo remove this constraint and add it to the form only as we want to
    // be able to save expired jobs for example when importing.
    $fields['field_job_closing_date']->addConstraint('DateNotInPast', [
      'statuses' => ['pending', 'published'],
      'permission' => 'edit any job content',
    ]);

    // Limit to 3 themes max.
    $fields['field_theme']->addConstraint('MaxNumberOfValues', [
      'max' => 3,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityMeta() {
    return [
      'posted' => $this->createDate($this->getCreatedTime()),
      'closing' => $this->createDate($this->field_job_closing_date->value),
      'country' => $this->getEntityMetaFromField('country'),
      'source' => $this->getEntityMetaFromField('source'),
      'city' => $this->field_city->value ?? '',
      'type' => $this->getEntityMetaFromField('job_type', 'TY'),
      'career_category' => $this->getEntityMetaFromField('career_categories', 'CC'),
      'experience' => $this->getEntityMetaFromField('job_experience', 'E'),
      'theme' => $this->getEntityMetaFromField('theme', 'T'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    // Remove the city field if there is no country.
    if ($this->hasField('field_country') && $this->get('field_country')->isEmpty()) {
      $this->set('field_city', []);
    }

    // Remove the themes for certain career categories.
    if ($this->hasField('field_career_categories') && !$this->get('field_career_categories')->isEmpty()) {
      $themeless = static::getJobThemelessCategories();
      foreach ($this->get('field_career_categories') as $item) {
        if (!empty($item->target_id) && in_array($item->target_id, $themeless)) {
          $this->set('field_theme', []);
          break;
        }
      }
    }

    // Convert the old "N/A" value for job experience to "0-2 years".
    if ($this->hasField('field_job_experience') && !$this->get('field_job_experience')->isEmpty()) {
      // Normally only 1 value is allowed.
      if (isset($this->get('field_job_experience')->first()->target_id) && $this->get('field_job_experience')->first()->target_id == 262) {
        $this->set('field_job_experience', 258);
      }
    }

    // Remove irrelevant themes and limit to 3.
    if ($this->hasField('field_theme') && !$this->get('field_theme')->isEmpty()) {
      $irrelevant_themes = static::getJobIrrelevantThemes();
      $count = 0;
      $themes = [];
      foreach ($this->get('field_theme') as $item) {
        if (!empty($item->target_id) && !in_array($item->target_id, $irrelevant_themes) && $count < 3) {
          $themes[$count++] = $item->target_id;
        }
      }
      $this->set('field_theme', $themes);
    }

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
    $timestamp = DateHelper::getDateTimeStamp($this->field_job_closing_date->value);
    return empty($timestamp) || ($timestamp < gmmktime(0, 0, 0));
  }

  /**
   * Get the list of countries that are irrelevant for jobs.
   *
   * @return array
   *   List of theme term ids.
   */
  public static function getJobIrrelevantCountries() {
    return ReliefWebStateHelper::getJobIrrelevantCountries();
  }

  /**
   * Get the list of themes that are irrelevant for jobs.
   *
   * @return array
   *   List of theme term ids.
   */
  public static function getJobIrrelevantThemes() {
    return ReliefWebStateHelper::getJobIrrelevantThemes();
  }

  /**
   * Get the list of job categories for which themes are irrelevant.
   *
   * @return array
   *   List of theme term ids.
   */
  public static function getJobThemelessCategories() {
    return ReliefWebStateHelper::getJobThemelessCategories();
  }

}
