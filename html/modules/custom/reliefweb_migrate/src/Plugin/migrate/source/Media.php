<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * Retrieve image medias from the Drupal 7 database.
 *
 * Available configuration keys:
 * - field (required): image field.
 *
 * @MigrateSource(
 *   id = "reliefweb_media"
 * )
 */
class Media extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $entity_type = $this->configuration['entity_type'];
    $bundle = $this->configuration['bundle'];

    $field = $this->configuration['field'];
    $table = 'field_data_' . $field;

    // Query the image field table for the given entity type and bundle.
    $query = $this->select($table, 'f');
    $query->condition('f.entity_type', $entity_type, '=');
    $query->condition('f.bundle', $bundle, '=');

    // Join the file_managed and file usage tables to restrict to used images.
    $query->innerJoin('file_managed', 'fm', 'fm.fid = f.' . $field . '_fid');
    $query->innerJoin('file_usage', 'fu', 'fu.fid = fm.fid');
    $query->condition('fm.filemime', 'image/%', 'LIKE');
    $query->condition('fm.status', 1, '=');
    $query->condition('fu.count', 0, '>');

    // Get the base file fields.
    $query->addField('fm', 'fid', 'fid');
    $query->addField('fm', 'uid', 'uid');
    $query->addField('fm', 'filename', 'filename');
    $query->addField('fm', 'status', 'status');
    $query->addField('fm', 'timestamp', 'timestamp');

    // Get the image fields.
    $query->addField('f', $field . '_alt', 'alt');
    $query->addField('f', $field . '_title', 'title');
    $query->addField('f', $field . '_width', 'width');
    $query->addField('f', $field . '_height', 'height');

    return $query->distinct();
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'fid' => $this->t('File ID'),
      'uid' => $this->t('The {users}.uid who added the file. If set to 0, this file was added by an anonymous user.'),
      'filename' => $this->t('File name'),
      'status' => $this->t('The published status of a file.'),
      'timestamp' => $this->t('The time that the file was added.'),
      'alt' => $this->t('Image alternative text'),
      'title' => $this->t('Image title'),
      'width' => $this->t('Image width'),
      'height' => $this->t('Image height'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['fid']['type'] = 'integer';
    $ids['fid']['alias'] = 'fm';
    return $ids;
  }

}
