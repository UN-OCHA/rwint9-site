<?php

namespace Drupal\reliefweb_subscriptions\Command;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
use Drupal\Core\Database\Connection;
use Drupal\reliefweb_subscriptions\ReliefwebSubscriptionsMailer;
use Drush\Commands\DrushCommands;

/**
 * Docstore Drush commandfile.
 */
class ReliefwebSubscriptionsSendCommand extends DrushCommands implements SiteAliasManagerAwareInterface {

  // Drush traits.
  use ProcessManagerAwareTrait;
  use SiteAliasManagerAwareTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The actual mailer.
   *
   * @var \Drupal\reliefweb_subscriptions\ReliefwebSubscriptionsMailer
   */
  protected $mailer;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    Connection $database,
    ReliefwebSubscriptionsMailer $mailer
  ) {
    $this->database = $database;
    $this->mailer = $mailer;
  }

  /**
   * Send notifications.
   *
   * @param int $limit
   *   Max number of items to send.
   *
   * @command reliefweb_subscriptions:send
   * @usage reliefweb_subscriptions:send
   *   Send emails.
   * @validate-module-enabled reliefweb_subscriptions
   */
  public function send($limit = 50) {
    // Get queued notifications, older first.
    // Triggered notifications have priority.
    $query = $this->database->select('reliefweb_subscriptions_queue', 'q');
    $query->fields('q', ['eid', 'sid', 'bundle', 'entity_id', 'last']);
    $query->addExpression('IF(q.entity_id > 0, 1, 0)', 'sortby');
    $query->orderBy('sortby', 'DESC');
    $query->orderBy('q.eid', 'ASC');
    $query->range(0, $limit);

    // Send the notifications.
    $notifications = $query->execute()?->fetchAllAssoc('eid');
    $this->mailer->send($notifications);

    // Remove the processed notifications from the queue.
    if (!empty($notifications)) {
      $query = $this->database->delete('reliefweb_subscriptions_queue');
      $query->condition('eid', array_keys($notifications), 'IN');
      $query->execute();
    }
  }

  /**
   * Queue notification.
   *
   * @param string $sid
   *   Subscription id.
   * @param array $options
   *   Drush options.
   *
   * @command reliefweb_subscriptions:queue
   * @usage reliefweb_subscriptions:queue
   *   Queue emails.
   * @validate-module-enabled reliefweb_subscriptions
   * @option entity_type
   *   Entity type.
   * @option entity_id
   *   Entity Id.
   * @option last
   *   Timestamp to use as the last time notifications were sent.
   */
  public function queue($sid, array $options = [
    'entity_type' => '',
    'entity_id' => 0,
    'last' => 0,
  ]) {
    $this->mailer->queue($sid, $options);
  }

}
