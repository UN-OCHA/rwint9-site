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
 * Import reports from the IOM Crisis Response JSON feeds.
 */
#[ReliefWebImporter(
  id: 'iom_crisis_response',
  label: new TranslatableMarkup('IOM Crisis Response importer'),
  description: new TranslatableMarkup('Import reports from the IOM Crisis Response publications and appeal documents feeds.')
)]
class IomCrisisResponseImporter extends ReliefWebImporterPluginBase {

  /**
   * Default feed URLs.
   */
  protected const DEFAULT_API_URLS = "https://crisisresponse.iom.int/iom-publications/format/json\nhttps://crisisresponse.iom.int/appeal-documents/format/json";

  /**
   * Language mapping for title suffixes and labels.
   *
   * @var array<string, int>
   */
  protected array $languageMapping = [
    'EN' => 267,
    'English' => 267,
    'FR' => 268,
    'French' => 268,
    'ES' => 269,
    'Spanish' => 269,
  ];

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_urls'] = [
      '#type' => 'textarea',
      '#title' => $this->t('API URLs'),
      '#description' => $this->t('IOM Crisis Response JSON feed URLs. One per line. Documents from all feeds are merged and deduplicated by PDF URL.'),
      '#default_value' => $form_state->getValue('api_urls', $this->getPluginSetting('api_urls', static::DEFAULT_API_URLS, FALSE)),
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

      $this->getLogger()->info('Retrieving documents from the IOM Crisis Response feeds.');

      // Retrieve the latest documents (already sorted oldest first).
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

    $this->getLogger()->info(strtr('Retrieved @count IOM Crisis Response documents.', [
      '@count' => count($documents),
    ]));

    // Process the documents importing new ones and updated ones.
    $processed = $this->processDocuments($documents, $provider_uuid, $plugin);

