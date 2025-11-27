<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_meta\BaseEntity;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Spatie\SchemaOrg\EventAttendanceModeEnumeration;
use Spatie\SchemaOrg\EventStatusType;
use Spatie\SchemaOrg\Course;
use Spatie\SchemaOrg\CourseInstance;
use Spatie\SchemaOrg\EducationEvent;
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

    // Use the entity permalink as identifier and the canonical URL as URL.
    $id = $this->getEntityPermalinkUrl($entity);
    $url = $this->getEntityCanonicalUrl($entity);

    // List of keywords to add to the schema.
    $keywords = [];

    // Retrieve the training date range.
    $start_date = NULL;
    $end_date = NULL;
    if ($entity->hasField('field_training_date') && !$entity->get('field_training_date')->isEmpty()) {
      $start_date = $entity->get('field_training_date')->value ?: NULL;
      $end_date = $entity->get('field_training_date')->end_value ?: NULL;
      $end_date = $end_date ?: $start_date;
    }

    // Retrieve the training formats.
    $online = FALSE;
    $onsite = FALSE;
    if ($entity->hasField('field_training_format') && !$entity->get('field_training_format')->isEmpty()) {
      foreach ($entity->get('field_training_format')->referencedEntities() as $training_format) {
        if ((int) $training_format->id() === 4606) {
          $onsite = TRUE;
        }
        elseif ((int) $training_format->id() === 4607) {
          $online = TRUE;
        }
      }
    }

    // Retrieve the training type.
    $training_type_id = NULL;
    if ($entity->hasField('field_training_type') && !$entity->get('field_training_type')->isEmpty()) {
      $training_type = $entity->get('field_training_type')->entity;
      // Add the training type label to the keywords.
      $keywords[] = $training_type->label();
      // Keep track of the trainint type ID to determine the schema.
      $training_type_id = (int) $training_type->id();
    }

    // Determine the schema based on the training type and dates.
    $schema = match (TRUE) {
      // No dates, use course schema.
      empty($start_date) => Schema::course(),
      // Academic Degree/Course.
      $training_type_id === 4610 => Schema::course(),
      // Call for Papers.
      $training_type_id === 4608 => Schema::EducationEvent(),
      // Conference/Lecture.
      $training_type_id === 21006 => Schema::EducationEvent(),
      // Training/Workshop
      $training_type_id === 4609 => Schema::EducationEvent(),
      // Other.
      default => Schema::course(),
    };

    // Add the basic information to the schema.
    $schema
      ->name($entity->label())
      ->identifier($id)
      ->url($url);

    // If it's a course add the creation and modification dates.
    if ($schema instanceof Course) {
      $schema->dateCreated(date('c', (int) $entity->getCreatedTime()));
      $schema->dateModified(date('c', (int) $entity->getChangedTime()));
    }

    // Course instance or event.
    $instance = NULL;

    // Offer (to indicate cost and registration deadline).
    $offer = NULL;

    // Determine if the training has an instance (course or event).
    if ($start_date) {
      $instance = match (get_class($schema)) {
        // Use the educational event schema itself.
        EducationEvent::class => $schema,
        // Use a Course instance schema which extends the Event schema.
        Course::class => Schema::courseInstance(),
        // No instsance, for example for permanent training.
        default => NULL,
      };
    }

    // Add the training dates to the instance.
    if ($instance && $start_date) {
      // The dates are in the format YYYY-MM-DD which is a valid ISO 8601
      // format, so we can use them directly.
      $instance->startDate($start_date);
      $instance->endDate($end_date);
    }
    else {
      // Add "permanent" as keyword to indicate that the training is permanent.
      $keywords[] = 'permanent';
    }

    // Add the attendance mode to the instance.
    if ($instance && ($online || $onsite)) {
      $attendance_mode = match (TRUE) {
        $online && $onsite => EventAttendanceModeEnumeration::MixedEventAttendanceMode,
        $onsite => EventAttendanceModeEnumeration::OfflineEventAttendanceMode,
        $online => EventAttendanceModeEnumeration::OnlineEventAttendanceMode,
        default => NULL,
      };
      if ($attendance_mode) {
        $instance->eventAttendanceMode($attendance_mode);
      }

      // Also add the course mode if it is a course instance.
      if ($instance instanceof CourseInstance) {
        $course_modes = match (TRUE) {
          $online && $onsite => ['online', 'onsite'],
          $onsite => ['onsite'],
          $online => ['online'],
          default => NULL,
        };
        if ($course_modes) {
          $instance->courseMode($course_modes);
        }
      }
    }

    // Add the registration deadline if any to the course offer.
    if ($entity->hasField('field_registration_deadline') && !$entity->get('field_registration_deadline')->isEmpty()) {
      $deadline = $entity->get('field_registration_deadline')->value;
      if ($deadline) {
        $offer = $offer ?? Schema::offer();
        $offer->validThrough($deadline);
      }
    }

    // Add the cost information.
    $cost = NULL;
    if ($entity->hasField('field_cost') && !$entity->get('field_cost')->isEmpty()) {
      $cost = $entity->get('field_cost')->value;
      if ($cost === 'free') {
        $schema->isAccessibleForFree(TRUE);
      }
      elseif ($cost === 'fee-based') {
        $schema->isAccessibleForFree(FALSE);

        // Retrieve the fee information.
        if ($entity->hasField('field_fee_information') && !$entity->get('field_fee_information')->isEmpty()) {
          $fee_information = $entity->get('field_fee_information')->value;
          if ($fee_information) {
            $offer = $offer ?? Schema::offer();
            $offer->description($fee_information);
          }
        }
      }
    }

    // Add the course instance to the schema if it is a course.
    if ($instance && $schema instanceof Course) {
      $schema->hasCourseInstance($instance);
    }

    // Add the offer to the instance or the schema if no instance is present.
    if ($offer) {
      if ($instance) {
        $instance->offers($offer);
      }
      else {
        $schema->offers($offer);
      }
    }

    // Add career categories (professional functions) as keywords.
    if ($entity->hasField('field_career_categories') && !$entity->get('field_career_categories')->isEmpty()) {
      foreach ($entity->get('field_career_categories')->referencedEntities() as $career_category) {
        $keywords[] = $career_category->label();
      }
    }

    // Add themes as keywords.
    if ($entity->hasField('field_theme') && !$entity->get('field_theme')->isEmpty()) {
      foreach ($entity->get('field_theme')->referencedEntities() as $theme) {
        $keywords[] = $theme->label();
      }
    }

    // Add the keywords to the schema.
    $keywords = array_values(array_unique(array_filter($keywords)));
    if (!empty($keywords)) {
      $schema->keywords($keywords);
    }

    // Add a summary of the content as description.
    $description = $this->summarizeContent($entity, 'body');
    if (!empty($description)) {
      $schema->description($description);
    }

    // Add the languages in which the training is available to the schema.
    $language_codes = $this->getEntityLanguageCodes($entity, 'field_training_language');
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
        if ($schema instanceof EducationEvent) {
          $schema->organizer($sources);
        }
        else {
          $schema->provider($sources);
        }
      }
    }

    // Add the instance location(s).
    if ($entity->hasField('field_country') && !$entity->get('field_country')->isEmpty()) {
      $countries = [];
      foreach ($entity->get('field_country')->referencedEntities() as $country) {
        // Build a Country (Place) type reference for the country.
        $countries[] = $this->buildCountryReference($country);
      }
      if (!empty($countries) && $instance) {
        $instance->location($countries);
      }
    }

    // Add the event URL as "sameAs" to the schema since most of the time
    // it's the URL of the training page on the organization website.
    if ($entity->hasField('field_link') && !$entity->get('field_link')->isEmpty()) {
      $event_url = $entity->get('field_link')->uri;
      if (!empty($event_url) && UrlHelper::isValid($event_url, TRUE)) {
        $schema->sameAs(UrlHelper::encodeUrl($event_url));
      }
    }

    return $schema;
  }

}
