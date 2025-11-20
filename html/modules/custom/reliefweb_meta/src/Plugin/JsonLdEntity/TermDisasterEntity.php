<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_meta\BaseEntity;
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

    $id =  $this->getHomepageUrl() . 'taxonomy/term/' . $entity->id();
    $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();

    $keywords = [];
    // Add disaster types.
    if ($entity->hasField('field_disaster_type') && !$entity->get('field_disaster_type')->isEmpty()) {
      $terms = $entity->get('field_disaster_type')->referencedEntities();
      foreach ($terms as $term) {
        $keywords[] = $term->label();
      }
    }

    // Get moderation_status as keyword.
    if ($entity->hasField('moderation_status') && !$entity->get('moderation_status')->isEmpty()) {
      $keywords[] = 'Status: ' . $entity->get('moderation_status')->value;
    }

    // Add glide numbers as keywords.
    if ($entity->hasField('field_glide') && !$entity->get('field_glide')->isEmpty()) {
      foreach ($entity->get('field_glide')->getValue() as $item) {
        $keywords[] = 'GLIDE: ' . $item['value'];
      }
    }
    if ($entity->hasField('field_glide_related') && !$entity->get('field_glide_related')->isEmpty()) {
      foreach (explode("\n", $entity->get('field_glide_related')->value) as $item) {
        $keywords[] = 'GLIDE: ' . $item;
      }
    }

    $schema = Schema::event();
    $schema->name($entity->label())
      ->identifier($id)
      ->startDate($entity->get('field_disaster_date')->value)
      ->url($url)
      ->keywords($keywords);

    // Limit body to 1000 characters for description.
    if ($entity->hasField('description') && !$entity->get('description')->isEmpty()) {
      $schema->description(substr($entity->get('description')->value, 0, 1000));
    }

    // Only add location if country is present.
    $locations = [];
    if ($entity->hasField('field_primary_country') && !$entity->get('field_primary_country')->isEmpty()) {
      $locations[] = Schema::country()->name($entity->get('field_primary_country')->entity->label());
    }

    if ($entity->hasField('field_country') && !$entity->get('field_country')->isEmpty()) {
      foreach ($entity->get('field_country')->referencedEntities() as $country) {
        $locations[] = Schema::country()->name($country->label());
      }
    }

    if (!empty($locations)) {
      $locations = array_values(array_unique($locations));
      $schema->location($locations);
    }

    return $schema;
  }

}
