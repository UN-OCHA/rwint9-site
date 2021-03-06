<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;
use Drupal\taxonomy\Entity\Term;

/**
 * Default bundle class for taxonomy terms.
 */
class TaxonomyTermBase extends Term implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface {

  use EntityRevisionedTrait;
  use EntityModeratedTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public static function addFieldConstraints(&$fields) {
    // No specific constraints.
  }

}
