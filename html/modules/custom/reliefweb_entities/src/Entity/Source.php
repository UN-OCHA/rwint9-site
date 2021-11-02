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
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drupal\taxonomy\Entity\Term;

/**
 * Bundle class for source terms.
 */
class Source extends Term implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, SectionedContentInterface {

  use EntityModeratedTrait;
  use EntityRevisionedTrait;
  use SectionedContentTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return 'sources';
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
    $labels = [];

    // Consolidate sections, removing empty ones.
    return $this->consolidateSections($contents, $sections, $labels);
  }

  /**
   * {@inheritdoc}
   */
  public function getPageSections() {
    $sections = [];
    $sections['description'] = $this->getEntityDescription('description');
    $sections['organization-details'] = $this->getOrganizationDetails();
    $sections['social-media-links'] = $this->getOrganizationSocialMediaLinks();

    // Get data from the API.
    // @todo move those the Reports etc. river services.
    $queries = [
      'updates' => $this->getLatestUpdatesApiQuery('S'),
      'jobs' => $this->getLatestJobsApiQuery('S'),
      'training' => $this->getLatestTrainingApiQuery('S'),
    ];

    $sections += $this->getSectionsFromReliefWebApiQueries($queries);

    return $sections;
  }

  /**
   * {@inheritdoc}
   */
  public function getPageTableOfContents() {
    return [
      'information' => [
        'title' => $this->t('Information'),
        'sections' => [
          'description' => $this->t('Description'),
          'organization-details' => $this->t('Details'),
          'social-media-links' => $this->t('Social Media'),
        ],
      ],
      'latest' => [
        'title' => $this->t('Latest'),
        'sections' => [
          'updates' => $this->t('Updates'),
          'jobs' => $this->t('Jobs'),
          'training' => $this->t('Training'),
        ],
      ],
    ];
  }

  /**
   * Get the organization meta information (type, homepage etc.).
   *
   * @return array
   *   Render array with the organization details.
   */
  protected function getOrganizationDetails() {
    $meta = [];

    // Organization type.
    $type = $this->field_organization_type->entity->label();
    $meta['type'] = [
      'type' => 'link',
      'label' => $this->t('Organization type'),
      'value' => [
        'url' => RiverServiceBase::getRiverUrl('source', [
          'search' => 'type.exact:"' . $type . '"',
        ]),
        'title' => $type,
        'external' => FALSE,
      ],
    ];

    // Headquarters.
    $countries = [];
    foreach ($this->field_country as $item) {
      $name = $item->entity->label();
      $countries[] = [
        'url' => RiverServiceBase::getRiverUrl('source', [
          'search' => 'country.exact:"' . $name . '"',
        ]),
        'name' => $name,
      ];
    }
    $meta['headquarters'] = [
      'type' => 'taglist',
      'label' => $this->t('Headquarters'),
      'value' => $countries,
      'count' => NULL,
      'sort' => 'name',
    ];

    // Homepage (optional field).
    if (!$this->field_homepage->isEmpty()) {
      $homepage = UrlHelper::encodeUrl($this->field_homepage->uri);
      $meta['homepage'] = [
        'type' => 'link',
        'label' => $this->t('Homepage'),
        'value' => [
          'url' => $homepage,
          'title' => $homepage,
          'external' => TRUE,
        ],
      ];
    }

    return [
      '#theme' => 'reliefweb_entities_entity_details',
      '#meta' => $meta,
    ];
  }

  /**
   * Get the organization social media links.
   *
   * @return array
   *   Render array with the list of social media links.
   */
  protected function getOrganizationSocialMediaLinks() {
    $links = [];

    if (!$this->field_links->isEmpty()) {
      // Get the list of allowed social media.
      $allowed = \Drupal::config('reliefweb_entities.settings')
        ->get('allowed_social_media_links', []);

      // Prepare the links.
      foreach ($this->field_links as $link) {
        $host = parse_url($link->uri, PHP_URL_HOST);
        foreach ($allowed as $key => $name) {
          if ($host && mb_strpos($host, $key) !== FALSE) {
            $links[$key] = [
              'url' => UrlHelper::encodeUrl($link->uri),
              'title' => $name,
            ];
            break;
          }
        }
      }
    }

    if (!empty($links)) {
      return [
        '#theme' => 'reliefweb_entities_entity_social_media_links',
        '#links' => $links,
      ];
    }

    return [];
  }

}
