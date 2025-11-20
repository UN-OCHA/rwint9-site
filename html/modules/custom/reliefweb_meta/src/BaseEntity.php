<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
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
   * Get homepage URL.
   */
  protected function getHomepageUrl(): string {
    $home = &drupal_static(__FUNCTION__);
    if (isset($home)) {
      return $home;
    }

    $home = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
    return $home;
  }

  /**
   * Build source reference.
   */
  protected function buildSourceReference(TermInterface $source): Type {
    $id = $this->getHomepageUrl() . 'taxonomy/term/' . $source->id();
    $url = $source->toUrl('canonical', ['absolute' => TRUE])->toString();
    return Schema::organization()
      ->identifier($id)
      ->url($url)
      ->name($source->label());
  }

  /**
   * Build disaster event reference.
   */
  protected function buildDisasterEventReference(TermInterface $disaster): Type {
    $id = $this->getHomepageUrl() . 'taxonomy/term/' . $disaster->id();
    $url = $disaster->toUrl('canonical', ['absolute' => TRUE])->toString();
    return Schema::event()
      ->identifier($id)
      ->name($disaster->label())
      ->url($url);
  }

  /**
   * Build country reference.
   */
  protected function buildCountryReference(TermInterface $country): Type {
    $id = $this->getHomepageUrl() . 'taxonomy/term/' . $country->id();
    $url = $country->toUrl('canonical', ['absolute' => TRUE])->toString();
    return Schema::country()
      ->identifier($id)
      ->name($country->label())
      ->url($url);
  }

}
