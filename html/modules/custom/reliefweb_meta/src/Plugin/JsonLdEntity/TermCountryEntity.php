<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\reliefweb_meta\BaseEntity;
use Drupal\taxonomy\TermInterface;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\Type;

/**
 * A country entity.
 *
 * @JsonLdEntity(
 *   label = "Term country Entity",
 *   id = "rw_term_country",
 * )
 */
class TermCountryEntity extends BaseEntity {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(EntityInterface $entity, $view_mode): bool {
    // Make sure it is a taxonomy term.
    if (!$entity instanceof TermInterface) {
      return FALSE;
    }

    // Only apply to country.
    if ($entity->bundle() !== 'country') {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getData(EntityInterface $entity, $view_mode): Type {
    /** @var \Drupal\taxonomy\TermInterface $entity */

    $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
    $country = Schema::country()
      ->identifier($url)
      ->name($entity->label())
      ->url($url);

    $alternate_names = [];
    if ($entity->hasField('field_shortname') && !$entity->get('field_shortname')->isEmpty()) {
      $shortname = $entity->get('field_shortname')->value;
      if ($shortname) {
        $alternate_names[] = $shortname;
      }
    }

    if ($entity->hasField('field_longname') && !$entity->get('field_longname')->isEmpty()) {
      $longname = $entity->get('field_longname')->value;
      if ($longname) {
        $alternate_names[] = $longname;
      }
    }

    if ($entity->hasField('field_aliases') && !$entity->get('field_aliases')->isEmpty()) {
      $aliases = $entity->get('field_aliases')->value;
      if ($aliases) {
        foreach (explode("\n", $aliases) as $item) {
          $alternate_names[] = $item;
        }
      }
    }

    if (!empty($alternate_names)) {
      $alternate_names = array_values(array_unique($alternate_names));
      $country->alternateName($alternate_names);
    }

    // Add geo coordinates if available.
    if ($entity->hasField('field_location') && !$entity->get('field_location')->isEmpty()) {
      /** @var \Drupal\geofield\Plugin\Field\FieldType\GeofieldItem $location */
      $location = $entity->get('field_location')->first();
      if ($location) {
        $geo = Schema::geoCoordinates()
          ->latitude($location->lat)
          ->longitude($location->lon);
        $country->geo($geo);
      }
    }

    return $country;
  }

}
