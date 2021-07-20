<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\Core\Database\Query\Condition;
use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * Retrieve url aliases from the Drupal 7 database.
 *
 * @MigrateSource(
 *   id = "reliefweb_url_alias"
 * )
 */
class UrlAlias extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $condition = new Condition('OR');
    $condition->condition('ua.source', 'node/%', 'LIKE');
    $condition->condition('ua.source', 'taxonomy/term/%', 'LIKE');

    // The order of the migration is significant since
    // \Drupal\path_alias\AliasRepository::lookupPathAlias() orders by pid
    // before returning a result. Postgres does not automatically order by
    // primary key therefore we need to add a specific order by.
    return $this->select('url_alias', 'ua')
      ->fields('ua')
      ->where($condition)
      ->orderBy('pid');
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'pid' => $this->t('The numeric identifier of the path alias.'),
      'language' => $this->t('The language code of the URL alias.'),
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
