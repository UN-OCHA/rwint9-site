<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin\ReliefWebImporter;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_import\Attribute\ReliefWebImporter;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginBase;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface;
use Drupal\reliefweb_post_api\Helpers\HashHelper;
use Drupal\reliefweb_utility\Helpers\DateHelper;

/**
 * Import reports from the WFP Logcluster API.
 */
#[ReliefWebImporter(
  id: 'wfp_logcluster',
  label: new TranslatableMarkup('WFP Logcluster importer'),
  description: new TranslatableMarkup('Import reports from the WFP Logcluster API.')
)]
class WfpLogClusterImporter extends ReliefWebImporterPluginBase {

  /**
   * Language mapping.
   *
   * @var array<string, int>
   */
  protected array $languageMapping = [
    'ar' => 6876,
    'Arabic' => 6876,
    'en' => 267,
    'English' => 267,
    'fr' => 268,
    'French' => 268,
    'ru' => 10906,
    'Russian' => 10906,
    'es' => 269,
    'Spanish' => 269,
  ];

  /**
   * Content format mapping.
   *
   * @var array<string, int>
   */
  protected array $formatMapping = [
    'Academic Marketplace Resources' => 9,
    'Action Plan' => 7,
    'Administration' => 9,
    'Administrative Documents' => 9,
    'Agenda' => 9,
    'Annexes' => 9,
    'Assessment' => 5,
    'Cargo transport schedule' => 9,
    'Communications' => 9,
    'Concept Note' => 7,
    'Concept of Operations' => 10,
    'Contingency Plan' => 7,
    'Form' => 7,
    'Gaps and Needs Analysis (GNA)' => 5,
    'Guidance' => 7,
    'Handouts' => 7,
    'Infographic' => 12570,
    'Lessons Learned' => 6,
    'Main documents' => 9,
    'Maps' => 'Map',
    'Meeting Minutes' => 9,
    'NFR' => 9,
    'Operation Overview' => 9,
    'Operational Information' => 10,
    'Other' => 9,
    'Pre-reading Material' => 9,
    'Presentation Slides' => 9,
    'Reference Documents' => 7,
    'Report' => 10,
    'Reporting' => 10,
    'Research / Paper' => 3,
    'Resources' => 7,
    'Schedule' => 9,
    'Situation Update' => 10,
    'Snapshots' => 12570,
    'SOP' => 7,
    'Strategy' => 7,
    'Tools' => 7,
    'Training' => 7,
    'Training Material' => 7,
    'Training Report' => 7,
  ];

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API URL'),
      '#description' => $this->t('The base URL of the WFP Logcluster API.'),
      '#default_value' => $form_state->getValue('api_url', $this->getPluginSetting('api_url', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('The API key for authentication.'),
      '#default_value' => $form_state->getValue('api_key', $this->getPluginSetting('api_key', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['max_age'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Max age in days of documents to retrieve'),
      '#description' => $this->t('The maximum age in days of documents to retrieve.'),
      '#default_value' => $form_state->getValue('max_age', $this->getPluginSetting('max_age', '', FALSE)),
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

      $this->getLogger()->info('Retrieving documents from the WFP Logcluster API.');

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

    $this->getLogger()->info(strtr('Retrieved @count WFP Logcluster documents.', [
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
  protected function getDocuments(int $limit, string $order_property = 'last_update'): array {
    // Get list of documents.
    try {
      $timeout = $this->getPluginSetting('timeout', 5, FALSE);
      $api_url = $this->getPluginSetting('api_url');
      $api_key = $this->getPluginSetting('api_key');
      $max_age = max(1, $this->getPluginSetting('max_age', 3, FALSE));
      $last_update = date('Y-m-d', strtotime('-' . $max_age . ' day'));

      // Query the Log Cluster API.
      $query = http_build_query([
        // Get documents created or updated in the last x days.
        'last_update' => $last_update,
        'page_size' => $limit,
      ]);

      $url = rtrim($api_url, '/') . '/?' . $query;

      $response = $this->httpClient->get($url, [
        'connect_timeout' => $timeout,
        'timeout' => $timeout,
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'x-api-key' => $api_key,
        ],
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
    foreach ($documents['rows'] ?? [] as $document) {
      if (!isset($document['id'])) {
        continue;
      }
      $map[$document['id']] = $document;
    }

    return $map;
  }

  /**
   * Process the documents retrieved from the API.
   *
   * @param array $documents
   *   WFP Logcluster documents.
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
      ];

      // Retrieve the document ID.
      if (!isset($document['id'])) {
        $this->getLogger()->notice('Undefined WFP Logcluster document ID, skipping document import.');
        continue;
      }
      $id = $document['id'];
      $import_record['imported_item_id'] = $id;

      if (isset($manually_posted[$id])) {
        $this->getLogger()->notice(strtr('WFP Logcluster document @id already manually posted as report @report_id.', [
          '@id' => $id,
          '@report_id' => $manually_posted[$id],
        ]));
        continue;
      }

      // Retrieve the document URL.
      if (!isset($document['path'])) {
        $this->getLogger()->notice(strtr('Undefined document URL for WFP Logcluster document ID @id, skipping document import.', [
          '@id' => $id,
        ]));
        continue;
      }
      $url = $document['path'];
      $import_record['imported_item_url'] = $url;

      // Generate the UUID for the document.
      $uuid = $this->generateUuid($url);
      $import_record['imported_item_uuid'] = $uuid;

      // Merge with existing record if available.
      if (isset($existing_import_records[$uuid])) {
        $import_record = $existing_import_records[$uuid] + $import_record;
      }

      $this->getLogger()->info(strtr('Processing WFP Logcluster document @id.', [
        '@id' => $id,
      ]));

      // Generate a hash from the data we use to import the document. This is
      // used to detect changes that can affect the document on ReliefWeb.
      $filtered_document = $this->filterArrayByKeys($document, [
        'id',
        'title',
        'path',
        'date',
        'document_language',
        'countries',
        'document_type',
        'organisations',
        'document_url',
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
        $this->getLogger()->info(strtr('WFP Logcluster document @id (entity @entity_id) already imported and not changed, skipping.', [
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

        $this->getLogger()->error(strtr('Too many import attempts for WFP Logcluster document @id, skipping.', [
          '@id' => $id,
        ]));

        continue;
      }

      // Process the item data into importable data.
      $data = $this->getImportData($uuid, $document);
      if (empty($data)) {
        $this->getLogger()->notice(strtr('No data to import for WFP Logcluster document @id.', [
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
        $this->getLogger()->info(strtr('Successfully processed WFP Logcluster document @id to entity @entity_id.', [
          '@id' => $id,
          '@entity_id' => $entity->id(),
        ]));
      }
      catch (\Exception $exception) {
        $import_record['status'] = 'error';
        $import_record['message'] = $exception->getMessage();
        $import_record['attempts'] = ($import_record['attempts'] ?? 0) + 1;
        $this->getLogger()->error(strtr('Unable to process WFP Logcluster document @id: @exception', [
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

    // Source: Logistics Cluster.
    $sources = [3594];

    if (isset($document['organisations'])) {
      foreach ($document['organisations'] ?? [] as $organisation) {
        if ($organisation === 'WFP') {
          continue;
        }

        if ($source = $this->getSourceByName($organisation)) {
          $sources[] = $source;
        }
      }
    }

    // Document URL.
    $url = $document['path'];

    // Retrieve the title and clean it.
    $title = $this->sanitizeText($document['title'] ?? '');

    // No body text is available in the API for the WFP Logcluster documents.
    $body = '';

    // Retrieve the publication date.
    $published = strtotime($document['last_update'] ?? $document['date']);
    $published = DateHelper::format($published, 'custom', 'c');

    // Retrieve the document languages and default to English if none of the
    // supported languages were found.
    $languages = [];
    if (isset($document['document_language'])) {
      if (isset($this->languageMapping[$document['document_language']])) {
        $languages[$document['document_language']] = $this->languageMapping[$document['document_language']];
      }
    }

    if (empty($languages)) {
      $languages['English'] = $this->languageMapping['English'];
    }

    // Retrieve the content format and map it to 'Other' if there is no match.
    foreach ($document['document_type'] ?? [] as $type) {
      if (isset($this->formatMapping[$type])) {
        $formats = [$this->formatMapping[$type]];
        break;
      }
    }

    if (empty($formats)) {
      $formats = [9];
    }

    // Retrieve the countries. Consider the first one as the primary country.
    $countries = [];
    foreach ($document['countries'] ?? [] as $location) {
      if (isset($this->countryMapping[$location])) {
        $country = $this->getCountryByIso($location);
        $countries[] = $country;
        $countries = array_unique($countries);
      }
    }

    // Tag with World if empty so that, at least, we can import.
    if (empty($countries)) {
      $countries = [254];
    }

    // Retrieve the data for the attachment if any.
    $files = [];
    if (isset($document['document_url'])) {
      foreach ($document['document_url'] ?? [] as $document) {
        $document_url = reset($document);
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
      $this->getLogger()->info(strtr('No PDF found for WFP Logcluster @id, skipping.', [
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
