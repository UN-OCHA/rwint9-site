<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_meta\BaseEntity;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Spatie\SchemaOrg\NewsArticle;
use Spatie\SchemaOrg\Report;
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

    // Use the entity permalink as identifier and the canonical URL as URL.
    $id = $this->getEntityPermalinkUrl($entity);
    $url = $this->getEntityCanonicalUrl($entity);

    // List of keywords to add to the schema.
    $keywords = [];

    // Determine the schema type based on the content format.
    $schema_type = 'report';
    if ($entity->hasField('field_content_format') && !$entity->get('field_content_format')->isEmpty()) {
      // Get the term and check the field_json_schema field.
      $content_format = $entity->get('field_content_format')->entity;
      if ($content_format) {
        // Add the content format to the keywords.
        $keywords[] = $content_format->label();

        // If the content format has a json schema, use it.
        if ($content_format->hasField('field_json_schema') && !$content_format->get('field_json_schema')->isEmpty()) {
          $schema_type = $content_format->get('field_json_schema')->value;
        }
        // Otherwise, use the id to determine the schema type.
        else {
          $schema_type = match ((int) $content_format->id()) {
            8 => 'news_article',
            12 => 'map',
            default => 'report',
          };
        }
      }
    }

    // Create the schema object based on the schema type.
    $schema = match ($schema_type) {
      'map' => Schema::map(),
      'creative_work' => Schema::creativeWork(),
      'news_article' => Schema::newsArticle(),
      'report' => Schema::report(),
      default => Schema::report(),
    };

    $schema
      ->identifier($id)
      ->dateCreated(date('c', (int) $entity->getCreatedTime()))
      ->dateModified(date('c', (int) $entity->getChangedTime()))
      ->isAccessibleForFree(TRUE)
      ->url($url)
      // Indicate that ReliefWeb is the publisher of this structured data
      // since it's the organization that published the document initially.
      ->sdPublisher(Schema::organization()->name('ReliefWeb'));

    // Add the title of the document based on the schema type.
    if ($schema instanceof NewsArticle || $schema instanceof Report) {
      $schema->headline($entity->label());
    }
    else {
      $schema->name($entity->label());
    }

    // Add the original publication date to the schema.
    // The data is stored as YYYY-MM-DD, which is a valid ISO 8601 format.
    $publication_date = NULL;
    if ($entity->hasField('field_original_publication_date') && !$entity->get('field_original_publication_date')->isEmpty()) {
      $date = $entity->get('field_original_publication_date')->value;
      if (!empty($date)) {
        $publication_date = $date;
      }
    }
    // Use the creation date as fallback if the original publication date is not
    // set.
    if (empty($publication_date)) {
      $publication_date = date('Y-m-d', (int) $entity->getCreatedTime());
    }
    $schema->datePublished($publication_date);

    // Add the origin URL to the schema.
    if ($entity->hasField('field_origin_notes') && !$entity->get('field_origin_notes')->isEmpty()) {
      $origin = $entity->get('field_origin_notes')->value;
      if (!empty($origin) && UrlHelper::isValid($origin, TRUE)) {
        $schema->isBasedOn(UrlHelper::encodeUrl($origin));
      }
    }

    // Add themes as keywords.
    if ($entity->hasField('field_theme') && !$entity->get('field_theme')->isEmpty()) {
      $terms = $entity->get('field_theme')->referencedEntities();
      foreach ($terms as $term) {
        $keywords[] = $term->label();
      }
    }

    // Add disaster types as keywords.
    if ($entity->hasField('field_disaster_type') && !$entity->get('field_disaster_type')->isEmpty()) {
      $terms = $entity->get('field_disaster_type')->referencedEntities();
      foreach ($terms as $term) {
        $keywords[] = $term->label();
      }
    }

    // Add the keywords to the schema.
    $keywords = array_values(array_unique(array_filter($keywords)));
    if (!empty($keywords)) {
      $schema->keywords($keywords);
    }

    // Add a summary of the content as description to avoid duplicating the
    // content of the page and increase page load time.
    $description = $this->summarizeContent($entity, 'body');
    if (!empty($description)) {
      // Use abstract instead of description or articleBody since it's more
      // appropriate for a summary (or truncated version) of the content.
      $schema->abstract($description);
    }

    // Add the languages in which the document is available to the schema.
    $language_codes = $this->getEntityLanguageCodes($entity, 'field_language');
    if (!empty($language_codes)) {
      $schema->inLanguage($language_codes);
    }

    // Add references to the original sources of the document.
    if ($entity->hasField('field_source') && !$entity->get('field_source')->isEmpty()) {
      $sources = [];
      foreach ($entity->get('field_source')->referencedEntities() as $source) {
        $sources[] = $this->buildSourceReference($source);
      }
      if (!empty($sources)) {
        $schema->author($sources);
        $schema->publisher($sources);
      }
    }

    // Add references to the countries covered by the document.
    if ($entity->hasField('field_country') && !$entity->get('field_country')->isEmpty()) {
      $countries = [];
      foreach ($entity->get('field_country')->referencedEntities() as $country) {
        // Build a Country (Place) type reference for the country.
        $countries[] = $this->buildCountryReference($country);
      }
      if (!empty($countries)) {
        $schema->spatialCoverage($countries);
      }
    }

    return $schema;
  }

}
