<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\destination;

use Drupal\Core\Entity\EntityInterface;
use Drupal\migrate\Plugin\migrate\destination\EntityContentBase;
use Drupal\migrate\Row;

/**
 * Save the book menus.
 *
 * @MigrateDestination(
 *   id = "reliefweb_book",
 * )
 */
class Book extends EntityContentBase {

  /**
   * {@inheritdoc}
   */
  protected static function getEntityTypeId($plugin_id) {
    return 'node';
  }

  /**
   * {@inheritdoc}
   */
  protected function updateEntity(EntityInterface $entity, Row $row) {
    if ($entity->book) {
      $book = $row->getDestinationProperty('book');
      foreach ($book as $key => $value) {
        $entity->book[$key] = $value;
      }
    }
    else {
      $entity->book = $row->getDestinationProperty('book');
    }
    return parent::updateEntity($entity, $row);
  }

}
