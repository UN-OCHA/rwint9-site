<?php

declare(strict_types=1);

namespace Drupal\reliefweb_users\Service;

/**
 * Finds and deletes inactive user accounts with no ReliefWeb activity.
 */
interface InactiveUserDeletionServiceInterface {

  /**
   * Sort candidates by oldest last access first.
   */
  public const string SORT_OLDEST = 'oldest';

  /**
   * Sort candidates by newest last access first.
   */
  public const string SORT_NEWEST = 'newest';

  /**
   * Compute the activity cutoff timestamp for a given inactivity period.
   *
   * @param int $weeks
   *   Number of weeks of inactivity.
   *
   * @return int
   *   Unix timestamp; users with effective activity before this are inactive.
   */
  public function getCutoffTimestamp(int $weeks): int;

  /**
   * Resolve the effective activity timestamp for a user record.
   *
   * @param int $access
   *   Last access timestamp from users_field_data.access.
   * @param int $created
   *   Account creation timestamp from users_field_data.created.
   *
   * @return int
   *   Last access when the user has visited the site, otherwise created.
   */
  public function getEffectiveActivityTimestamp(int $access, int $created): int;

  /**
   * Format last activity for log output.
   *
   * @param int $access
   *   Last access timestamp from users_field_data.access.
   * @param int $created
   *   Account creation timestamp from users_field_data.created.
   *
   * @return string
   *   Human-readable last activity label.
   */
  public function formatActivityForLog(int $access, int $created): string;

  /**
   * Find candidate user accounts eligible for deletion.
   *
   * @param int $weeks
   *   Minimum inactivity period in weeks.
   * @param int $limit
   *   Maximum number of candidates to return.
   * @param string $sort
   *   Sort order: self::SORT_OLDEST or self::SORT_NEWEST.
   *
   * @return array<int, array{uid: int, mail: string|null, access: int, created: int}>
   *   Candidate records ordered by effective activity per $sort.
   */
  public function findCandidateUids(int $weeks, int $limit, string $sort = self::SORT_OLDEST): array;

  /**
   * Check whether a user account is eligible for deletion.
   *
   * @param int $uid
   *   User ID.
   * @param int $cutoff
   *   Effective activity cutoff timestamp.
   *
   * @return bool
   *   TRUE if the user is eligible.
   */
  public function isEligible(int $uid, int $cutoff): bool;

  /**
   * Delete a user account if still eligible.
   *
   * @param int $uid
   *   User ID.
   * @param int $cutoff
   *   Effective activity cutoff timestamp for eligibility recheck.
   *
   * @return bool
   *   TRUE if the user was deleted.
   */
  public function deleteUser(int $uid, int $cutoff): bool;

}
