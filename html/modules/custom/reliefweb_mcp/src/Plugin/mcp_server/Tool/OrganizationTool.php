<?php

declare(strict_types=1);

namespace Drupal\reliefweb_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Get a list of ReliefWeb organizations.
 */
#[Tool(
  id: 'reliefweb_organizations',
  label: new TranslatableMarkup('ReliefWeb Organizations'),
  description: new TranslatableMarkup('Provides information about ReliefWeb organizations.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'name' => [
        'type' => 'string',
        'description' => 'The name of the country or part of the name.',
      ],
      'offset' => [
        'type' => 'integer',
        'description' => 'The number of items to skip before starting to collect the result set.',
      ],
      'limit' => [
        'type' => 'integer',
        'description' => 'The maximum number of items to return.',
      ],
    ],
    'required' => [],
  ],
  outputSchema: [
    'type' => 'object',
    'properties' => [
      'success' => [
        'type' => 'boolean',
        'description' => 'Indicates whether the operation was successful.',
      ],
      'message' => [
        'type' => 'string',
        'description' => 'The number of results found.',
      ],
      'data' => [
        'type' => 'array',
        'description' => 'A list of organizations.',
        'items' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'string',
              'description' => 'The organization ID.',
            ],
            'name' => [
              'type' => 'string',
              'description' => 'The name of the organization.',
            ],
            'short_name' => [
              'type' => 'string',
              'description' => 'The short name of the organization.',
            ],
            'long_name' => [
              'type' => 'string',
              'description' => 'The long name of the organization.',
            ],
            'aliases' => [
              'type' => 'string',
              'description' => 'The aliases of the organization.',
            ],
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
final class OrganizationTool extends BaseTool {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): array {
    $offset = max(0, $arguments['offset'] ?? 0);
    $limit = min(1000, $arguments['limit'] ?? 10);

    $name = $arguments['name'] ?? '';

    // Sanitize input values.
    $name = trim($name);

    $vocabulary_id = 'source';
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query
      ->accessCheck(TRUE)
      ->condition('vid', $vocabulary_id);

    // Do a partial match on name, shortname and longname when searching.
    if ($name !== '') {
      $names = $query->orConditionGroup()
        ->condition('name', $name, 'CONTAINS')
        ->condition('field_shortname', $name, 'CONTAINS')
        ->condition('field_longname', $name, 'CONTAINS')
        ->condition('field_aliases', $name, 'CONTAINS')
        ->condition('field_spanish_name', $name, 'CONTAINS');
      $query->condition($names);
    }

    $query->range($offset, $limit);
    $result = $query->execute();
    $data = [];

    if (!empty($result)) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($result);
      /** @var \Drupal\taxonomy\TermInterface $term */
      foreach ($terms as $term) {
        if (!$term->access('view', $this->currentUser)) {
          continue;
        }

        $data[] = [
          'id' => (string) $term->id(),
          'name' => $term->label(),
          'short_name' => $term->get('field_shortname')->value ?? '',
          'long_name' => $term->get('field_longname')->value ?? '',
          'aliases' => $term->get('field_aliases')->value ?? '',
        ];
      }
    }

    return [
      'success' => TRUE,
      'message' => match(count($data)) {
        0 => 'No organizations found.',
        1 => 'One organization found.',
        default => count($data) . ' organizations found.',
      },
      'data' => $data,
    ];
  }

}
