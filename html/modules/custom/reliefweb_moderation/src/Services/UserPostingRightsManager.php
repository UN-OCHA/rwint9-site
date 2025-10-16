<?php

declare(strict_types=1);

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_utility\Helpers\TaxonomyHelper;
use Drupal\reliefweb_utility\Helpers\UserHelper;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Drupal\user\EntityOwnerInterface;

/**
 * Manager for user posting rights information.
 */
class UserPostingRightsManager implements UserPostingRightsManagerInterface {

  use EntityDatabaseInfoTrait;

  /**
   * Static cache for user posting rights.
   *
   * @var array
   */
  protected array $userPostingRightsCache = [];

  /**
   * Constructs a UserPostingRightsManager object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
    protected AccountProxyInterface $currentUser,
    protected StateInterface $state,
  ) {}

  /**
   * Reset the user posting rights cache.
   */
  public function resetCache(): void {
    $this->userPostingRightsCache = [];
  }

  /**
   * Check if the entity supports posting rights.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return bool
   *   TRUE if the entity supports posting rights, FALSE otherwise.
   */
  public function entitySupportsPostingRights(EntityInterface $entity): bool {
    return $this->entityBundleSupportsPostingRights($entity->bundle());
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
  public function entityBundleSupportsPostingRights(string $bundle): bool {
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
  public function getEntityAuthorPostingRights(EntityInterface $entity): string {
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
    $domain = $this->extractDomainFromEmail($email);

    $source_entities = $source_item_list->referencedEntities();
    foreach ($source_entities as $source_entity) {
      if (!($source_entity instanceof ContentEntityInterface)) {
        continue;
      }

      // Skip this source if the current bundle is not allowed for this source.
      $allowed_content_types = $this->getAllowedContentTypes($source_entity);
      if (empty($allowed_content_types[$bundle])) {
        continue;
      }

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
  public function getUserPostingRights(?AccountInterface $account = NULL, array $sources = []): array {
    $account = $account ?: $this->currentUser;

    // Static cache key for the combination account/sources.
    $key = $account->id() . ':' . implode('-', $sources);

    // Return the cached rights if any.
    if (isset($this->userPostingRightsCache[$key])) {
      return $this->userPostingRightsCache[$key];
    }

    // Skip the query for the anonymous user.
    if (!empty($account->id())) {
      $table = $this->getFieldTableName('taxonomy_term', 'field_user_posting_rights');
      $id_field = $this->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'id');
      $job_field = $this->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'job');
      $training_field = $this->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'training');
      $report_field = $this->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'report');

      // Get the rights associated with the user id.
      $query = $this->database->select($table, $table);
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
        $results += $this->getDomainPostingRights($account);
      }
      // Otherwise, check domain posting rights for the sources for which no
      // user posting rights were found.
      else {
        $missing_sources = array_diff($sources, array_keys($results));
        if (!empty($missing_sources)) {
          $results += $this->getDomainPostingRights($account, $missing_sources);
        }
      }
    }
    else {
      $results = [];
    }

