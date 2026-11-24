<?php

declare(strict_types=1);

namespace Drupal\reliefweb_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Drupal\node\NodeInterface;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Example tool that looks up a node by ID and returns its title and body.
 */
#[Tool(
  id: 'rw_content_lookup',
  label: new TranslatableMarkup('Content Lookup'),
  description: new TranslatableMarkup('Looks up a Drupal node by ID and returns its title and body.'),
  module_dependencies: ['node'],
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'node_id' => [
        'type' => 'integer',
        'description' => 'The ID of the node to look up.',
      ],
    ],
    'required' => ['node_id'],
  ],
  outputSchema: [
    'type' => 'object',
    'properties' => [
      'success' => [
        'type' => 'boolean',
        'description' => 'Indicates whether the content lookup operation was successful.',
      ],
      'message' => [
        'type' => 'string',
        'description' => 'The message describing the result of the content lookup.',
      ],
      'data' => [
        'type' => 'object',
        'description' => 'The data containing the node information.',
        'properties' => [
          'title' => [
            'type' => 'string',
            'description' => 'The title of the node.',
          ],
          'body' => [
            'type' => 'string',
            'description' => 'The body of the node.',
          ],
        ],
      ],
    ],
    'required' => ['success', 'message', 'data'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ContentLookupTool extends ToolPluginBase {

  /**
   * Constructs a ContentLookupTool object.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $currentUser);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Example tools default to enabled so that a freshly installed examples
   * module is immediately usable without extra configuration steps.
   */
  protected function defaultConfiguration(): array {
    return [
      'enabled' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function checkAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'access content');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): array {
    $node = $this->entityTypeManager
      ->getStorage('node')
      ->load($arguments['node_id'] ?? NULL);

    if (!$node instanceof NodeInterface) {
      return [
        'success' => FALSE,
        'message' => (string) $this->t('Node not found.'),
        'data' => [],
      ];
    }

    if (!$node->access('view', $this->currentUser)) {
      return [
        'success' => FALSE,
        'message' => (string) $this->t('Access denied.'),
        'data' => [],
      ];
    }

    $body = $node->hasField('body') ? ($node->get('body')->value ?? '') : '';

    return [
      'success' => TRUE,
      'message' => (string) $this->t('Node found.'),
      'data' => [
        'title' => $node->label(),
        'body' => $body,
      ],
    ];
  }

}
