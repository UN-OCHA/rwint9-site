<?php

declare(strict_types=1);

namespace Drupal\reliefweb_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Get a list of ReliefWeb reports.
 */
#[Tool(
  id: 'reliefweb_reports',
  label: new TranslatableMarkup('ReliefWeb Reports'),
  description: new TranslatableMarkup('Provides information about ReliefWeb reports.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'search' => [
        'type' => 'string',
        'description' => 'The search term for the report.',
      ],
      'primary_country' => [
        'type' => 'string',
        'description' => 'The ISO3 code of the primary country.',
      ],
      'country' => [
        'type' => 'string',
        'description' => 'The ISO3 code of the country associated with the report.',
      ],
      'organization' => [
        'type' => 'string',
        'description' => 'The organization id associated with the report. Use the reliefweb_organizations tool to find the id.',
      ],
      'organization_type' => [
        'type' => 'string',
        'description' => 'The type of the organization associated with the report (skill://reliefweb_organization-type).',
      ],
      'disaster' => [
        'type' => 'string',
        'description' => 'The disaster associated with the report.',
      ],
      'disaster_type' => [
        'type' => 'string',
        'description' => 'The type of the disaster associated with the report (skill://reliefweb_disaster-type).',
      ],
      'theme' => [
        'type' => 'string',
        'description' => 'The theme associated with the report (skill://reliefweb_theme).',
      ],
      'content_format' => [
        'type' => 'string',
        'description' => 'The content format of the report (skill://reliefweb_content-format).',
      ],
      'language' => [
        'type' => 'string',
        'description' => 'The language of the report: en, fr, es, ar, zh.',
      ],
      'publication_date' => [
        'type' => 'string',
        'description' => 'The publication date of the report, provide start and end dates in the format YYYY-MM-DD. Example 2023-01-01 to 2023-12-31.',
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
        'description' => 'A list of reports.',
        'items' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'string',
              'description' => 'The report ID.',
            ],
            'name' => [
              'type' => 'string',
              'description' => 'The name of the report.',
            ],
            'primary_country' => [
              'type' => 'string',
              'description' => 'The primary country iso3 code of the report.',
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
final class ReportTool extends BaseTool {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): array {
    $offset = max(0, $arguments['offset'] ?? 0);
    $limit = min(1000, $arguments['limit'] ?? 10);

    $name = $arguments['name'] ?? '';
    $primary_country = $arguments['primary_country'] ?? '';
    $country = $arguments['country'] ?? '';
    $organization = $arguments['organization'] ?? '';
    $organization_type = $arguments['organization_type'] ?? '';
    $disaster = $arguments['disaster'] ?? '';
    $disaster_type = $arguments['disaster_type'] ?? '';
    $theme = $arguments['theme'] ?? '';
    $content_format = $arguments['content_format'] ?? '';
    $language = $arguments['language'] ?? '';
    $publication_date = $arguments['publication_date'] ?? '';

    // Sanitize input values.
    $name = trim($name);
    $primary_country = $this->sanitizeInput($primary_country);
    $country = $this->sanitizeInput($country);
    $organization = $this->sanitizeInput($organization);
    $organization_type = $this->sanitizeInput($organization_type);
    $disaster = $this->sanitizeInput($disaster);
    $disaster_type = $this->sanitizeInput($disaster_type);
    $theme = $this->sanitizeInput($theme);
    $content_format = $this->sanitizeInput($content_format);
    $language = $this->sanitizeInput($language);
    $publication_date = $this->sanitizeInput($publication_date);

    $content_type = 'report';
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query
      ->accessCheck(TRUE)
      ->condition('type', $content_type);

    // Do a partial match on name, shortname and longname when searching.
    if ($name !== '') {
      $names = $query->orConditionGroup()
        ->condition('title', $name, 'CONTAINS');
      $query->condition($names);
    }

    if ($primary_country !== '') {
      $country_term = $this->getTerm($primary_country, 'country', 'field_iso3');
      if (!$country_term) {
        return [
          'success' => FALSE,
          'message' => 'Invalid primary country.',
          'data' => [],
        ];
      }
      $query->condition('field_primary_country', $country_term->id());
    }

    if ($country !== '') {
      $country_term = $this->getTerm($country, 'country', 'field_iso3');
      if (!$country_term) {
        return [
          'success' => FALSE,
          'message' => 'Invalid country.',
          'data' => [],
        ];
      }
      $query->condition('field_country', $country_term->id());
    }

    if ($organization !== '') {
      $organization_term = $this->getTerm($organization, 'source');
      if (!$organization_term) {
        return [
          'success' => FALSE,
          'message' => 'Invalid organization.',
          'data' => [],
        ];
      }
      $query->condition('field_organization', $organization_term->id());
    }

    if ($organization_type !== '') {
      $organization_type_term = $this->getTerm($organization_type, 'organization_type');
      if (!$organization_type_term) {
        return [
          'success' => FALSE,
          'message' => 'Invalid organization type.',
          'data' => [],
        ];
      }
      $query->condition('field_organization_type', $organization_type_term->id());
    }

    if ($disaster !== '') {
      $disaster_term = $this->getTerm($disaster, 'disaster');
      if (!$disaster_term) {
        return [
          'success' => FALSE,
          'message' => 'Invalid disaster.',
          'data' => [],
        ];
      }
      $query->condition('field_disaster', $disaster_term->id());
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
      $query->condition('field_disaster_type', $disaster_type_term->id());
    }

    if ($theme !== '') {
      $theme_term = $this->getTerm($theme, 'theme');
      if (!$theme_term) {
        return [
          'success' => FALSE,
          'message' => 'Invalid theme.',
          'data' => [],
        ];
      }
      $query->condition('field_theme', $theme_term->id());
    }

    if ($content_format !== '') {
      $content_format_term = $this->getTerm($content_format, 'content_format');
      if (!$content_format_term) {
        return [
          'success' => FALSE,
          'message' => 'Invalid content format.',
          'data' => [],
        ];
      }
      $query->condition('field_content_format', $content_format_term->id());
    }

    if ($language !== '') {
      $language_term = $this->getTerm($language, 'language');
      if (!$language_term) {
        return [
          'success' => FALSE,
          'message' => 'Invalid language.',
          'data' => [],
        ];
      }
      $query->condition('field_language', $language_term->id());
    }

    if ($publication_date !== '') {
      // Check if the publication date contains a range (start and end dates).
      if (strpos($publication_date, ' to ') !== FALSE) {
        [$start_date, $end_date] = explode(' to ', $publication_date);
        $query->condition('field_original_publication_date', $start_date, '>=');
        $query->condition('field_original_publication_date', $end_date, '<=');
      }
      else {
        $query->condition('field_original_publication_date', $publication_date);
      }
    }

    $query->range($offset, $limit);
    $query->sort('created', 'DESC');

    $result = $query->execute();
    $data = [];

    if (!empty($result)) {
      $reports = $this->entityTypeManager->getStorage('node')->loadMultiple($result);
      /** @var \Drupal\node\NodeInterface $report */
      foreach ($reports as $report) {
        if (!$report->access('view', $this->currentUser)) {
          continue;
        }

        $data[] = [
          'id' => (string) $report->id(),
          'name' => $report->label(),
          'created' => date('c', (int) $report->get('created')->value ?? 0),
          'body' => $report->get('body')->value ?? '',
          'url' => $report->toUrl('canonical', ['absolute' => TRUE])->toString(),
          'primary_country' => $report->get('field_primary_country')->entity?->get('field_iso3')->value ?? '',
        ];
      }
    }

    return [
      'success' => TRUE,
      'message' => match(count($data)) {
        0 => 'No reports found.',
        1 => 'One report found.',
        default => count($data) . ' reports found.',
      },
      'data' => $data,
    ];
  }

}
