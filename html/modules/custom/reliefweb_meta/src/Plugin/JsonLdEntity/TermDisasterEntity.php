<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
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
    // Make sure it is a node.
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

    $schema = Schema::thing();
    $schema->name($entity->label())
      ->identifier($entity->uuid())
      ->description($entity->get('description')->value)
      ->dateCreated(date('c', (int) $entity->created->value))
      ->dateModified(date('c', (int) $entity->changed->value))
      ->datePublished($entity->get('field_disaster_date')->value)
      ->isAccessibleForFree(TRUE)
      ->url($entity->toUrl('canonical', ['absolute' => TRUE])->toString())
      ->keywords($keywords)
      ->publisher([
        Schema::organization()
          ->name('ReliefWeb'),
      ]);

    // Only add sourceOrganization if field_source has a value.
    if ($entity->hasField('field_source') && !$entity->get('field_source')->isEmpty()) {
      $schema->sourceOrganization($this->buildSourceThing($entity->get('field_source')->entity));
    }

    // Only add contentLocation if country is present.
    if ($entity->hasField('field_primary_country') && !$entity->get('field_primary_country')->isEmpty()) {
      $schema->contentLocation([
        Schema::country()->name($entity->get('field_primary_country')->entity->label()),
      ]);
    }

    return $schema;
  }

}