    // Filter results based on allowed content types.
    $results = $this->filterPostingRightsByAllowedContentTypes($results);

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
    $this->userPostingRightsCache[$key] = $results;

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
  public function getDomainPostingRights(AccountInterface $account, array $sources = []): array {
    $results = [];

    // Get user's email domain.
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if (!$user || !$user->getEmail()) {
      return $results;
    }

    $domain = $this->extractDomainFromEmail($user->getEmail());
    if (!$domain) {
      return $results;
    }

    // Get domain posting rights table and field names.
    $table = $this->getFieldTableName('taxonomy_term', 'field_domain_posting_rights');
    $domain_field = $this->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'domain');
    $job_field = $this->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'job');
    $training_field = $this->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'training');
    $report_field = $this->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'report');

    // Query domain posting rights.
    $query = $this->database->select($table, $table);
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

    // Filter results based on allowed content types.
    return $this->filterPostingRightsByAllowedContentTypes($results);
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
  public function extractDomainFromEmail(string $email): ?string {
    if (empty($email) || strpos($email, '@') === FALSE) {
      return NULL;
    }

    [, $domain] = explode('@', $email, 2);
    return mb_strtolower(trim($domain));
  }

  /**
   * Get allowed content types for a source entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source_entity
   *   Source entity.
   *
   * @return array<string, bool>
   *   Associative array of allowed content type bundles (job, training, report)
   *   as keys and TRUE as values.
   */
  public function getAllowedContentTypes(ContentEntityInterface $source_entity): array {
    $allowed_content_types = [];

    if ($source_entity->hasField('field_allowed_content_types')) {
      foreach ($source_entity->get('field_allowed_content_types') as $item) {
        if (!$item->isEmpty()) {
          $bundle = match ((int) $item->value) {
            0 => 'job',
            1 => 'report',
            2 => 'training',
            default => NULL,
          };
          if (isset($bundle)) {
            $allowed_content_types[$bundle] = TRUE;
          }
        }
      }
    }

    return $allowed_content_types;
  }

  /**
   * Filter posting rights based on allowed content types for sources.
   *
   * @param array<int, array<string, mixed>> $results
   *   Posting rights results.
   *
   * @return array<int, array<string, mixed>>
   *   Filtered posting rights results.
   */
  public function filterPostingRightsByAllowedContentTypes(array $results): array {
    if (empty($results)) {
      return $results;
    }

    // Get allowed content types for all sources.
    $source_ids = array_keys($results);
    $allowed_table = $this->getFieldTableName('taxonomy_term', 'field_allowed_content_types');
    $allowed_field = $this->getFieldColumnName('taxonomy_term', 'field_allowed_content_types', 'value');

    $query = $this->database->select($allowed_table, 'allowed');
    $query->addField('allowed', 'entity_id', 'tid');
    $query->addField('allowed', $allowed_field, 'value');
    $query->condition('allowed.bundle', 'source', '=');
    $query->condition('allowed.entity_id', $source_ids, 'IN');

    $allowed_content_types = [];
    foreach ($query->execute() as $row) {
      $bundle = match ((int) $row->value) {
        0 => 'job',
        1 => 'report',
        2 => 'training',
        default => NULL,
      };
      if (isset($bundle)) {
        $allowed_content_types[$row->tid][$bundle] = TRUE;
      }
    }

    // Preserve original rights for allowed types; set to unverified (0)
    // for types not allowed for the source.
    foreach ($results as $tid => $data) {
      $allowed_types = $allowed_content_types[$tid] ?? [];
      $data['job'] = isset($allowed_types['job']) ? ($data['job'] ?? 0) : 0;
      $data['report'] = isset($allowed_types['report']) ? ($data['report'] ?? 0) : 0;
      $data['training'] = isset($allowed_types['training']) ? ($data['training'] ?? 0) : 0;
      $results[$tid] = $data;
    }

    return $results;
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
  public function getUserConsolidatedPostingRight(AccountInterface $account, string $bundle, array $sources): array {
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
    foreach ($this->getUserPostingRights($account, $sources) as $tid => $data) {
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
  public function userHasPostingRights(AccountInterface $account, EntityInterface $entity, string $status): bool {
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
    if ($this->entitySupportsPostingRights($entity)) {
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
        foreach ($this->getUserPostingRights($account, $sources) as $data) {
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
  public function isUserAllowedOrTrustedForAnySource(?AccountInterface $account = NULL, string $bundle = 'job'): bool {
    $account = $account ?: $this->currentUser;

    // Anonymous users are never allowed or trusted.
    if ($account->isAnonymous()) {
      return FALSE;
    }

    // Validate the bundle.
    if (!in_array($bundle, ['job', 'training', 'report'])) {
      throw new \InvalidArgumentException("Invalid bundle: $bundle. Must be 'job', 'training', or 'report'.");
    }

    $sources = $this->getSourcesWithPostingRightsForUser($account, [$bundle => [2, 3]], limit: 1);
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
  public function getSourcesWithPostingRightsForUser(
    AccountInterface $account,
    array $bundles = [],
    string $operator = 'AND',
    ?int $limit = NULL,
  ): array {
    // Fetch the user posting rights.
    $results = $this->getSourcesWithUserPostingRightsForUser($account, $bundles, $operator, $limit);

    // Fetch domain posting rights to merge with user posting rights.
    // We use the `+` operator to merge the results so that user posting rights
    // take precedence over domain posting rights.
    $results += $this->getSourcesWithDomainPostingRightsForUser($account, $bundles, $operator, $limit);

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
  public function getSourcesWithUserPostingRightsForUser(
    AccountInterface $account,
    array $bundles = [],
    string $operator = 'AND',
    ?int $limit = NULL,
  ): array {
    // Get user posting rights.
    $table = $this->getFieldTableName('taxonomy_term', 'field_user_posting_rights');
    $id_field = $this->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'id');

    /** @var array<string, string> $bundle_fields */
    $bundle_fields = [];
    $bundle_fields['job'] = $this->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'job');
    $bundle_fields['training'] = $this->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'training');
    $bundle_fields['report'] = $this->getFieldColumnName('taxonomy_term', 'field_user_posting_rights', 'report');

    $query = $this->database->select($table, $table);
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

    // Filter results based on allowed content types.
    return $this->filterPostingRightsByAllowedContentTypes($results);
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
  public function getSourcesWithDomainPostingRightsForUser(
    AccountInterface $account,
    array $bundles = [],
    string $operator = 'AND',
    ?int $limit = NULL,
  ): array {
    // Get user's email domain.
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if (!$user || !$user->getEmail()) {
      return [];
    }

    $domain = $this->extractDomainFromEmail($user->getEmail());
    if (!$domain) {
      return [];
    }

    // Get domain posting rights.
    $table = $this->getFieldTableName('taxonomy_term', 'field_domain_posting_rights');
    $domain_field = $this->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'domain');

    /** @var array<string, string> $bundle_fields */
    $bundle_fields = [];
    $bundle_fields['job'] = $this->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'job');
    $bundle_fields['training'] = $this->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'training');
    $bundle_fields['report'] = $this->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'report');

    $query = $this->database->select($table, $table);
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

    $results = $query->execute()?->fetchAllAssoc('entity_id', FetchAs::Associative);

    // Filter results based on allowed content types.
    return $this->filterPostingRightsByAllowedContentTypes($results);
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
  public function renderRight(string $right): MarkupInterface {
    $build = [
      '#theme' => 'reliefweb_moderation_user_posting_right',
      '#right' => $right,
    ];
    return $this->renderer->render($build);
  }

  /**
   * Get the user posting rights to moderation status mapping.
   *
   * @return array
   *   Mapping of user posting rights to moderation status.
   */
  public function getUserPostingRightsToModerationStatusMapping(): array {
    return $this->state->get('reliefweb_posting_rights_status_mapping', []);
  }

  /**
   * Set the user posting rights to moderation status mapping.
   *
   * @param array $mapping
   *   Mapping of user posting rights to moderation status.
   */
  public function setUserPostingRightsToModerationStatusMapping(array $mapping): void {
    $this->state->set('reliefweb_posting_rights_status_mapping', $mapping);
  }

  /**
   * {@inheritdoc}
   */
  public function updateModerationStatusFromPostingRights(
    EntityModeratedInterface $entity,
    AccountInterface $user,
    array $statuses = ['pending'],
  ): bool {
    // Get the current status and bundle.
    $status = $entity->getModerationStatus();
    $bundle = $entity->bundle();

    // Skip if there is no user.
    if (empty($user->id())) {
      return FALSE;
    }

    // Check if the current status warrants a modification.
    if (!in_array($status, $statuses)) {
      return FALSE;
    }

    // Get the editing user role for the entity bundle and current user
    // so we can retrieve the correct status mapping.
    $role = UserHelper::getEditingUserRole($bundle, $user);
    if (empty($role)) {
      return FALSE;
    }

    // Retrieve the user posting rights to moderation status mapping.
    $mapping = $this->getUserPostingRightsToModerationStatusMapping();
    if (!isset($mapping[$role][$bundle])) {
      return FALSE;
    }

    // Skip if there are no sources.
    if (!$entity->hasField('field_source') || $entity->field_source->isEmpty()) {
      return FALSE;
    }

    // Extract source ids.
    $sources = [];
    foreach ($entity->field_source as $item) {
      if (!empty($item->target_id)) {
        $sources[] = $item->target_id;
      }
    }

    // Get the user's posting right for the document.
    $rights = [0 => [], 1 => [], 2 => [], 3 => []];
    foreach ($this->getUserPostingRights($user, $sources) as $tid => $data) {
      $rights[$data[$bundle] ?? 0][] = $tid;
    }

    $scenario = match (TRUE) {
      // Blocked for any source.
      !empty($rights[1]) => 'blocked',
      // Trusted for all the sources.
      count($rights[3]) === count($sources) => 'trusted_all',
      // Trusted for some sources with the rest allowed (since no unverified).
      !empty($rights[3]) && empty($rights[0]) => 'trusted_some_allowed',
      // Trusted for some sources with some unverified sources.
      !empty($rights[3]) && !empty($rights[0]) => 'trusted_some_unverified',
      // Allowed for all the sources.
      count($rights[2]) === count($sources) => 'allowed_all',
      // Allowed for some sources with the rest unverified.
      !empty($rights[2]) && !empty($rights[0]) => 'allowed_some_unverified',
      // Unverified for all sources or default scenario.
      default => 'unverified_all',
    };

    // Skip if there no status for the scenario.
    if (!isset($mapping[$role][$bundle][$scenario])) {
      return FALSE;
    }

    // Get the updated status.
    $status = $mapping[$role][$bundle][$scenario];

    // Update the status.
    $entity->setModerationStatus($status);

    // Add messages indicating the posting rights for easier review.
    $message = '';
    if (!empty($rights[1])) {
      $message = trim($message . strtr(' Blocked user for @sources.', [
        '@sources' => implode(', ', TaxonomyHelper::getSourceShortnames($rights[1])),
      ]));
    }
    if (!empty($rights[0])) {
      $message = trim($message . strtr(' Unverified user for @sources.', [
        '@sources' => implode(', ', TaxonomyHelper::getSourceShortnames($rights[0])),
      ]));
    }
    if (!empty($rights[2])) {
      $message = trim($message . strtr(' Allowed user for @sources.', [
        '@sources' => implode(', ', TaxonomyHelper::getSourceShortnames($rights[2])),
      ]));
    }
    if (!empty($rights[3])) {
      $message = trim($message . strtr(' Trusted user for @sources.', [
        '@sources' => implode(', ', TaxonomyHelper::getSourceShortnames($rights[3])),
      ]));
    }
    // Prepend the message to the revision log.
    $entity->updateRevisionLogMessage($message, 'prepend');

    return TRUE;
  }

}
