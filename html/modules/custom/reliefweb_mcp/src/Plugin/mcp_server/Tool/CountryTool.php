<?php

declare(strict_types=1);

namespace Drupal\reliefweb_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Get a list of ReliefWeb countries based on name or ISO3 code.
 */
#[Tool(
  id: 'reliefweb_countries',
  label: new TranslatableMarkup('ReliefWeb Countries'),
  description: new TranslatableMarkup('Provides information about ReliefWeb countries.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'name' => [
        'type' => 'string',
        'description' => 'The name of the country or part of the name.',
      ],
      'iso3' => [
        'type' => 'string',
        'description' => 'The ISO3 code of the country.',
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
        'description' => 'The country name, with optional ISO3 code applied.',
      ],
      'data' => [
        'type' => 'array',
        'description' => 'A list of countries.',
        'items' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'string',
              'description' => 'The country ID.',
            ],
            'iso3' => [
              'type' => 'string',
              'description' => 'The ISO3 code of the country.',
            ],
            'name' => [
              'type' => 'string',
              'description' => 'The name of the country.',
            ],
            'short_name' => [
              'type' => 'string',
              'description' => 'The short name of the country.',
            ],
            'long_name' => [
              'type' => 'string',
              'description' => 'The long name of the country.',
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
final class CountryTool extends BaseTool {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): array {
    $offset = max(0, $arguments['offset'] ?? 0);
    $limit = min(1000, $arguments['limit'] ?? 10);

    $name = $arguments['name'] ?? '';
    $iso3 = $arguments['iso3'] ?? '';

    // Sanitize input values.
    $name = trim($name);
    $iso3 = $this->sanitizeInput($iso3);

    $vocabulary_id = 'country';
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query
      ->accessCheck(TRUE)
      ->condition('vid', $vocabulary_id);

    // Do a partial match on name, shortname and longname when searching.
    if ($name !== '') {
      $names = $query->orConditionGroup()
        ->condition('name', $name, 'CONTAINS')
        ->condition('field_shortname', $name, 'CONTAINS')
        ->condition('field_longname', $name, 'CONTAINS');
      $query->condition($names);
    }

    if ($iso3 !== '') {
      $query->condition('field_iso3', $iso3);
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
          'iso3' => $term->get('field_iso3')->value ?? '',
          'short_name' => $term->get('field_shortname')->value ?? '',
          'long_name' => $term->get('field_longname')->value ?? '',
        ];
      }
    }

    return [
      'success' => TRUE,
      'message' => match(count($data)) {
        0 => 'No countries found.',
        1 => 'One country found.',
        default => count($data) . ' countries found.',
      },
      'data' => $data,
    ];
  }

}
