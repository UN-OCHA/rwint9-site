<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_entities\DocumentInterface;
use Drupal\reliefweb_entities\DocumentTrait;
use Drupal\reliefweb_entities\EntityModeratedInterface;
use Drupal\reliefweb_entities\EntityModeratedTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drupal\node\Entity\Node;

/**
 * Bundle class for report nodes.
 */
class Report extends Node implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, DocumentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
  use EntityRevisionedTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return 'reports';
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityMeta() {
    $origin = $this->field_origin_notes->value;
    if (!UrlHelper::isValid($origin, TRUE)) {
      $origin = '';
    }
    else {
      $origin = UrlHelper::encodeUrl($origin);
    }

    return [
      'origin' => $origin,
      'posted' => $this->createDate($this->getCreatedTime()),
      'published' => $this->createDate($this->field_original_publication_date->value),
      'country' => $this->getEntityMetaFromField('country', 'C'),
      'source' => $this->getEntityMetaFromField('source', 'S'),
      'disaster' => $this->getEntityMetaFromField('disaster', 'D'),
      'format' => $this->getEntityMetaFromField('content_format', 'F'),
      'theme' => $this->getEntityMetaFromField('theme', 'T'),
      'disaster_type' => $this->getEntityMetaFromField('disaster_type', 'DT'),
      'language' => $this->getEntityMetaFromField('language', 'L'),
    ];
  }

  /**
   * Get the source disclaimers.
   *
   * @return array
   *   Render array with the list of disclaimers.
   */
  public function getSourceDisclaimers() {
    if ($this->field_source->isEmpty()) {
      return [];
    }

    $disclaimers = [];
    foreach ($this->field_source->referencedEntities() as $entity) {
      if (!$entity->field_disclaimer->isEmpty()) {
        $disclaimers[] = [
          'name' => $entity->label(),
          'disclaimer' => $entity->field_disclaimer->value,
        ];
      }
    }

    if (empty($disclaimers)) {
      return [];
    }

    return [
      '#theme' => 'reliefweb_entities_entity_source_disclaimers',
      '#disclaimers' => $disclaimers,
    ];
  }

}
