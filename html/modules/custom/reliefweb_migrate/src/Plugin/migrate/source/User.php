<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\reliefweb_migrate\Plugin\migrate\id_map\AccumulatedSql;

/**
 * Retrieve users from the Drupal 7 database.
 *
 * @MigrateSource(
 *   id = "reliefweb_user"
 * )
 */
class User extends EntityBase {

  /**
   * Store the source entity IDs.
   *
   * @var array
   */
  protected $sourceEntityIds;

  /**
   * {@inheritdoc}
   */
  protected $idField = 'uid';

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $this->initializeBatchSize();
    if (empty($this->batchSize)) {
      return parent::initializeIterator();
    }

    // If a batch has run the query is already setup.
    // We also need to have a clean query if we use IDs to migrate because,
    // otherwise, the ID condition will be merged with the previous one...
    if ($this->batch == 0 || isset($this->idsToMigrate)) {
      $this->prepareQuery();
    }

    // Initialize the list of IDs to migrate.
    if (!isset($this->idsToMigrate)) {
      $this->idsToMigrate = $this->getIdsToMigrate();

      \Drupal::logger('migrate')->info(strtr('IDs to migrate: @ids', [
        '@ids' => count($this->idsToMigrate),
      ]));
    }

    // If there are IDs to migrate, then we go through the list.
    if (!empty($this->idsToMigrate)) {
      $ids = array_splice($this->idsToMigrate, 0, $this->batchSize);
      $this->idsToProcess = array_flip($ids);

      $this->query->condition('u.uid', $ids, 'IN');
    }
    else {
      $this->idsToProcess = [];
      $this->query->alwaysFalse();
    }

    // Wrap the query result in an iterator.
    $statement = $this->query->execute();
    $statement->setFetchMode(\PDO::FETCH_ASSOC);
    $iterator = new \IteratorIterator($statement);

    // Preload the ID mapping and the list of migrated entities for the results.
    $this->preloadIdMapping($iterator);
    $this->preloadExisting($iterator);

    // Rewind the iterator just in case.
    $iterator->rewind();

