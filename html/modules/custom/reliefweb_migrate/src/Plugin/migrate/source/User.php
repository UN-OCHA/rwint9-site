<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;

/**
 * Retrieve users from the Drupal 7 database.
 *
 * @MigrateSource(
 *   id = "reliefweb_user"
 * )
 */
class User extends EntityBase {

  /**
   * {@inheritdoc}
   */
  protected $idField = 'uid';

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

    return $query->orderBy('u.uid', 'ASC');
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
   * {@inheritdoc}
   */
  protected function getDestinationEntityIds() {
    return $this->getDatabaseConnection()
      ->select('users', 'u')
      ->fields('u', ['uid'])
      // Skip the anonymous, admin and system users.
      ->condition('u.uid', 2, '>')
      ->orderBy('u.uid', 'ASC')
      ->execute()
      ?->fetchAllKeyed(0, 0) ?? [];
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