    // @todo check if we want to return TRUE only if there was no errors or if
    // return TRUE for partial success is fine enough.
    return $processed > 0;
  }

  /**
   * Retrieve documents from the configured feeds.
   *
   * @param int $limit
   *   Maximum number of documents to retrieve at once.
   *
   * @return array
   *   List of normalized documents keyed by stable IDs, oldest first.
   */
  protected function getDocuments(int $limit): array {
    $timeout = $this->getPluginSetting('timeout', 5, FALSE);
    $max_age = max(1, (int) $this->getPluginSetting('max_age', 3, FALSE));
    $cutoff = time() - ($max_age * 24 * 60 * 60);

    $api_urls = $this->parseApiUrls(
      (string) $this->getPluginSetting('api_urls', static::DEFAULT_API_URLS, FALSE)
    );
    if (empty($api_urls)) {
      throw new \Exception('No IOM Crisis Response API URLs configured.');
    }

    // Merge and dedupe by canonical PDF URL.
    $merged = [];
    foreach ($api_urls as $api_url) {
      $feed = $this->extractFeedName($api_url);
      $items = $this->fetchFeed($api_url, (int) $timeout);

      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }

        $document = $this->normalizeFeedItem($item, $feed);
        if ($document === NULL) {
          continue;
        }

        if ($document['timestamp'] < $cutoff) {
          continue;
        }

        $pdf_url = $document['url'];
        if (isset($merged[$pdf_url])) {
          $merged[$pdf_url]['feeds'] = array_values(array_unique([
            ...$merged[$pdf_url]['feeds'],
            ...$document['feeds'],
          ]));
          continue;
        }

        $merged[$pdf_url] = $document;
      }
    }

    if (empty($merged)) {
      return [];
    }

    // Sort oldest first, then keep the newest $limit documents.
    $documents = array_values($merged);
    usort($documents, static function (array $a, array $b): int {
      return $a['timestamp'] <=> $b['timestamp'];
    });

    if ($limit > 0 && count($documents) > $limit) {
      $documents = array_slice($documents, -$limit);
    }

    // Preserve oldest-first order while keying by stable id.
    $map = [];
    foreach ($documents as $document) {
      $map[$document['id']] = $document;
    }

    return $map;
  }

  /**
   * Fetch and decode a JSON feed.
   *
   * @param string $api_url
   *   Feed URL.
   * @param int $timeout
   *   Request timeout in seconds.
   *
   * @return array
   *   Decoded list of feed items.
   */
  protected function fetchFeed(string $api_url, int $timeout): array {
    $response = $this->httpClient->get($api_url, [
      'connect_timeout' => $timeout,
      'timeout' => $timeout,
    ]);

    if ($response->getStatusCode() !== 200) {
      // @todo try to retrieve the error message.
      throw new \Exception('Failure with response code: ' . $response->getStatusCode() . ' for ' . $api_url);
    }

    $content = $response->getBody()->getContents();
    if ($content === '') {
      return [];
    }

    $documents = json_decode($content, TRUE, flags: \JSON_THROW_ON_ERROR);
    if (!is_array($documents)) {
      throw new \Exception('Invalid JSON feed response for ' . $api_url);
    }

    return $documents;
  }

  /**
   * Normalize a raw feed item.
   *
   * @param array $item
   *   Raw feed item.
   * @param string $feed
   *   Feed name.
   *
   * @return array|null
   *   Normalized document or NULL if it should be skipped.
   */
  protected function normalizeFeedItem(array $item, string $feed): ?array {
    $title = html_entity_decode(trim((string) ($item['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $link = $this->normalizeUrl((string) ($item['field_link'] ?? ''));
    $date = $this->parseFeedDate((string) ($item['field_date'] ?? ''));

    if ($title === '' || $link === '' || $date === NULL) {
      return NULL;
    }

    if (!$this->isPdfUrl($link)) {
      return NULL;
    }

    return [
      'id' => hash('sha256', $link),
      'title' => $title,
      'url' => $link,
      'pdfurl' => $link,
      'date' => gmdate('c', $date),
      'timestamp' => $date,
      'feeds' => [$feed],
    ];
  }

  /**
   * Process the documents retrieved from the feeds.
   *
   * @param array $documents
   *   Normalized IOM documents.
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

    $document_urls = [];
    foreach ($documents as $document) {
      if (isset($document['url']) && is_string($document['url'])) {
        $document_urls[] = $document['url'];
      }
    }

    // Retrieve the list of documents manually posted so we can exclude them
    // from the import.
    $manually_posted = $this->getManuallyPostedDocumentsFromUrls($document_urls);

    // Retrieve the list of existing import records for the documents.
    $uuids = array_filter(array_map(fn($item) => is_string($item['url'] ?? NULL) ? $this->generateUuid($item['url']) : '', $documents));
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
        'source' => 'IOM',
        'extra' => [
          'iom_crisis_response' => [
            'feeds' => $document['feeds'] ?? [],
          ],
        ],
      ];

      // Retrieve the document ID.
      if (!isset($document['id'])) {
        $this->getLogger()->notice('Undefined IOM Crisis Response document ID, skipping document import.');
        continue;
      }
      $id = $document['id'];
      $import_record['imported_item_id'] = $id;

      // Retrieve the document URL.
      if (!isset($document['url']) || !is_string($document['url']) || $document['url'] === '') {
        $this->getLogger()->notice(strtr('Undefined document URL for IOM Crisis Response document ID @id, skipping document import.', [
          '@id' => $id,
        ]));
        continue;
      }
      $url = $document['url'];
      $import_record['imported_item_url'] = $url;

      // Check if the document was not already manually posted.
      if (isset($manually_posted[$url])) {
        $this->getLogger()->notice(strtr('IOM Crisis Response document @id already manually posted as report @report_id.', [
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

      $this->getLogger()->info(strtr('Processing IOM Crisis Response document @id.', [
        '@id' => $id,
      ]));

      // Generate a hash from the data we use to import the document. This is
      // used to detect changes that can affect the document on ReliefWeb.
      $filtered_document = $this->filterArrayByKeys($document, [
        'title',
        'url',
        'pdfurl',
        'date',
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
        $this->getLogger()->info(strtr('IOM Crisis Response document @id (entity @entity_id) already imported and not changed, skipping.', [
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

        $this->getLogger()->error(strtr('Too many import attempts for IOM Crisis Response document @id, skipping.', [
          '@id' => $id,
        ]));

        continue;
      }

      // Process the item data into importable data.
      $data = $this->getImportData($uuid, $document);
      if (empty($data)) {
        $this->getLogger()->notice(strtr('No data to import for IOM Crisis Response document @id.', [
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
        $this->getLogger()->info(strtr('Successfully processed IOM Crisis Response document @id to entity @entity_id.', [
          '@id' => $id,
          '@entity_id' => $entity->id(),
        ]));
      }
      catch (DuplicateException $exception) {
        $import_record['status'] = 'duplicate';
        $import_record['message'] = $exception->getMessage();
        $import_record['attempts'] = $max_import_attempts;
        $this->getLogger()->error(strtr('Unable to process IOM Crisis Response document @id: @exception', [
          '@id' => $id,
          '@exception' => $exception->getMessage(),
        ]));
      }
      catch (\Exception $exception) {
        $import_record['status'] = 'error';
        $import_record['message'] = $exception->getMessage();
        $import_record['attempts'] = ($import_record['attempts'] ?? 0) + 1;
        $this->getLogger()->error(strtr('Unable to process IOM Crisis Response document @id: @exception', [
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
    // Source: International Organization for Migration (IOM).
    $sources = [1255];

    // Document URL (canonical PDF).
    $url = $document['url'];

    // Retrieve the title and clean it.
    $title = $this->sanitizeText(is_string($document['title'] ?? NULL) ? $document['title'] : '');

    // No body text is available in the feeds.
    $body = '';

    // Retrieve the publication date.
    $published = strtotime($document['date'] ?? '');
    $published = DateHelper::format($published, 'custom', 'c');

    // Language from optional [EN]/[ES]/[FR] title suffix, else English.
    $languages = [];
    if (preg_match('/\[(EN|ES|FR)\]\s*$/i', (string) ($document['title'] ?? ''), $matches)) {
      $code = strtoupper($matches[1]);
      if (isset($this->languageMapping[$code])) {
        $languages[$code] = $this->languageMapping[$code];
      }
    }
    if (empty($languages)) {
      $languages['English'] = $this->languageMapping['English'];
    }

    // Set content format to 'Other'.
    $formats = [9];

    // Tag with World; classification can refine country later.
    $countries = [254];

    // Retrieve the data for the attachment if any.
    $files = [];
    if (isset($document['pdfurl']) && is_string($document['pdfurl']) && $document['pdfurl'] !== '') {
      $document_url = $document['pdfurl'];
      $info = $this->getRemoteFileInfo($document_url);
      if (!empty($info)) {
        $file_url = $document_url;
        $file_uuid = $this->generateUuid($file_url, $uuid);
        $files[$file_uuid] = [
          'url' => $file_url,
          'uuid' => $file_uuid,
          'download_url' => $file_url,
        ] + $info;
      }
    }

    if (empty($files)) {
      $this->getLogger()->info(strtr('No PDF found for IOM Crisis Response @id, skipping.', [
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
      'file' => $files,
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
      // Allow non-PDF attachment filenames for import.
      if (isset($decoded['properties']['file']['patternProperties']) && is_array($decoded['properties']['file']['patternProperties'])) {
        foreach ($decoded['properties']['file']['patternProperties'] as &$file_value_schema) {
          if (($file_value_schema['type'] ?? NULL) === 'object') {
            unset($file_value_schema['properties']['filename']['pattern']);
          }
        }
        unset($file_value_schema);
      }
      // Allow empty strings as body.
      unset($decoded['properties']['body']['minLength']);
      unset($decoded['properties']['body']['allOf']);
      unset($decoded['properties']['body']['not']);
      $schema = Json::encode($decoded);
    }
    return $schema;
  }

  /**
   * Parse configured API URLs.
   *
   * @param string $api_urls
   *   Newline-separated URLs.
   *
   * @return array
   *   List of URLs.
   */
  protected function parseApiUrls(string $api_urls): array {
    $urls = [];
    foreach (preg_split('/\R/', $api_urls) ?: [] as $url) {
      $url = trim($url);
      if ($url !== '') {
        $urls[] = $url;
      }
    }
    return $urls;
  }

  /**
   * Extract a short feed name from a feed URL.
   *
   * @param string $api_url
   *   Feed URL.
   *
   * @return string
   *   Feed name.
   */
  protected function extractFeedName(string $api_url): string {
    $path = parse_url($api_url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
      return $api_url;
    }

    $parts = array_values(array_filter(explode('/', $path)));
    // Expect paths like /iom-publications/format/json.
    if (count($parts) >= 3 && $parts[count($parts) - 2] === 'format') {
      return $parts[count($parts) - 3];
    }

    return $parts[0] ?? $api_url;
  }

  /**
   * Normalize a document URL.
   *
   * @param string $url
   *   Raw URL.
   *
   * @return string
   *   Normalized absolute URL or empty string.
   */
  protected function normalizeUrl(string $url): string {
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '') {
      return '';
    }

    return str_replace('http://', 'https://', $url);
  }

  /**
   * Check whether a URL points to a PDF file.
   *
   * @param string $url
   *   URL to check.
   *
   * @return bool
   *   TRUE if the URL looks like a PDF.
   */
  protected function isPdfUrl(string $url): bool {
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path)) {
      return FALSE;
    }

    return str_ends_with(strtolower($path), '.pdf');
  }

  /**
   * Parse the publication date from the feed HTML date field.
   *
   * @param string $field_date
   *   Raw field_date value (often an HTML time element).
   *
   * @return int|null
   *   Unix timestamp or NULL.
   */
  protected function parseFeedDate(string $field_date): ?int {
    $field_date = trim($field_date);
    if ($field_date === '') {
      return NULL;
    }

    if (preg_match('/datetime="([^"]+)"/i', $field_date, $matches)) {
      $timestamp = strtotime($matches[1]);
      return $timestamp !== FALSE ? $timestamp : NULL;
    }

    $timestamp = strtotime(strip_tags($field_date));
    return $timestamp !== FALSE ? $timestamp : NULL;
  }

}
