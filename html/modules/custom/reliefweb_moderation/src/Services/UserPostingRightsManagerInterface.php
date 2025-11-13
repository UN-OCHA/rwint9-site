<?php

declare(strict_types=1);

namespace Drupal\reliefweb_moderation\Services;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;

/**
 * Interface for the user posting rights manager.
 */
interface UserPostingRightsManagerInterface {

  /**
   * Reset the user posting rights cache.
   */
  public function resetCache(): void;

  /**
   * Check if the entity supports posting rights.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return bool
   *   TRUE if the entity supports posting rights, FALSE otherwise.
   */
  public function entitySupportsPostingRights(EntityInterface $entity): bool;

  /**
   * Check if the entity bundle supports posting rights.
   *
   * @param string $bundle
   *   Entity bundle.
   *
   * @return bool
   *   TRUE if the entity supports posting rights, FALSE otherwise.
   */
  public function entityBundleSupportsPostingRights(string $bundle): bool;

  /**
   * Get the user posting rights for an entity's author.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return string
   *   Consolidated posting rights for the author of the entity.
   */
  public function getEntityAuthorPostingRights(EntityInterface $entity): string;

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
  public function getUserPostingRights(?AccountInterface $account = NULL, array $sources = []): array;

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
  public function getDomainPostingRights(AccountInterface $account, array $sources = []): array;

  /**
   * Extract domain from email address.
   *
   * @param string $email
   *   Email address.
   *
   * @return string|null
   *   Domain part of the email address or NULL if invalid.
   */
  public function extractDomainFromEmail(string $email): ?string;

  /**
   * Get allowed content types for a source entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source_entity
   *   Source entity.
   *
   * @return array<string, bool>
   *   Associative array of allowed content type bundles.
   */
  public function getAllowedContentTypes(ContentEntityInterface $source_entity): array;

  /**
   * Filter posting rights based on allowed content types for sources.
   *
   * @param array<int, array<string, mixed>> $results
   *   Posting rights results.
   *
   * @return array<int, array<string, mixed>>
   *   Filtered posting rights results.
   */
  public function filterPostingRightsByAllowedContentTypes(array $results): array;

  /**
   * Get an account's consolidated posting right for a document.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   A user's account object.
   * @param string $bundle
   *   Entity bundle.
   * @param array<int> $sources
   *   List of sources. Limit the returned rights to the given sources.
   *
   * @return array<string, mixed>
   *   Associative array with the right code and name.
   */
  public function getUserConsolidatedPostingRight(AccountInterface $account, string $bundle, array $sources): array;

  /**
   * Check if a user has posting rights on the entity.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   A user's account object.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity for which to check access.
   * @param string $status
   *   Entity status.
   *
   * @return bool
   *   Whether the user has posting rights or not.
   */
  public function userHasPostingRights(AccountInterface $account, EntityInterface $entity, string $status): bool;

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
  public function isUserAllowedOrTrustedForAnySource(?AccountInterface $account = NULL, string $bundle = 'job'): bool;

  /**
   * Get the sources the user has posting rights for.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User for which to retrieve the sources.
   * @param array<string, array<int>> $bundles
   *   Bundle rights filters.
   * @param string $operator
   *   How to combine the bundle rights conditions.
   * @param ?int $limit
   *   Number of sources to retrieve.
   *
   * @return array<int, array<string, mixed>>
   *   Associative array with the source IDs as keys.
   */
  public function getSourcesWithPostingRightsForUser(
    AccountInterface $account,
    array $bundles = [],
    string $operator = 'AND',
    ?int $limit = NULL,
  ): array;

  /**
   * Get the sources the user has user posting rights for.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User for which to retrieve the sources.
   * @param array<string, array<int>> $bundles
   *   Bundle rights filters.
   * @param string $operator
   *   How to combine the bundle rights conditions.
   * @param ?int $limit
   *   Number of sources to retrieve.
   *
   * @return array<int, array<string, mixed>>
   *   Associative array with the source IDs as keys.
   */
  public function getSourcesWithUserPostingRightsForUser(
    AccountInterface $account,
    array $bundles = [],
    string $operator = 'AND',
    ?int $limit = NULL,
  ): array;

  /**
   * Get the sources the user has domain posting rights for.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User for which to retrieve the sources.
   * @param array<string, array<int>> $bundles
   *   Bundle rights filters.
   * @param string $operator
   *   How to combine the bundle rights conditions.
   * @param ?int $limit
   *   Number of sources to retrieve.
   *
   * @return array<int, array<string, mixed>>
   *   Associative array with the source IDs as keys.
   */
  public function getSourcesWithDomainPostingRightsForUser(
    AccountInterface $account,
    array $bundles = [],
    string $operator = 'AND',
    ?int $limit = NULL,
  ): array;

  /**
   * Get the sources with domain posting rights for a given domain.
   *
   * @param string $domain
   *   Domain for which to retrieve the sources.
   * @param array<string, array<int>> $bundles
   *   Bundle rights filters.
   * @param string $operator
   *   How to combine the bundle rights conditions.
   * @param ?int $limit
   *   Number of sources to retrieve.
   *
   * @return array<int, array<string, mixed>>
   *   Associative array with the source IDs as keys and the corresponding
   *   posting rights as values.
   */
  public function getSourcesWithDomainPostingRightsForDomain(
    string $domain,
    array $bundles = [],
    string $operator = 'AND',
    ?int $limit = NULL,
  ): array;

  /**
   * Format a user posting right.
   *
   * @param string $right
   *   Right.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   Formatted right.
   */
  public function renderRight(string $right): MarkupInterface;

  /**
   * Get the user posting rights to moderation status mapping.
   *
   * @return array<string, array<string, array<string, string>>>
   *   Mapping of user posting rights to moderation status.
   */
  public function getUserPostingRightsToModerationStatusMapping(): array;

  /**
   * Set the user posting rights to moderation status mapping.
   *
   * @param array<string, array<string, array<string, string>>> $mapping
   *   Mapping of user posting rights to moderation status.
   */
  public function setUserPostingRightsToModerationStatusMapping(array $mapping): void;

  /**
   * Update moderation status based on user posting rights.
   *
   * @param \Drupal\reliefweb_moderation\EntityModeratedInterface $entity
   *   The entity to update.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user for whom to check posting rights.
   * @param array<string> $statuses
   *   Array of statuses that warrant modification.
   *
   * @return bool
   *   TRUE if the moderation status was updated, FALSE otherwise.
   */
  public function updateModerationStatusFromPostingRights(
    EntityModeratedInterface $entity,
    AccountInterface $user,
    array $statuses = ['pending'],
  ): bool;

  /**
   * Get the list of content types that support posting rights.
   *
   * @return array<string>
   *   Array of content type machine names.
   */
  public function getSupportedContentTypes(): array;

}
