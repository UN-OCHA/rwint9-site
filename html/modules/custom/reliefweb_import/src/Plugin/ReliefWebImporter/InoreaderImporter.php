<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin\ReliefWebImporter;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\reliefweb_import\Attribute\ReliefWebImporter;
use Drupal\reliefweb_import\Exception\ReliefwebImportException;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginBase;
use Drupal\reliefweb_post_api\Exception\DuplicateException;
use Drupal\reliefweb_post_api\Helpers\HashHelper;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Import reports from the Inoreader.
 */
#[ReliefWebImporter(
  id: 'inoreader',
  label: new TranslatableMarkup('Inoreader importer'),
  description: new TranslatableMarkup('Import reports from the Inoreader.')
)]
class InoreaderImporter extends ReliefWebImporterPluginBase {

  /**
   * Inoreader service.
   *
   * @var \Drupal\reliefweb_import\Service\InoreaderService
   */
  protected $inoreaderService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->inoreaderService = $container->get('reliefweb_import.inoreader_service');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $form_state->getValue('email', $this->getPluginSetting('email', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#default_value' => $form_state->getValue('password', $this->getPluginSetting('password', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App Id'),
      '#default_value' => $form_state->getValue('app_id', $this->getPluginSetting('app_id', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['app_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App Key'),
      '#default_value' => $form_state->getValue('app_key', $this->getPluginSetting('app_key', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API URL'),
      '#description' => $this->t('The URL of the Inoreader feed.'),
      '#default_value' => $form_state->getValue('api_url', $this->getPluginSetting('api_url', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout'),
      '#description' => $this->t('Connection and request timeout in seconds.'),
      '#default_value' => $form_state->getValue('timeout', $this->getPluginSetting('timeout', 10, FALSE)),
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['fetch_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Fetch timeout'),
      '#description' => $this->t('Connection and request timeout in seconds to grab external HTML.'),
      '#default_value' => $form_state->getValue('fetch_timeout', $this->getPluginSetting('fetch_timeout', 15, FALSE)),
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['local_file_load'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Load json from local file'),
      '#default_value' => $form_state->getValue('local_file_load', $this->getPluginSetting('local_file_load', FALSE, FALSE)),
    ];

    $form['local_file_save'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dump json to local file'),
      '#default_value' => $form_state->getValue('local_file_save', $this->getPluginSetting('local_file_save', FALSE, FALSE)),
    ];

    $form['local_file_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Local file path'),
      '#default_value' => $form_state->getValue('local_file_path', $this->getPluginSetting('local_file_path', '/var/www/inoreader.json', FALSE)),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function importContent(int $limit = 50): bool {
    // Get list of documents.
    try {
      $provider_uuid = $this->getPluginSetting('provider_uuid');

      // Retrieve the POST API content processor plugin.
      $plugin = $this->contentProcessorPluginManager->getPluginByResource('reports');

      // Ensure the provider is valid.
      $plugin->getProvider($provider_uuid);

      $documents = $this->getDocuments($limit);
    }
    catch (\Exception $exception) {
      $this->getLogger()->error($exception->getMessage());
      return FALSE;
    }

    $this->getLogger()->info(strtr('Retrieved @count Inoreader documents from @url.', [
      '@count' => count($documents),
      '@url' => $this->getPluginSetting('api_url'),
    ]));

    if (empty($documents)) {
      $this->getLogger()->info('No Inoreader documents to process.');
      return TRUE;
    }

    // Process the documents importing new ones and updated ones.
    $processed = $this->processDocuments($documents, $provider_uuid, $plugin);

    // Retrieve the timestamp (microseconds) of the most recent document.
    $most_recent_timestamp = max(array_column($documents, 'timestampUsec'));
    if (is_numeric($most_recent_timestamp) && $most_recent_timestamp > 0) {
      // Casting to int is safe because we are on a 64 bits system.
      $this->state->set('reliefweb_importer_inoreader_most_recent_timestamp', (int) $most_recent_timestamp);
    }

    // @todo check if we want to return TRUE only if there was no errors or if
    // return TRUE for partial success is fine enough.
    return $processed > 0;
  }

  /**
   * Retrieve documents from the Inoreader.
   *
   * @return array
   *   List of documents keyed by IDs.
   */
  protected function getDocuments(int $limit = 50): array {
    $this->inoreaderService->setLogger($this->getLogger());
    $this->inoreaderService->setSettings([
      'email' => $this->getPluginSetting('email'),
      'password' => $this->getPluginSetting('password'),
      'app_id' => $this->getPluginSetting('app_id'),
      'app_key' => $this->getPluginSetting('app_key'),
      'api_url' => $this->getPluginSetting('api_url'),
      'timeout' => $this->getPluginSetting('timeout', 10, FALSE),
      'fetch_timeout' => $this->getPluginSetting('fetch_timeout', 15, FALSE),
      'local_file_load' => $this->getPluginSetting('local_file_load', FALSE, FALSE),
      'local_file_save' => $this->getPluginSetting('local_file_save', FALSE, FALSE),
      'local_file_path' => $this->getPluginSetting('local_file_path', '/var/www/inoreader.json', FALSE),
    ]);

    return $this->inoreaderService->getDocuments($limit);
  }

  /**
   * Process the documents retrieved from the Inoreader.
   *
   * @param array $documents
   *   Inoreader documents.
   * @param string $provider_uuid
   *   The provider UUID.
   * @param \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface $plugin
   *   The Post API content plugin processor used to import the documents.
   *
   * @return int
   *   The number of documents that were skipped or imported successfully.
   */
  protected function processDocuments(array $documents, string $provider_uuid, ContentProcessorPluginInterface $plugin): int {
    $entity_type_id = $this->getEntityTypeId();
    $bundle = $this->getEntityBundle();

    $schema = $this->getJsonSchema($bundle);

    // Allow passing raw bytes for files.
    $plugin->setPluginSetting('allow_raw_bytes', TRUE);

    // This is the list of extensions supported by the report attachment field.
    $extensions = explode(' ', 'csv doc docx jpg jpeg odp ods odt pdf png pps ppt pptx svg xls xlsx zip');
    $allowed_mimetypes = array_filter(array_map(fn($extension) => $this->mimeTypeGuesser->guessMimeType('dummy.' . $extension), $extensions));
    $allowed_mimetypes[] = 'application/octet-stream';

    // Override some plugin settings to accommodate for specifities of the data.
    $plugin->setPluginSetting('schema', $schema);
    $plugin->setPluginSetting('attachments.allowed_mimetypes', $allowed_mimetypes);

    // Disable content type validation because the files to download do not have
    // consistent content type headers (ex: pdf instead of application/pdf).
    $plugin->setPluginSetting('validate_file_content_type', FALSE);

    // Retrieve the list of existing import records for the documents.
    $uuids = array_filter(array_map(fn($item) => $this->generateUuid($item['canonical'][0]['href'] ?? ''), $documents));
    $existing_import_records = $this->getExistingImportRecords($uuids);

    // Max import attempts.
    $max_import_attempts = $this->getPluginSetting('max_import_attempts', 3, FALSE);
    // Prepare the documents and submit them.
    $processed = 0;
    $import_records = [];
    foreach ($documents as $document) {
      $source_title = trim(substr($document['origin']['title'] ?? '', 0, strpos($document['origin']['title'] ?? '', '[source:') ?: NULL));
      $source_title = $this->sanitizeText($source_title);

      // Ex: feed/webfeed://https%3A%2F%2Fwww.unicef.org%2Freports--44f158e4
      // We need to URL encode everything after `feed/` to build a working
      // inoreader feed URL.
      $feed_url = $document['origin']['streamId'] ?? '';
      $feed_url = str_starts_with($feed_url, 'feed/') ? 'feed/' . urlencode(substr($feed_url, 5)) : $feed_url;
      $feed_url = 'https://www.inoreader.com/' . $feed_url;

      $import_record = [
        'importer' => $this->getPluginId(),
        'provider_uuid' => $provider_uuid,
        'entity_type_id' => $entity_type_id,
        'entity_bundle' => $bundle,
        'status' => 'pending',
        'message' => '',
        'source' => $source_title,
        'extra' => [
          'inoreader' => [
            'feed_name' => $document['origin']['title'] ?? '',
            'feed_url' => $feed_url,
            'feed_origin' => $document['origin']['htmlUrl'] ?? '',
          ],
        ],
      ];

      // Retrieve the document ID.
      if (!isset($document['id'])) {
        $this->getLogger()->notice('Undefined Inoreader document ID, skipping document import.');
        continue;
      }
      $id = $document['id'];
      $import_record['imported_item_id'] = $id;

      // Retrieve the document URL.
      if (!isset($document['canonical'][0]['href'])) {
        $this->getLogger()->notice(strtr('Undefined document URL for Inoreader document ID @id, skipping document import.', [
          '@id' => $id,
        ]));
        continue;
      }
      $url = $document['canonical'][0]['href'];
      // Force url to use HTTPS.
      if (strpos($url, 'http://') === 0) {
        $url = 'https://' . substr($url, 7);
      }

      $import_record['imported_item_url'] = $url;

      // Generate the UUID for the document.
      $uuid = $this->generateUuid($url);
      $import_record['imported_item_uuid'] = $uuid;

      // Merge with existing record if available.
      if (isset($existing_import_records[$uuid])) {
        $import_record = NestedArray::mergeDeep($existing_import_records[$uuid], $import_record);
      }

      // Bail out if status is manually set.
      if (!empty($import_record['attempts']) && $import_record['attempts'] == 99) {
        unset($existing_import_records[$uuid]);
        continue;
      }

      $this->getLogger()->info(strtr('Processing Inoreader document @id, from @url', [
        '@id' => $id,
        '@url' => $url,
      ]));

      // Generate a hash from the data we use to import the document. This is
      // used to detect changes that can affect the document on ReliefWeb.
      // We do not include the source because it can change for example when
      // editing the title and it's not used directly as imported data.
      $filtered_document = $this->filterArrayByKeys($document, [
        'id',
        'title',
        'published',
        'canonical',
        'alternate',
        'summary',
      ]);
      $hash = HashHelper::generateHash($filtered_document);
      $import_record['imported_data_hash'] = $hash;

      // Skip if there is already an entity with the same UUID and same content
      // hash since it means the document has been not updated since the last
      // time it was imported.
      $records = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('uuid', $uuid, '=')
        ->condition('field_post_api_hash', $hash, '=')
        ->execute();
      if (!empty($records)) {
        $processed++;
        $this->getLogger()->info(strtr('Inoreader document @id (entity @entity_id) already imported and not changed, skipping.', [
          '@id' => $id,
          '@entity_id' => reset($records),
        ]));
        continue;
      }

      // Check how many times we tried to import this item.
      if (!empty($import_record['attempts']) && $import_record['attempts'] >= $max_import_attempts) {
        $import_record['status'] = 'error';
        $import_record['editorial_flow'] = 'to_process';
        $import_record['message'] = 'Too many attempts.';
        $import_records[$import_record['imported_item_uuid']] = $import_record;

        $this->getLogger()->error(strtr('Too many import attempts for Inoreader document @id, skipping.', [
          '@id' => $id,
        ]));

        continue;
      }

      // Process the item data into importable data.
      try {
        $data = $this->getImportData($uuid, $document);
        if (empty($data)) {
          $this->getLogger()->info(strtr('Inoreader document @id has no data to import, skipping.', [
            '@id' => $id,
          ]));

          // Log it.
          $import_record['status'] = 'skipped';
          $import_record['editorial_flow'] = 'to_process';
          $import_record['message'] = 'No data to import.';
          $import_record['attempts'] = ($import_record['attempts'] ?? 0) + 1;
          $import_records[$import_record['imported_item_uuid']] = $import_record;

          continue;
        }
      }
      catch (\Exception $e) {
        $this->getLogger()->error(strtr('Error processing Inoreader document @id: @message', [
          '@id' => $id,
          '@message' => $e->getMessage(),
        ]));

        if ($e instanceof ReliefwebImportException) {
          $import_record['status_type'] = $e->getStatusType();
        }
        else {
          $import_record['status_type'] = '';
        }

        $import_record['status'] = 'error';
        $import_record['editorial_flow'] = 'to_process';
        $import_record['message'] = $e->getMessage();
        $import_record['attempts'] = ($import_record['attempts'] ?? 0) + 1;
        $import_records[$import_record['imported_item_uuid']] = $import_record;

        continue;
      }

      // Mandatory information.
      $data['provider'] = $provider_uuid;
      $data['bundle'] = $bundle;
      $data['hash'] = $hash;
      $data['uuid'] = $uuid;
      $data['url'] = $url;

      // Queue the document.
      try {
        $entity = $plugin->process($data);
        $import_record['status'] = 'success';
        $import_record['status_type'] = '';
        $import_record['editorial_flow'] = 'to_process';
        $import_record['message'] = '';
        $import_record['attempts'] = 0;
        $import_record['entity_id'] = $entity->id();
        $import_record['entity_revision_id'] = $entity->getRevisionId();
        $processed++;
        $this->getLogger()->info(strtr('Successfully processed Inoreader @id to entity @entity_id.', [
          '@id' => $id,
          '@entity_id' => $entity->id(),
        ]));
      }
      catch (DuplicateException $exception) {
        $import_record['status'] = 'duplicate';
        $import_record['status_type'] = '';
        $import_record['message'] = $exception->getMessage();
        $import_record['attempts'] = $max_import_attempts;
        $this->getLogger()->error(strtr('Unable to process Inoreader @id: @exception', [
          '@id' => $id,
          '@exception' => $exception->getMessage(),
        ]));
      }
      catch (\Exception $exception) {
        $import_record['status'] = 'error';
        $import_record['editorial_flow'] = 'to_process';
        if ($exception instanceof ReliefwebImportException) {
          $import_record['status_type'] = $exception->getStatusType();
        }

        $import_record['message'] = $exception->getMessage();
        $import_record['attempts'] = ($import_record['attempts'] ?? 0) + 1;
        $this->getLogger()->error(strtr('Unable to process Inoreader @id: @exception', [
          '@id' => $id,
          '@exception' => $exception->getMessage(),
        ]));
      }
      finally {
        $import_records[$import_record['imported_item_uuid']] = $import_record;
      }
    }

    // Create or update the import records.
    $this->saveImportRecords($import_records);

    return $processed;
  }

  /**
   * {@inheritdoc}
   */
  protected function processDocumentData(string $uuid, array $document): array {
    $id = $document['id'];

    $data = $this->inoreaderService->processDocumentData($document);
    if (!isset($data['file_data'])) {
      $this->logger->info(strtr('No file data found for Inoreader @id, skipping.', [
        '@id' => $id,
      ]));

      return [];
    }

    $has_pdf = $data['_has_pdf'] ?? FALSE;

    // Remove all keys starting with an underscore.
    foreach ($data as $key => $value) {
      if (strpos($key, '_') === 0) {
        unset($data[$key]);
      }
    }

    if ($has_pdf) {
      $pdf = $data['file_data']['pdf'] ?? '';
      $pdf_bytes = $data['file_data']['bytes'] ?? NULL;

      $files = [];
      $info = $this->getRemoteFileInfo($pdf, 'pdf', $pdf_bytes);
      if (!empty($info)) {
        $file_uuid = $this->generateUuid($pdf, $uuid);
        $files[] = [
          'url' => $pdf,
          'uuid' => $file_uuid,
        ] + $info;
      }

      unset($data['file_data']);

      $data += array_filter([
        'file' => array_values($files),
      ]);

      if (empty($data['file'])) {
        $this->logger->info(strtr('No files found for Inoreader @id, skipping.', [
          '@id' => $id,
        ]));

        return [];
      }

      return $data;
    }

    // Make sure body is present and not empty.
    if (empty($data['body'])) {
      $this->logger->info(strtr('No body or PDF found for Inoreader @id, skipping.', [
        '@id' => $id,
      ]));

      return [];
    }

    unset($data['file_data']);

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getJsonSchema(string $bundle): string {
    $schema = parent::getJsonSchema($bundle);
    $decoded = Json::decode($schema);
    if ($decoded) {
      // Allow attachment URLs without a PDF extension.
      unset($decoded['properties']['file']['items']['properties']['url']['pattern']);
      // Allow empty strings as body.
      unset($decoded['properties']['body']['minLength']);
      unset($decoded['properties']['body']['allOf']);
      unset($decoded['properties']['body']['not']);
      $schema = Json::encode($decoded);
    }
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationForceFieldUpdate(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void {
    parent::alterContentClassificationForceFieldUpdate($fields, $workflow, $context);
    if (!isset($context['entity'])) {
      return;
    }

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $context['entity'];

    // Allow overriding the title with the AI extracted one if the title
    // contains a link.
    if (preg_match('#https?://#i', $entity->title->value)) {
      $fields['title__value'] = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterReliefWebEntitiesModerationStatusAdjustment(bool &$bypass, EntityInterface $entity): void {
    // Retrieve the import record and check if there is a defined status.
    $record = $this->getImportRecordForEntity($entity);
    if (empty($record)) {
      return;
    }

    $feed_name = $record['extra']['feed_name'] ?? '';
    $tags = $this->inoreaderService->extractTags($feed_name);
    if (isset($tags['status'])) {
      $bypass = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function forceContentLanguage(EntityInterface $entity): string|int|null {
    // Retrieve the import record and check if there is a defined language.
    $record = $this->getImportRecordForEntity($entity);
    if (empty($record)) {
      return NULL;
    }

    $feed_name = $record['extra']['feed_name'] ?? '';
    $tags = $this->inoreaderService->extractTags($feed_name);

    if (isset($tags['language'])) {
      $defined_languages = reliefweb_import_get_defined_languages();
      if (!isset($defined_languages[$tags['language']])) {
        // Language not defined, skip.
        return NULL;
      }

      // Return the term ID matching the language code.
      return $defined_languages[$tags['language']];
    }

    return NULL;
  }

}
