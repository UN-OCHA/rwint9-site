<?php

namespace Drupal\reliefweb_post_api\Queue;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueDatabaseFactory;

/**
 * Factory class for generating unique database queues.
 */
class ReliefWebPostApiDatabaseQueueFactory extends QueueDatabaseFactory {

  /**
   * Constructs this factory object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The Connection object containing the key-value tables.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    Connection $connection,
    protected TimeInterface $time,
  ) {
    parent::__construct($connection);
  }

  /**
   * {@inheritdoc}
   */
  public function get($name) {
    return new ReliefWebPostApiDatabaseQueue($name, $this->connection, $this->time);
  }

}
