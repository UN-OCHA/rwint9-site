<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta;

use Drupal\Core\Entity\EntityInterface;
use Drupal\json_ld_schema\Entity\JsonLdEntityBase;
use Drupal\taxonomy\TermInterface;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\Type;

/**
 * Base entity.
 */
class BaseEntity extends JsonLdEntityBase {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(EntityInterface $entity, $view_mode): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getData(EntityInterface $entity, $view_mode): Type {
    return Schema::thing();
  }

  /**
   * Build source thing.
   */
  protected function buildSourceThing(TermInterface $source): Type {
    $org = Schema::organization()
      ->identifier($source->uuid())
      ->name($source->label())
      ->url($source->toUrl('canonical', ['absolute' => TRUE])->toString());

    if ($source->hasField('field_shortname') && !$source->get('field_shortname')->isEmpty()) {
      $shortname = $source->get('field_shortname')->value;
      if ($shortname) {
        $org->alternateName($shortname);
      }
    }

    if ($source->hasField('field_aliases') && !$source->get('field_aliases')->isEmpty()) {
      $aliases = $source->get('field_aliases')->value;
      if ($aliases) {
        $org->formerName($aliases);
      }
    }

    if ($source->hasField('field_logo') && !$source->get('field_logo')->isEmpty()) {
      $logo_file = $source->get('field_logo')->entity;
      $file_url_generator = \Drupal::service('file_url_generator');

      if ($logo_file) {
        $org->logo($file_url_generator->generateAbsoluteString($logo_file->get('field_media_image')->entity->getFileUri()));
      }
    }

    if ($source->hasField('field_homepage') && !$source->get('field_homepage')->isEmpty()) {
      $homepage = $source->get('field_homepage')->entity;
      if ($homepage) {
        $org->url($homepage->toUrl('canonical', ['absolute' => TRUE])->toString());
      }
    }

    if ($source->hasField('field_country') && !$source->get('field_country')->isEmpty()) {
      $org->location([
        Schema::country()->name($source->get('field_country')->entity->label()),
      ]);
    }

    return $org;
  }

}
