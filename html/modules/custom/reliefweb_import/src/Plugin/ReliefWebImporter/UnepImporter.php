<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin\ReliefWebImporter;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_import\Attribute\ReliefWebImporter;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginBase;
use Drupal\reliefweb_post_api\Exception\DuplicateException;
use Drupal\reliefweb_post_api\Helpers\HashHelper;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface;
use Drupal\reliefweb_utility\Helpers\DateHelper;

/**
 * Import reports from the UNEP API.
 */
#[ReliefWebImporter(
  id: 'unep',
  label: new TranslatableMarkup('UNEP importer'),
  description: new TranslatableMarkup('Import reports from the UNEP API.')
)]
class UnepImporter extends ReliefWebImporterPluginBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API URL'),
      '#description' => $this->t('The base URL of the UNEP API.'),
      '#default_value' => $form_state->getValue('api_url', $this->getPluginSetting('api_url', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('The base64 encode username and password for authentication.'),
      '#default_value' => $form_state->getValue('api_key', $this->getPluginSetting('api_key', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Max age in days of documents to retrieve'),
      '#description' => $this->t('The maximum age in days of documents to retrieve.'),
      '#default_value' => $form_state->getValue('max_age', $this->getPluginSetting('max_age', 3, FALSE)),
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['document_types'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Document types'),
      '#description' => $this->t('List of document types to import. One per line.'),
      '#default_value' => $form_state->getValue('document_types', $this->getPluginSetting('document_types', 'resource', FALSE)),
      '#required' => TRUE,
    ];

    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout'),
      '#description' => $this->t('Connection and request timeout in seconds.'),
      '#default_value' => $form_state->getValue('timeout', $this->getPluginSetting('timeout', 5, FALSE)),
      '#min' => 1,
      '#required' => TRUE,
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

      $this->getLogger()->info('Retrieving documents from the UNEP API.');

      // Retrieve the latest created documents.
      $documents = $this->getDocuments($limit);

      if (empty($documents)) {
        $this->getLogger()->notice('No documents.');
        return TRUE;
      }
    }
    catch (\Exception $exception) {
      $this->getLogger()->error($exception->getMessage());
      return FALSE;
    }

    $this->getLogger()->info(strtr('Retrieved @count UNEP documents.', [
      '@count' => count($documents),
    ]));

    // Sort the documents by ID ascending to process the oldest ones first.
    ksort($documents);

    // Process the documents importing new ones and updated ones.
    $processed = $this->processDocuments($documents, $provider_uuid, $plugin);

    // @todo check if we want to return TRUE only if there was no errors or if
    // return TRUE for partial success is fine enough.
    return $processed > 0;
  }

  /**
   * Retrieve documents from the API.
   *
   * @param int $limit
   *   Maximum number of documents to retrieve at once.
   * @param string $order_property
   *   Property to use to sort the documents.
   *
   * @return array
   *   List of documents keyed by IDs.
   */
  protected function getDocuments(int $limit, string $order_property = 'publication_date'): array {
    // Get list of documents.
    try {
      $timeout = $this->getPluginSetting('timeout', 5, FALSE);
      $api_url = $this->getPluginSetting('api_url');
      $api_key = $this->getPluginSetting('api_key');

      // Get the list of document types.
      $document_types = [];
      $document_types_setting = $this->getPluginSetting('document_types', '', FALSE);
      if (!empty($document_types_setting)) {
        foreach (explode("\n", $document_types_setting) as $document_type) {
          $document_type = trim($document_type);
          if (!empty($document_type)) {
            $document_types[] = $document_type;
          }
        }
      }

      $max_age = max(1, $this->getPluginSetting('max_age', 3, FALSE));
      $last_update = (int) 1000 * (time() - ($max_age * 24 * 60 * 60));

      $payload = '
        {
          "track_total_hits": true,
          "size": ' . $limit . ',
          "_source": false,
          "fields": [
            "title",
            "links.uri",
            "publication_date",
            "path"
          ],
          "query": {
            "bool": {
              "must": [
                {
                  "match": {
                    "type": {
                      "query": "' . implode(' ', $document_types) . '",
                      "operator": "or"
                    }
                  }
                },
                {
                  "regexp": {
                    "links.uri": ".*.pdf"
                  }
                },
                {
                  "range": {
                    "publication_date": {
                      "gte": ' . $last_update . '
                    }
                  }
                }
              ]
            }
          },
          "sort": [
            {
              "publication_date": {
                "order": "desc"
              }
            }
          ]
        }
      ';

      $response = $this->httpClient->request('GET', $api_url, [
        'connect_timeout' => $timeout,
        'timeout' => $timeout,
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'Authorization' => 'Basic ' . $api_key,
        ],
        'json' => json_decode($payload, TRUE),
      ]);

      if ($response->getStatusCode() !== 200) {
        // @todo try to retrieve the error message.
        throw new \Exception('Failure with response code: ' . $response->getStatusCode());
      }

      $content = $response->getBody()->getContents();

      if (!empty($content)) {
        $documents = json_decode($content, TRUE, flags: \JSON_THROW_ON_ERROR);
      }
      else {
        return [];
      }
    }
    catch (\Exception $exception) {
      $message = $exception->getMessage();

      // Make sure we do not leak the API key.
      if (isset($api_key)) {
        $message = str_replace($api_key, 'REDACTED_API_KEY', $message);
      }

      throw new \Exception($message);
    }

    // Map the document's data to the document's ID.
    $map = [];
    foreach ($documents['hits']['hits'] ?? [] as $document) {
      if (!isset($document['_id'])) {
        continue;
      }
      $map[$document['_id']] = [
        'id' => $document['_id'],
        'title' => $document['fields']['title'][0] ?? '',
        'path' => $document['fields']['path'][0] ?? '',
        'publication_date' => $document['fields']['publication_date'][0] ?? '',
        'links' => $document['fields']['links.uri'] ?? [],
      ];
    }

    return $map;
  }

  /**
   * Process the documents retrieved from the API.
   *
   * @param array $documents
   *   UNEP documents.
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

    // Max import attempts.
    $max_import_attempts = $this->getPluginSetting('max_import_attempts', 3, FALSE);

    // Fix the document paths.
    $document_urls = [];
    foreach ($documents as &$document) {
      if (isset($document['path'])) {
        $document['path'] = 'https://www.unep.org/' . ltrim($document['path'], '/');
        // Store the url to retrieve any manually posted document using it.
        $document_urls[] = $document['path'];
      }
    }

    // Retrieve the list of documents manually posted so we can exclude them
    // from the import.
    $manually_posted = $this->getManuallyPostedDocumentsFromUrls($document_urls);

    // Retrieve the list of existing import records for the documents.
    $uuids = array_filter(array_map(fn($item) => $this->generateUuid($item['path'] ?? ''), $documents));
    $existing_import_records = $this->getExistingImportRecords($uuids);

    // Prepare the documents and submit them.
    $processed = 0;
    $import_records = [];
    foreach ($documents as $document) {
      $import_record = [
        'importer' => $this->getPluginId(),
        'provider_uuid' => $provider_uuid,
        'entity_type_id' => $entity_type_id,
        'entity_bundle' => $bundle,
        'status' => 'pending',
        'message' => '',
        'attempts' => 0,
        'source' => 'UNEP',
      ];

      // Retrieve the document ID.
      if (!isset($document['id'])) {
        $this->getLogger()->notice('Undefined UNEP document ID, skipping document import.');
        continue;
      }
      $id = $document['id'];
      $import_record['imported_item_id'] = $id;

      // Retrieve the document URL.
      if (!isset($document['path'])) {
        $this->getLogger()->notice(strtr('Undefined document URL for UNEP document ID @id, skipping document import.', [
          '@id' => $id,
        ]));
        continue;
      }
      $url = $document['path'];
      $import_record['imported_item_url'] = $url;

      // Check if the document was not already manually posted.
      if (isset($manually_posted[$url])) {
        $this->getLogger()->notice(strtr('UNEP document @id already manually posted as report @report_id.', [
          '@id' => $id,
          '@report_id' => $manually_posted[$url],
        ]));
        continue;
      }

      // Generate the UUID for the document.
      $uuid = $this->generateUuid($url);
      $import_record['imported_item_uuid'] = $uuid;

      // Merge with existing record if available.
      if (isset($existing_import_records[$uuid])) {
        $import_record = $existing_import_records[$uuid] + $import_record;
      }

      $this->getLogger()->info(strtr('Processing UNEP document @id.', [
        '@id' => $id,
      ]));

      // Generate a hash from the data we use to import the document. This is
      // used to detect changes that can affect the document on ReliefWeb.
      $filtered_document = $this->filterArrayByKeys($document, [
        'title',
        'path',
        'publication_date',
        'links.uri',
      ]);
      $hash = HashHelper::generateHash($filtered_document);
      $import_record['imported_data_hash'] = $hash;

      // Skip if there is already an entity with the same UUID and same content
      // hash since it means the document has been not updated since the last
      // time it was imported.
      $records = $this->entityTypeManager
        ->getStorage($entity_type_id)
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('uuid', $uuid, '=')
        ->condition('field_post_api_hash', $hash)
        ->execute();
      if (!empty($records)) {
        $processed++;
        $this->getLogger()->info(strtr('UNEP document @id (entity @entity_id) already imported and not changed, skipping.', [
          '@id' => $id,
          '@entity_id' => reset($records),
        ]));
        continue;
      }

      // Check how many times we tried to import this item.
      if (!empty($import_record['attempts']) && $import_record['attempts'] >= $max_import_attempts) {
        $import_record['status'] = 'error';
        $import_record['message'] = 'Too many attempts.';
        $import_records[$import_record['imported_item_uuid']] = $import_record;

        $this->getLogger()->error(strtr('Too many import attempts for UNEP document @id, skipping.', [
          '@id' => $id,
        ]));

        continue;
      }

      // Process the item data into importable data.
      $data = $this->getImportData($uuid, $document);
      if (empty($data)) {
        $this->getLogger()->notice(strtr('No data to import for UNEP document @id.', [
          '@id' => $id,
        ]));

        continue;
      }

      // Mandatory information.
      $data['provider'] = $provider_uuid;
      $data['bundle'] = $bundle;
      $data['hash'] = $hash;
      $data['uuid'] = $uuid;
      $data['url'] = $url;

      // Submit the document directly, no need to go through the queue.
      try {
        $entity = $plugin->process($data);
        $import_record['status'] = 'success';
        $import_record['message'] = '';
        $import_record['attempts'] = 0;
        $import_record['entity_id'] = $entity->id();
        $import_record['entity_revision_id'] = $entity->getRevisionId();
        $processed++;
        $this->getLogger()->info(strtr('Successfully processed UNEP document @id to entity @entity_id.', [
          '@id' => $id,
          '@entity_id' => $entity->id(),
        ]));
      }
      catch (DuplicateException $exception) {
        $import_record['status'] = 'duplicate';
        $import_record['message'] = $exception->getMessage();
        $import_record['attempts'] = $max_import_attempts;
        $this->getLogger()->error(strtr('Unable to process UNEP document @id: @exception', [
          '@id' => $id,
          '@exception' => $exception->getMessage(),
        ]));
      }
      catch (\Exception $exception) {
        $import_record['status'] = 'error';
        $import_record['message'] = $exception->getMessage();
        $import_record['attempts'] = ($import_record['attempts'] ?? 0) + 1;
        $this->getLogger()->error(strtr('Unable to process UNEP document @id: @exception', [
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
    $data = [];

    // Source: UNEP.
    $sources = [1482];

    // Document URL.
    $url = $document['path'];

    // Retrieve the title and clean it.
    $title = $this->sanitizeText($document['title'] ?? '');

    // No body text is available in the API for the UNEP documents.
    $body = '';

    // Retrieve the publication date.
    $published = strtotime($document['publication_date']);
    $published = DateHelper::format($published, 'custom', 'c');

    $languages['English'] = 267;
    $formats = [9];
    $countries = [254];

    // Retrieve the data for the attachment if any.
    $files = [];
    if (isset($document['links'])) {
      foreach ($document['links'] ?? [] as $document_url) {
        $info = $this->getRemoteFileInfo($document_url);
        if (!empty($info)) {
          $file_url = $document_url;
          $file_uuid = $this->generateUuid($file_url, $uuid);
          $files[] = [
            'url' => $file_url,
            'uuid' => $file_uuid,
          ] + $info;
        }
      }
    }

    if (empty($files)) {
      $this->getLogger()->info(strtr('No PDF found for UNEP @id, skipping.', [
        '@id' => $document['id'],
      ]));

      return [];
    }

    // Submission data.
    $data = [
      'title' => $title,
      'body' => $body,
      'source' => $sources,
      'published' => $published,
      'origin' => $url,
      'language' => array_values($languages),
      'country' => array_values($countries),
      'format' => array_values($formats),
    ];

    // Add the optional fields.
    $data += array_filter([
      'file' => array_values($files),
    ]);

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

}
