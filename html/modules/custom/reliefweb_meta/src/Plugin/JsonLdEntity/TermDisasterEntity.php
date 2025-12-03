<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\reliefweb_meta\BaseEntity;
use Drupal\reliefweb_meta\DisasterSchema;
use Drupal\taxonomy\TermInterface;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\Type;

/**
 * A disaster entity.
 *
 * @JsonLdEntity(
 *   label = "Term Disaster Entity",
 *   id = "rw_term_disaster",
 * )
 */
class TermDisasterEntity extends BaseEntity {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(EntityInterface $entity, $view_mode): bool {
    // Make sure it is a taxonomy term.
    if (!$entity instanceof TermInterface) {
      return FALSE;
    }

    // Only apply to disaster.
    if ($entity->bundle() !== 'disaster') {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getData(EntityInterface $entity, $view_mode): Type {
    /** @var \Drupal\taxonomy\TermInterface $entity */

    // Use the entity permalink as identifier and the canonical URL as URL.
    $id = $this->getEntityPermalinkUrl($entity);
    $url = $this->getEntityCanonicalUrl($entity);

    // Keywords to add to the schema.
    $keywords = [];

    // Create the schema object for the disaster event.
    //
    // There is no type for natural disasters in the schema.org vocabulary, so
    // we use an Event type.
    //
    // We use our DisasterSchema class and setProperty('@id',...) so that we can
    // use identifier() later on to set the GLIDE numbers as extra identifiers.
    // This is a workaround because identifier() is forcefully converted to
    // `@id` in the Spatie SchemaOrg library.
    $event = (new DisasterSchema())
      ->setProperty('@id', $id)
      ->name($entity->label())
      ->url($url);

    // Add the disaster date as start date.
    if ($entity->hasField('field_disaster_date') && !$entity->get('field_disaster_date')->isEmpty()) {
      $event->startDate($entity->get('field_disaster_date')->value);
    }

    // Add glide numbers as extra identifiers to the event.
    $glide_numbers = [];
    if ($entity->hasField('field_glide') && !$entity->get('field_glide')->isEmpty()) {
      $glide_numbers[] = $entity->get('field_glide')->value;
    }
    if ($entity->hasField('field_glide_related') && !$entity->get('field_glide_related')->isEmpty()) {
      foreach (explode("\n", $entity->get('field_glide_related')->value) as $item) {
        $glide_numbers[] = $item;
      }
    }
    $glide_numbers = array_filter(array_map('trim', $glide_numbers));
    if (!empty($glide_numbers)) {
      $glide_numbers = array_values(array_unique($glide_numbers));
      $glide_numbers = array_map(function ($glide_number) {
        return Schema::propertyValue()
          ->propertyID('https://glidenumber.net/')
          ->value($glide_number);
      }, $glide_numbers);
      $event->setProperty('identifier', $glide_numbers);
    }

    // Add "Disaster" as keyword because the "Event" type is too generic.
    $keywords[] = 'Disaster';
    // Add the moderation status as keyword.
    $keywords[] = $entity->getModerationStatusLabel();

    // Add disaster types as keywords.
    if ($entity->hasField('field_primary_disaster_type') && !$entity->get('field_primary_disaster_type')->isEmpty()) {
      $primary_disaster_type = $entity->get('field_primary_disaster_type')->entity;
      if ($primary_disaster_type) {
        $keywords[] = $primary_disaster_type->label();
      }
    }
    if ($entity->hasField('field_disaster_type') && !$entity->get('field_disaster_type')->isEmpty()) {
      foreach ($entity->get('field_disaster_type')->referencedEntities() as $disaster_type) {
        $keywords[] = $disaster_type->label();
      }
    }

    // Add the keywords to the schema.
    $keywords = array_values(array_unique(array_filter($keywords)));
    if (!empty($keywords)) {
      $event->keywords($keywords);
    }

    // Add affected countries as locations.
    $countries = [];
    if ($entity->hasField('field_primary_country') && !$entity->get('field_primary_country')->isEmpty()) {
      $primary_country = $entity->get('field_primary_country')->entity;
      if ($primary_country) {
        $countries[$primary_country->id()] = $this->buildCountryReference($primary_country);
      }
    }
    if ($entity->hasField('field_country') && !$entity->get('field_country')->isEmpty()) {
      foreach ($entity->get('field_country')->referencedEntities() as $country) {
        $countries[$country->id()] = $this->buildCountryReference($country);
      }
    }
    if (!empty($countries)) {
      $event->location(array_values($countries));
    }

    // Add a summary of the content as description to avoid duplicating the
    // content of the page and increase page load time.
    $description = $this->summarizeContent($entity, 'description');
    if (!empty($description)) {
      $event->description($description);
    }

    // We return the Event schema directly because this page describes a
    // specific disaster event as its primary entity (with temporal boundaries,
    // casualties, impacts, and status). The disaster event itself is what's
    // being documented, not just a collection of resources about it.
    return $event;
  }

}
