<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;

/**
 * Helper to get media images.
 */
class MediaHelper {

  /**
   * Get the image information for the first referenced media.
   *
   * @param \Drupal\Core\Field\EntityReferenceFieldItemListInterface $field
   *   Media reference field.
   *
   * @return array
   *   Image information with the uri, width, height, alt and copyright.
   */
  public static function getImage(EntityReferenceFieldItemListInterface $field) {
    return static::getImageFromMediaFieldItem($field->first());
  }

  /**
   * Get the information for the images of the referenced media.
   *
   * @param \Drupal\Core\Field\EntityReferenceFieldItemListInterface $field
   *   Media reference field.
   *
   * @return array
   *   List of image information with the uri, width, height, alt and copyright.
   */
  public static function getImages(EntityReferenceFieldItemListInterface $field) {
    $images = [];
    foreach ($field as $item) {
      $image = static::getImageFromMediaFieldItem($item);
      if (!empty($image)) {
        $images[] = $image;
      }
    }
    return $images;
  }

  /**
   * Get the image information from the field item referencing a media.
   *
   * @param \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem|null $item
   *   Field item.
   *
   * @return array
   *   Image information with the uri, width, height, alt and copyright.
   */
  public static function getImageFromMediaFieldItem(?EntityReferenceItem $item) {
    if (empty($item) || $item->isEmpty()) {
      return [];
    }

    // Get the referenced media entity.
    $media_entity = $item
      ?->get('entity')
      ?->getTarget()
      ?->getValue();
    if (empty($media_entity)) {
      return [];
    }

    // Get the image field.
    $image_field = $media_entity
      ?->get('field_media_image')
      ?->first();
    if (empty($image_field)) {
      return [];
    }

    // Get the image entity.
    $image_entity = $image_field
      ?->get('entity')
      ?->getTarget()
      ?->getValue();
    if (empty($image_entity)) {
      return [];
    }

    $image = [
      'uri' => $image_entity->getFileUri(),
      'width' => $image_field->width,
      'height' => $image_field->height,
    ];

    // Image description.
    if ($media_entity->hasField('field_description') && !$media_entity->field_description->isEmpty()) {
      $image['alt'] = trim($media_entity->field_description->value);
    }

    // Image copyright.
    if ($media_entity->hasField('field_copyright') && !$media_entity->field_copyright->isEmpty()) {
      $image['copyright'] = trim($media_entity->field_copyright->value, " \n\r\t\v\0@");
    }

    return $image;
  }

}
