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
use Drupal\node\Entity\Node;

/**
 * Bundle class for job nodes.
 */
class Job extends Node implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, DocumentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
  use EntityRevisionedTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return 'jobs';
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityMeta() {
    return [
      'posted' => $this->createDate($this->getCreatedTime()),
      'closing' => $this->createDate($this->field_job_closing_date->value),
      'country' => $this->getEntityMetaFromField('country', 'C'),
      'source' => $this->getEntityMetaFromField('source', 'S'),
      'city' => $this->field_city->value ?? '',
      'type' => $this->getEntityMetaFromField('job_type', 'TY'),
      'career_category' => $this->getEntityMetaFromField('career_categories', 'CC'),
      'experience' => $this->getEntityMetaFromField('job_experience', 'E'),
      'theme' => $this->getEntityMetaFromField('theme', 'T'),
    ];
  }

}
