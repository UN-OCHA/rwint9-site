<?php

namespace Drupal\reliefweb_semantic\Commands;

use Aws\S3\S3Client;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\State\StateInterface;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\ResponseInterface;
use Spipu\Html2Pdf\Html2Pdf;

/**
 * ReliefWeb API Drush commandfile.
 */
class ReliefWebSemanticCommands extends DrushCommands {

  /**
   * ReliefWeb API config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Entity Field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Entity Type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The HTTP client to fetch the files with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * File system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The render service.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $renderer;

  /**
   * List of config for each bundle.
   *
   * @var array
   */
  protected $bundles = [
    'report' => [
      'type' => 'node',
      'index' => 'rw-reports',
      'bucket' => 'rw-kb-reports',
      'field-list' => [
        'nid' => 'id',
        'uuid' => 'uuid',
        'created' => 'created',
        'changed' => 'changed',
        'title' => 'title',
        'status' => 'status',
        'body' => 'body',
        'field_file' => 'files',
        'field_country' => 'country',
        'field_disaster' => 'disaster',
        'field_disaster_type' => 'disaster_type',
        'field_feature' => 'feature',
        'field_primary_country' => 'primary_country',
        'field_source' => 'source',
        'field_theme' => 'theme',
      ],
    ],
    'job' => [
      'type' => 'node',
      'index' => 'rw-jobs',
      'bucket' => 'rw-kb-jobs',
      'field-list' => [
        'nid' => 'id',
        'uuid' => 'uuid',
        'created' => 'created',
        'changed' => 'changed',
        'title' => 'title',
        'status' => 'status',
        'body' => 'body',
        'field_career_categories' => 'career_categories',
        'field_city' => 'city',
        'field_job_closing_date' => 'job_closing_date',
        'field_country' => 'country',
        'field_city' => 'city',
        'field_how_to_apply' => 'how_to_apply',
        'field_job_type' => 'job_type',
        'field_job_experience' => 'job_experience',
        'field_source' => 'source',
        'field_theme' => 'theme',
      ],
    ],
    'training' => [
      'type' => 'node',
      'index' => 'rw-trainings-2',
      'bucket' => 'rw-kb-trainings',
      'field-list' => [
        'nid' => 'id',
        'uuid' => 'uuid',
        'created' => 'created',
        'changed' => 'changed',
        'title' => 'title',
        'status' => 'status',
        'body' => 'body',
        'field_country' => 'country',
        'field_city' => 'city',
        'field_source' => 'source',
        'field_theme' => 'theme',
      ],
    ],
    'blog_post' => [
      'type' => 'node',
      'index' => 'rw-blog-posts-2',
      'bucket' => 'rw-kb-blog-posts',
      'field-list' => [
        'nid' => 'id',
        'uuid' => 'uuid',
        'created' => 'created',
        'changed' => 'changed',
        'title' => 'title',
        'status' => 'status',
        'body' => 'body',
      ],
    ],
    'topic' => [
      'type' => 'node',
      'index' => 'rw-topics',
      'bucket' => 'rw-kb-topics',
      'field-list' => [
        'nid' => 'id',
        'uuid' => 'uuid',
        'created' => 'created',
        'changed' => 'changed',
        'title' => 'title',
        'status' => 'status',
        'body' => 'body',
      ],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    StateInterface $state,
    ClientInterface $http_client,
    FileSystemInterface $file_system,
    RendererInterface $renderer,
  ) {
    $this->config = $config_factory->get('reliefweb_semantic.settings');
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->state = $state;
    $this->httpClient = $http_client;
    $this->fileSystem = $file_system;
    $this->renderer = $renderer;
  }

  /**
   * Index content in the ReliefWeb API.
   *
   * @param string $bundle
   *   Entity bundle to index.
   * @param array $options
   *   Additional options for the command.
   *
   * @command reliefweb-semantic:index
   *
   * @option limit Maximum number of entities to index, defaults to 0 (all).
   * @option offset ID of the entity from which to start the indexing, defaults
   *   to the most recent one.
   *
   * @default $options []
   *
   * @usage reliefweb-semantic:index --id=123 report
   *   Index the report with ID 123.
   * @usagereliefweb-semantic:index --limit=10 report
   *   Index latest 10 reports.
   */
  public function index(
    $bundle = '',
    array $options = [
      'limit' => 10,
      'id' => 0,
    ],
  ) {
    // Index all the resources.
    if ($bundle === 'all') {
      foreach ($this->bundles as $bundle => $info) {
        $this->index($bundle, $options);
      }
      return;
    }

    if (!empty($options['id'])) {
      $this->processItem($bundle, $options['id']);
      return;
    }

    // Index the given bundles.
    if (strpos($bundle, ',') > 0) {
      $bundles = explode(',', $bundle);
      foreach ($bundles as $bundle) {
        if (isset($this->bundles[$bundle])) {
          $this->index($bundle, $options);
        }
      }
      return;
    }

    // Index indexing options.
    $limit = (int) ($options['limit'] ?: 10);

    // Launch the indexing or index removal.
    try {
      $num_items = $this->indexItems($bundle, $limit);
      if ($num_items == 0) {
        $this->logger->notice(strtr('Nothing left to index for @bundle', [
          '@bundle' => $bundle,
        ]));
      }
      else {
        $this->logger->notice(strtr('Indexed @num_items items for @bundle', [
          '@num_items' => $num_items,
          '@bundle' => $bundle,
        ]));
      }
    }
    catch (\Exception $exception) {
      if ($exception->getMessage() !== 'No entity to index.') {
        $this->logger->error('(' . $exception->getCode() . ') ' . $exception->getMessage());
      }
      else {
        $this->logger->notice($exception->getMessage());
      }
    }
  }

  /**
   * Index items.
   */
  protected function indexItems($bundle, $limit = 10) : int {
    $entity_type = $this->bundles[$bundle]['type'] ?? '';
    if (empty($entity_type)) {
      $this->logger->notice(strtr('Unknown entity type for @bundle', [
        '@bundle' => $bundle,
      ]));
    }

    $key = 'nid';
    if ($entity_type == 'taxonomy_term') {
      $key = 'tid';
    }

    $query = $this->entityTypeManager
      ->getStorage($entity_type)
      ->getQuery()
      ->accessCheck(FALSE)
      ->range(0, $limit)
      ->sort($key, 'DESC')
      ->condition($key, $this->state->get('reliefweb_semantic_last_indexed_' . $bundle, 0), '>');

    if ($entity_type == 'node') {
      $query->condition('type', $bundle);
    }
    elseif ($entity_type == 'taxonomy_term') {
      $query->condition('vid', $bundle);
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return 0;
    }

    $entities = $this->entityTypeManager->getStorage($entity_type)->loadMultiple($ids);
    $count = 0;
    foreach ($entities as $id => $entity) {
      $data = $this->prepareItem($entity);
      if (empty($data)) {
        continue;
      }

      try {
        $this->indexItem($bundle, $data);

        $this->state->set('reliefweb_semantic_last_indexed_' . $bundle, $id);
        $count++;
      }
      catch (\Throwable $th) {
        $this->logger->notice(strtr('Unable to index @id for @bundle', [
          '@id' => $data['id'],
          '@bundle' => $bundle,
        ]));
      }
    }

    return $count;
  }

  /**
   * Process an item.
   */
  protected function processItem(string $bundle, string $id) {
    $entity_type = $this->bundles[$bundle]['type'] ?? '';
    if (empty($entity_type)) {
      $this->logger->notice('Unknown entity type for @bundle', [
        '@bundle' => $bundle,
      ]);
    }

    $entity = $this->entityTypeManager->getStorage($entity_type)->load($id);
    if (empty($entity)) {
      $this->logger->notice(strtr('Unable to load @id for @bundle', [
        '@id' => $id,
        '@bundle' => $bundle,
      ]));

      return;
    }

    if ($entity->bundle() != $bundle) {
      $this->logger->notice(strtr('Bundle @a found for @id instead of @b', [
        '@a' => $entity->bundle(),
        '@id' => $id,
        '@b' => $bundle,
      ]));

      return;
    }

    $data = $this->prepareItem($entity);
    if (empty($data)) {
      return FALSE;
    }

    try {
      $this->indexItem($bundle, $data);
      return TRUE;
    }
    catch (\Throwable $th) {
      $this->logger->notice(strtr('Unable to index @id for @bundle', [
        '@id' => $data['id'],
        '@bundle' => $bundle,
      ]));
    }

    return FALSE;
  }

  /**
   * Prepare data for the index.
   */
  protected function prepareItem(ContentEntityInterface $entity) : array {
    $this->logger->notice(strtr('Preparing @bundle: @title (@id)', [
      '@bundle' => $entity->bundle(),
      '@title' => $entity->label(),
      '@id' => $entity->id(),
    ]));

    $data = [];
    $field_list = $this->bundles[$entity->bundle()]['field-list'] ?? [];
    if (empty($field_list)) {
      $this->logger->notice(strtr('No field list found for @bundle', [
        '@bundle' => $entity->bundle(),
      ]));

      return [];
    }

    $date = new \DateTime('now', new \DateTimeZone('UTC'));
    $data['timestamp'] = $date->format(\DateTime::ATOM);
    $data['bundle'] = $entity->bundle();
    $data['nid'] = $entity->id();

    /** @var \Drupal\node\NodeViewBuilder */
    $view_builder = $this->entityTypeManager->getViewBuilder('node');

    if (!isset($data['html'])) {
      $data['html'] = '';
    }

    foreach ($field_list as $field_name => $property_name) {
      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field_type = $entity->get($field_name)->getFieldDefinition()->getFieldStorageDefinition()->getType();
      switch ($field_type) {
        case 'text_with_summary':
        case 'text_long':
          $data[$property_name] = $entity->get($field_name)->value;

          $build = $view_builder->viewField($entity->get($field_name), 'full');
          $data['html'] .= $this->renderer->renderPlain($build);
          $data['html'] .= "\n\n";
          break;

        case 'entity_reference':
          $data[$property_name] = [];
          $as_string = [];
          foreach ($entity->get($field_name)->referencedEntities() as $ref) {
            $data[$property_name][] = (string) $ref->id();
            $as_string[] = $ref->label();
          }

          if (!empty($as_string)) {
            $data['html'] .= '<p>' . $property_name . ': ' . implode(', ', $as_string) . '</p>';
          }
          break;

        case 'datetime':
          $date = new \DateTime($entity->get($field_name)->value, new \DateTimeZone('UTC'));
          $item[$property_name] = $date->format(\DateTime::ATOM);
          break;

        case 'reliefweb_file':
          $data['files'] = [];
          /** @var \Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile $item */
          foreach ($entity->get($field_name) as $item) {
            $data['files'][] = $item->loadFile()->getFileUri();
          }
          break;

        default:
          $data[$property_name] = $entity->get($field_name)->value;
      }
    }

    return $data;
  }

  /**
   * Index the index.
   */
  protected function indexItem(string $bundle, array $data, array $files = []) {
    $index = $this->bundles[$bundle]['index'] ?? [];
    if (empty($index)) {
      $this->logger->notice(strtr('No index found for @bundle', [
        '@bundle' => $bundle,
      ]));

      return [];
    }

    $bucket = $this->bundles[$bundle]['bucket'] ?? '';
    if (empty($bucket)) {
      $this->logger->notice('Unknown bucket for @bundle', [
        '@bundle' => $bundle,
      ]);
    }

    if (empty($files) && isset($data['files'])) {
      $files = $data['files'];
      unset($data['files']);
    }

    // Dump title and html field into a PDF.
    // @see https://docs.aws.amazon.com/bedrock/latest/userguide/kb-chunking-parsing.html#kb-advanced-parsing
    if (!empty($data['html'])) {
      $content = '<h1>' . $data['title'] . '</h1>' . "\n\n" . $data['html'];
      $destination = 'temporary://' . $data['id'] . '.pdf';

      $html2pdf = new Html2Pdf();
      $html2pdf->writeHTML($content);
      $content = $html2pdf->output($data['id'] . '.pdf', 'S');

      $this->fileSystem->saveData($content, $destination, FileSystemInterface::EXISTS_REPLACE);
      $files[] = $destination;
    }

    // Dump metadata.
    if (isset($data['html'])) {
      unset($data['html']);
    }
    if (isset($data['body'])) {
      unset($data['body']);
    }

    // Remove empty fields.
    $data = array_filter($data);

    $content = json_encode([
      'metadataAttributes' => $data,
    ]);
    $metadata_file = 'temporary://' . $data['id'] . '.pdf.metadata.json';
    $this->fileSystem->saveData($content, $metadata_file, FileSystemInterface::EXISTS_REPLACE);

    foreach ($files as $file) {
      $absolute_path = $this->fileSystem->realpath($file);
      if (!$absolute_path) {
        $this->logger->notice(strtr('Unable to process @file for @id', [
          '@file' => $file,
          '@id' => $data['id'],
        ]));
        continue;
      }

      $this->sendToS3($bucket, $absolute_path);
      $basename = basename($absolute_path);
      $this->sendToS3($bucket, $metadata_file, $basename . '.metadata.json');
    }
  }

  /**
   * Store file and metadata on S3.
   */
  protected function sendToS3(string $bucket, string $file_name, string $save_as = '') {
    $client_options = reliefweb_semantic_get_aws_client_options();
    $client = new S3Client($client_options);

    if (empty($save_as)) {
      $save_as = basename($file_name);
    }

    $client->putObject([
      'Bucket' => $bucket,
      'Key' => $save_as,
      'SourceFile' => $file_name,
    ]);
  }

  /**
   * Perform a request against the AWS API.
   *
   * @param string $method
   *   Request method.
   * @param string $endpoint
   *   Request endpoint.
   * @param mixed|null $payload
   *   Optional payload (will be converted to JSON if no content type is
   *   provided).
   * @param string|null $content_type
   *   Optional content type of the payload. If not defined it is assumed to be
   *   JSON.
   * @param array $valid_status_codes
   *   List of valid status codes that should not be logged as errors.
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   The response or NULL if the request was not successful.
   */
  protected function request(string $method, string $endpoint, $payload = NULL, ?string $content_type = NULL, array $valid_status_codes = []): ?ResponseInterface {
    $url = rtrim($this->config->get('url'), '/') . '/' . ltrim($endpoint, '/');
    $options = [];

    if (isset($payload)) {
      if (empty($content_type)) {
        $options['json'] = $payload;
      }
      else {
        $options['body'] = $payload;
        $options['headers']['Content-Type'] = $content_type;
      }
    }

    try {
      /** @var \Psr\Http\Message\ResponseInterface $response */
      $response = $this->httpClient->request($method, $url, $options);
      return $response;
    }
    catch (BadResponseException $exception) {
      $response = $exception->getResponse();
      $status_code = $response->getStatusCode();
      if (!in_array($status_code, $valid_status_codes)) {
        $this->logger()->error(strtr('@method request to @endpoint failed with @status error: @error', [
          '@method' => $method,
          '@endpoint' => $endpoint,
          '@status' => $status_code,
          '@error' => $exception->getMessage(),
        ]));
      }
    }
    catch (\Exception $exception) {
      $this->logger()->error(strtr('@method request to @endpoint failed with @status error: @error', [
        '@method' => $method,
        '@endpoint' => $endpoint,
        '@status' => $exception->getCode(),
        '@error' => $exception->getMessage(),
      ]));
    }

    return NULL;
  }

}
