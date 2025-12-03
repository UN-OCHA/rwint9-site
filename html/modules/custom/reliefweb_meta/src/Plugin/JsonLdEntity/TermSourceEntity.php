<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\reliefweb_meta\BaseEntity;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\Type;

/**
 * A source entity.
 *
 * @JsonLdEntity(
 *   label = "Term Source Entity",
 *   id = "rw_term_source",
 * )
 */
class TermSourceEntity extends BaseEntity {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(EntityInterface $entity, $view_mode): bool {
    return $this->isEntityApplicable($entity, 'taxonomy_term', 'source');
  }

  /**
   * {@inheritdoc}
   */
  public function getData(EntityInterface $entity, $view_mode): Type {
    /** @var \Drupal\taxonomy\TermInterface $entity */

    // Use the entity permalink as identifier so that we can link to it
    // in the report schema.org JSON-LD.
    $id = $this->getEntityPermalinkUrl($entity);

    // Create the schema object for the organization.
    $org = Schema::organization()
      ->identifier($id)
      ->name($entity->label());

    // Use the organization homepage as URL if set.
    if ($entity->hasField('field_homepage') && !$entity->get('field_homepage')->isEmpty()) {
      $homepage = $entity->get('field_homepage')->uri;
      if ($homepage) {
        $org->url($homepage);
      }
    }

    // Add the alternate names: shortname, longname, aliases, spanish name.
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

    if ($entity->hasField('field_spanish_name') && !$entity->get('field_spanish_name')->isEmpty()) {
      $shortname = $entity->get('field_spanish_name')->value;
      if ($shortname) {
        $alternate_names[] = $shortname;
      }
    }

    if (!empty($alternate_names)) {
      $alternate_names = array_map('trim', $alternate_names);
      $alternate_names = array_filter($alternate_names, function($value) use ($entity) {
        return !empty($value) && $value !== $entity->label();
      });
      $alternate_names = array_values(array_unique($alternate_names));

      if (!empty($alternate_names)) {
        $org->alternateName($alternate_names);
      }
    }

    // Add the headquarter country as location.
    if ($entity->hasField('field_country') && !$entity->get('field_country')->isEmpty()) {
      $country = $entity->get('field_country')->entity;
      if ($country) {
        $org->location($this->buildCountryReference($country));
      }
    }

    // Add the social media links.
    $social_media_links = [];
    foreach ($entity->getOrganizationSocialMediaLinks()['#links'] ?? [] as $link) {
      $social_media_links[] = $link['url'];
    }
    if (!empty($social_media_links)) {
      $org->sameAs($social_media_links);
    }

    // Use the canonical (aliased) URL for the ProfilePage ID and URL because it
    // identifies the specific web document serving as the profile, distinct
    // from the organization entity itself.
    $url = $this->getEntityCanonicalUrl($entity);

    // We use a ProfilePage because this ReliefWeb source page describes the
    // organization, but is not the organization's official homepage.
    $profile = Schema::profilePage()
      ->identifier($url)
      ->url($url);

    // Set the organization as main entity of the profile page.
    $profile->mainEntity($org);

    return $profile;
  }

}
