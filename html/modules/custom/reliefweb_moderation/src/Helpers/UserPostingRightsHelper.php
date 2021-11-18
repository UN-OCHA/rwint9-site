<?php

namespace Drupal\reliefweb_moderation\Helpers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Session\AccountInterface;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Drupal\user\EntityOwnerInterface;

/**
 * Helper to get user posting rights information.
 */
class UserPostingRightsHelper {

  use EntityDatabaseInfoTrait;

  /**
   * Get the user posting rights for an entity's author.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return string
   *   Consolidated posting rights for the author of the entity based on the
   *   user posting rights of the sources of the entity for that user:
   *   - "blocked" if the user is blocked for at least one of the sources
   *   - "unverified" if the user is unverified for at least one of the sources
   *     or if the are no posting rights for the user in any of the sources
   *   - "trusted" if the user is trusted for all the sources
   *   - "allowed" if the user is allowed or trusted for the sources.
   *
   * @todo consolidate with the other posting rights methods once ported.
   */
  public static function getEntityAuthorPostingRights(EntityInterface $entity) {
    if (!$entity->hasField('field_source') || !method_exists($entity, 'getOwnerId')) {
      return 'unknown';
    }

    $source_item_list = $entity->get('field_source');
    if (!$source_item_list instanceof EntityReferenceFieldItemList) {
      return 'unknown';
    }

    $rights = [
      'unverified' => 0,
      'blocked' => 0,
      'allowed' => 0,
      'trusted' => 0,
    ];
    $rights_keys = array_keys($rights);

    $bundle = $entity->bundle();
    $uid = $entity->getOwnerId();

    $source_entities = $source_item_list->referencedEntities();
    foreach ($source_entities as $source_entity) {
      if (!$source_entity->hasField('field_user_posting_rights')) {
        continue;
      }

      foreach ($source_entity->get('field_user_posting_rights') as $item) {
        if ($item->get('id')->getValue() != $uid) {
          continue;
        }
        $right = $item->get($bundle)->getValue();
        $rights[$rights_keys[$right] ?? 'unverified']++;
      }
    }

    // Compute the consolidated right for the user.
    if ($rights['blocked'] > 0) {
      return 'blocked';
    }
    elseif ($rights['unverified'] > 0) {
      return 'unverified';
    }
    elseif ($rights['trusted'] === count($source_entities)) {
      return 'trusted';
    }
    elseif ($rights['allowed'] > 0) {
      return 'allowed';
    }
    return 'unverified';
  }

