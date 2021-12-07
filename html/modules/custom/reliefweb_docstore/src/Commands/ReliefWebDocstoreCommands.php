<?php

namespace Drupal\reliefweb_docstore\Commands;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\reliefweb_docstore\Services\DocstoreClient;
use Drupal\reliefweb_utility\Helpers\LegacyHelper;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Drush\Commands\DrushCommands;

/**
 * ReliefWeb migration Drush commandfile.
 *
 * @todo remove after the migration from D7 to D9.
 */
class ReliefWebDocstoreCommands extends DrushCommands {

  use EntityDatabaseInfoTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The docstore client service.
   *
   * @var \Drupal\reliefweb_docstore\Services\DocstoreClient
   */
  protected $docstoreClient;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    Connection $database,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    DocstoreClient $docstore_client
  ) {
    $this->database = $database;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->docstoreClient = $docstore_client;
  }

  /**
   * Migrate the report attachments to the docstore.
   *
   * @command rw-docstore:migrate-attachments
   *
   * @option base_url The base url of the site from which to retrieve the files.
   * @option batch_size Number of reports with attachments to process at once.
   * @option limit Maximum number of reports with non migrated files to process,
   * 0 means process everything.
   *
   * @default options [
   *   'base_url' => 'https://reliefweb.int',
   *   'batch_size' => 1000,
   *   'limit' => 0,
   * ]
   *
   * @aliases rw-dma,rw-docstore-migrate-attachments
   *
   * @usage rw-docstore:migrate-attachments
   *   Migrate the attachments to the docstore
   *
   * @validate-module-enabled reliefweb_docstore
   */
  public function migrateAttachments($options = [
    'base_url' => 'https://reliefweb.int',
    'batch_size' => 1000,
    'limit' => 0,
  ]) {
    $base_url = $options['base_url'];
    $batch_size = (int) $options['batch_size'];
    $limit = (int) $options['limit'];

    if (preg_match('#^https?://[^/]+$#', $base_url) !== 1) {
      $this->logger()->error(dt('The base url must be in the form http(s)://example.test.'));
      return FALSE;
    }
    if ($batch_size < 1 || $batch_size > 1000) {
      $this->logger()->error(dt('The batch size must be within 1 and 1000.'));
      return FALSE;
    }
    if ($limit < 0) {
      $this->logger()->error(dt('The limit must be equal or superior to 0.'));
      return FALSE;
    }

    // Get the attachment field table.
    $table = $this->getFieldTableName('node', 'field_file');
    $revision_id_field = $this->getFieldColumnName('node', 'field_file', 'revision_id');

    // Retrieve the most recent report node with attachments.
    $query = $this->getDatabase()->select($table, $table);
    $query->condition($table . '.bundle', 'report');
    $query->addExpression('MAX(' . $table . '.entity_id)');
    $last = $query->execute()?->fetchField();

    if (empty($last)) {
      $this->logger()->info(dt('No report with attachments found.'));
      return TRUE;
    }

    $last = $last + 1;
    $count_ids = 0;
    $count_files = 0;

    while ($last !== NULL) {
      $ids = $this->getDatabase()
        ->select($table, $table)
        ->fields($table, ['entity_id'])
        ->condition($table . '.bundle', 'report')
        ->condition($table . '.entity_id', $last, '<')
        ->condition($table . '.' . $revision_id_field, 0, '=')
        ->groupBy($table . '.entity_id')
        ->orderBy($table . '.entity_id', 'DESC')
        ->range(0, $limit > 0 ? min($limit - $count_ids, $batch_size) : $batch_size)
        ->execute()
        ?->fetchCol() ?? [];

      if (!empty($ids)) {
        $last = min($ids);
        $count_ids += count($ids);
        $count_files += $this->migrateFiles($ids, $base_url);

        static::clearEntityCache('node', $ids);
      }
      else {
        $last = NULL;
        break;
      }

      if ($limit > 0 && $count_ids >= $limit) {
        break;
      }
    }

    $this->logger()->info(dt('Migrated @count_files attachments for @count_ids reports.', [
      '@count_files' => $count_files,
      '@count_ids' => $count_ids,
    ]));
    return TRUE;
  }

  /**
   * Migrate the file attached to the given entity ids.
   *
   * @param array $ids
   *   Entity ids.
   * @param string $base_url
   *   The base url of the site from which to retrieve the files.
   *
   * @return int
   *   The number of migrated files.
   */
  protected function migrateFiles(array $ids, $base_url) {
    $query = Database::getConnection('default', 'rwint7')
      ->select('field_data_field_file', 'f')
      ->fields('f', ['entity_id'])
      ->condition('f.entity_type', 'node', '=')
      ->condition('f.entity_id', $ids, 'IN');

    $query->innerJoin('file_managed', 'fm', 'fm.fid = f.field_file_fid');
    $query->fields('fm', ['fid', 'uri', 'filename', 'filemime']);

    $records = $query->execute()?->fetchAll(\PDO::FETCH_ASSOC) ?? [];

    // Group the files per entity.
    $entities = [];
    foreach ($records as $record) {
      $entities[$record['entity_id']][] = $record;
    }

    // Migrate the files and create/update the remote document resources.
    // @todo we need to find a way to identify if the file has been replaced.
    // Maybe we can generate the file_uuid based on the file ID.
    $count = 0;
    foreach ($entities as $entity_id => $files) {
      $uuids = [];
      foreach ($files as $file) {
        $uuid = $this->createOrUpdateRemoteFile($file, $base_url);
        if ($uuid !== FALSE) {
          $uuids[] = $uuid;
        }
      }
      $this->updateOrCreateRemoteDocument($entity_id, $uuids);
      $count += count($uuids);
    }
    return $count;
  }

  /**
   * Create or update a remote file with the database file data.
   *
   * @param array $file
   *   Legacy file data.
   * @param string $base_url
   *   The base url of the site from which to retrieve the files.
   *
   * @return string|false
   *   The UUID of the file resource on success or FALSE.
   */
  protected function createOrUpdateRemoteFile(array $file, $base_url) {
    $uuid = LegacyHelper::generateAttachmentUuid($file['uri']);

    // First, try to get the file resource.
    $response = $this->docstoreClient->getFile($uuid);

    // If the file doesn't exist, try to create it.
    if ($response->isNotFound()) {
      $response = $this->docstoreClient->createFile([
        'uuid' => $uuid,
        'filename' => $file['filename'],
        'mimetype' => $file['filemime'],
        // The docstore will fetch the file content from this URL.
        'uri' => LegacyHelper::getFileLegacyUrl($file['uri'], $base_url),
      ]);

      if (!$response->isSuccessful()) {
        $this->logger()->info(dt('Unable to create remote file for @uri.', [
          '@uri' => $file['uri'],
        ]));
        return FALSE;
      }
    }
    // Abort if something went wrong.
    elseif (!$response->isSuccessful()) {
      $this->logger()->info(dt('Unable to retrieve the remote file for @uri.', [
        '@uri' => $file['uri'],
      ]));
      return FALSE;
    }

    $content = $response->getContent();

    // If there is no revision id then it means no content was uploaded, try.
    if (empty($content['revision_id'])) {
      $url = LegacyHelper::getFileLegacyUrl($file['uri']);
      $response = $this->docstoreClient->updateFileContentFromFilePath($uuid, $url);

      if (!$response->isSuccessful()) {
        $this->logger()->info(dt('Unable to update remote file for @uri.', [
          '@uri' => $file['uri'],
        ]));
        return FALSE;
      }

      $content = $response->getContent();
    }

    // Get the revision ID.
    if (empty($content['revision_id'])) {
      $this->logger()->info(dt('Missing file revision id for @uri.', [
        '@uri' => $file['uri'],
      ]));
      return FALSE;
    }
    $revision_id = $content['revision_id'];

    // Update the file record with the revision id.
    $this->updateFileFieldRecord($uuid, $revision_id);

    // Select the revision ID as the active one.
    $this->docstoreClient->selectFileRevision($uuid, $revision_id);

    return $uuid;
  }

  /**
   * Update the file field record with the remote resource revision_id.
   *
   * @param string $uuid
   *   File resource UUID.
   * @param int $revision_id
   *   File resource revision id.
   */
  protected function updateFileFieldRecord($uuid, $revision_id) {
    $database = $this->getDatabase();
    $table = $this->getFieldTableName('node', 'field_file');
    $revision_table = $this->getFieldRevisionTableName('node', 'field_file');
    $uuid_field = $this->getFieldColumnName('node', 'field_file', 'uuid');
    $revision_id_field = $this->getFieldColumnName('node', 'field_file', 'revision_id');

    // Update field table.
    $database->update($table)
      ->fields([$revision_id_field => $revision_id])
      ->condition($uuid_field, $uuid)
      ->execute();

    // Update revision field table.
    $database->update($revision_table)
      ->fields([$revision_id_field => $revision_id])
      ->condition($uuid_field, $uuid)
      ->execute();
  }

  /**
   * Update or create a remote document resource.
   *
   * @param int $entity_id
   *   The entity id associated with the resource.
   * @param array $uuids
   *   The file uuids referenced by the document.
   *
   * @return string|false
   *   The UUID of the document resource on success or FALSE.
   */
  protected function updateOrCreateRemoteDocument($entity_id, array $uuids) {
    $uuid = LegacyHelper::generateDocumentUuid($entity_id);

    // First, try to get the document resource.
    $response = $this->docstoreClient->getDocument($uuid);

    // If the document doesn't exist, create it.
    if ($response->isNotFound()) {
      $response = $this->docstoreClient->createDocument([
        'uuid' => $uuid,
        'title' => $this->getNodeTitle($entity_id),
        'private' => TRUE,
        'files' => $uuids,
      ]);

      if (!$response->isSuccessful()) {
        $this->logger()->info(dt('Unable to create remote document @uuid.', [
          '@uuid' => $uuid,
        ]));
        return FALSE;
      }
    }
    // Abort if something went wrong.
    elseif (!$response->isSuccessful()) {
      $this->logger()->info(dt('Unable to retrieve the remote document @uuid.', [
        '@uuid' => $uuid,
      ]));
      return FALSE;
    }
    // Update the remote document.
    else {
      $response = $this->docstoreClient->updateDocument($uuid, [
        'files' => $uuids,
      ]);

      if (!$response->isSuccessful()) {
        $this->logger()->info(dt('Unable to update remote document for @uuid.', [
          '@uuid' => $uuid,
        ]));
        return FALSE;
      }
    }

    return $uuid;
  }

  /**
   * Get a node's title.
   *
   * @param int $id
   *   The node id.
   *
   * @return string
   *   The node title.
   */
  protected function getNodeTitle($id) {
    $table = $this->getEntityTypeDataTable('node');
    $id_field = $this->getEntityTypeIdField('node');
    $label_field = $this->getEntityTypeLabelField('node');

    return $this->getDatabase()
      ->select($table, $table)
      ->fields($table, [$label_field])
      ->condition($table . '.' . $id_field, $id, '=')
      ->execute()
      ?->fetchField();
  }

  /**
   * Reset the caches for the given entities.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param array $ids
   *   Entity IDs.
   */
  public static function clearEntityCache($entity_type_id, array $ids) {
    if (!empty($ids)) {
      $cache_tags = [];
      foreach ($ids as $id) {
        $cache_tags[] = $entity_type_id . ':' . $id;
      }
      Cache::invalidateTags($cache_tags);

      \Drupal::entityTypeManager()
        ->getStorage($entity_type_id)
        ?->resetCache($ids);
    }
  }

}
