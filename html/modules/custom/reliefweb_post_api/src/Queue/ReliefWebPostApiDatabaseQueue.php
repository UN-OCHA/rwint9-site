<?php

namespace Drupal\reliefweb_post_api\Queue;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\InvalidMergeQueryException;
use Drupal\Core\Queue\DatabaseQueue;

/**
 * Submission queue with unique items.
 */
class ReliefWebPostApiDatabaseQueue extends DatabaseQueue {

  /**
   * The database table name.
   *
   * We need a separate table as we use a different schema.
   */
  public const TABLE_NAME = 'reliefweb_post_api_queue';

  /**
   * Constructor.
   *
   * @param string $name
   *   The name of the queue.
   * @param \Drupal\Core\Database\Connection $connection
   *   The Connection object containing the key-value tables.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    string $name,
    Connection $connection,
    protected TimeInterface $time,
  ) {
    parent::__construct($name, $connection);
  }

  /**
   * {@inheritdoc}
   */
  public function doCreateItem($data) {
    if (empty($data['uuid'])) {
      return FALSE;
    }

    $uuid = $data['uuid'];

    try {
      $query = $this->connection
        ->merge('reliefweb_post_api_queue')
        ->key(['uuid' => $uuid])
        ->fields([
          'name' => $this->name,
          'data' => serialize($data),
          // We cannot rely on REQUEST_TIME because many items might be created
          // by a single request which takes longer than 1 second.
          'created' => $this->time->getCurrentTime(),
          'uuid' => $uuid,
        ]);
      $query->execute();
    }
    catch (InvalidMergeQueryException $exception) {
      return FALSE;
    }

    // We return the UUID since the merge query doesn't return the created or
    // update ID field.
    return $uuid;
  }

  /**
   * {@inheritdoc}
   */
  public function schemaDefinition() {
    return array_merge_recursive(
      parent::schemaDefinition(),
      [
        'fields' => [
          'uuid' => [
            'type' => 'varchar_ascii',
            'length' => 36,
            'not null' => TRUE,
            'description' => 'The UUID of the item.',
          ],
        ],
        'unique keys' => [
          'unique' => ['uuid'],
        ],
      ]
    );
  }

}
