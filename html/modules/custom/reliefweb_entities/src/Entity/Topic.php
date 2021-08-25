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

    $toc = [];
    $section_links = $this->get('field_sections');
    foreach ($section_links as $index => $section_link) {
      $toc['section-' . $index] = [
        'title' => $section_link->title,
      ];
    }

    return $toc;
  }

  /**
   * Convert a river URL to API payload.
   */
  protected function riverUrlToApi($url) {
    $parts = parse_url($url);

    if (!isset($parts['query'])) {
      return [];
    }

    $query = [];
    parse_str($parts['query'], $query);

    if (!isset($query['advanced-search'])) {
      return [];
    }

    return explode('_', $query['advanced-search']);
  }

}
