<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin\ReliefWebImporter;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\reliefweb_import\Attribute\ReliefWebImporter;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginBase;
use Drupal\reliefweb_post_api\Exception\DuplicateException;
use Drupal\reliefweb_post_api\Helpers\HashHelper;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface;
use Drupal\reliefweb_utility\Helpers\DateHelper;

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

      if ($this->getPluginSetting('local_file_load', FALSE, FALSE)) {
        $local_file_path = $this->getPluginSetting('local_file_path', '/var/www/inoreader.json', FALSE);
        $this->getLogger()->info('Retrieving documents from disk.');
        $documents = file_get_contents($local_file_path);
        if ($documents === FALSE) {
          $this->getLogger()->error('Unable to retrieve the Inoreader documents.');
          return FALSE;
        }
        $documents = json_decode($documents, TRUE, flags: \JSON_THROW_ON_ERROR);
      }
      else {
        $this->getLogger()->info('Retrieving documents from the Inoreader.');

        // Retrieve the latest created documents.
        $documents = $this->getDocuments($limit);

        if (empty($documents)) {
          $this->getLogger()->notice('No documents.');
          return TRUE;
        }
      }
    }
    catch (\Exception $exception) {
      $this->getLogger()->error($exception->getMessage());
      return FALSE;
    }

    $this->getLogger()->info(strtr('Retrieved @count Inoreader documents from @url.', [
      '@count' => count($documents),
      '@url' => $this->getPluginSetting('api_url'),
    ]));

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
   * Get authorization token from Inoreader.
   */
  protected function getAuthToken(): string {
    $timeout = $this->getPluginSetting('timeout', 10, FALSE);
    $email = $this->getPluginSetting('email');
    $password = $this->getPluginSetting('password');
    $app_id = $this->getPluginSetting('app_id');
    $app_key = $this->getPluginSetting('app_key');

    // Get auth token.
    $response = $this->httpClient->post("https://www.inoreader.com/accounts/ClientLogin", [
      'connect_timeout' => $timeout,
      'timeout' => $timeout,
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'AppId' => $app_id,
        'AppKey' => $app_key,
      ],
      'form_params' => [
        'Email' => $email,
        'Passwd' => $password,
      ],
    ]);

    if ($response->getStatusCode() !== 200) {
      // @todo try to retrieve the error message.
      throw new \Exception('Failure with response code: ' . $response->getStatusCode());
    }

    $auth = '';
    $content = $response->getBody()->getContents();
    foreach (explode("\n", $content) as $line) {
      if (preg_match('/Auth=([^&]+)/', $line, $matches)) {
        $auth = $matches[1];
        break;
      }
    }

    if (empty($auth)) {
      throw new \Exception('Unable to retrieve auth token.');
    }

    return $auth;
  }

  /**
   * Retrieve documents from the Inoreader.
   *
   * @return array
   *   List of documents keyed by IDs.
   */
  protected function getDocuments(int $limit = 50): array {
    $real_limit = $limit;
    $continuation = '';
    $use_continuation = FALSE;
    if ($limit > 100) {
      $use_continuation = TRUE;
      $limit = 100;
    }

    $documents = [];

    // Get list of documents.
    try {
      $timeout = $this->getPluginSetting('timeout', 10, FALSE);
      $app_id = $this->getPluginSetting('app_id');
      $app_key = $this->getPluginSetting('app_key');
      $api_url = $this->getPluginSetting('api_url');
      $max_age = (int) $this->state->get('reliefweb_importer_inoreader_max_age', 24 * 60 * 60);
      $most_recent_timestamp = (int) $this->state->get('reliefweb_importer_inoreader_most_recent_timestamp', 0);
      $ignore_timestamp = (bool) $this->state->get('reliefweb_importer_inoreader_ignore_timestamp', FALSE);

      // This is mostly for the first run.
      if (empty($most_recent_timestamp)) {
        $most_recent_timestamp = (time() - $max_age) * 1_000_000;
      }
      else {
        // 1 minute margin.
        $most_recent_timestamp -= (60 * 1_000_000);
      }

      $auth = $this->getAuthToken();

      while ($real_limit > 0) {
        $api_parts = parse_url($api_url);
        parse_str($api_parts['query'] ?? '', $query);
        $query['n'] = $limit;
        if (!empty($continuation)) {
          $query['c'] = $continuation;
        }

        if (!$ignore_timestamp) {
          // Add filter on start date (microseconds timestamp).
          $query['ot'] = $most_recent_timestamp;

          // Exclude starred items.
          $query['xt'] = 'user/-/state/com.google/starred';
        }

        // Rebuild the URL.
        $api_url = $api_parts['scheme'] . '://' . $api_parts['host'] . $api_parts['path'] . '?' . http_build_query($query);

        $response = $this->httpClient->get($api_url, [
          'connect_timeout' => $timeout,
          'timeout' => $timeout,
          'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'AppId' => $app_id,
            'AppKey' => $app_key,
            'Authorization' => 'GoogleLogin auth=' . $auth,
          ],
        ]);

        if ($response->getStatusCode() !== 200) {
          // @todo try to retrieve the error message.
          throw new \Exception('Failure with response code: ' . $response->getStatusCode());
        }

        $content = $response->getBody()->getContents();
        if (!empty($content)) {
          $result = json_decode($content, TRUE, flags: \JSON_THROW_ON_ERROR);
          if (isset($result['items'])) {
            foreach ($result['items'] as $document) {
              if (!isset($result['id'])) {
                continue;
              }

              $documents[$document['id']] = $document;
            }
          }
          if ($use_continuation && isset($result['continuation'])) {
            $continuation = $result['continuation'];
            $real_limit -= $limit;
          }
          else {
            $continuation = '';
            $real_limit = 0;
          }
        }
        else {
          $continuation = '';
          $real_limit = 0;
        }
      }
    }
    catch (\Exception $exception) {
      $message = $exception->getMessage();
      throw new \Exception($message);
    }

    if ($this->getPluginSetting('local_file_save', FALSE, FALSE)) {
      $local_file_path = $this->getPluginSetting('local_file_path', '/var/www/inoreader.json', FALSE);
      $f = fopen($local_file_path, 'w');
      if ($f) {
        fwrite($f, json_encode($documents, \JSON_PRETTY_PRINT));
        fclose($f);
        $this->getLogger()->info('Inoreader documents written to ' . $local_file_path);
      }
      else {
        $this->getLogger()->error('Unable to open file ' . $local_file_path . ' for writing.');
      }
    }

    return $documents;
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
      $import_record = [
        'importer' => $this->getPluginId(),
        'provider_uuid' => $provider_uuid,
        'entity_type_id' => $entity_type_id,
        'entity_bundle' => $bundle,
        'status' => 'pending',
        'message' => '',
        'attempts' => 0,
        'extra' => [
          'inoreader' => [
            'feed_name' => $document['origin']['title'] ?? '',
            'feed_url' => 'https://www.inoreader.com/' . $document['origin']['streamId'] ?? '',
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
      $import_record['imported_item_url'] = $url;

      // Generate the UUID for the document.
      $uuid = $this->generateUuid($url);
      $import_record['imported_item_uuid'] = $uuid;

      // Merge with existing record if available.
      if (isset($existing_import_records[$uuid])) {
        $import_record = NestedArray::mergeDeep($existing_import_records[$uuid], $import_record);
      }

      $this->getLogger()->info(strtr('Processing Inoreader document @id.', [
        '@id' => $id,
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
        $import_record['message'] = 'Too many attempts.';
        $import_records[$import_record['imported_item_uuid']] = $import_record;

        $this->getLogger()->error(strtr('Too many import attempts for Inoreader document @id, skipping.', [
          '@id' => $id,
        ]));

        continue;
      }

      // Process the item data into importable data.
      $data = $this->getImportData($uuid, $document);
      if (empty($data)) {
        $this->getLogger()->info(strtr('Inoreader document @id has no data to import, skipping.', [
          '@id' => $id,
        ]));

        // Log it.
        $import_record['status'] = 'skipped';
        $import_record['message'] = 'No data to import.';
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
      catch (\Exception $exception) {
        $import_record['status'] = 'error';
        $import_record['message'] = $exception->getMessage();
        // In case of duplication, we do not try further imports.
        if ($exception instanceof DuplicateException) {
          $import_record['attempts'] = $max_import_attempts;
        }
        else {
          $import_record['attempts'] = ($import_record['attempts'] ?? 0) + 1;
        }
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
    $fetch_timeout = $this->getPluginSetting('fetch_timeout', 10, FALSE);

    $data = [];

    $id = $document['id'];
    $url = $document['canonical'][0]['href'];

    // Retrieve the title and clean it.
    $title = $this->sanitizeText(html_entity_decode($document['title'] ?? ''));

    // Retrieve the publication date.
    $published = $document['published'] ?? time();
    $published = DateHelper::format($published, 'custom', 'c');

    // Retrieve the description.
    $body = $this->sanitizeText(html_entity_decode($document['summary']['content'] ?? ''), TRUE);

    $origin_title = trim($this->sanitizeText($document['origin']['title'] ?? ''));
    $files = [];
    $sources = [];
    $pdf = '';

    if (strpos($origin_title, '[source:') === FALSE) {
      if (empty($sources)) {
        $this->getLogger()->info(strtr('No source defined for Inoreader @id, skipping. Origin is set to @origin_title', [
          '@id' => $id,
          '@origin_title' => $origin_title,
        ]));

        return [];
      }
    }

    preg_match_all('/\[(.*?)\]/', $origin_title, $matches);
    $matches = $matches[1];

    // Parse everything so we can reference it easily.
    $tags = [];
    foreach ($matches as $match) {
      $tag_parts = explode(':', $match);
      $tag_key = reset($tag_parts);
      array_shift($tag_parts);
      $tag_value = implode(':', $tag_parts);

      if (!isset($tags[$tag_key])) {
        $tags[$tag_key] = $tag_value;
      }
      else {
        if (!is_array($tags[$tag_key])) {
          $tags[$tag_key] = [
            $tags[$tag_key],
          ];
        }
        $tags[$tag_key][] = $tag_value;
      }
    }

    // Get extra tags from state.
    $extra_tags = $this->state->get('reliefweb_importer_inoreader_extra_tags', []);

    if (!empty($extra_tags[$tags['source']])) {
      foreach ($extra_tags[$tags['source']] as $key => $value) {
        if (isset($tags[$key])) {
          if (!is_array($tags[$key])) {
            $tags[$key] = [
              $tags[$key],
            ];
          }
          $tags[$key] = array_merge($tags[$key], $value);
        }
        else {
          $tags[$key] = $value;
        }
      }
    }

    // Source is mandatory, so present.
    $sources = [
      (int) $tags['source'],
    ];

    // Check for custom fetch timeout.
    if (isset($tags['timeout'])) {
      $fetch_timeout = (int) $tags['timeout'];
      unset($tags['timeout']);
    }

    foreach ($tags as $tag_key => $tag_value) {
      if ($tag_key == 'pdf') {
        switch ($tag_value) {
          case 'canonical':
            $pdf = $document['canonical'][0]['href'] ?? '';
            break;

          case 'summary-link':
            $pdf = $this->extractPdfUrl($document['summary']['content'] ?? '', 'a', 'href');
            break;

          case 'page-link':
            $page_url = $document['canonical'][0]['href'] ?? '';
            $html = $this->downloadHtmlPage($page_url, $fetch_timeout);
            if (empty($html)) {
              $this->getLogger()->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                '@id' => $id,
                '@url' => $page_url,
              ]));
            }
            else {
              $pdf = $this->tryToExtractPdfFromHtml($page_url, $html, $tags);
              if (isset($tags['follow'])) {
                // Follow link and fetch PDF from that page.
                if (strpos($pdf, $tags['follow']) !== FALSE) {
                  $page_url = $pdf;
                  $html = $this->downloadHtmlPage($page_url, $fetch_timeout);
                  if (empty($html)) {
                    $this->getLogger()->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                      '@id' => $id,
                      '@url' => $page_url,
                    ]));
                  }
                  else {
                    $pdf = $this->tryToExtractPdfFromHtml($page_url, $html, $tags);
                  }
                }
              }
            }

            break;

          case 'page-object':
            $page_url = $document['canonical'][0]['href'] ?? '';
            $html = $this->downloadHtmlPage($page_url, $fetch_timeout);
            if (empty($html)) {
              $this->getLogger()->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                '@id' => $id,
                '@url' => $page_url,
              ]));
            }
            $pdf = $this->extractPdfUrl($html, 'object', 'data');

            break;

          case 'page-iframe-data-src':
            $page_url = $document['canonical'][0]['href'] ?? '';
            $html = $this->downloadHtmlPage($page_url, $fetch_timeout);
            if (empty($html)) {
              $this->getLogger()->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                '@id' => $id,
                '@url' => $page_url,
              ]));
            }
            $pdf = $this->extractPdfUrl($html, 'iframe', 'data-src');

            break;

          case 'page-iframe-src':
            $page_url = $document['canonical'][0]['href'] ?? '';
            $html = $this->downloadHtmlPage($page_url, $fetch_timeout);
            if (empty($html)) {
              $this->getLogger()->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                '@id' => $id,
                '@url' => $page_url,
              ]));
            }
            $pdf = $this->extractPdfUrl($html, 'iframe', 'src');
            break;

          case 'js':
            $page_url = $document['canonical'][0]['href'] ?? '';
            $pdf = $this->tryToExtractPdfUsingPuppeteer($page_url, $tags, $fetch_timeout);
            break;

        }

        $pdf = $this->rewritePdfLink($pdf, $tags);
      }
      elseif ($tag_key == 'content') {
        switch ($tag_value) {
          case 'clear':
          case 'ignore':
            $body = '';
            break;
        }
      }
      elseif ($tag_key == 'title') {
        switch ($tag_value) {
          case 'filename':
          case 'canonical':
            $title = basename($document['canonical'][0]['href'] ?? '');
            $title = str_replace('.pdf', '', $title);
            $title = str_replace(['-', '_'], ' ', $title);
            $title = $this->sanitizeText($title);
            break;
        }
      }
    }

    if (empty($sources)) {
      $this->getLogger()->info(strtr('No source defined for Inoreader @id, skipping. Origin is set to @origin_title', [
        '@id' => $id,
        '@origin_title' => $origin_title,
      ]));

      return [];
    }

    if (empty($pdf)) {
      $this->getLogger()->info(strtr('No PDF found for Inoreader @id, skipping.', [
        '@id' => $id,
      ]));

      return [];
    }

    $info = $this->getRemoteFileInfo($pdf);
    if (!empty($info)) {
      $file_url = $pdf;
      $file_uuid = $this->generateUuid($file_url, $uuid);
      $files[] = [
        'url' => $file_url,
        'uuid' => $file_uuid,
      ] + $info;
    }

    if (empty($files)) {
      $this->getLogger()->info(strtr('No files found for Inoreader @id, skipping.', [
        '@id' => $id,
      ]));

      return [];
    }

    // Submission data.
    $data = [
      'title' => $title,
      'body' => substr($body ?? '', 0, 100000),
      'published' => $published,
      'origin' => $url,
      'source' => $sources,
      'language' => [267],
      'country' => [254],
      'format' => [8],
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

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationForceFieldUpdate(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void {
    parent::alterContentClassificationForceFieldUpdate($fields, $workflow, $context);
    if (isset($context['entity'])) {
      // Allow overriding the title with the AI extracted one if the title
      // contains a link.
      if (preg_match('#https?://#i', $context['entity']->title->value)) {
        $fields['title__value'] = TRUE;
      }
    }
  }

  /**
   * Try to extract the link to a PDF file from HTML content.
   * */
  protected function tryToExtractPdfFromHtml($page_url, $html, $tags) {
    $pdf = '';
    $contains = [];
    if (isset($tags['url'])) {
      if (is_array($tags['url'])) {
        $contains = $tags['url'];
      }
      else {
        $contains[] = $tags['url'];
      }
    }

    if (isset($tags['wrapper'])) {
      if (is_array($tags['wrapper'])) {
        foreach ($tags['wrapper'] as $wrapper) {
          $pdf = $this->extractPdfUrl($html, 'a', 'href', '', '', $wrapper, $contains);
          if ($pdf) {
            break;
          }
        }
      }
      else {
        $pdf = $this->extractPdfUrl($html, 'a', 'href', '', '', $tags['wrapper'], $contains);
      }
    }
    else {
      $pdf = $this->extractPdfUrl($html, 'a', 'href', '', '', '', $contains);
    }
    if (!empty($pdf) && strpos($pdf, 'http') !== 0) {
      $url_parts = parse_url($page_url);
      $pdf = ($url_parts['scheme'] ?? 'https') . '://' . $url_parts['host'] . $pdf;
    }

    return $pdf;
  }

  /**
   * Extract the PDF URL from the HTML content.
   */
  protected function extractPdfUrl(string $html, string $tag, string $attribute, string $class = '', string $extension = '', string $wrapper = '', array $contains = []): ?string {
    if (empty($html)) {
      return '';
    }

    $dom = new \DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new \DOMXPath($dom);

    $elements = [];
    if (empty($wrapper)) {
      $elements = $xpath->query("//{$tag}[@{$attribute}]");
    }
    else {
      [$wrapper_element, $wrapper_class] = explode('.', $wrapper);
      $parent = $xpath->query("//{$wrapper_element}[contains(@class, '{$wrapper_class}')]")->item(0);
      if (!$parent) {
        return '';
      }

      $elements = $xpath->query(".//{$tag}[@{$attribute}]", $parent);
    }

    /** @var \DOMNode $element */
    foreach ($elements as $element) {
      $url = $element->getAttribute($attribute);
      if (empty($url) || $url == '#') {
        continue;
      }

      if (!empty($class) && $element->hasAttribute('class') && preg_match('/\b' . preg_quote($class, '/') . '\b/', $element->getAttribute('class'))) {
        if (!empty($contains)) {
          foreach ($contains as $contain) {
            if (strpos($url, $contain) !== FALSE) {
              return $url;
            }
          }
        }
        else {
          return $url;
        }
      }

      if (!empty($extension) && preg_match('/\.' . $extension . '$/i', $url)) {
        if (!empty($contains)) {
          foreach ($contains as $contain) {
            if (strpos($url, $contain) !== FALSE) {
              return $url;
            }
          }
        }
        else {
          return $url;
        }
      }

      if (empty($class) && empty($extension)) {
        if (!empty($contains)) {
          foreach ($contains as $contain) {
            if (strpos($url, $contain) !== FALSE) {
              return $url;
            }
          }
        }
        else {
          return $url;
        }
      }
    }

    return '';
  }

  /**
   * Download HTML page as string.
   */
  protected function downloadHtmlPage($url, $fetch_timeout) {
    try {
      $response = $this->httpClient->get($url, [
        'connect_timeout' => $fetch_timeout,
        'timeout' => $fetch_timeout,
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 AppleWebKit Chrome/134.0.0.0 Safari/537.36',
          'accept' => 'text/html,application/xhtml+xml,application/xml,*/*',
          'accept-language' => 'en-US,en;q=0.9',
        ],
      ]);

      if ($response->getStatusCode() !== 200) {
        throw new \Exception('Failure with response code: ' . $response->getStatusCode());
      }

      return $response->getBody()->getContents();
    }
    catch (\Exception $exception) {
      try {
        // Try without headers.
        $response = $this->httpClient->get($url, [
          'connect_timeout' => $fetch_timeout,
          'timeout' => $fetch_timeout,
        ]);

        if ($response->getStatusCode() !== 200) {
          throw new \Exception('Failure with response code: ' . $response->getStatusCode());
        }

        return $response->getBody()->getContents();
      }
      catch (\Exception $exception) {
        // Fail silently.
        $this->getLogger()->info('Failure with response code: ' . $exception->getMessage());
        return '';
      }
    }

    return '';
  }

  /**
   * Rewrite PDF link.
   */
  protected function rewritePdfLink($pdf, $tags) {
    if (empty($pdf)) {
      return $pdf;
    }

    if (!isset($tags['replace'])) {
      return $pdf;
    }

    if (!is_array($tags['replace'])) {
      $tags['replace'] = [$tags['replace']];
    }

    foreach ($tags['replace'] as $replace) {
      [$from, $to] = explode(':', $replace);
      $pdf = str_replace($from, $to, $pdf);
    }

    return $pdf;
  }

  /**
   * Try to extract the link to a PDF file from HTML content.
   * */
  protected function tryToExtractPdfUsingPuppeteer($page_url, $tags, $fetch_timeout) {
    $pdf = '';
    $blob = FALSE;

    // Check if we need to request the PDF as Blob.
    if (isset($tags['puppeteer-blob'])) {
      $blob = TRUE;
    }

    if (isset($tags['wrapper'])) {
      if (!is_array($tags['wrapper'])) {
        $tags['wrapper'] = [$tags['wrapper']];
      }

      foreach ($tags['wrapper'] as $wrapper) {
        $pdf = reliefweb_import_extract_pdf_file($page_url, $wrapper, $tags['puppeteer'], $tags['puppeteer-attrib'] ?? 'href', $fetch_timeout, $blob);
        if ($pdf) {
          break;
        }
      }
    }
    else {
      $pdf = reliefweb_import_extract_pdf_file($page_url, '', $tags['puppeteer'], $tags['puppeteer-attrib'] ?? 'href', $fetch_timeout, $blob);
    }

    if (empty($pdf)) {
      return '';
    }

    if (!$blob) {
      if (!empty($pdf['pdf']) && strpos($pdf['pdf'], 'http') !== 0) {
        $url_parts = parse_url($page_url);
        $pdf['pdf'] = ($url_parts['scheme'] ?? 'https') . '://' . $url_parts['host'] . $pdf['pdf'];
      }

      return $pdf['pdf'];
    }

    if (empty($pdf['blob'])) {
      $this->getLogger()->error(strtr('Unable to retrieve the PDF blob for Inoreader document @id -- @url.', [
        '@id' => $page_url,
        '@url' => $pdf['pdf'],
      ]));
      return '';
    }

    // Save the blob to a file.
    $local_file_path = '/tmp/' . basename($pdf['pdf']);
    $f = fopen($local_file_path, 'w');
    if ($f) {
      fwrite($f, $pdf['blob']);
      fclose($f);
      $this->getLogger()->info('Inoreader PDF blob written to ' . $local_file_path);
      return 'file://' . $local_file_path;
    }
    else {
      $this->getLogger()->error('Unable to open file ' . $local_file_path . ' for writing.');
    }

    return '';
  }

}
