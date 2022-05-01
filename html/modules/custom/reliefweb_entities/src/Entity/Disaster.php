<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_entities\SectionedContentInterface;
use Drupal\reliefweb_entities\SectionedContentTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\LocalizationHelper;
use Drupal\taxonomy\Entity\Term;

/**
 * Bundle class for disaster terms.
 */
class Disaster extends Term implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, SectionedContentInterface {

  use EntityModeratedTrait;
  use EntityRevisionedTrait;
  use SectionedContentTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return 'disasters';
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

    // Sort the countries by alpha.
    if (!empty($sections['countries']['#entities'])) {
      LocalizationHelper::collatedSort($sections['countries']['#entities'], 'title');
    }

    // Section label overrides.
    $labels = [];

    // Set the label for the disaster section based on the number of disasters.
    if (!empty($sections['disasters']['#entities'])) {
      $labels['disasters'] = $this->formatPlural(
        count($sections['disasters']['#entities']),
        'Other disasters affecting the country',
        'Other disasters affecting the countries'
      );
    }

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
      'countries' => $this->getAffectedCountriesApiQuery(),
      'most-read' => $this->getMostReadApiQuery('D'),
      'updates' => $this->getLatestUpdatesApiQuery('D'),
      'maps-infographics' => $this->getLatestMapsInfographicsApiQuery('D'),
      'disasters' => $this->getRelatedDisastersApiQuery(),
    ];

    $sections += $this->getSectionsFromReliefWebApiQueries($queries);

    return $sections;
  }

  /**
   * {@inheritdoc}
   */
  public function getPageTableOfContents() {
    return [
      'overview' => [
        'title' => $this->t('Overview'),
        'sections' => [
          'overview' => $this->t('Disaster description'),
          'countries' => $this->t('Affected Countries'),
          'appeals-response-plans' => $this->t('Appeals and Response Plans'),
        ],
      ],
      'latest' => [
        'title' => $this->t('Latest'),
        'sections' => [
          'key-content' => $this->t('Key Content'),
          'updates' => $this->t('Latest Updates'),
          'maps-infographics' => $this->t('Maps and Infographics'),
          'most-read' => $this->t('Most Read'),
        ],
      ],
      'resources' => [
        'title' => $this->t('Other'),
        'sections' => [
          'disasters' => $this->t('Related Disasters'),
          'useful-links' => $this->t('Useful Links'),
        ],
      ],
    ];
  }

  /**
   * Get payload for the disaster's affected countries.
   *
   * @return array
   *   List of affected countries with their name, coordinates and url.
   */
  public function getAffectedCountriesApiQuery() {
    $country_ids = [];
    /** @var \Drupal\Core\Field\FieldItemInterface $item */
    foreach ($this->field_country as $item) {
      $country_ids[] = $item->target_id;
    }

    $payload = [
      'fields' => [
        // Rather than including only the fields we are interested in, we need
        // to include everything and exclude the fields we don't want to
        // retrieve because the "location" field, while stored, is not in the
        // list of recognized fields in the country field definitions.
        // @todo change if/when the API is updated to recognize the location.
        'include' => [
          '*',
        ],
        'exclude' => [
          'description',
          'description-html',
          'profile',
          'url',
        ],
      ],
      'filter' => [
        'field' => 'id',
        'value' => $country_ids,
      ],
      // Ensure we retrieve all the countries for the disaster.
      'limit' => 1000,
    ];

    return [
      'resource' => 'countries',
      'bundle' => 'country',
      'entity_type' => 'taxonomy_term',
      'payload' => $payload,
    ];
  }

  /**
   * Get payload for related alert and ongoing disasters.
   *
   * @return array
   *   Query data with the API resource and payload, a callback to parse the
   *   API data and a url to the corresponding river.
   */
  public function getRelatedDisastersApiQuery() {
    $entity_id = $this->id();

    $country_ids = [];
    /** @var \Drupal\Core\Field\FieldItemInterface $item */
    foreach ($this->field_country as $item) {
      $country_ids[] = $item->target_id;
    }

    $payload = RiverServiceBase::getRiverApiPayload('disaster');
    $payload['filter'] = [
      'conditions' => [
        // Exclude the current disaster.
        [
          'field' => 'id',
          'value' => $entity_id,
          'negate' => TRUE,
        ],
        // Include all disasters affecting the same countries.
        [
          'field' => 'country.id',
          'value' => $country_ids,
        ],
        // Only retrieve alert and ongoing disasters.
        [
          'field' => 'status',
          // For legacy purpose we add both current and ongoing.
          'value' => ['alert', 'current', 'ongoing'],
        ],
      ],
      'operator' => 'AND',
    ];
    // High limit to ensure we get all of them.
    $payload['limit'] = 100;

    return [
      'resource' => 'disasters',
      'bundle' => 'disaster',
      'entity_type' => 'taxonomy_term',
      'payload' => $payload,
    ];
  }

}
