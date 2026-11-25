<?php

declare(strict_types=1);

namespace Drupal\reliefweb_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Get a list of ReliefWeb disasters.
 */
#[Tool(
  id: 'reliefweb_disasters',
  label: new TranslatableMarkup('ReliefWeb Disasters'),
  description: new TranslatableMarkup('Provides information about ReliefWeb disasters.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'name' => [
        'type' => 'string',
        'description' => 'The name of the disaster or part of the name.',
      ],
      'country' => [
        'type' => 'string',
        'description' => 'The country id or iso3 code, use the reliefweb_countries tool to find the id.',
      ],
      'disaster_type' => [
        'type' => 'string',
        'description' => 'The type of the disaster (skill://reliefweb_disaster-type).',
      ],
      'glide_number' => [
        'type' => 'string',
        'description' => 'The GLIDE number of the disaster.',
      ],
      'disaster_date' => [
        'type' => 'string',
        'description' => 'The date of the disaster, provide start and end dates in the format YYYY-MM-DD. Example 2023-01-01 to 2023-12-31.',
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
        'description' => 'A short description of the result.',
      ],
      'data' => [
        'type' => 'array',
        'description' => 'A list of disasters.',
        'items' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'string',
              'description' => 'The disaster ID.',
            ],
            'name' => [
              'type' => 'string',
              'description' => 'The name of the disaster.',
            ],
            'glide_number' => [
              'type' => 'string',
              'description' => 'The GLIDE number of the disaster.',
            ],
            'disaster_date' => [
              'type' => 'string',
              'description' => 'The date of the disaster.',
            ],
            'primary_country' => [
              'type' => 'string',
              'description' => 'The primary country of the disaster.',
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
final class DisasterTool extends BaseTool {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): array {
    $offset = max(0, $arguments['offset'] ?? 0);
    $limit = min(1000, $arguments['limit'] ?? 10);

    $name = $arguments['name'] ?? '';
    $country_id = $arguments['country'] ?? '';
    $glide_number = $arguments['glide_number'] ?? '';
    $disaster_type = $arguments['disaster_type'] ?? '';
    $disaster_date = $arguments['disaster_date'] ?? '';

    // Sanitize input values.
    $name = trim($name);
    $country_id = $this->sanitizeInput($country_id);
    $glide_number = $this->sanitizeInput($glide_number);
    $disaster_type = $this->sanitizeInput($disaster_type);
    $disaster_date = $this->sanitizeInput($disaster_date);

    $vocabulary_id = 'disaster';
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

    if ($country_id !== '') {
      if (is_numeric($country_id)) {
        $query->condition('field_country', $country_id);
      }
      else {
        $country_id = $this->getTerm($country_id, 'country', 'field_iso3');
        if ($country_id) {
          $country_id = $country_id->id();
          $query->condition('field_country', $country_id);
        }
        else {
          return [
            'success' => FALSE,
            'message' => 'Invalid country id or iso3 code.',
            'data' => [],
          ];
        }
      }
    }

    if ($glide_number !== '') {
      $query->condition('field_glide', $glide_number);
    }

    if ($disaster_type !== '') {
      $disaster_type_term = $this->getTerm($disaster_type, 'disaster_type');
      if (!$disaster_type_term) {
        return [
          'success' => FALSE,
          'message' => 'Invalid disaster type.',
          'data' => [],
        ];
      }
      $disaster_type = $disaster_type_term->id();
      $query->condition('field_disaster_type', $disaster_type);
    }

    if ($disaster_date !== '') {
      // Check if the disaster date contains a range (start and end dates).
      if (strpos($disaster_date, ' to ') !== FALSE) {
        [$start_date, $end_date] = explode(' to ', $disaster_date);
        $query->condition('field_disaster_date', $start_date, '>=');
        $query->condition('field_disaster_date', $end_date, '<=');
      }
      else {
        $query->condition('field_disaster_date', $disaster_date);
      }
    }

    $query->range($offset, $limit);
    $query->sort('field_disaster_date', 'DESC');

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
          'glide_number' => $term->get('field_glide')->value ?? '',
          'disaster_date' => $term->get('field_disaster_date')->value ?? '',
          'primary_country' => $term->get('field_primary_country')->entity?->get('field_iso3')->value ?? '',
        ];
      }
    }

    return [
      'success' => TRUE,
      'message' => match(count($data)) {
        0 => 'No disasters found.',
        1 => 'One disaster found.',
        default => count($data) . ' disasters found.',
      },
      'data' => $data,
    ];
  }

}
