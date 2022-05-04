<?php

namespace Drupal\reliefweb_files\Commands;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Drupal\reliefweb_files\Services\DocstoreClient;
use Drupal\reliefweb_utility\Helpers\LegacyHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Drush\Commands\DrushCommands;

/**
 * ReliefWeb migration Drush commandfile.
 *
 * @todo remove after the migration from D7 to D9.
 */
class ReliefWebFilesCommands extends DrushCommands {

  use EntityDatabaseInfoTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

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
   * @var \Drupal\reliefweb_files\Services\DocstoreClient
   */
  protected $docstoreClient;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    Connection $database,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    DocstoreClient $docstore_client,
  ) {
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->docstoreClient = $docstore_client;
  }

  /**
   * Migrate the report attachments.
   *
   * @command rw-files:migrate-attachments
   *
   * @option base_url The base url of the site from which to retrieve the files.
   * @option batch_size Number of reports with attachments to process at once.
   * @option limit Maximum number of reports with non migrated files to process,
   * 0 means process everything.
   * @option preview_only Only download the attachment previews.
   * @option source_directory Local source directory with the ReliefWeb files.
   * If empty, fetch the files remotely using the base URL.
   *
   * @default options [
   *   'base_url' => 'https://reliefweb.int',
   *   'batch_size' => 1000,
   *   'limit' => 0,
   *   'preview_only' => 0,
   *   'source_directory' => '',
   * ]
   *
   * @aliases rw-fma,rw-files-migrate-attachments
   *
   * @usage rw-files:migrate-attachments
   *   Migrate the attachments.
   *
   * @validate-module-enabled reliefweb_files
   */
  public function migrateAttachments($options = [
    'base_url' => 'https://reliefweb.int',
    'batch_size' => 1000,
    'limit' => 0,
    'preview_only' => 0,
    'source_directory' => '',
  ]) {
    $base_url = $options['base_url'];
    $batch_size = (int) $options['batch_size'];
    $limit = (int) $options['limit'];
    $preview_only = !empty($options['preview_only']);
    $source_directory = rtrim($options['source_directory'], '/');

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
    if (!empty($source_directory) && !file_exists($source_directory)) {
      $this->logger()->error(dt('The source directory does not exist.'));
      return FALSE;
    }

    // Get the storage mode: local or docstore.
    $local = $this->configFactory->get('reliefweb_files.settings')->get('local') === TRUE;

    if (!empty($source_directory) && $local === FALSE) {
      $this->logger()->error(dt('The source directory option is only valid in local mode.'));
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
        $count_files += $this->migrateFiles($ids, $base_url, $local, $preview_only, $source_directory);

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
   * @param bool $local
   *   Whether to store the files locally or in the docstore.
   * @param bool $preview_only
   *   If TRUE, then only retrieve the previews.
   * @param string $source_directory
   *   The source directory with the ReliefWeb files.
   *
   * @return int
   *   The number of migrated files.
   */
  protected function migrateFiles(array $ids, $base_url, $local = FALSE, $preview_only = FALSE, $source_directory = '') {
    $query = Database::getConnection('default', 'rwint7')
      ->select('field_data_field_file', 'f')
      ->fields('f', ['entity_id', 'field_file_description'])
      ->condition('f.entity_type', 'node', '=')
      ->condition('f.entity_id', $ids, 'IN');

    $query->innerJoin('file_managed', 'fm', 'fm.fid = f.field_file_fid');
    $query->fields('fm', ['fid', 'uri', 'filename', 'filemime']);

    $records = $query->execute()?->fetchAll(\PDO::FETCH_ASSOC) ?? [];

    // Group the files per entity.
    $entities = [];
    foreach ($records as $record) {
      $file = $this->prepareFileData($record, $base_url, $source_directory);
      if (!empty($file)) {
        $entities[$record['entity_id']][] = $file;
      }
    }

    if (empty($entities)) {
      return 0;
    }

    // Migrate the files.
    if ($preview_only) {
      return $this->migratePreviewFiles($entities);
    }
    elseif ($local) {
      return $this->migrateLocalFiles($entities);
    }
    else {
      return $this->migrateRemoteFiles($entities);
    }
  }

  /**
   * Prepare the D9 file data from the D7 database record.
   *
   * @param array $record
   *   D7 file record.
   * @param string $base_url
   *   The base url of the site from which to retrieve the files.
   * @param string $source_directory
   *   The source directory with the ReliefWeb files.
   *
   * @return array
   *   D9 file data.
   */
  protected function prepareFileData(array $record, $base_url, $source_directory) {
    $uuid = LegacyHelper::generateAttachmentUuid($record['uri']);
    $file_uuid = LegacyHelper::generateAttachmentFileUuid($uuid, $record['fid']);

    if (empty($source_directory)) {
      $url = LegacyHelper::getFileLegacyUrl($record['uri'], $base_url);
    }
    else {
      $url = $source_directory . '/resources/' . basename($record['uri']);
    }

    $file = $this->getDatabase()
      ->select('file_managed', 'fm')
      ->fields('fm', ['fid', 'uri'])
      ->condition('fm.uuid', $file_uuid, '=')
      ->execute()
      ?->fetch(\PDO::FETCH_ASSOC);

    if (!empty($file)) {
      $file['filename'] = $record['filename'];
      $file['filemime'] = $record['filemime'];
      $file['uuid'] = $uuid;
      $file['file_uuid'] = $file_uuid;
      $file['url'] = $url;
      $file['private'] = strpos($file['uri'], 'private://') === 0;
      $file['preview_url'] = $this->getPreviewUrl($record, $base_url, $source_directory);
      $file['legacy_uri'] = $record['uri'];
    }
    else {
      $this->logger()->error(dt('No database entry found for file @uri.', [
        '@uri' => $record['uri'],
      ]));
    }
    return $file;
  }

  /**
   * Get the preview URL for the file record.
   *
   * @param array $record
   *   D7 file record.
   * @param string $base_url
   *   The base url of the site from which to retrieve the files.
   * @param string $source_directory
   *   The source directory with the ReliefWeb files.
   *
   * @return string
   *   D7 preview URL.
   */
  protected function getPreviewUrl(array $record, $base_url, $source_directory) {
    if (preg_match('/\|\d+\|(0|90|-90)$/', $record['field_file_description']) === 1) {
      $filename = basename(urldecode($record['filename']), '.pdf');
      $filename = str_replace('%', '', $filename);
      $filename = $record['fid'] . '-' . $filename . '.png';
      if (empty($source_directory)) {
        $filename = UrlHelper::encodePath($filename);
        return $base_url . '/sites/reliefweb.int/files/resources-pdf-previews/' . $filename;
      }
      else {
        return $source_directory . '/resources-pdf-previews/' . $filename;
      }
    }
    return '';
  }

  /**
   * Migrate files to their local storage.
   *
   * @param array $entities
   *   List of entities that have attachments, keyed by entity id and with the
   *   list of files as values.
   *
   * @return int
   *   The number of migrated files.
   */
  protected function migrateLocalFiles(array $entities) {
    $count = 0;
    foreach ($entities as $files) {
      foreach ($files as $file) {
        if ($this->downloadLocalFile($file)) {
          $count++;
          // Try to download the file preview.
          $this->downloadFilePreview($file);
        }
      }
    }
    return $count;
  }

  /**
   * Migrate files to their remote storage.
   *
   * @param array $entities
   *   List of entities that have attachments, keyed by entity id and with the
   *   list of files as values.
   *
   * @return int
   *   The number of migrated files.
   */
  protected function migrateRemoteFiles(array $entities) {
    $count = 0;
    foreach ($entities as $entity_id => $files) {
      $uuids = [];
      foreach ($files as $file) {
        $uuid = $this->createOrUpdateRemoteFile($file);
        if ($uuid !== FALSE) {
          $uuids[] = $uuid;
          // Try to download the file preview.
          $this->downloadFilePreview($file);
        }
      }
      $this->updateOrCreateRemoteDocument($entity_id, $uuids);
      $count += count($uuids);
    }
    return $count;
  }

  /**
   * Migrate preview files to their local storage.
   *
   * @param array $entities
   *   List of entities that have attachments, keyed by entity id and with the
   *   list of files as values.
   *
   * @return int
   *   The number of migrated files.
   */
  protected function migratePreviewFiles(array $entities) {
    $count = 0;
    foreach ($entities as $files) {
      foreach ($files as $file) {
        // Try to download the file preview.
        if ($this->downloadFilePreview($file)) {
          $count++;
        }
      }
    }
    return $count;
  }

  /**
   * Download a file to its local location.
   *
   * @param array $file
   *   Legacy file data.
   *
   * @return bool
   *   TRUE if the file could be downloaded.
   */
  protected function downloadLocalFile(array $file) {
    $uuid = $file['uuid'];
    $uri = $file['uri'];
    $url = $file['url'];

    // Update the file record using the file id as revision id.
    $this->updateFileFieldRecord($uuid, $file['fid']);

    // Try to download the file.
    $success = FALSE;
    if (ReliefWebFile::prepareDirectory($uri)) {
      if (!copy($url, $uri)) {
        $this->logger()->error(dt('Unable to download file @url to @uri.', [
          '@url' => $url,
          '@uri' => $uri,
        ]));
      }
      else {
        $this->logger()->info(dt('Successfully downloaded file @url to @uri.', [
          '@url' => $url,
          '@uri' => $uri,
        ]));
        $success = TRUE;
      }
    }
    else {
      $this->logger()->error(dt('Unable to create directory for @uri.', [
        '@uri' => $uri,
      ]));
    }

    return $success;
  }

  /**
   * Download an attachment's preview file to its local location.
   *
   * @param array $file
   *   Legacy file data.
   *
   * @return bool
   *   TRUE if the file could be downloaded.
   */
  protected function downloadFilePreview(array $file) {
    if (!empty($file['preview_url'])) {
      $uri = str_replace('/attachments/', '/previews/', $file['uri']);
      $uri = preg_replace('#\.pdf$#i', '.png', $uri);
      $url = $file['preview_url'];

      // Try to download the preview file.
      $success = FALSE;
      if (ReliefWebFile::prepareDirectory($uri)) {
        if (!copy($url, $uri)) {
          $this->logger()->error(dt('Unable to download preview file @url to @uri.', [
            '@url' => $url,
            '@uri' => $uri,
          ]));
        }
        else {
          $this->logger()->info(dt('Successfully downloaded preview file @url to @uri.', [
            '@url' => $url,
            '@uri' => $uri,
          ]));
          $success = TRUE;
        }
      }
      else {
        $this->logger()->error(dt('Unable to create preview directory for @uri.', [
          '@uri' => $uri,
        ]));
      }
    }

    return $success;
  }

  /**
   * Create or update a remote file with the database file data.
   *
   * @param array $file
   *   Legacy file data.
   *
   * @return string|false
   *   The UUID of the file resource on success or FALSE.
   */
  protected function createOrUpdateRemoteFile(array $file) {
    $uuid = $file['uuid'];
    $url = $file['url'];

    // First, try to get the file resource.
    $response = $this->docstoreClient->getFile($uuid);

    // If the file doesn't exist, try to create it.
    if ($response->isNotFound()) {
      $response = $this->docstoreClient->createFile([
        'uuid' => $uuid,
        'filename' => $file['filename'],
        'mimetype' => $file['filemime'],
        'private' => $file['private'],
        // The docstore will fetch the file content from this URL.
        'uri' => $url,
      ], 300);

      if (!$response->isSuccessful()) {
        $this->logger()->info(dt('Unable to create remote file for @url.', [
          '@url' => $url,
        ]));
        return FALSE;
      }
    }
    // Abort if something went wrong.
    elseif (!$response->isSuccessful()) {
      $this->logger()->info(dt('Unable to retrieve the remote file for @url.', [
        '@url' => $url,
      ]));
      return FALSE;
    }

    $content = $response->getContent();

    // If there is no revision id then it means no content was uploaded, try.
    if (empty($content['revision_id'])) {
      $response = $this->docstoreClient->updateFileContentFromFilePath($uuid, $url);

      if (!$response->isSuccessful()) {
        $this->logger()->info(dt('Unable to update remote file for @url.', [
          '@url' => $url,
        ]));
        return FALSE;
      }

      $content = $response->getContent();

      // Update the status of the file, if different.
      if (empty($content['private']) !== empty($file['private'])) {
        $this->docstoreClient->updateFileStatus($uuid, $file['private']);
      }
    }

    // Get the revision ID.
    if (empty($content['revision_id'])) {
      $this->logger()->info(dt('Missing file revision id for @url.', [
        '@url' => $url,
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

  /**
   * Fix migration of the report attachments.
   *
   * This creates the entries in the file_managed for some attachments for which
   * the entries were not created for some reason during the report migration.
   *
   * @command rw-files:fix-migration
   *
   * @usage rw-files:fix-migration
   *   Migrate the attachments.
   *
   * @validate-module-enabled reliefweb_files
   */
  public function fixMigration() {
    $ids = [];

    $query = $this->getDatabase()->select('node__field_file', 'nf');
    $query->fields('nf', ['entity_id']);
    $query->condition(' nf.field_file_file_uuid', NULL, 'IS NOT NULL');
    $query->leftJoin('file_managed', 'fm', 'fm.uuid  = nf.field_file_file_uuid');
    $query->isNull('fm.fid');
    $ids += $query->execute()?->fetchAllKeyed(0, 0) ?? [];

    $query = $this->getDatabase()->select('node__field_file', 'nf');
    $query->fields('nf', ['entity_id']);
    $query->condition(' nf.field_file_preview_uuid', NULL, 'IS NOT NULL');
    $query->leftJoin('file_managed', 'fm', 'fm.uuid  = nf.field_file_preview_uuid');
    $query->isNull('fm.fid');
    $ids += $query->execute()?->fetchAllKeyed(0, 0) ?? [];

    if (empty($ids)) {
      $this->logger()->info(dt('No files to fix'));
    }
    else {
      sort($ids);
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);

      $processed = 0;
      foreach ($nodes as $node) {
        $this->logger()->info(dt('Fixing files for node @nid', [
          '@nid' => $node->id(),
        ]));

        foreach ($node->field_file as $item) {
          $private = !$node->isPublished();
          $created = $node->getCreatedTime();

          // Create file if it doesn't exist.
          $file = $item->loadFile();
          if (empty($file)) {
            $file = $item->createFile();
            if (empty($file)) {
              $this->logger()->error(dt('Unable to create file with uuid @uuid for node @nid', [
                '@uuid' => $item->getUuid(),
                '@nid' => $node->id(),
              ]));
            }
            else {
              $file->setFileUri($item->getPermanentUri($private, FALSE));
              $file->setSize($item->getFileSize());
              $file->setPermanent();
              $file->changed->value = $created;
              $file->created->value = $created;
              $file->save();
              $processed++;

              $this->logger()->info(dt('Created file with uuid @uuid for node @nid', [
                '@uuid' => $item->getFileUuid(),
                '@nid' => $node->id(),
              ]));
            }
          }
          else {
            $this->logger()->info(dt('File with uuid @uuid for node @nid already exists', [
              '@uuid' => $file->uuid(),
              '@nid' => $node->id(),
            ]));
          }

          // Create preview file if doesn't exist.
          $preview_file = $item->loadPreviewFile();
          if (empty($preview_file)) {
            $preview_file = $item->createPreviewFile();
            if (empty($preview_file)) {
              $this->logger()->error(dt('Unable to create preview file with uuid @uuid for node @nid', [
                '@uuid' => $item->getUuid(),
                '@nid' => $node->id(),
              ]));
            }
            else {
              $preview_file->setFileUri($item->getPermanentUri($private, TRUE));
              $preview_file->setPermanent();
              $preview_file->changed->value = $created;
              $preview_file->created->value = $created;
              $preview_file->save();

              $this->logger()->info(dt('Created preview file with uuid @uuid for node @nid', [
                '@uuid' => $item->getPreviewUuid(),
                '@nid' => $node->id(),
              ]));
            }
          }
          else {
            $this->logger()->info(dt('Preview file with uuid @uuid for node @nid already exists', [
              '@uuid' => $preview_file->uuid(),
              '@nid' => $node->id(),
            ]));
          }
        }
      }

      $this->logger()->info(dt('Fixed @files files for @nodes nodes', [
        '@files' => $processed,
        '@nodes' => count($nodes),
      ]));
    }
  }

}
