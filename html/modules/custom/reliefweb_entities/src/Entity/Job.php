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
    // Restrict body length.
    $fields['body']->addConstraint('TextLengthWithinRange', [
      'min' => 400,
      'max' => 50000,
    ]);

    // Restrict how to apply length.
    $fields['field_how_to_apply']->addConstraint('TextLengthWithinRange', [
      'min' => 100,
      'max' => 10000,
    ]);

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
      'country' => $this->getEntityMetaFromField('country', 'C'),
      'source' => $this->getEntityMetaFromField('source', 'S'),
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
      if ($this->get('field_job_experience')->first()->target_id == 262) {
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
   * Get the list of job categories for which themes are irrelevant.
   *
   * @return array
   *   List of theme term ids.
   */
  public static function getJobIrrelevantCountries() {
    // Irrelevant countries (Trello #DI9bxljg):
    // - World (254).
    $default = [254];
    return \Drupal::state()->get('reliefweb_job_irrelevant_countries', $default);
  }

  /**
   * Get the list of job categories for which themes are irrelevant.
   *
   * @return array
   *   List of theme term ids.
   */
  public static function getJobIrrelevantThemes() {
    // Irrelevant career categories (Trello #RfWgIdwA):
    // - Contributions (4589) (Collab #2327).
    // - Humanitarian Financing (4597) (Trello #OnXq5cCC).
    // - Logistics and Telecommunications (4598) (Trello #G3YgNUF6).
    $default = [4589, 4597, 4598];
    return \Drupal::state()->get('reliefweb_job_irrelevant_themes', $default);
  }

  /**
   * Get the list of job categories for which themes are irrelevant.
   *
   * @return array
   *   List of theme term ids.
   */
  public static function getJobThemelessCategories() {
    // Disable the themes for some career categories (Trello #RfWgIdwA):
    // - Human Resources (6863).
    // - Administration/Finance (6864).
    // - Information and Communications Technology (6866).
    // - Donor Relations/Grants Management (20966).
    $default = [6863, 6864, 6866, 20966];
    return \Drupal::state()->get('reliefweb_job_themeless_categories', $default);
  }

}
