<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\reliefweb_meta\BaseEntity;
use Drupal\taxonomy\TermInterface;
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
    // Make sure it is a taxonomy term.
    if (!$entity instanceof TermInterface) {
      return FALSE;
    }

    // Only apply to source.
    if ($entity->bundle() !== 'source') {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getData(EntityInterface $entity, $view_mode): Type {
    /** @var \Drupal\taxonomy\TermInterface $entity */

    $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
    $org = Schema::organization()
      ->identifier($url)
      ->name($entity->label())
      ->url($url);

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
      $alternate_names = array_values(array_unique($alternate_names));
      $org->alternateName($alternate_names);
    }

    if ($entity->hasField('field_logo') && !$entity->get('field_logo')->isEmpty()) {
      $logo_file = $entity->get('field_logo')->entity;
      $file_url_generator = \Drupal::service('file_url_generator');

      if ($logo_file) {
        $org->logo($file_url_generator->generateAbsoluteString($logo_file->get('field_media_image')->entity->getFileUri()));
      }
    }

    if ($entity->hasField('field_homepage') && !$entity->get('field_homepage')->isEmpty()) {
      $homepage = $entity->get('field_homepage')->entity;
      if ($homepage) {
        $org->url($homepage->toUrl('canonical', ['absolute' => TRUE])->toString());
      }
    }

    if ($entity->hasField('field_country') && !$entity->get('field_country')->isEmpty()) {
      $org->location([
        Schema::country()->name($entity->get('field_country')->entity->label()),
      ]);
    }

    return $org;
  }

}