    return $iterator;
  }

  /**
   * Get the list of entity IDs to migrate.
   *
   * @return array
   *   List of IDs (ex: revision IDs).
   */
  protected function getIdsToMigrate() {
    $destination_ids = $this->getDestinationEntityIds();
    $source_ids = $this->getSourceEntityIds();
    $imported_ids = array_intersect($destination_ids, $source_ids);
    $updated_ids = array_diff_assoc($imported_ids, $source_ids);
    $new_ids = array_diff($source_ids, $imported_ids);
    // We need the user ID not the UID + changed combination.
    $ids = array_unique($new_ids + $updated_ids);
    sort($ids);
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  protected function rowChanged(Row $row) {
    $id = $row->getSourceProperty('uid');
    if (isset($this->idsToProcess[$id])) {
      return TRUE;
    }
    return parent::rowChanged($row);
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('users', 'u')
      ->fields('u', [
        'uid',
        'name',
        'pass',
        'mail',
        'signature',
        'signature_format',
        'created',
        'changed',
        'access',
        'login',
        'status',
        'timezone',
        'language',
        'picture',
        'init',
      ])
      // Skip the anonymous and admin users.
      ->condition('u.uid', 1, '>');

    // Add the notes field.
    $query->leftJoin('field_data_field_notes', 'fn', 'fn.entity_type = :entity_type AND fn.entity_id = u.uid', [
      ':entity_type' => 'user',
    ]);
    $query->addField('fn', 'field_notes_value', 'notes');

    return $query->orderBy('u.uid', 'ASC');
  }

  /**
   * {@inheritdoc}
   */
  protected function doCount() {
    return count($this->getSourceEntityIds());
  }

  /**
   * {@inheritdoc}
   */
  protected function doPreloadExisting(array $ids) {
    if (!empty($ids)) {
      return $this->getDatabaseConnection()
        ->select('users', 'u')
        ->fields('u', ['uid'])
        ->condition('u.uid', $ids, 'IN')
        ->execute()
        ?->fetchCol() ?? [];
    }
    return [];
  }

  /**
   * Get the list of source ids that can be imported.
   *
   * @return array
   *   Associative array keyed by revision ids if available, otherwise keyed by
   *   entity ids and with the entity ids as values.
   */
  protected function getSourceEntityIds() {
    // The query to get the url aliases is slow so we store the result.
    if (isset($this->sourceEntityIds)) {
      return $this->sourceEntityIds;
    }

    $query = $this->select('users', 'u')
      ->fields('u', ['uid', 'changed'])
      // Skip the anonymous and admin users.
      ->condition('u.uid', 1, '>');

    // Get the users who posted content, have favorites or subscriptions.
    $subquery = $this->select('node', 'n')
      ->fields('n', ['uid'])
      ->distinct()
      ->union(
        $this->select('taxonomy_term_data_revision', 't')
          ->fields('t', ['uid'])
          ->distinct()
      )
      ->union(
        $this->select('reliefweb_bookmarks_favorites', 'f')
          ->fields('f', ['uid'])
          ->distinct()
      )
      ->union(
        $this->select('reliefweb_subscriptions_subscriptions', 's')
          ->fields('s', ['uid'])
          ->distinct()
      );

    $query->innerJoin($subquery, 'subquery', 'subquery.uid = u.uid');

    $ids = [];
    foreach ($query->execute() ?? [] as $record) {
      // The combination uid + changed acts as a revision ID in order to
      // determine differences with the migrated content.
      $ids[$record['uid'] . '###' . $record['changed']] = $record['uid'];
    }

    asort($ids);

    $this->sourceEntityIds = $ids;

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDestinationEntityIds() {
    $records = $this->getDatabaseConnection()
      ->select('users_field_data', 'u')
      ->fields('u', ['uid', 'changed'])
      // Skip the anonymous, admin and system users.
      ->condition('u.uid', 2, '>')
      ->orderBy('u.uid', 'ASC')
      ->execute() ?? [];
    $ids = [];
    foreach ($records as $record) {
      $ids[$record->uid . '###' . $record->changed] = $record->uid;
    }
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDestinationEntityIdsToDelete(array $ids) {
    if (!empty($ids)) {
      return array_diff($ids, $this->select('users', 'u')
        ->fields('u', ['uid'])
        ->condition('u.uid', $ids, 'IN')
        ->execute()
        ?->fetchCol() ?? []);
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function removeDeletedEntities() {
    $source_ids = $this->getSourceEntityIds();
    $destination_ids = $this->getDestinationEntityIds();

    $deleted_ids = array_diff($destination_ids, $source_ids);
    if (empty($deleted_ids)) {
      return;
    }

    $destination_plugin = $this->migration->getDestinationPlugin();
    $delete_from_id_map = $this->idMap instanceof AccumulatedSql;

    foreach (array_chunk($deleted_ids, 1000) as $ids) {
      foreach ($ids as $id) {
        $destination_plugin->rollback([$id]);
      }
      if ($delete_from_id_map) {
        $this->idMap->deleteFromSourceIds($ids);
      }
    }

    \Drupal::logger('migrate')->info(strtr('IDs deleted: @ids', [
      '@ids' => count($deleted_ids),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function setHighWaterToLatestNonImported($check_only = FALSE, $set_to_max = FALSE) {
    $destination_ids = $this->getDestinationEntityIds();

    if ($set_to_max) {
      $ids = array_values($destination_ids);
    }
    else {
      $source_ids = $this->getSourceEntityIds();
      $imported_ids = array_intersect($destination_ids, $source_ids);
      $updated_ids = array_diff_assoc($imported_ids, $source_ids);
      $new_ids = array_diff($source_ids, $imported_ids);
      $ids = array_values($new_ids + $updated_ids);
    }

    $id = NULL;
    if (!empty($ids)) {
      $id = $set_to_max ? max($ids) : min($ids) - 1;
    }

    if ($check_only) {
      print_r([
        $this->migration->id() => [
          'old' => $this->getHighWater(),
          'new' => $id,
        ],
      ]);
      return $id;
    }
    elseif (isset($id)) {
      $this->getHighWaterStorage()->set($this->migration->id(), $id);
    }
    return $this->getHighWater();
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      // Base `users` table fields.
      'uid' => $this->t('User ID'),
      'name' => $this->t('Username'),
      'pass' => $this->t('Password'),
      'mail' => $this->t('Email address'),
      'signature' => $this->t('Signature'),
      'signature_format' => $this->t('Signature format'),
      'created' => $this->t('Registered timestamp'),
      'changed' => $this->t('Timestamp for when user was changed.'),
      'access' => $this->t('Last access timestamp'),
      'login' => $this->t('Last login timestamp'),
      'status' => $this->t('Status'),
      'timezone' => $this->t('Timezone'),
      'language' => $this->t('Language'),
      'picture' => $this->t('Picture'),
      'init' => $this->t('Init'),
      // List of user roles.
      'roles' => $this->t('Roles'),
      // Custom fields.
      'notes' => $this->t('Notes'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $uid = $row->getSourceProperty('uid');

    // Roles.
    $query = $this->select('users_roles', 'ur')
      ->fields('ur', ['rid'])
      ->condition('ur.uid', $uid);
    $row->setSourceProperty('roles', $query->execute()->fetchCol());

    // Set the initial email address if not set.
    if (empty($row->getSourceProperty('init'))) {
      $row->setSourceProperty('init', $row->getSourceProperty('mail'));
    }

    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['uid']['type'] = 'integer';
    $ids['uid']['alias'] = 'u';
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function bundleMigrationRequired() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function entityTypeId() {
    return 'user';
  }

}
