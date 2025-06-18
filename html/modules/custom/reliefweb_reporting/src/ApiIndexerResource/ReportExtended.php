<?php

namespace Drupal\reliefweb_reporting\ApiIndexerResource;

use RWAPIIndexer\Database\DatabaseConnection;
use RWAPIIndexer\Database\Query as DatabaseQuery;
use RWAPIIndexer\Elasticsearch;
use RWAPIIndexer\Options;
use RWAPIIndexer\Processor;
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
    bool $include_body = FALSE,
  ) {
    // Add extra fields.
    $this->queryOptions['fields']['user'] = 'uid';
    $this->queryOptions['field_joins']['field_origin'] = [
      'origin_type' => 'value',
    ];

    // Remove some fields.
    if ($include_body !== TRUE) {
      unset($this->queryOptions['field_joins']['body']);
    }

    parent::__construct(
      $bundle,
      $entity_type,
      $index,
      $elasticsearch,
      $connection,
      $processor,
      $references,
      $options,
    );
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
    $this->updateImporterData($items, $ids);

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

  /**
   * Update the import data for the given entity items.
   *
   * @param array<mixed> $items
   *   Entity items to update.
   * @param array<mixed> $ids
   *   Entity ids to update.
   */
  public function updateImporterData(array &$items, array $ids = []): void {
    if (empty($ids)) {
      foreach ($items as $item) {
        if (isset($item['id'])) {
          $ids[] = $item['id'];
        }
      }
    }

    if (empty($ids)) {
      return;
    }

    $records = $this->getImportRecords($ids);
    foreach ($items as &$item) {
      if (isset($item['id'])) {
        $item_id = $item['id'];
        if (isset($records[$item_id])) {
          $item['importer'] = [
            'name' => $records[$item_id]['importer'] ?? '',
            'source' => $records[$item_id]['source'] ?? '',
          ];
        }
      }
    }

  }

  /**
   * Retrieve import records.
   *
   * @return array
   *   An array of import records keyed by the import item id.
   */
  protected function getImportRecords(array $ids): array {
    $query = new DatabaseQuery('reliefweb_import_records', 'r', $this->connection);
    $query->addField('r', 'entity_id', 'entity_id');
    $query->addField('r', 'importer', 'importer');
    $query->addField('r', 'source', 'source');
    $query->condition('r.entity_id', $ids, 'IN');
    $query->condition('r.entity_bundle', 'report', '=');
    $records = $query->execute()?->fetchAllAssoc('entity_id', \PDO::FETCH_ASSOC) ?? [];

    // Deserialize the extra field.
    foreach ($records as &$record) {
      if (isset($record['extra'])) {
        $record['extra'] = json_decode($record['extra'], TRUE);
      }
    }

    return $records;
  }

}
