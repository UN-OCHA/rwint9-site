<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;

/**
 * Retrieve url aliases from the Drupal 7 database.
 *
 * @MigrateSource(
 *   id = "reliefweb_url_alias"
 * )
 */
class UrlAlias extends EntityBase {

  /**
   * {@inheritdoc}
   */
  protected $idField = 'pid';

  /**
   * {@inheritdoc}
   */
  public function query() {
    $subquery = $this->select('url_alias', 'ua2');
    $subquery->addField('ua2', 'source', 'source');
    $subquery->addExpression('MAX(ua2.pid)', 'pid');
    $subquery->groupBy('ua2.source');
    $subquery->having('COUNT(ua2.pid) > 1');

    $query = $this->select('url_alias', 'ua');
    $query->fields('ua', ['pid', 'source', 'alias']);
    $query->innerJoin($subquery, 'sq', 'ua.source  = %alias.source AND ua.pid <> %alias.pid');
    $query->orderBy('ua.pid', 'ASC');
    $query->groupBy('ua.alias');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  protected function doPreloadExisting(array $ids) {
    if (!empty($ids)) {
      return $this->getDatabaseConnection()
        ->select('redirect', 'r')
        ->fields('r', ['rid'])
        ->condition('r.rid', $ids, 'IN')
        ->execute()
        ?->fetchCol() ?? [];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function getDestinationEntityIds() {
    return $this->getDatabaseConnection()
      ->select('redirect', 'r')
      ->fields('r', ['rid'])
      ->orderBy('r.rid', 'ASC')
      ->execute()
      ?->fetchAllKeyed(0, 0) ?? [];
  }

  /**
   * {@inheritdoc}
   */
  protected function getDestinationEntityIdsToDelete(array $ids) {
    if (!empty($ids)) {
      return array_diff($ids, $this->select('url_alias', 'ua')
        ->fields('ua', ['pid'])
        ->condition('ua.pid', $ids, 'IN')
        ->execute()
        ?->fetchCol() ?? []);
    }
    return [];
  }

  /**
   * Remove the entities from the D9 site that don't exist in the D7 site.
   */
  protected function removeDeletedEntities() {
    // Skip because, we do a full re-import when running this migration.
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    if (parent::prepareRow($row) === FALSE) {
      return FALSE;
    }

    $source = $row->getSourceProperty('source');
    if (strpos($source, 'node/') === 0) {
      $row->setSourceProperty('source', 'entity:' . $source);
    }
    elseif (strpos($source, 'taxonomy/term/') === 0) {
      $source = str_replace('taxonomy/term', 'taxonomy_term', $source);
      $row->setSourceProperty('source', 'entity:' . $source);
    }
    else {
      $row->setSourceProperty('source', 'internal:/' . $source);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'pid' => $this->t('The numeric identifier of the path alias.'),
      'source' => $this->t('The internal system path.'),
      'alias' => $this->t('The path alias.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['pid']['type'] = 'integer';
    $ids['pid']['alias'] = 'ua';
    return $ids;
  }

}
