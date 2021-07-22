<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_entities\EntityModeratedInterface;
use Drupal\reliefweb_entities\EntityModeratedTrait;
use Drupal\reliefweb_entities\SectionedContentInterface;
use Drupal\reliefweb_entities\SectionedContentTrait;
use Drupal\taxonomy\Entity\Term;

/**
 * Bundle class for country terms.
 */
class Country extends Term implements BundleEntityInterface, EntityModeratedInterface, SectionedContentInterface {

  use EntityModeratedTrait;
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
    $sections['digital-sitrep'] = $this->getDigitalSitrepSection();
    $sections['key-figures'] = $this->getKeyFiguresSection();

    $queries = [];

    // Profile sections. Only display if show profile is selected.
    if (!empty($this->field_profile->value)) {
      $sections['overview'] = $this->getEntityDescription();
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
          'key-figures' => $this->t('Key Figures'),
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
   * Get the Digital Situation Report for the country.
   *
   * @return array
   *   Render array for the Digital Situation Report section.
   */
  protected function getDigitalSitrepSection() {
    $client = \Drupal::service('reliefweb_dsr.client');
    $iso3 = $this->field_iso3->value;
    $ongoing = $this->getModerationStatus() === 'ongoing';

    return $client->getDigitalSitrepBuild($iso3, $ongoing);
  }

  /**
   * Get the ReliefWeb key figures for the country.
   *
   * @return array
   *   Render array for the Key Figures section.
   */
  protected function getKeyFiguresSection() {
    $client = \Drupal::service('reliefweb_key_figures.client');
    $iso3 = $this->field_iso3->value;

    return $client->getKeyFiguresBuild($iso3, $this->label());
  }

}
