<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_meta\BaseEntity;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\Type;

/**
 * A Job entity.
 *
 * @JsonLdEntity(
 *   label = "Node Job Entity",
 *   id = "rw_node_job",
 * )
 */
class NodeJobEntity extends BaseEntity {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(EntityInterface $entity, $view_mode): bool {
    // Make sure it is a node.
    if (!$entity instanceof NodeInterface) {
      return FALSE;
    }

    // Only apply to job content type.
    if ($entity->bundle() !== 'job') {
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

    // Create the schema object for the job posting.
    $schema = Schema::jobPosting()
      ->title($entity->label())
      ->identifier($id)
      ->datePosted(date('c', (int) $entity->getCreatedTime()))
      ->url($url);

    // Add the employment type to the schema.
    if ($entity->hasField('field_job_type') && !$entity->get('field_job_type')->isEmpty()) {
      $employment_type = $entity->get('field_job_type')->entity?->label() ?? 'Job';
      $schema->employmentType($employment_type);
    }

    // Add the job closing date to the schema.
    if ($entity->hasField('field_job_closing_date') && !$entity->get('field_job_closing_date')->isEmpty()) {
      $schema->validThrough($entity->get('field_job_closing_date')->value);
    }

    // Add themes from field_theme.
    if ($entity->hasField('field_theme') && !$entity->get('field_theme')->isEmpty()) {
      $terms = $entity->get('field_theme')->referencedEntities();
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

    // Add the keywords to the schema.
    $keywords = array_values(array_unique(array_filter($keywords)));
    if (!empty($keywords)) {
      $schema->keywords($keywords);
    }

    // Add a summary of the content as description to avoid duplicating the
    // content of the page and increase page load time.
    $description = $this->summarizeContent($entity, 'body');
    if (!empty($description)) {
      $schema->description($description);
    }

    // Add the hiring organization.
    if ($entity->hasField('field_source') && !$entity->get('field_source')->isEmpty()) {
      $source = $entity->get('field_source')->entity;
      if ($source) {
        $org = $this->buildSourceReference($source);
        $schema->hiringOrganization($org);
      }
    }

    // Add the country as job location.
    if ($entity->hasField('field_country') && !$entity->get('field_country')->isEmpty()) {
      $country = $entity->get('field_country')->entity;
      if ($country) {
        // Build a Country (Place) type reference for the country.
        $schema->jobLocation($this->buildCountryReference($country));
      }
    }
    else {
      // If no country is specified, indicate that the job is remote, roster,
      // or roving.
      $schema->jobLocationType('Remote, roster, roving, or location to be determined');
    }

    // Add the years of experience required for the job.
    if ($entity->hasField('field_job_experience') && !$entity->get('field_job_experience')->isEmpty()) {
      $years_of_experience = $entity->get('field_job_experience')->entity;
      if ($years_of_experience) {
        // Convert the minimum number of years of experience to months of
        // experience.
        $months_of_experience = match ((int) $years_of_experience->id()) {
          // 0-2 years, no prior experience or minimal experience.
          258 => 0,
          // 3-4 years, minimum of 3 years of experience.
          259 => 36,
          // 5-9 years, minimum of 5 years of experience.
          260 => 60,
          // 10+ years, minimum of 10 years of experience and above
          261 => 120,
          // Unknown, no experience required.
          default => NULL,
        };
        if (isset($months_of_experience)) {
          $schema->experienceRequirements(
            Schema::OccupationalExperienceRequirements()
              ->monthsOfExperience($months_of_experience)
          );
        }
      }
    }

    return $schema;
  }

}
