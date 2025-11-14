<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_meta\BaseEntity;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\Type;

/**
 * A Report entity.
 *
 * @JsonLdEntity(
 *   label = "Node Report Entity",
 *   id = "rw_node_report",
 * )
 */
class NodeReportEntity extends BaseEntity {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(EntityInterface $entity, $view_mode): bool {
    // Make sure it is a node.
    if (!$entity instanceof NodeInterface) {
      return FALSE;
    }

    // Only apply to report content type.
    if ($entity->bundle() !== 'report') {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getData(EntityInterface $entity, $view_mode): Type {
    /** @var \Drupal\node\NodeInterface $entity */

    // Fallback to report if field is empty.
    $content_format = 'report';
    if ($entity->hasField('field_content_format') && !$entity->get('field_content_format')->isEmpty()) {
      // Get the term and check the field_json_schema field.
      $term = $entity->get('field_content_format')->entity;
      if ($term && $term->hasField('field_json_schema') && !$term->get('field_json_schema')->isEmpty()) {
        $content_format = $term->get('field_json_schema')->value;
      }
    }

    $keywords = [];
    // Add themes from field_theme.
    if ($entity->hasField('field_theme') && !$entity->get('field_theme')->isEmpty()) {
      $terms = $entity->get('field_theme')->referencedEntities();
      foreach ($terms as $term) {
        $keywords[] = $term->label();
      }
    }

    $schema = NULL;
    switch ($content_format) {
      case 'map':
        $schema = Schema::map();
        $schema->description($entity->get('body')->value);
        break;

      case 'creative_work':
        $schema = Schema::creativeWork();
        $schema->articleBody($entity->get('body')->value);
        break;

      case 'news_article':
        $schema = Schema::newsArticle();
        $schema->articleBody($entity->get('body')->value);
        break;

      case 'report':
      default:
        $schema = Schema::report();
        $schema->articleBody($entity->get('body')->value);

        break;
    }

    $schema->name($entity->label())
      ->identifier($entity->uuid())
      ->dateCreated(date('c', (int) $entity->getCreatedTime()))
      ->dateModified(date('c', (int) $entity->getChangedTime()))
      ->datePublished($entity->get('field_original_publication_date')->value)
      ->isAccessibleForFree(TRUE)
      ->url($entity->toUrl('canonical', ['absolute' => TRUE])->toString())
      ->keywords($keywords)
      ->publisher([
        Schema::organization()
          ->name('ReliefWeb'),
      ]);

    // Get the language code from the language entity.
    if ($entity->hasField('field_language') && !$entity->get('field_language')->isEmpty()) {
      $language_entity = $entity->get('field_language')->entity;
      $language_code = $language_entity ? $language_entity->get('field_language_code')->value : 'en';
      $schema->inLanguage($language_code);
    }

    // Only add sourceOrganization if field_source has a value.
    if ($entity->hasField('field_source') && !$entity->get('field_source')->isEmpty()) {
      $schema->sourceOrganization($this->buildSourceThing($entity->get('field_source')->entity));
    }

    // Only add contentLocation if country is present.
    if ($entity->hasField('field_country') && !$entity->get('field_country')->isEmpty()) {
      $schema->contentLocation([
        Schema::country()->name($entity->get('field_country')->entity->label()),
      ]);
    }

    return $schema;
  }

}
