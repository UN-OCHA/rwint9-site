<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin\ReliefWebImporter;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\reliefweb_import\Attribute\ReliefWebImporter;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginBase;
use Drupal\reliefweb_post_api\Helpers\HashHelper;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use Drupal\reliefweb_post_api\Queue\ReliefWebPostApiDatabaseQueueFactory;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

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
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ClientInterface $httpClient,
    protected MimeTypeGuesserInterface $mimeTypeGuesser,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
    protected Connection $database,
    protected ContentProcessorPluginManagerInterface $contentProcessorPluginManager,
    protected ExtensionPathResolver $pathResolver,
    protected ReliefWebPostApiDatabaseQueueFactory $queueFactory,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $configFactory,
      $state,
      $loggerFactory,
      $httpClient,
      $mimeTypeGuesser,
      $entityFieldManager,
      $entityTypeManager,
      $entityRepository,
      $database,
      $contentProcessorPluginManager,
      $pathResolver,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('logger.factory'),
      $container->get('http_client'),
      $container->get('file.mime_type.guesser.extension'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('entity.repository'),
      $container->get('database'),
      $container->get('plugin.manager.reliefweb_post_api.content_processor'),
      $container->get('extension.path.resolver'),
      $container->get('reliefweb_post_api.queue.database'),
    );
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

      if (TRUE) {
        $this->getLogger()->info('Retrieving documents from disk.');
        $documents = file_get_contents('/var/www/inoreader.json');
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

    $this->getLogger()->info(strtr('Retrieved @count Inoreader documents.', [
      '@count' => count($documents),
    ]));

    // Process the documents importing new ones and updated ones.
    $processed = $this->processDocuments($documents, $provider_uuid, $plugin);

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
    $real_limit = $limit;
    $continuation = '';
    $use_continuation = FALSE;
    if ($limit > 100) {
      $use_continuation = TRUE;
      $limit = 100;
    }

    // Get list of documents.
    try {
      $timeout = $this->getPluginSetting('timeout', 10, FALSE);
      $email = $this->getPluginSetting('email');
      $password = $this->getPluginSetting('password');
      $app_id = $this->getPluginSetting('app_id');
      $app_key = $this->getPluginSetting('app_key');
      $api_url = $this->getPluginSetting('api_url');

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

      while ($real_limit > 0) {
        $api_parts = parse_url($api_url);
        parse_str($api_parts['query'] ?? '', $query);
        $query['n'] = $limit;
        if (!empty($continuation)) {
          $query['c'] = $continuation;
        }
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

    $f = fopen('/var/www/inoreader.json', 'w');
    if ($f) {
      fwrite($f, json_encode($documents, JSON_PRETTY_PRINT));
      fclose($f);
    }
    else {
      $this->getLogger()->error('Unable to open file for writing.');
    }
    $this->getLogger()->info('Inoreader documents written to /tmp/inoreader.json');

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
    $schema = $this->getJsonSchema('report');

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
        'entity_type_id' => 'node',
        'entity_bundle' => 'report',
        'status' => 'pending',
        'message' => '',
        'attempts' => 0,
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
        $import_record = $existing_import_records[$uuid] + $import_record;
      }

      $this->getLogger()->info(strtr('Processing Inoreader document @id.', [
        '@id' => $id,
      ]));

      // Check if how many times we tried to import this item.
      if (!empty($import_record['attempts']) && $import_record['attempts'] >= $max_import_attempts) {
        $import_record['status'] = 'error';
        $import_record['message'] = 'Too many attempts.';
        $import_records[$import_record['imported_item_uuid']] = $import_record;

        $this->getLogger()->error(strtr('Too many import attempts for Inoreader document @id, skipping.', [
          '@id' => $id,
        ]));
        continue;
      }
      // Generate hash.
      $hash = HashHelper::generateHash($document);
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

      // Retrieve the title and clean it.
      $title = $this->sanitizeText($document['title'] ?? '');

      // Retrieve the publication date.
      $published = $document['published'] ?? time();
      $published = DateHelper::format($published, 'custom', 'c');

      // Retrieve the description.
      $body = $this->sanitizeText($document['summary']['content'] ?? '', TRUE);

      $origin_title = trim($this->sanitizeText($document['origin']['title'] ?? ''));
      $files = [];
      $sources = [];
      $pdf = '';

      if (strpos($origin_title, '[source:') > 0) {
        preg_match_all('/\[(.*?)\]/', $origin_title, $matches);
        $matches = $matches[1];

        // Parse everything so we can reference it easily.
        $tags = [];
        foreach ($matches as $match) {
          $tag_parts = explode(':', $match);
          $tag_key = reset($tag_parts);
          array_shift($tag_parts);
          $tag_value = implode(':', $tag_parts);

          if (isset($tags[$tag_key])) {
            $tags[$tag_key] = [
              $tags[$tag_key],
            ];
          }
          if (isset($tags[$tag_key]) && is_array($tags[$tag_key])) {
            $tags[$tag_key][] = $tag_value;
          }
          else {
            $tags[$tag_key] = $tag_value;
          }
        }

        // Source is mandatory, so present.
        $sources = [
          (int) $tags['source'],
        ];
        unset($tags['source']);

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
                $html = $this->downloadHtmlPage($page_url);
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
                      $html = $this->downloadHtmlPage($page_url);
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

            }
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
                break;
            }
          }
        }
      }

      if (empty($sources)) {
        switch ($origin_title) {
          case '[decom] ECHO - Flash':
          case 'ECHO - Flash':
            // Skip it.
            break;

          case 'IOM DTM - Displacement Reports':
            $sources = [1255];

            $pdf = $this->extractPdfUrl($document['summary']['content'] ?? '', 'iframe', 'src');
            $pdf = str_replace('?iframe=true', '', $pdf);

            break;

          case 'UNHCR Global Focus - All Publications':
            $sources = [2868];

            $pdf = $document['canonical'][0]['href'] ?? '';

            break;

          case 'UNHCR - Global All docs':
          case '[decom] UNHCR - Global All docs':
            $sources = [2868];

            $pdf = $document['canonical'][0]['href'] ?? '';
            $pdf = str_replace('/details/', '/download/', $pdf);

            break;

          case 'IFRC - Appeals':
            $sources = [1242];

            $pdf = $document['canonical'][0]['href'] ?? '';

            break;

          case 'Global Protection Cluster - Publications':
            $sources = [8619];

            // Clear body.
            $body = '';

            if (!empty($document['canonical'][0]['href'] ?? '')) {
              $html = $this->downloadHtmlPage($document['canonical'][0]['href'] ?? '');
              if (empty($html)) {
                $this->getLogger()->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                  '@id' => $id,
                  '@url' => $document['canonical'][0]['href'] ?? '',
                ]));
              }
              else {
                $pdf = $this->extractPdfUrl($html, 'a', 'href', 'btn-primary', 'pdf');
                if (!empty($pdf) && strpos($pdf, 'http') !== 0) {
                  $pdf = 'https://globalprotectioncluster.org' . $pdf;
                }
              }
            }

            break;

          case 'Global CCCM Cluster - Documents':
            $sources = [9677];

            // Clear body.
            $body = '';

            if (!empty($document['canonical'][0]['href'] ?? '')) {
              $html = $this->downloadHtmlPage($document['canonical'][0]['href'] ?? '');
              if (empty($html)) {
                $this->getLogger()->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                  '@id' => $id,
                  '@url' => $document['canonical'][0]['href'] ?? '',
                ]));
              }
              else {
                $pdf = $this->extractPdfUrl($html, 'a', 'href', '', 'pdf');
                if (!empty($pdf) && strpos($pdf, 'http') !== 0) {
                  $pdf = 'https://www.cccmcluster.org' . $pdf;
                }
              }
            }

            break;

          case 'ECHO - Emergency Maps':
            $sources = [620];

            if (!empty($document['canonical'][0]['href'] ?? '')) {
              $html = $this->downloadHtmlPage($document['canonical'][0]['href'] ?? '');
              if (empty($html)) {
                $this->getLogger()->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                  '@id' => $id,
                  '@url' => $document['canonical'][0]['href'] ?? '',
                ]));
              }
              else {
                $pdf = $this->extractPdfUrl($html, 'a', 'href', 'zoom-in', 'pdf');
                if (!empty($pdf) && strpos($pdf, 'http') !== 0) {
                  $pdf = 'https://erccportal.jrc.ec.europa.eu' . $pdf;
                }
              }
            }

            break;

          case 'ACLED - Regional Overview and Analysis':
            // Skipping, no PDF file.
            break;

          case 'Emergency Telecommunications Cluster (ETC) - Operational Updates':
            $sources = [13799];

            if (!empty($document['canonical'][0]['href'] ?? '')) {
              $html = $this->downloadHtmlPage($document['canonical'][0]['href'] ?? '');
              if (empty($html)) {
                $this->getLogger()->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                  '@id' => $id,
                  '@url' => $document['canonical'][0]['href'] ?? '',
                ]));
              }
              else {
                $pdf = $this->extractPdfUrl($html, 'a', 'href', 'zoom-in', 'pdf');
              }
            }

            break;

          case 'WFP - Publications':
            $sources = [1741];

            if (!empty($document['canonical'][0]['href'] ?? '')) {
              $html = $this->downloadHtmlPage($document['canonical'][0]['href'] ?? '');
              if (empty($html)) {
                $this->getLogger()->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                  '@id' => $id,
                  '@url' => $document['canonical'][0]['href'] ?? '',
                ]));
              }
              else {
                $pdf = $this->extractPdfUrl($html, 'a', 'href', 'button-new--primary', '', 'section.document-links-table');
              }
            }

            break;

          default:
            $this->getLogger()->error(strtr('Unknown source for Inoreader document @id: @source.', [
              '@id' => $id,
              '@source' => $origin_title,
            ]));
            break;
        }
      }

      if (empty($sources)) {
        continue;
      }

      if (!empty($pdf)) {
        $info = $this->getRemoteFileInfo($pdf);
        if (!empty($info)) {
          $file_url = $pdf;
          $file_uuid = $this->generateUuid($file_url, $uuid);
          $files[] = [
            'url' => $file_url,
            'uuid' => $file_uuid,
          ] + $info;
        }
      }

      // Submission data.
      $data = [
        'provider' => $provider_uuid,
        'bundle' => 'report',
        'hash' => $hash,
        'url' => $url,
        'uuid' => $uuid,
        'title' => $title,
        'body' => $body,
        'published' => $published,
        'origin' => $url,
        'source' => $sources,
        'language' => [267],
        'country' => [254],
        'format' => [8],
        'user' => 2,
      ];

      // Add the optional fields.
      $data += array_filter([
        'file' => array_values($files),
      ]);

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
  public function alterContentClassificationSkipClassification(bool &$skip, ClassificationWorkflowInterface $workflow, array $context): void {
    // Allow the automated classification.
    $skip = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationUserPermissionCheck(bool &$check, AccountInterface $account, array $context): void {
    // Bypass the user permission check for the classification since the user
    // associated with the provider may not be authorized to use the automated
    // classification.
    $check = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationSpecifiedFieldCheck(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void {
    // Mark all the field as optional so that the classification is not skipped
    // if any of the field is already filled.
    $fields = array_map(fn($field) => FALSE, $fields);
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationForceFieldUpdate(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void {
    // Force the update of the fields with the data from the classifier even
    // if they already had a value.
    $fields = array_map(fn($field) => TRUE, $fields);
    // Keep the original title and source.
    $fields['title__value'] = FALSE;
    $fields['field_source'] = FALSE;
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
    $dom = new \DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new \DOMXPath($dom);

    $elements = [];
    if (empty($wrapper)) {
      $elements = $xpath->query("//{$tag}[@{$attribute}]");
    }
    else {
      [$wrapper_element, $wrapper_class] = explode('.', $wrapper);
      $parent = $xpath->query("//{$wrapper_element}[@class='{$wrapper_class}']")->item(0);
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
  protected function downloadHtmlPage($url) {
    $timeout = $this->getPluginSetting('timeout', 10, FALSE);
    try {
      $response = $this->httpClient->get($url, [
        'connect_timeout' => $timeout,
        'timeout' => $timeout,
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
        ],
      ]);

      if ($response->getStatusCode() !== 200) {
        throw new \Exception('Failure with response code: ' . $response->getStatusCode());
      }
      return $response->getBody()->getContents();
    }
    catch (\Exception $exception) {
      return '';
    }

    return '';
  }

}
