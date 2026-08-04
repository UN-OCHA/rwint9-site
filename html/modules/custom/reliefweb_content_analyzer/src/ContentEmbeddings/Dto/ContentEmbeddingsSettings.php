<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto;

use Drupal\reliefweb_content_analyzer\Services\EmbeddingsStorageInterface;

/**
 * Typed settings for content embedding generation and storage.
 */
final class ContentEmbeddingsSettings {

  /**
   * Constructs ContentEmbeddingsSettings.
   *
   * @param string $embedEndpoint
   *   AI helper POST /text/deduplicate/embed URL.
   * @param int $dimensions
   *   Embedding vector dimensions.
   * @param float $defaultTimeout
   *   HTTP timeout in seconds.
   * @param array<string, array<string, \Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingSourceSettings>> $sources
   *   Sources keyed by entity type, then bundle.
   */
  public function __construct(
    public readonly string $embedEndpoint,
    public readonly int $dimensions,
    public readonly float $defaultTimeout,
    public readonly array $sources,
  ) {}

  /**
   * Build from the content_embeddings config mapping.
   *
   * Accepts nested sources[entity_type][bundle] and legacy flat keys like
   * "node.report" (migrated into nested form).
   *
   * @param array<string, mixed>|null $config
   *   Config array, or NULL for defaults.
   *
   * @return self
   *   Settings.
   */
  public static function fromConfigArray(?array $config): self {
    $config ??= [];
    $defaults = self::defaultConfig();
    $default_sources = $defaults['sources'];
    $raw_sources = self::normalizeRawSources(
      is_array($config['sources'] ?? NULL) ? $config['sources'] : [],
    );

    $sources = [];
    foreach ($default_sources as $entity_type_id => $bundles) {
      if (!is_array($bundles)) {
        continue;
      }
      foreach ($bundles as $bundle => $default_source) {
        if (!is_string($bundle) || !is_array($default_source)) {
          continue;
        }
        $raw = is_array($raw_sources[$entity_type_id][$bundle] ?? NULL)
          ? $raw_sources[$entity_type_id][$bundle]
          : [];
        $sources[$entity_type_id][$bundle] = EmbeddingSourceSettings::fromConfigArray($raw, $default_source);
      }
    }

    foreach ($raw_sources as $entity_type_id => $bundles) {
      if (!is_string($entity_type_id) || !is_array($bundles)) {
        continue;
      }
      foreach ($bundles as $bundle => $raw) {
        if (!is_string($bundle) || isset($sources[$entity_type_id][$bundle]) || !is_array($raw)) {
          continue;
        }
        $sources[$entity_type_id][$bundle] = EmbeddingSourceSettings::fromConfigArray($raw, [
          'enabled' => FALSE,
          'fields' => ['body'],
          'min_text_length' => 200,
        ]);
      }
    }

    return new self(
      embedEndpoint: trim((string) ($config['embed_endpoint'] ?? $defaults['embed_endpoint'])),
      dimensions: max(1, (int) ($config['dimensions'] ?? $defaults['dimensions'])),
      defaultTimeout: max(1.0, (float) ($config['default_timeout'] ?? $defaults['default_timeout'])),
      sources: $sources,
    );
  }

  /**
   * Default config mapping.
   *
   * @return array<string, mixed>
   *   Defaults.
   */
  public static function defaultConfig(): array {
    return [
      'embed_endpoint' => 'http://ocha-ai-helper/text/deduplicate/embed',
      'dimensions' => EmbeddingsStorageInterface::DEFAULT_DIMENSIONS,
      'default_timeout' => 60.0,
      'sources' => [
        'node' => [
          'report' => [
            'enabled' => TRUE,
            'fields' => ['body'],
            'min_text_length' => 200,
          ],
        ],
      ],
    ];
  }

  /**
   * Config mapping for form submit / install.
   *
   * @return array<string, mixed>
   *   Config array (nested sources; Config-API safe).
   */
  public function toConfigArray(): array {
    $sources = [];
    foreach ($this->sources as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle => $source) {
        $sources[$entity_type_id][$bundle] = $source->toConfigArray();
      }
    }
    return [
      'embed_endpoint' => $this->embedEndpoint,
      'dimensions' => $this->dimensions,
      'default_timeout' => $this->defaultTimeout,
      'sources' => $sources,
    ];
  }

  /**
   * Source settings for an entity type + bundle.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param string $bundle
   *   Bundle.
   *
   * @return \Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingSourceSettings|null
   *   Source settings, or NULL when not configured.
   */
  public function getSource(string $entity_type_id, string $bundle): ?EmbeddingSourceSettings {
    return $this->sources[$entity_type_id][$bundle] ?? NULL;
  }

  /**
   * Normalize sources to entity_type → bundle → settings arrays.
   *
   * Converts legacy flat keys ("node.report") into nested structure.
   *
   * @param array<string, mixed> $raw_sources
   *   Raw sources from config.
   *
   * @return array<string, array<string, array<string, mixed>>>
   *   Nested sources.
   */
  private static function normalizeRawSources(array $raw_sources): array {
    $nested = [];
    foreach ($raw_sources as $key => $value) {
      if (!is_string($key) || !is_array($value)) {
        continue;
      }

      // Legacy flat: "node.report" => settings.
      if (str_contains($key, '.')) {
        $parts = explode('.', $key, 2);
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
          $nested[$parts[0]][$parts[1]] = $value;
        }
        continue;
      }

      // Nested: entity_type => [ bundle => settings ].
      foreach ($value as $bundle => $settings) {
        if (is_string($bundle) && is_array($settings)) {
          $nested[$key][$bundle] = $settings;
        }
      }
    }
    return $nested;
  }

}
