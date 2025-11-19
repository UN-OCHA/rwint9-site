<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta;

use Drupal\Core\Entity\EntityInterface;
use Drupal\json_ld_schema\Entity\JsonLdEntityBase;
use Drupal\taxonomy\TermInterface;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\Type;

/**
 * Base entity.
 */
class BaseEntity extends JsonLdEntityBase {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(EntityInterface $entity, $view_mode): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getData(EntityInterface $entity, $view_mode): Type {
    return Schema::thing();
  }

  /**
   * Build source reference.
   */
  protected function buildSourceReference(TermInterface $source): Type {
    $url = $source->toUrl('canonical', ['absolute' => TRUE])->toString();
    return Schema::organization()
      ->identifier($url)
      ->url($url)
      ->name($source->label());
  }

  /**
   * Build disaster event reference.
   */
  protected function buildDisasterEventReference(TermInterface $disaster): Type {
    $url = $disaster->toUrl('canonical', ['absolute' => TRUE])->toString();
    return Schema::event()
      ->identifier($url)
      ->name($disaster->label())
      ->url($url);
  }

  /**
   * Build country reference.
   */
  protected function buildCountryReference(TermInterface $country): Type {
    $url = $country->toUrl('canonical', ['absolute' => TRUE])->toString();
    return Schema::country()
      ->identifier($url)
      ->name($country->label())
      ->url($url);
  }

}
