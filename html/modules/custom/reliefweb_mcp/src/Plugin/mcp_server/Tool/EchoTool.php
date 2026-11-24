<?php

declare(strict_types=1);

namespace Drupal\reliefweb_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Mcp\Server\ClientGateway;

/**
 * Example tool that echoes the input message back to the caller.
 */
#[Tool(
  id: 'rw_echo',
  label: new TranslatableMarkup('Echo'),
  description: new TranslatableMarkup('Echoes back the input message, optionally with a prefix.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'message' => [
        'type' => 'string',
        'description' => 'The message to echo back.',
      ],
      'prefix' => [
        'type' => 'string',
        'description' => 'Optional prefix prepended to the echoed message.',
      ],
    ],
    'required' => ['message'],
  ],
  outputSchema: [
    'type' => 'object',
    'properties' => [
      'success' => [
        'type' => 'boolean',
        'description' => 'Indicates whether the echo operation was successful.',
      ],
      'message' => [
        'type' => 'string',
        'description' => 'The echoed message, with optional prefix applied.',
      ],
      'data' => [
        'type' => 'object',
        'description' => 'The data containing the echoed message.',
        'properties' => [
          'echo' => [
            'type' => 'string',
            'description' => 'The echoed message, with optional prefix applied.',
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
final class EchoTool extends ToolPluginBase {

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
  public function execute(array $arguments, ClientGateway $gateway): array {
    $message = $arguments['message'] ?? '';
    $prefix = $arguments['prefix'] ?? '';
    $result = $prefix !== '' ? "$prefix: $message" : $message;

    return [
      'success' => TRUE,
      'message' => $result,
      'data' => ['echo' => $result],
    ];
  }

}