  /**
   * Get the posting rights per sources for an account.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   A user's account object or the current user if NULL.
   * @param array $sources
   *   List of source ids. Limit the returned rights to the given sources.
   *
   * @return array
   *   Posting rights as an associative array keyed by source id.
   */
  public static function getUserPostingRights(?AccountInterface $account = NULL, array $sources = []) {
    static $users;

    $helper = new UserPostingRightsHelper();
    $account = $account ?: \Drupal::currentUser();

    // Static cache key for the combination account/soures.
    $key = $account->id() . ':' . implode('-', $sources);

    // Returned the cached rights if any.
    if (isset($users[$key])) {
      return $users[$key];
    }

    // Skip the query for the anonymous user.
    if (!empty($account->id())) {
      $table = $helper->getFieldTableName('taxonomy_term', 'field_user_posting_rights');
      $id_field = $helper->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'id');
      $job_field = $helper->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'job');
      $training_field = $helper->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'training');

      // Get the rights associated with the user id.
      $query = $helper->getDatabase()->select($table, $table);
      $query->addField($table, 'entity_id', 'tid');
      $query->addField($table, $job_field, 'job');
      $query->addField($table, $training_field, 'training');
      $query->condition($table . '.bundle', 'source', '=');
      $query->condition($table . '.' . $id_field, $account->id(), '=');
      if (!empty($sources)) {
        $query->condition($table . '.entity_id', $sources, 'IN');
      }

      $results = $query->execute()?->fetchAllAssoc('tid', \PDO::FETCH_ASSOC);
    }
    else {
      $results = [];
    }

    // Add default rights for non matched sources.
    foreach ($sources as $tid) {
      if (!isset($results[$tid])) {
        $results[$tid] = [
          'tid' => $tid,
          'job' => 0,
          'training' => 0,
        ];
      }
    }

    // Cache the rights.
    $users[$key] = $results;

    return $results;
  }

  /**
   * Get an account's consolidated posting right for a document.
   *
   * Compute the "final" posting right for a document based on an account's
   * rights for the given sources.
   *
   * Currently only for jobs and training.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   A user's account object or the current user if NULL.
   * @param string $bundle
   *   Entity bundle.
   * @param array $sources
   *   List of sources. Limit the returned rights to the given sources.
   *
   * @return array
   *   Associative array with the right code and name, and the sources for which
   *   the right applies.
   */
  public static function getUserConsolidatedPostingRight(AccountInterface $account, $bundle, array $sources) {
    // Not a job nor training or no sources, consider the user 'unverified'.
    if (empty($account->uid) || ($bundle !== 'job' && $bundle !== 'training') || empty($sources)) {
      return [
        'code' => 0,
        'name' => 'unverified',
        'sources' => $sources,
      ];
    }

    // Get the current user's posting rights for the selected sources.
    $rights = [];
    foreach (static::getUserPostingRights($account, $sources) as $tid => $data) {
      $rights[$data[$bundle] ?? 0][] = $tid;
    }

    // Blocked for some sources.
    if (!empty($rights[1])) {
      return [
        'code' => 1,
        'name' => 'blocked',
        'sources' => $rights[1],
      ];
    }
    // Unverified for some sources.
    elseif (!empty($rights[0])) {
      return [
        'code' => 0,
        'name' => 'unverified',
        'sources' => $rights[0],
      ];
    }
    // Trusted for all the sources.
    elseif (isset($rights[3]) && count($rights[3]) === count($sources)) {
      return [
        'code' => 3,
        'name' => 'trusted',
        'sources' => $sources,
      ];
    }
    // Allowed or trusted for all sources.
    return [
      'code' => 2,
      'name' => 'allowed',
      'sources' => $sources,
    ];
  }

  /**
   * Check if a user has posting rights on the entity.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   A user's account object or the current user if NULL.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity for which to check access.
   * @param string $status
   *   Entity status.
   *
   * @return bool
   *   Whether the user has posting rights or not.
   */
  public static function userHasPostingRights(AccountInterface $account, EntityInterface $entity, $status) {
    // Disallow for anonymous or undefined users.
    if ($account->id() === NULL || $account->id() === 0) {
      return FALSE;
    }

    // Disallow in case of unknown entity id (ex: new entity).
    if ($entity->id() === NULL) {
      return FALSE;
    }

    $bundle = $entity->bundle();
    $allowed = FALSE;

    $owner = FALSE;
    if ($entity instanceof EntityOwnerInterface) {
      $owner = $entity->getOwnerId() === $account->id() && $account->id() > 0;
    }

    // Only applies to job and training.
    if ($bundle === 'job' || $bundle === 'training') {
      // Check for sources for which the user is blocked, allowed or trusted.
      //
      // Note: if there is no source or the user in unverified for the sources
      // then we default to the base behavior: disallowed unless owner.
      if ($entity->hasField('field_source') && !$entity->field_source->isEmpty()) {
        // Get the document sources.
        $sources = [];
        foreach ($entity->field_source as $item) {
          if (!empty($item->target_id)) {
            $sources[] = $item->target_id;
          }
        }

        // Check if the user is allowed or blocked.
        foreach (static::getUserPostingRights($account, $sources) as $data) {
          $right = $data[$bundle] ?? 0;
          // If the user is blocked for one of the sources always disallow even
          // if the user is the owner of the document, except for drafts.
          //
          // No strict equality as $right can be a numeric string or an integer.
          if ($right == 1) {
            return $owner && $status === 'draft';
          }
          // Allowed for at least one of the sources. That means that in the
          // case of joint ads, being allowed to post for one of the sources
          // is enough to be considered having posting rights on the document
          // (unless blocked for one of the sources, of course).
          elseif ($right > 1) {
            $allowed = TRUE;
          }
        }
      }
    }

    // Owner can edit their own posts (unless blocked for a source, see above).
    return $allowed || $owner;
  }

  /**
   * Format a user posting right.
   *
   * @param string $right
   *   Right.
   *
   * @return \Drupl\Component\Render\MarkupInterface
   *   Formatted right.
   */
  public static function renderRight($right) {
    $build = [
      '#theme' => 'reliefweb_moderation_user_posting_right',
      '#right' => $right,
    ];
    return \Drupal::service('renderer')->render($build);
  }

}
