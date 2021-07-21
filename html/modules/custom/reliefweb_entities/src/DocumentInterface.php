<?php

namespace Drupal\reliefweb_entities;

/**
 * Interface for the document entities.
 */
interface DocumentInterface {

  /**
   * Get the meta information for the entity.
   *
   * @return array
   *   Meta information.
   */
  public function getEntityMeta();

  /**
   * Get the entity image.
   *
   * @return array
   *   Image information with uri, width, height, alt and copyright.
   */
  public function getEntityImage();

  /**
   * Get the list of social media links to share the content.
   *
   * @return array
   *   Render array for the list of share links.
   */
  public function getShareLinks();

  /**
   * Get the reports related to the entity.
   *
   * @param int $limit
   *   Number of reports to return.
   *
   * @return array
   *   Render array for the related reports river.
   */
  public function getRelatedContent($limit = 4);

  /**
   * Get the entity meta information for the given entity reference field.
   *
   * @param string $field
   *   Field name without the `field_` prefix.
   * @param string $code
   *   Advanced search code for the field.
   * @param array $extra_fields
   *   List of additional properties to add to the items. By default, the
   *   shortname if defined is added.
   *
   * @return array
   *   List of meta items for the field with name, url and some additional
   *   properties like shortname.
   */
  public function getEntityMetaFromField($field, $code, array $extra_fields = ['shortname' => 'shortname']);

  /**
   * Get the entity ids from a entity reference field.
   *
   * @param string $field
   *   Field name.
   *
   * @return array|false
   *   List of entity ids referenced by the field.
   */
  public function getReferencedEntityIds($field);

  /**
   * Convert a date to a \DateTime object.
   *
   * @param string $date
   *   ISO 6901 date or timestamp.
   *
   * @return \DateTime|null
   *   Date object or NULL if the date is not valid.
   *
   * @todo move that to the reliefweb_utility module?
   */
  public function createDate($date);

}
