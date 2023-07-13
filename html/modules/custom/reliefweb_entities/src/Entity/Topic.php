<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_entities\DocumentInterface;
use Drupal\reliefweb_entities\DocumentTrait;
use Drupal\reliefweb_entities\SectionedContentInterface;
use Drupal\reliefweb_entities\SectionedContentTrait;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\MediaHelper;

/**
 * Bundle class for topic nodes.
 */
class Topic extends Node implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, DocumentInterface, SectionedContentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
  use EntityRevisionedTrait;
  use SectionedContentTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return 'topic';
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
  public function getEntityMeta() {
    return [
      'posted' => $this->createDate($this->getCreatedTime()),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getPageContent() {
    $sections = $this->getPageSections();
    $contents = $this->getPageTableOfContents();

    // We do a bit of gymnastic to add the sections not already assigned in the
    // table of contents to its sections' sections. We need to do that because
    // the indices of those sections are created in ::getPageSections().
    $assigned = [];
    foreach ($contents as $group) {
      foreach ($group['sections'] as $name => $label) {
        if (isset($sections[$name])) {
          $assigned[$name] = TRUE;
        }
      }
    }
    $sections_sections = [];
    foreach ($sections as $index => $section) {
      if (!isset($assigned[$index]) && !empty($section['#title'])) {
        $sections_sections[$index] = $section['#title'];
      }
    }
    // Put those sections before any other sections already there.
    $contents['sections']['sections'] = $sections_sections + $contents['sections']['sections'];

    // Consolidate sections, removing empty ones.
    return $this->consolidateSections($contents, $sections);
  }

  /**
   * {@inheritdoc}
   */
  public function getPageSections() {
    $sections = [];

    // Text sections.
    $sections['introduction'] = $this->getEntityTextField('body', 'introduction', $this->t('Introduction'));
    $sections['overview'] = $this->getEntityTextField('field_overview', 'overview', $this->t('Overview'));
    $sections['resources'] = $this->getEntityTextField('field_resources', 'resources', $this->t('Resources'));

    // River link data.
    $section_links = [];

    // River info for the search fields.
    $rivers = [
      'reports' => [
        'title' => $this->t('Latest Updates'),
      ],
      'jobs' => [
        'title' => $this->t('Jobs'),
      ],
      'training' => [
        'title' => $this->t('Training'),
      ],
      'disasters' => [
        'title' => $this->t('Disasters'),
      ],
    ];

    // Append searches to section links.
    foreach ($rivers as $river => $data) {
      $url = $this->get('field_' . $river . '_search')->url;
      if (!empty($url)) {
        $section_links[$river] = $data + ['url' => $url];
      }
    }

    // Parse the sections field.
    foreach ($this->get('field_sections') as $delta => $item) {
      if ($item->isEmpty()) {
        continue;
      }

      $title = $item->title;
      $view = '';
      $limit = 3;
      $exclude = [];

      // Not using Html::getUniqueId() on purpose as it may return a random ID
      // in some future update of Drupal core.
      // @see \Drupal\Component\Utility\Html::getUniqueId()
      $index = Html::getId($title);
      $parts = explode('-', $index);

      // Try to consolidate some special sections across the countries,
      // disasters, organizations and topics.
      if (in_array('maps', $parts) || in_array('infographics', $parts)) {
        if (!isset($section_links['maps-infographics'])) {
          $index = 'maps-infographics';
          $title = $this->t('Maps and Infographics');
          $view = 'maps';
          $exclude = ['summary', 'format'];
        }
      }
      elseif (in_array('appeals', $parts)) {
        if (!isset($section_links['appeals-response-plans'])) {
          $index = 'appeals-response-plans';
          $title = $this->t('Appeals and Response Plans');
          $exclude = ['summary', 'format'];
          $limit = 4;
        }
      }

      // Avoid ID clash with the other sections.
      if (array_key_exists($index, $sections) || array_key_exists($index, $section_links)) {
        $index .= '-' . $delta;
      }

      $section_links[$index] = [
        'url' => $item->url,
        'title' => $title,
        'override' => $item->override,
        'exclude' => $exclude,
        'view' => $view,
        'limit' => $limit,
      ];
    }

    // Prepare the API queries.
    $queries = [];
    foreach ($section_links as $index => $section_link) {
      $queries[$index] = $this->riverUrlToApi($section_link);
    }

    $sections += $this->getSectionsFromReliefWebApiQueries($queries);

    return $sections;
  }

  /**
   * {@inheritdoc}
   */
  public function getPageTableOfContents() {
    return [
      'information' => [
        'title' => $this->t('Overview'),
        'sections' => [
          'introduction' => $this->t('Introduction'),
          'overview' => $this->t('Overview'),
        ],
      ],
      'sections' => [
        'title' => $this->t('Sections'),
        'sections' => [
          'reports' => $this->t('Latest Updates'),
        ],
      ],
      'related' => [
        'title' => $this->t('Related Content'),
        'sections' => [
          'jobs' => $this->t('Jobs'),
          'training' => $this->t('Training'),
          'disasters' => $this->t('Disasters'),
          'resources' => $this->t('Resources'),
        ],
      ],
    ];
  }

  /**
   * Get the topic icon.
   *
   * @return array
   *   Image information with uri, width, height, alt and copyright.
   */
  public function getIcon() {
    if ($this->field_icon->isEmpty()) {
      return [];
    }
    return MediaHelper::getImage($this->field_icon);
  }

  /**
   * Convert a river URL to API payload.
   *
   * @param array $data
   *   Array with the river URL, title and optional override, limit, view
   *   and exclude properties.
   * @param int $limit
   *   Number of resources to retrieve.
   *
   * @return array
   *   Array with the API resource for the river, the entity bundle for the
   *   river, the view for the river (ex: maps), the API payload and the
   *   view more link (given URL).
   *
   * @todo modify after refactoring the river services: RW-143.
   */
  protected function riverUrlToApi(array $data, $limit = 3) {
    if (empty($data['url']) || empty($data['title'])) {
      return [];
    }

    $url = $data['url'];
    $title = $data['title'];
    $override = $data['override'] ?? NULL;
    $limit = $data['limit'] ?? $limit;

    $service = RiverServiceBase::getRiverServiceFromUrl($url);
    if (empty($service)) {
      return [];
    }

    // Update the river view.
    if (isset($data['view'])) {
      $service->setSelectedView($data['view']);
    }

    // Get the API payload ready for the API request.
    $payload = $service->prepareApiRequest($limit, FALSE);

    // If an override is defined we add a search condition on the override ID
    // with a boost and sort by score first. This will results in the override
    // document to appear first if it exists and keep the rest of the documents
    // in the proper order.
    if (!empty($override) && is_numeric($override)) {
      if (!empty($payload['query']['value'])) {
        $payload['query']['value'] = 'id:' . $override . ' OR (' . $payload['query']['value'] . ')';
      }
      else {
        $payload['query']['value'] = 'id:' . $override . '^1000 OR id:>0';
      }
      array_unshift($payload['sort'], 'score:desc');
    }

    return [
      'resource' => $service->getResource(),
      'bundle' => $service->getBundle(),
      'entity_type' => $service->getEntityTypeId(),
      'river' => $service->getRiver(),
      'title' => $title,
      'view' => $service->getSelectedView(),
      'payload' => $payload,
      'exclude' => $data['exclude'] ?? [],
      // Create a sanitized version of the given URL for the view more.
      'more' => [
        'url' => RiverServiceBase::getRiverUrl(
          $service->getBundle(),
          $service->getParameters()->getAllSorted(['list'])
          // @todo after reviewing the impact of adding the list parameter to
          // river URL we may want to revisit what to do with the ones used
          // in topics. For now no title because it's too random.
          // @see RW-700, RW-706
        ),
        'label' => $this->t('View all @title', [
          '@title' => $title,
        ]),
      ],
    ];
  }

}
