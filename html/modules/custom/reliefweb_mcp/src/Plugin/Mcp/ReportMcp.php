<?php

namespace Drupal\reliefweb_mcp\Plugin\Mcp;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Resource;
use Drupal\mcp\ServerFeatures\ResourceTemplate;
use Drupal\mcp\ServerFeatures\Tool;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation for MCP that demonstrates basic functionality.
 */
#[Mcp(
  id: 'reliefweb-mcp-report',
  name: new TranslatableMarkup('Report MCP'),
  description: new TranslatableMarkup('Provides custom MCP functionality.'),
)]
class ReportMcp extends McpPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger for the Inoreader service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritDoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    $instance = parent::create(
      $container, $configuration, $plugin_id, $plugin_definition
    );

    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->logger = $container->get('logger.factory')->get('reliefweb_mcp');
    $instance->configFactory = $container->get('config.factory');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'enabled' => TRUE,
      'config'  => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getTools(): array {
    $tools = [];

    // Force probe tool.
    $tools[] = new Tool(
      name: 'find-report-iso3',
      description: 'Find reports given an ISO3 code.',
      inputSchema: [
        'type'       => 'object',
        'properties' => [
          'iso3'   => [
            'title'       => 'ISO3 Code',
            'type'        => 'string',
            'description' => 'ISO3 country code.',
          ],
          'limit' => [
            'title'       => 'Limit',
            'type'        => 'integer',
            'description' => 'Number of reports to return.',
            'default'     => 5,
          ],
        ],
        'required'   => ['iso3'],
      ],
    );

    return $tools;
  }

  /**
   * {@inheritdoc}
   */
  public function executeTool(string $toolId, mixed $arguments): array {
    switch ($toolId) {
      case md5('find-report-iso3'):
        return $this->executeFindReportIso3($arguments);
    }

    throw new \InvalidArgumentException(
      'Unknown tool.'
    );
  }

  /**
   * Executes the find-report-iso3 tool.
   *
   * @param array $arguments
   *   The arguments for the tool.
   *
   * @return array
   *   The result of the tool execution.
   */
  protected function executeFindReportIso3(array $arguments): array {
    $iso3 = $arguments['iso3'] ?? NULL;
    $limit = $arguments['limit'] ?? 5;

    if (!$iso3) {
      throw new \InvalidArgumentException('ISO3 code is required.');
    }

    if (!is_string($iso3) || strlen($iso3) !== 3) {
      throw new \InvalidArgumentException('ISO3 code must be a 3-letter string.');
    }

    if (!is_int($limit) || $limit <= 0) {
      throw new \InvalidArgumentException('Limit must be a positive integer.');
    }

    $country = $this->getTermsByIso3($iso3);

    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_primary_country', $country->id())
      ->condition('type', 'report')
      ->condition('status', 1)
      ->range(0, $limit)
      ->sort('created', 'DESC');
    $nids = $query->execute();

    if (empty($nids)) {
      return [];
    }

    $reports = $this->entityTypeManager
      ->getStorage('node')
      ->loadMultiple($nids);

    $result = [];
    foreach ($reports as $report) {
      $result[] = [
        'type' => 'text',
        'text' => "Found report: {$report->label()} at {$report->toUrl()->setAbsolute()->toString()}",
      ];
    }

    return $result;
  }

  /**
   * Get taxonomy terms using the iso3 code.
   */
  protected function getTermsByIso3(string $iso3): Term {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties([
        'field_iso3' => $iso3,
        'vid' => 'country',
        'status' => 1,
      ]);

    if (empty($terms)) {
      throw new \InvalidArgumentException("No terms found for ISO3 code: $iso3");
    }

    return reset($terms);
  }

  /**
   * {@inheritdoc}
   */
  public function getResources(): array {
    $resources = [];

    $resources[] = new Resource(
      uri: "report",
      name: 'Report',
      description: 'Reliefweb report',
      mimeType: 'application/json',
      text: NULL,
    );

    return $resources;
  }

  /**
   * {@inheritdoc}
   */
  public function getResourceTemplates(): array {
    $resourceTemplates = [];

    $resourceTemplates[] = new ResourceTemplate(
      uriTemplate: "report/{id}",
      name: 'Report',
      description: 'Reliefweb report',
      mimeType: 'application/json',
    );

    return $resourceTemplates;
  }

  /**
   * {@inheritdoc}
   */
  public function readResource(string $resourceId): array {
    $parts = explode('/', $resourceId);
    $this->logger->debug('Reading resource: @resourceId', ['@resourceId' => $resourceId]);

    if (count($parts) === 2) {
      return $this->readNodeContent($parts[1]);
    }

    throw new \InvalidArgumentException("Unknown resource Id: $resourceId");
  }

  /**
   * Read and return node content.
   */
  private function readNodeContent(string $node_id): array {
    $nodes = $this->entityTypeManager->getStorage('node')
      ->loadByProperties([
        'nid'  => $node_id,
      ]);
    $node = reset($nodes);

    if (!$node instanceof NodeInterface) {
      throw new \InvalidArgumentException("Node not found: $node_id");
    }

    $nodeData = [
      'id' => $node->id(),
      'title' => $node->getTitle(),
      'type' => $node->bundle(),
      'created' => $node->getCreatedTime(),
      'changed' => $node->getChangedTime(),
      'status' => $node->isPublished() ? 'published' : 'unpublished',
      'url' => $node->toUrl()->toString(),
      'body' => [
        'value' => $node->get('body')->value,
        'format' => $node->get('body')->format,
      ],
    ];

    return [
      new Resource(
        uri: 'report/' . $node_id,
        name: $node->getTitle(),
        description: NULL,
        mimeType: 'application/json',
        text: json_encode(
          $nodeData, JSON_UNESCAPED_UNICODE
        ),
      ),
    ];
  }

}
