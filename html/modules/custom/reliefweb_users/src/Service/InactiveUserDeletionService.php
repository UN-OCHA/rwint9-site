<?php

declare(strict_types=1);

namespace Drupal\reliefweb_users\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\user\UserInterface;

/**
 * Finds and deletes inactive user accounts with no ReliefWeb activity.
 */
class InactiveUserDeletionService implements InactiveUserDeletionServiceInterface {

  /**
   * Seconds in one week.
   */
  protected const int SECONDS_PER_WEEK = 7 * 24 * 60 * 60;

  /**
   * Constructs an InactiveUserDeletionService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TimeInterface $time,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Get the logger.
   *
   * @return \Drupal\Core\Logger\LoggerChannelInterface
   *   The logger channel.
   */
  protected function getLogger(): LoggerChannelInterface {
    return $this->loggerFactory->get('reliefweb_users');
  }

  /**
   * {@inheritdoc}
   */
  public function getCutoffTimestamp(int $weeks): int {
    return $this->time->getRequestTime() - ($weeks * self::SECONDS_PER_WEEK);
  }

  /**
   * {@inheritdoc}
   */
  public function getEffectiveActivityTimestamp(int $access, int $created): int {
    return $access > 0 ? $access : $created;
  }

  /**
   * {@inheritdoc}
   */
  public function formatActivityForLog(int $access, int $created): string {
    if ($access > 0) {
      return date('Y-m-d H:i:s', $access);
    }

    return 'never accessed (created ' . date('Y-m-d H:i:s', $created) . ')';
  }

  /**
   * {@inheritdoc}
   */
  public function findCandidateUids(int $weeks, int $limit, string $sort = self::SORT_OLDEST): array {
    if (!in_array($sort, [self::SORT_OLDEST, self::SORT_NEWEST], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Invalid sort value "%s".', $sort));
    }

    $cutoff = $this->getCutoffTimestamp($weeks);
    $query = $this->buildEligibilityQuery($cutoff);
    $query->fields('ufd', ['uid', 'mail', 'access', 'created']);
    $query->addExpression(
      'CASE WHEN ufd.access > 0 THEN ufd.access ELSE ufd.created END',
      'effective_activity',
    );
    $direction = $sort === self::SORT_NEWEST ? 'DESC' : 'ASC';
    $query->orderBy('effective_activity', $direction);
    $query->range(0, $limit);

    $results = $query->execute()?->fetchAll(\PDO::FETCH_ASSOC) ?? [];
    $candidates = [];
    foreach ($results as $row) {
      $candidates[] = [
        'uid' => (int) $row['uid'],
        'mail' => $row['mail'],
        'access' => (int) $row['access'],
        'created' => (int) $row['created'],
      ];
    }
    return $candidates;
  }

  /**
   * {@inheritdoc}
   */
  public function isEligible(int $uid, int $cutoff): bool {
    if ($uid <= 2) {
      return FALSE;
    }

    $query = $this->buildEligibilityQuery($cutoff);
    $query->addExpression('1', 'exists');
    $query->condition('ufd.uid', $uid);

    return (bool) $query->execute()?->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteUser(int $uid, int $cutoff): bool {
    if (!$this->isEligible($uid, $cutoff)) {
      return FALSE;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return FALSE;
    }

    $this->getLogger()->info('Deleting inactive user account @uid (@mail), last activity @activity.', [
      '@uid' => $uid,
      '@mail' => $user->getEmail(),
      '@activity' => $this->formatActivityForLog(
        (int) $user->getLastAccessedTime(),
        (int) $user->getCreatedTime(),
      ),
    ]);

    $user->delete();
    return TRUE;
  }

  /**
   * Build the base eligibility query with all exclusion conditions.
   *
   * @param int $cutoff
   *   Effective activity cutoff timestamp.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The select query.
   */
  protected function buildEligibilityQuery(int $cutoff): SelectInterface {
    $query = $this->database->select('users_field_data', 'ufd');
    $query->condition('ufd.default_langcode', 1);
    $query->condition('ufd.uid', 2, '>');
    $query->where(
      '(CASE WHEN [ufd].[access] > 0 THEN [ufd].[access] ELSE [ufd].[created] END) < :cutoff',
      [':cutoff' => $cutoff],
    );

    $this->addNotExistsSubquery($query, 'user__roles', 'r', static function (SelectInterface $subquery): void {
      $subquery->where('[r].[entity_id] = [ufd].[uid]');
      $subquery->condition('r.deleted', 0);
      $subquery->condition('r.roles_target_id', ['authenticated'], 'NOT IN');
    });

    $this->addNotExistsSubquery($query, 'node_field_revision', 'n', static function (SelectInterface $subquery): void {
      $subquery->where('[n].[uid] = [ufd].[uid]');
    });

    $this->addNotExistsSubquery($query, 'reliefweb_subscriptions_subscriptions', 's', static function (SelectInterface $subquery): void {
      $subquery->where('[s].[uid] = [ufd].[uid]');
    });

    $this->addNotExistsSubquery($query, 'reliefweb_bookmarks', 'b', static function (SelectInterface $subquery): void {
      $subquery->where('[b].[uid] = [ufd].[uid]');
    });

    $this->addNotExistsSubquery($query, 'taxonomy_term__field_user_posting_rights', 'p', static function (SelectInterface $subquery): void {
      $subquery->where('[p].[field_user_posting_rights_id] = [ufd].[uid]');
      $subquery->condition('p.deleted', 0);
    });

    $this->addNotExistsSubquery($query, 'user__field_api_key', 'k', static function (SelectInterface $subquery): void {
      $subquery->where('[k].[entity_id] = [ufd].[uid]');
      $subquery->condition('k.deleted', 0);
      $subquery->isNotNull('k.field_api_key_value');
      $subquery->condition('k.field_api_key_value', '', '<>');
    });

    $this->addNotExistsSubquery($query, 'user__field_notes', 'fn', static function (SelectInterface $subquery): void {
      $subquery->where('[fn].[entity_id] = [ufd].[uid]');
      $subquery->condition('fn.deleted', 0);
      $subquery->condition('fn.field_notes_value', '', '<>');
    });

    $this->addNotExistsSubquery($query, 'file_managed', 'f', static function (SelectInterface $subquery): void {
      $subquery->where('[f].[uid] = [ufd].[uid]');
    });

    $this->addNotExistsSubquery($query, 'media_field_data', 'm', static function (SelectInterface $subquery): void {
      $subquery->where('[m].[uid] = [ufd].[uid]');
    });

    return $query;
  }

  /**
   * Add a correlated NOT EXISTS subquery to an eligibility query.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The parent query.
   * @param string $table
   *   Subquery table name.
   * @param string $alias
   *   Subquery table alias.
   * @param callable $configure
   *   Callback receiving the subquery for additional conditions.
   */
  protected function addNotExistsSubquery(
    SelectInterface $query,
    string $table,
    string $alias,
    callable $configure,
  ): void {
    $subquery = $this->database->select($table, $alias);
    $subquery->addExpression('1');
    $configure($subquery);
    $query->notExists($subquery);
  }

}
