<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\reliefweb_meta\BaseEntity;
use Drupal\reliefweb_meta\CountrySchema;
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
    return $this->isEntityApplicable($entity, 'taxonomy_term', 'country');
  }

  /**
   * {@inheritdoc}
   */
  public function getData(EntityInterface $entity, $view_mode): Type {
    /** @var \Drupal\taxonomy\TermInterface $entity */

    // Use the entity permalink as identifier so that we can link to it
    // in the report schema.org JSON-LD.
    $id = $this->getEntityPermalinkUrl($entity);

    // Create the schema object for the country.
    //
    // We use our CountrySchema class and setProperty('@id',...) so that we can
    // use identifier() later on to set the ISO3 code as extra identifiers.
    // This is a workaround because identifier() is forcefully converted to
    // `@id` in the Spatie SchemaOrg library.
    $country = (new CountrySchema())
      ->setProperty('@id', $id)
      ->name($entity->label());

    // Add the ISO3 code as extra identifier.
    if ($entity->hasField('field_iso3') && !$entity->get('field_iso3')->isEmpty()) {
      $iso3 = $entity->get('field_iso3')->value;
      if (!empty($iso3)) {
        $country->identifier(Schema::propertyValue()
          ->propertyID('ISO 3166-1 alpha-3')
          ->value($iso3));
      }
    }

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
      $alternate_names = array_map('trim', $alternate_names);
      $alternate_names = array_filter($alternate_names, function($value) use ($entity) {
        return !empty($value) && $value !== $entity->label();
      });
      $alternate_names = array_values(array_unique($alternate_names));

      if (!empty($alternate_names)) {
        $country->alternateName($alternate_names);
      }
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

    // Use the canonical (aliased) URL for the CollectionPage ID and URL because
    // it identifies the specific web document serving as the collection,
    // distinct from the country entity itself.
    $url = $this->getEntityCanonicalUrl($entity);

    // We use a CollectionPage because this ReliefWeb country page is a curated
    // collection of reports and resources about the country, not the country
    // entity itself.
    $collection = Schema::collectionPage()
      ->name($entity->label())
      ->identifier($url)
      ->url($url);

    // Set the country as the subject of the collection page.
    $collection->about($country);

    return $collection;
  }

}
