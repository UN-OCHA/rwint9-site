<?php

declare(strict_types=1);

namespace Drupal\reliefweb_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
final class CountryTool extends ToolPluginBase {

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
    $name = $arguments['name'] ?? '';
    $iso3 = $arguments['iso3'] ?? '';

    // Sanitize input values.
    $name = $this->sanitizeInput($name);
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

  /**
   * Make sure input only contains letters and numbers.
   */
  protected function sanitizeInput(string $input): string {
    $input = trim($input);
    return preg_replace('/[^a-zA-Z0-9\(\)]/', '', $input);
  }

}
