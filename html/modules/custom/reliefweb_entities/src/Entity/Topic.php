<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_entities\DocumentInterface;
use Drupal\reliefweb_entities\DocumentTrait;
use Drupal\reliefweb_entities\EntityModeratedInterface;
use Drupal\reliefweb_entities\EntityModeratedTrait;
use Drupal\reliefweb_entities\SectionedContentInterface;
use Drupal\reliefweb_entities\SectionedContentTrait;
use Drupal\reliefweb_rivers\AdvancedSearch;
use Drupal\reliefweb_rivers\Parameters;
use Drupal\reliefweb_rivers\RiverServiceBase;

/**
 * Bundle class for topic nodes.
 */
class Topic extends Node implements BundleEntityInterface, EntityModeratedInterface, DocumentInterface, SectionedContentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
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

    if (!$this->hasField('field_sections')) {
      return $sections;
    }

    $sections['introduction'] = $this->getEntityTextField('body');
    $sections['overview'] = $this->getEntityTextField('field_overview');
    $sections['resources'] = $this->getEntityTextField('field_resources');

    $queries = [];
    $section_links = $this->get('field_sections');

    // Append searches to section links.
    $rivers = [
      'reports' => $this->t('Latest Updates'),
      'jobs' => $this->t('Jobs'),
      'training' => $this->t('Training'),
      'disasters' => $this->t('Disasters'),
    ];

    foreach ($rivers as $river => $title) {
      $url = $this->get('field_' . $river . '_search')->url;
      if (!empty($url)) {
        $section_links[] = [
          'title' => $title,
          'url' => $url,
        ];
      }
    }

    foreach ($section_links as $index => $section_link) {
      $queries[$index] = $this->riverUrlToApi($section_link->url);
    }

    $sections += $this->getSectionsFromReliefWebApiQueries($queries);

    return $sections;
  }

  /**
   * {@inheritdoc}
   */
  public function getPageTableOfContents() {
    if (!$this->hasField('field_sections')) {
      return [];
    }

    // Table of contents.
    $toc = [
      'information' => [
        'title' => $this->t('Overview'),
        'sections' => [
          'introduction' => $this->t('Introduction'),
          'overview' => $this->t('Overview'),
        ],
      ],
      'sections' => [
        'title' => $this->t('Sections'),
        'sections' => [],
      ],
      'related' => [
        'title' => $this->t('Related Content'),
        'sections' => [
          'resources' => $this->t('Resources'),
        ],
      ],
    ];

    $sections['introduction'] = $this->getEntityTextField('body');
    $sections['overview'] = $this->getEntityTextField('field_overview');
    $sections['resources'] = $this->getEntityTextField('field_resources');

    $queries = [];
    $section_links = $this->get('field_sections');

    // Append searches to section links.
    $rivers = [
      'reports' => $this->t('Latest Updates'),
      'jobs' => $this->t('Jobs'),
      'training' => $this->t('Training'),
      'disasters' => $this->t('Disasters'),
    ];

    foreach ($rivers as $river => $title) {
      $url = $this->get('field_' . $river . '_search')->url;
      if (!empty($url)) {
        $section_links[] = [
          'title' => $title,
          'url' => $url,
        ];
      }
    }

    foreach ($section_links as $index => $section_link) {
      $queries[$index] = $this->riverUrlToApi($section_link->url);
      $toc['sections']['sections'][$index] = $section_link->title;
    }

    return $toc;
  }

  /**
   * Convert a river URL to API payload.
   */
  protected function riverUrlToApi($url) {
    $mapping = [
      'updates' => [
        'bundle' => 'report',
        'resource' => 'reports',
      ],
      // Legacy path.
      'maps' => [
        'resource' => 'reports',
        'bundle' => 'report',
        'river' => 'updates',
        'view' => 'maps',
      ],
      'jobs' => [
        'resource' => 'jobs',
        'bundle' => 'job',
      ],
      'training' => [
        'resource' => 'training',
        'bundle' => 'training',
      ],
    ];

    $parts = parse_url($url);
    if ($parts === FALSE) {
      return;
    }

    // Strip leading /.
    $path = $parts['path'];
    $path = substr($path, 1);

    $query = [];
    if (isset($parts['query'])) {
      parse_str($parts['query'], $query);
    }

    // Maybe check the host as well?
    if (!isset($mapping[$path])) {
      return;
    }

    $resource = $mapping[$path]['resource'];
    $bundle = $mapping[$path]['bundle'];
    $river = $mapping[$path]['river'] ?? $path;
    $view = $mapping[$path]['view'] ?? 'all';

    // Get the river service for the bundle.
    $service = RiverServiceBase::getRiverService($bundle);

    // Parse the query parameters.
    $parameters = new Parameters($query);

    // Get the advanced search handler.
    $advanced_search = new AdvancedSearch(
      $bundle,
      $river,
      $parameters,
      $service->getFilters(),
      $service->getFilterSample()
    );

    // Get the sanitized search parameter.
    $search = $service->getParameters()->get('search', '');

    // Get the API payload for the river and limit the results to 3 items.
    $payload = $service->getApiPayload($view);
    $payload['limit'] = 3;

    // Set the full text search query or remove it if empty.
    if (!empty($search)) {
      $payload['query']['value'] = $search;
    }
    else {
      unset($payload['query']);
    }

    // Generate the API filter with the facet and advanced search filters.
    $filter = $advanced_search->getApiFilter();
    if (!empty($filter)) {
      // Update the payload filter.
      if (!empty($payload['filter'])) {
        $payload['filter'] = [
          'conditions' => [
            $payload['filter'],
            $filter,
          ],
          'operator' => 'AND',
        ];
      }
      else {
        $payload['filter'] = $filter;
      }
    }

    return [
      'resource' => $resource,
      'bundle' => $bundle,
      'river' => $river,
      'view' => $view,
      'payload' => $payload,
      'more' => $url,
    ];
  }

}
