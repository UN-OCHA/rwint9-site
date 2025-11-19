<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_meta\BaseEntity;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\Type;

/**
 * A training entity.
 *
 * @JsonLdEntity(
 *   label = "Node Training Entity",
 *   id = "rw_node_training",
 * )
 */
class NodeTrainingEntity extends BaseEntity {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(EntityInterface $entity, $view_mode): bool {
    // Make sure it is a node.
    if (!$entity instanceof NodeInterface) {
      return FALSE;
    }

    // Only apply to training content type.
    if ($entity->bundle() !== 'training') {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getData(EntityInterface $entity, $view_mode): Type {
    /** @var \Drupal\node\NodeInterface $entity */

    $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();

    $keywords = [];
    // Add themes from field_theme.
    if ($entity->hasField('field_theme') && !$entity->get('field_theme')->isEmpty()) {
      $terms = $entity->get('field_theme')->referencedEntities();
      foreach ($terms as $term) {
        $keywords[] = $term->label();
      }
    }

    // Add categories from field_training_type.
    if ($entity->hasField('field_training_type') && !$entity->get('field_training_type')->isEmpty()) {
      $terms = $entity->get('field_training_type')->referencedEntities();
      foreach ($terms as $term) {
        $keywords[] = $term->label();
      }
    }

    // Add categories from field_career_categories.
    if ($entity->hasField('field_career_categories') && !$entity->get('field_career_categories')->isEmpty()) {
      $terms = $entity->get('field_career_categories')->referencedEntities();
      foreach ($terms as $term) {
        $keywords[] = $term->label();
      }
    }

    // Limit body to 1000 characters for description.
    $description = substr($entity->get('body')->value, 0, 1000);

    $schema = Schema::course();
    $schema->name($entity->label())
      ->identifier($url)
      ->description($description)
      ->dateCreated(date('c', (int) $entity->getCreatedTime()))
      ->dateModified(date('c', (int) $entity->getChangedTime()))
      ->expires($entity->get('field_training_date')->value)
      ->url($url)
      ->keywords($keywords)
      ->publisher([
        Schema::organization()
          ->name('ReliefWeb'),
      ]);

    // Only add hiring organization if field_source has a value.
    if ($entity->hasField('field_source') && !$entity->get('field_source')->isEmpty()) {
      $source = $entity->get('field_source')->entity;
      $org = $this->buildSourceReference($source);
      $schema->author($org);
    }

    // Only add contentLocation if country is present.
    if ($entity->hasField('field_country') && !$entity->get('field_country')->isEmpty()) {
      $schema->contentLocation([
        $this->buildCountryReference($entity->get('field_country')->entity),
      ]);
    }

    return $schema;
  }

}
