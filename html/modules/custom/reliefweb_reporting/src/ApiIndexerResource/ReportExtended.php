<?php

namespace Drupal\reliefweb_reporting\ApiIndexerResource;

use RWAPIIndexer\Database\DatabaseConnection;
use RWAPIIndexer\Database\Query as DatabaseQuery;
use RWAPIIndexer\Elasticsearch;
use RWAPIIndexer\Options;
use RWAPIIndexer\Processor;
use RWAPIIndexer\Query;
use RWAPIIndexer\References;
use RWAPIIndexer\Resources\Report;

/**
 * Extension of the ReliefWeb API indexer report resource to get more data.
 */
class ReportExtended extends Report {

  /**
   * Simple mapping of the origin types.
   *
   * @var array<string>
   */
  protected array $originTypes = [
    'URL',
    'Submit mailbox',
    'Reliefweb product',
    'API',
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $bundle,
    $entity_type,
    $index,
    Elasticsearch $elasticsearch,
    DatabaseConnection $connection,
    Processor $processor,
    References $references,
    Options $options,
  ) {
    $this->bundle = $bundle;
    $this->entityType = $entity_type;
    $this->index = $index;
    $this->elasticsearch = $elasticsearch;
    $this->connection = $connection;
    $this->processor = $processor;
    $this->references = $references;
    $this->options = $options;

    $query_options = $this->queryOptions;

    // Add extra fields.
    $query_options['fields']['user'] = 'uid';
    $query_options['field_joins']['field_origin'] = [
      'origin_type' => 'value',
    ];

    // Remove some fields.
    unset($query_options['field_joins']['body']);
    unset($query_options['field_joins']['field_headline_image']);
    unset($query_options['field_joins']['field_headline_summary']);
    unset($query_options['references']['vulnerable_groups']);

    // Only apply filter to the resource being indexed (not to references).
    if ($this->bundle === $options->get('bundle')) {
      $query_options['filters'] = $this->parseFilters($options->get('filter'));
    }

    // Create a new Query object to get the items to index.
    $this->query = new Query($connection, $this->entityType, $this->bundle, $query_options);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem(&$item) {
    parent::processItem($item);

    if (isset($item['origin_type'])) {
      if (isset($this->originTypes[$item['origin_type']])) {
        $item['origin_type'] = $this->originTypes[$item['origin_type']];
      }
      else {
        unset($item['origin_type']);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getItems($limit = NULL, $offset = NULL, ?array $ids = NULL) {
    $items = parent::getItems($limit, $offset, $ids);

    $this->updateUserData($items);

    return $items;
  }

  /**
   * Update the user data for the given entity items.
   *
   * @param array<mixed> $items
   *   Entity items to update.
   *
   * @todo Cache the user data?
   */
  public function updateUserData(array &$items): void {
    $user_ids = [];
    foreach ($items as $item) {
      if (isset($item['user'])) {
        $user_ids[] = $item['user'];
      }
    }

    if (empty($user_ids)) {
      return;
    }

    $query = new DatabaseQuery('users_field_data', 'users', $this->connection);
    $query->addField('users', 'uid', 'uid');
    $query->addField('users', 'name', 'name');
    $query->addField('users', 'mail', 'mail');
    $query->condition('users.uid', $user_ids, 'IN');
    $users = $query->execute()?->fetchAllAssoc('uid', \PDO::FETCH_ASSOC) ?? [];

    // Add the user data to the items.
    foreach ($items as &$item) {
      if (isset($item['user'])) {
        if (isset($users[$item['user']])) {
          $user = $users[$item['user']];

          if ($user['uid'] == 1) {
            $name = 'Administrator';
            $role = 'administrator';
          }
          elseif ($user['uid'] == 2) {
            $name = 'System';
            $role = '';
          }
          // This is somehow more reliable that checking the roles because
          // roles can be removed while, in theory, reliefweb.int email
          // addresses are only for editors.
          else {
            $name = $user['name'];
            $role = str_contains($user['mail'], '@reliefweb.int') ? 'editor' : 'contributor';
          }

          $item['user'] = [
            'id' => $user['uid'],
            'name' => $name,
            'role' => $role,
          ];
        }
        else {
          unset($item['user']);
        }
      }
    }
  }

}
