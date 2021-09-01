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
    $labels = [
      'disasters' => $this->t('Alert and Ongoing Disasters'),
    ];

    // @todo build toc based on values.
    return [
      '#theme' => 'reliefweb_entities_sectioned_content',
      '#contents' => [
        '#theme' => 'reliefweb_entities_table_of_contents',
        '#title' => $this->t('Table of Contents'),
        '#sections' => $contents,
      ],
      '#sections' => $sections,
    ];

    // Consolidate sections, removing empty ones.
    return  $this->consolidateSections($contents, $sections, $labels);
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

    $toc = [];
    $section_links = $this->get('field_sections');
    foreach ($section_links as $index => $section_link) {
      $toc['section-' . $index] = [
        'title' => $section_link->title,
        'sections' => [
          'digital-sitrep' => $this->t('OCHA Situation Report'),
          'overview' => $this->t('In Focus'),
          'key-content' => $this->t('Key Content'),
          'updates' => $this->t('Latest Updates'),
          'maps-infographics' => $this->t('Maps and Infographics'),
          'most-read' => $this->t('Most Read'),
        ],
      ];
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

    $path = $parts['path'];
    // Strip leading /.
    $path = substr($path, 1);
    $query = $parts['query'] ?? [];

    // Maybe check the host as well?
    if (!isset($mapping[$path])) {
      return;
    }

    $resource = $mapping[$path]['resource'];
    $bundle = $mapping[$path]['bundle'];
    $river = $mapping[$path]['river'] ?? $path;
    $view = $mapping[$path]['view'] ?? 'all';

    // Get the river service for the bundle.
    $service = \Drupal\reliefweb_rivers\RiverServiceBase::getRiverService($bundle);

    // Parse the query parameters.
    $parameters = new \Drupal\reliefweb_rivers\Parameters($query);

    // Get the advanced search handler.
    $advanced_search = new \Drupal\reliefweb_rivers\AdvancedSearch(
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
