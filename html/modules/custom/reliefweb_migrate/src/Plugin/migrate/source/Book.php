<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * Retrieve book menus from the Drupal 7 database.
 *
 * @MigrateSource(
 *   id = "reliefweb_book",
 * )
 */
class Book extends SqlBase {

  /**
   * Mapping D7 mlid => nid.
   *
   * @var array
   */
  protected $menuIdMapping;

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('book', 'b')
      ->fields('b', ['nid', 'bid']);

    $query->join('menu_links', 'ml', 'b.mlid = ml.mlid');
    $query->addField('ml', 'plid', 'pid');

    $ml_fields = ['weight', 'has_children', 'depth'];
    foreach (range(1, 9) as $i) {
      $field = "p$i";
      $ml_fields[] = $field;
      $query->orderBy('ml.' . $field);
    }
    $query->fields('ml', $ml_fields);

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    if (parent::prepareRow($row) === FALSE) {
      return FALSE;
    }

    if (!isset($this->menuIdMapping)) {
      $this->menuIdMapping = $this->select('book', 'b')
        ->fields('b', ['mlid', 'nid'])
        ->execute()
        ?->fetchAllKeyed(0, 1) ?? [];
    }

    // Convert the menu link ids to their corresponding node ids.
    $fields = ['pid'];
    for ($i = 1; $i < 10; $i++) {
      $fields[] = 'p' . $i;
    }
    foreach ($fields as $field) {
      $id = $row->getSourceProperty($field);
      if (isset($this->menuIdMapping[$id])) {
        $row->setDestinationProperty($field, $this->menuIdMapping[$id]);
        $row->setSourceProperty($field, $this->menuIdMapping[$id]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['nid']['type'] = 'integer';
    $ids['nid']['alias'] = 'b';
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'nid' => $this->t('Node ID'),
      'bid' => $this->t('Book ID'),
      'pid' => $this->t('Parent link ID'),
      'weight' => $this->t('Weight'),
      'p1' => $this->t('The first mlid in the materialized path. If N = depth, then pN must equal the mlid. If depth > 1 then p(N-1) must equal the parent link mlid. All pX where X > depth must equal zero. The columns p1 .. p9 are also called the parents.'),
      'p2' => $this->t('The second mlid in the materialized path. See p1.'),
      'p3' => $this->t('The third mlid in the materialized path. See p1.'),
      'p4' => $this->t('The fourth mlid in the materialized path. See p1.'),
      'p5' => $this->t('The fifth mlid in the materialized path. See p1.'),
      'p6' => $this->t('The sixth mlid in the materialized path. See p1.'),
      'p7' => $this->t('The seventh mlid in the materialized path. See p1.'),
      'p8' => $this->t('The eighth mlid in the materialized path. See p1.'),
      'p9' => $this->t('The ninth mlid in the materialized path. See p1.'),
    ];
  }

}
