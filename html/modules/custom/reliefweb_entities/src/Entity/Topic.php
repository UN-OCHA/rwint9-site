<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_entities\DocumentInterface;
use Drupal\reliefweb_entities\DocumentTrait;
use Drupal\reliefweb_entities\EntityModeratedInterface;
use Drupal\reliefweb_entities\EntityModeratedTrait;
use Drupal\node\Entity\Node;

/**
 * Bundle class for topic nodes.
 */
class Topic extends Node implements BundleEntityInterface, EntityModeratedInterface, DocumentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
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

}
