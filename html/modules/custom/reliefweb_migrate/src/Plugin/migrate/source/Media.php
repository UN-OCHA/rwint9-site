<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\Core\Database\Database;
use Drupal\migrate\Event\MigrateImportEvent;

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
class Media extends EntityBase {

  /**
   * {@inheritdoc}
   */
  protected $idField = 'fid';

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

    $query->orderBy('fm.fid', 'ASC');
    return $query->distinct();
  }

  /**
   * {@inheritdoc}
   */
  protected function doPreloadExisting(array $ids) {
    if (!empty($ids)) {
      return $this->getDatabaseConnection()
        ->select('media', 'm')
        ->fields('m', ['mid'])
        ->condition('m.mid', $ids, 'IN')
        ->execute()
        ?->fetchCol() ?? [];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getMigrationStatus() {
    // We cannot differentiate report and blog post medias as we consolidate
    // them in D9. So to be able to get the propert destination ids for the
    // the current migration we need to use the id map.
    $id_map = $this->migration->getIdMap();
    $id_map_ids = $id_map->getDatabase()
      ->select($id_map->mapTableName(), 'map')
      ->fields('map', ['destid1'])
      ->execute()
      ?->fetchAllKeyed(0, 0) ?? [];

    $destination_ids = array_intersect_assoc($id_map_ids, $this->getDestinationEntityIds());
    $source_ids = $this->getSourceEntityIds();
    $imported_ids = array_intersect($destination_ids, $source_ids);

    $total = count($source_ids);
    $imported = count($destination_ids);
    $unchanged = count(array_intersect_assoc($source_ids, $destination_ids));
    $new = count(array_diff($source_ids, $imported_ids));
    $deleted = count(array_diff($destination_ids, $source_ids));
    $updated = count(array_diff_assoc($imported_ids, $source_ids));

    return [
      'total' => $total,
      'imported' => $imported,
      'unchanged' => $unchanged,
      'new' => $new,
      'deleted' => $deleted,
      'updated' => $updated,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getDestinationEntityIds() {
    $bundle = $this->migration
      ->getDestinationPlugin()
      ->getDestinationBundle();

    return $this->getDatabaseConnection()
      ->select('media', 'm')
      ->fields('m', ['mid'])
      ->condition('m.bundle', $bundle, '=')
      ->orderBy('m.mid', 'ASC')
      ->execute()
      ?->fetchAllKeyed(0, 0) ?? [];
  }

  /**
   * {@inheritdoc}
   */
  protected function getDestinationEntityIdsToDelete(array $ids) {
    if (!empty($ids)) {
      return array_diff($ids, $this->select('file_managed', 'fm')
        ->fields('fm', ['fid'])
        ->condition('fm.fid', $ids, 'IN')
        ->execute()
        ?->fetchCol() ?? []);
    }
    return [];
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

  /**
   * {@inheritdoc}
   */
  public function postImport(MigrateImportEvent $event) {
    parent::postImport($event);

    // Update the no-thumbnail media icon to prevent file ID clash with
    // attachments.
    Database::getConnection('default', 'default')
      ->update('file_managed')
      ->fields(['fid' => 1, 'uid' => 2])
      ->condition('uri', 'public://media-icons/generic/no-thumbnail.png', '=')
      ->execute();
  }

}
