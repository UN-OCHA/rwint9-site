<?php

declare(strict_types=1);

namespace Drupal\reliefweb_moderation\Helpers;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Database\Statement\FetchAs;
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
   * Check if the entity supports posting rights.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return bool
   *   TRUE if the entity supports posting rights, FALSE otherwise.
   */
  public static function entitySupportsPostingRights(EntityInterface $entity): bool {
    return static::entityBundleSupportsPostingRights($entity->bundle());
  }

  /**
   * Check if the entity bundle supports posting rights.
   *
   * @param string $bundle
   *   Entity bundle.
   *
   * @return bool
   *   TRUE if the entity supports posting rights, FALSE otherwise.
   */
  public static function entityBundleSupportsPostingRights(string $bundle): bool {
    return in_array($bundle, ['report', 'job', 'training']);
  }

  /**
   * Get the user posting rights for an entity's author.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return string
   *   Consolidated posting rights for the author of the entity based on the
   *   user and domain posting rights of the sources of the entity for that
   *   user:
   *   - "blocked" if the user is blocked for at least one of the sources
   *   - "unverified" if the user is unverified for at least one of the sources
   *     or if the are no posting rights for the user in any of the sources
   *   - "trusted" if the user is trusted for all the sources
   *   - "allowed" if the user is allowed or trusted for the sources.
   *
   * @todo consolidate with the other posting rights methods once ported.
   */
  public static function getEntityAuthorPostingRights(EntityInterface $entity): string {
    if (!$entity->hasField('field_source') || !($entity instanceof EntityOwnerInterface)) {
      return 'unknown';
    }

    $source_item_list = $entity->get('field_source');
    if (!$source_item_list instanceof EntityReferenceFieldItemList) {
      return 'unknown';
    }

    /** @var array<string, int> $rights */
    $rights = [
      'unverified' => 0,
      'blocked' => 0,
      'allowed' => 0,
      'trusted' => 0,
    ];
    /** @var array<int, string> $rights_keys */
    $rights_keys = array_keys($rights);

    $bundle = $entity->bundle();
    $uid = $entity->getOwnerId();
    $email = $entity->getOwner()?->getEmail() ?? '';
    $domain = static::extractDomainFromEmail($email);

    $source_entities = $source_item_list->referencedEntities();
    foreach ($source_entities as $source_entity) {
      // Default to unverified if the owner has no right defined for the source.
      $right = 'unverified';
      $user_right_found = FALSE;

      // First check user posting rights.
      if ($source_entity->hasField('field_user_posting_rights')) {
        foreach ($source_entity->get('field_user_posting_rights') as $item) {
          // No strict equality as $uid can be a numeric string or an integer.
          if ($item->get('id')->getValue() == $uid) {
            $right = $rights_keys[$item->get($bundle)->getValue()] ?? 'unverified';
            $user_right_found = TRUE;
            break;
          }
        }
      }

      // If no user posting rights found, check domain posting rights.
      if (!$user_right_found && $domain && $source_entity->hasField('field_domain_posting_rights')) {
        foreach ($source_entity->get('field_domain_posting_rights') as $item) {
          if ($item->get('domain')->getValue() === $domain) {
            $right = $rights_keys[$item->get($bundle)->getValue()] ?? 'unverified';
            break;
          }
        }
      }

      $rights[$right]++;
    }

    // Compute the consolidated right for the user.
    if ($rights['blocked'] > 0) {
      return 'blocked';
    }
    elseif ($rights['unverified'] > 0) {
      return 'unverified';
    }
    elseif (count($source_entities) > 0 && $rights['trusted'] === count($source_entities)) {
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
   * @param array<int> $sources
   *   List of source ids. Limit the returned rights to the given sources.
   *
   * @return array<int, array<string, mixed>>
   *   Posting rights as an associative array keyed by source id.
   */
  public static function getUserPostingRights(?AccountInterface $account = NULL, array $sources = []): array {
    $users = &drupal_static('reliefweb_moderation_getUserPostingRights');

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
      $report_field = $helper->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'report');

      // Get the rights associated with the user id.
      $query = $helper->getDatabase()->select($table, $table);
      $query->addField($table, 'entity_id', 'tid');
      $query->addField($table, $job_field, 'job');
      $query->addField($table, $training_field, 'training');
      $query->addField($table, $report_field, 'report');
      $query->condition($table . '.bundle', 'source', '=');
      $query->condition($table . '.' . $id_field, $account->id(), '=');
      if (!empty($sources)) {
        $query->condition($table . '.entity_id', $sources, 'IN');
      }

      $results = $query->execute()?->fetchAllAssoc('tid', FetchAs::Associative);

      // If no sources are provided, check domain posting rights for all
      // sources.
      if (empty($sources)) {
        $results += static::getDomainPostingRights($account);
      }
      // Otherwise, check domain posting rights for the sources for which no
      // user posting rights were found.
      else {
        $missing_sources = array_diff($sources, array_keys($results));
        if (!empty($missing_sources)) {
          $results += static::getDomainPostingRights($account, $missing_sources);
        }
      }
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
          'report' => 0,
        ];
      }
    }

    // Cache the rights.
    $users[$key] = $results;

    return $results;
  }

  /**
   * Get domain posting rights for an account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   A user's account object.
   * @param array<int> $sources
   *   List of source ids. Limit the returned rights to the given sources.
   *
   * @return array<int, array<string, mixed>>
   *   Domain posting rights as an associative array keyed by source id.
   */
  protected static function getDomainPostingRights(AccountInterface $account, array $sources = []): array {
    $helper = new self();
    $results = [];

    // Get user's email domain.
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($account->id());
    if (!$user || !$user->getEmail()) {
      return $results;
    }

    $domain = static::extractDomainFromEmail($user->getEmail());
    if (!$domain) {
      return $results;
    }

    // Get domain posting rights table and field names.
    $table = $helper->getFieldTableName('taxonomy_term', 'field_domain_posting_rights');
    $domain_field = $helper->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'domain');
    $job_field = $helper->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'job');
    $training_field = $helper->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'training');
    $report_field = $helper->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'report');

    // Query domain posting rights.
    $query = $helper->getDatabase()->select($table, $table);
    $query->addField($table, 'entity_id', 'tid');
    $query->addField($table, $job_field, 'job');
    $query->addField($table, $training_field, 'training');
    $query->addField($table, $report_field, 'report');
    $query->condition($table . '.bundle', 'source', '=');
    $query->condition($table . '.' . $domain_field, $domain, '=');
    if (!empty($sources)) {
      $query->condition($table . '.entity_id', $sources, 'IN');
    }

    $results = $query->execute()?->fetchAllAssoc('tid', FetchAs::Associative);

    return $results;
  }

  /**
   * Extract domain from email address.
   *
   * @param string $email
   *   Email address.
   *
   * @return string|null
   *   Domain part of the email address or NULL if invalid.
   */
  protected static function extractDomainFromEmail(string $email): ?string {
    if (empty($email) || strpos($email, '@') === FALSE) {
      return NULL;
    }

    [, $domain] = explode('@', $email, 2);
    return mb_strtolower(trim($domain));
  }

  /**
   * Get an account's consolidated posting right for a document.
   *
   * Compute the "final" posting right for a document based on an account's
   * rights for the given sources.
   *
   * Currently only for jobs, trainings and reports.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   A user's account object or the current user if NULL.
   * @param string $bundle
   *   Entity bundle.
   * @param array<int> $sources
   *   List of sources. Limit the returned rights to the given sources.
   *
   * @return array<string, mixed>
   *   Associative array with the right code and name, and the sources for which
   *   the right applies.
   */
  public static function getUserConsolidatedPostingRight(AccountInterface $account, string $bundle, array $sources): array {
    // Not a job, training nor report or no sources, 'unverified'.
    if (empty($account->id()) || ($bundle !== 'job' && $bundle !== 'training' && $bundle !== 'report') || empty($sources)) {
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
  public static function userHasPostingRights(AccountInterface $account, EntityInterface $entity, string $status): bool {
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

    // Only applies to job, training and report.
    if ($bundle === 'job' || $bundle === 'training' || $bundle === 'report') {
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
   * Check if a user is allowed or trusted for any source.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   A user's account object or the current user if NULL.
   * @param string $bundle
   *   Entity bundle (job, training, or report).
   *
   * @return bool
   *   TRUE if the user is allowed or trusted for any source, FALSE otherwise.
   */
  public static function isUserAllowedOrTrustedForAnySource(?AccountInterface $account = NULL, string $bundle = 'job'): bool {
    $account = $account ?: \Drupal::currentUser();

    // Anonymous users are never allowed or trusted.
    if ($account->isAnonymous()) {
      return FALSE;
    }

    // Validate the bundle.
    if (!in_array($bundle, ['job', 'training', 'report'])) {
      throw new \InvalidArgumentException("Invalid bundle: $bundle. Must be 'job', 'training', or 'report'.");
    }

    $sources = static::getSourcesWithPostingRightsForUser($account, [$bundle => [2, 3]], limit: 1);
    return !empty($sources);
  }

  /**
   * Get the sources the user has posting rights for.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User for which to retrieve the sources.
   * @param array<string, array<int>> $bundles
   *   Bundle rights filters in the form of an associative array with the
   *   bundles (job, training, report) as keys and a list of rights (0, 1, 2, 3)
   *   as values.
   * @param string $operator
   *   How to combine the bundle rights conditions.
   * @param ?int $limit
   *   Number of sources to retrieve.
   *
   * @return array<int, array<string, mixed>>
   *   Associative array with the source IDs as keys and the corresponding
   *   posting rights as values.
   */
  public static function getSourcesWithPostingRightsForUser(
    AccountInterface $account,
    array $bundles = [],
    string $operator = 'AND',
    ?int $limit = NULL,
  ): array {
    // Fetch the user posting rights.
    $results = static::getSourcesWithUserPostingRightsForUser($account, $bundles, $operator, $limit);

    // Fetch domain posting rights to merge with user posting rights.
    // We use the `+` operator to merge the results so that user posting rights
    // take precedence over domain posting rights.
    $results += static::getSourcesWithDomainPostingRightsForUser($account, $bundles, $operator, $limit);

    return $results;
  }

  /**
   * Get the sources the user has user posting rights for.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User for which to retrieve the sources.
   * @param array<string, array<int>> $bundles
   *   Bundle rights filters in the form of an associative array with the
   *   bundles (job, training, report) as keys and a list of rights (0, 1, 2, 3)
   *   as values.
   * @param string $operator
   *   How to combine the bundle rights conditions.
   * @param ?int $limit
   *   Number of sources to retrieve.
   *
   * @return array<int, array<string, mixed>>
   *   Associative array with the source IDs as keys and the corresponding
   *   posting rights as values.
   */
  public static function getSourcesWithUserPostingRightsForUser(
    AccountInterface $account,
    array $bundles = [],
    string $operator = 'AND',
    ?int $limit = NULL,
  ): array {
    $helper = new self();
    $database = $helper->getDatabase();

    // Get user posting rights.
    $table = $helper->getFieldTableName('taxonomy_term', 'field_user_posting_rights');
    $id_field = $helper->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'id');

    /** @var array<string, string> $bundle_fields */
    $bundle_fields = [];
    $bundle_fields['job'] = $helper->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'job');
    $bundle_fields['training'] = $helper->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'training');
    $bundle_fields['report'] = $helper->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'report');

    $query = $database->select($table, $table);
    $query->fields($table, ['entity_id']);
    $query->condition($table . '.bundle', 'source', '=');
    $query->condition($table . '.' . $id_field, $account->id(), '=');

    $condition_group = NULL;
    foreach ($bundle_fields as $bundle => $bundle_field) {
      $query->addField($table, $bundle_field, $bundle);

      // Filter by bundle rights.
      if (!empty($bundles[$bundle])) {
        $condition_group ??= $query->conditionGroupFactory($operator);
        $condition_group->condition($table . '.' . $bundle_field, $bundles[$bundle], 'IN');
      }
    }

    if (isset($condition_group)) {
      $query->condition($condition_group);
    }

    // Limit the result.
    if (!empty($limit)) {
      $query->range(0, $limit);
    }

    $results = $query->execute()?->fetchAllAssoc('entity_id', FetchAs::Associative);

    return $results;
  }

  /**
   * Get the sources the user has domain posting rights for.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User for which to retrieve the sources.
   * @param array<string, array<int>> $bundles
   *   Bundle rights filters in the form of an associative array with the
   *   bundles (job, training, report) as keys and a list of rights (0, 1, 2, 3)
   *   as values.
   * @param string $operator
   *   How to combine the bundle rights conditions.
   * @param ?int $limit
   *   Number of sources to retrieve.
   *
   * @return array<int, array<string, mixed>>
   *   Associative array with the source IDs as keys and the corresponding
   *   posting rights as values.
   */
  public static function getSourcesWithDomainPostingRightsForUser(
    AccountInterface $account,
    array $bundles = [],
    string $operator = 'AND',
    ?int $limit = NULL,
  ): array {
    $helper = new self();
    $database = $helper->getDatabase();

    // Get user's email domain.
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($account->id());
    if (!$user || !$user->getEmail()) {
      return [];
    }

    $domain = static::extractDomainFromEmail($user->getEmail());
    if (!$domain) {
      return [];
    }

    // Get domain posting rights.
    $table = $helper->getFieldTableName('taxonomy_term', 'field_domain_posting_rights');
    $domain_field = $helper->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'domain');

    /** @var array<string, string> $bundle_fields */
    $bundle_fields = [];
    $bundle_fields['job'] = $helper->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'job');
    $bundle_fields['training'] = $helper->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'training');
    $bundle_fields['report'] = $helper->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'report');

    $query = $database->select($table, $table);
    $query->fields($table, ['entity_id']);
    $query->condition($table . '.bundle', 'source', '=');
    $query->condition($table . '.' . $domain_field, $domain, '=');

    $condition_group = NULL;
    foreach ($bundle_fields as $bundle => $bundle_field) {
      $query->addField($table, $bundle_field, $bundle);

      // Filter by bundle rights.
      if (!empty($bundles[$bundle])) {
        $condition_group ??= $query->conditionGroupFactory($operator);
        $condition_group->condition($table . '.' . $bundle_field, $bundles[$bundle], 'IN');
      }
    }

    if (isset($condition_group)) {
      $query->condition($condition_group);
    }

    // Limit the result.
    if (!empty($limit)) {
      $query->range(0, $limit);
    }

    return $query->execute()?->fetchAllAssoc('entity_id', FetchAs::Associative);
  }

  /**
   * Format a user posting right.
   *
   * @param string $right
   *   Right.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   Formatted right.
   */
  public static function renderRight(string $right): MarkupInterface {
    $build = [
      '#theme' => 'reliefweb_moderation_user_posting_right',
      '#right' => $right,
    ];
    return \Drupal::service('renderer')->render($build);
  }

}
