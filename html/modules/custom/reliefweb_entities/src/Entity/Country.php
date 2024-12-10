<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_entities\SectionedContentInterface;
use Drupal\reliefweb_entities\SectionedContentTrait;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;
use Drupal\taxonomy\Entity\Term;

/**
 * Bundle class for country terms.
 */
class Country extends Term implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, SectionedContentInterface {

  use EntityModeratedTrait;
  use EntityRevisionedTrait;
  use SectionedContentTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return 'countries';
  }

  /**
   * {@inheritdoc}
   */
  public static function addFieldConstraints(&$fields) {
    // No specific constraints.
  }

  /**
   * {@inheritdoc}
   */
  public function getPageContent() {
    $sections = $this->getPageSections();
    $contents = $this->getPageTableOfContents();

    // Section label overrides.
    $labels = [
      'disasters' => $this->t('Alert and Ongoing Disasters'),
    ];

    // Consolidate sections, removing empty ones.
    return $this->consolidateSections($contents, $sections, $labels);
  }

  /**
   * {@inheritdoc}
   */
  public function getPageSections() {
    $sections = [];

    $queries = [];

    // Profile sections. Only display if show profile is selected.
    if (!empty($this->field_profile->value)) {
      $sections['overview'] = $this->getEntityDescription('overview');
      $sections['useful-links'] = $this->getUsefulLinksSection();

      // Retrieve the Key Content and Appeals and Response Plans.
      $queries['key-content'] = $this->getKeyContentApiQuery();
      $queries['appeals-response-plans'] = $this->getAppealsResponsePlansApiQuery();
    }

    // Get data from the API.
    // @todo move those the Reports etc. river services.
    $queries += [
      'most-read' => $this->getMostReadApiQuery(),
      'updates' => $this->getLatestUpdatesApiQuery(),
      'maps-infographics' => $this->getLatestMapsInfographicsApiQuery(),
      'disasters' => $this->getLatestDisastersApiQuery(),
      'jobs' => $this->getLatestJobsApiQuery(),
      'training' => $this->getLatestTrainingApiQuery(),
    ];

    $sections += $this->getSectionsFromReliefWebApiQueries($queries);

    return $sections;
  }

  /**
   * {@inheritdoc}
   */
  public function getPageTableOfContents() {
    return [
      'latest' => [
        'title' => $this->t('Latest'),
        'sections' => [
          'digital-sitrep' => $this->t('OCHA Situation Report'),
          'overview' => $this->t('In Focus'),
          'key-content' => $this->t('Key Content'),
          'updates' => $this->t('Latest Updates'),
          'maps-infographics' => $this->t('Maps and Infographics'),
          'most-read' => $this->t('Most Read'),
        ],
      ],
      'overview' => [
        'title' => $this->t('Overview'),
        'sections' => [
          'appeals-response-plans' => $this->t('Appeals and Response Plans'),
          'disasters' => $this->t('Disasters'),
        ],
      ],
      'resources' => [
        'title' => $this->t('Other Resources'),
        'sections' => [
          'useful-links' => $this->t('Useful Links'),
          'jobs' => $this->t('Jobs'),
          'training' => $this->t('Training'),
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultModerationStatus() {
    return 'normal';
  }

}
