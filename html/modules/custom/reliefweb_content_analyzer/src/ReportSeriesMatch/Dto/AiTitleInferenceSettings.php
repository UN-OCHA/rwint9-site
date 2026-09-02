<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto;

/**
 * Typed AI inference settings for report title generation.
 *
 * Built from the report_series_matching.matcher.ai_title_inference config.
 *
 * @phpstan-type InferenceSettings array{
 *   plugin_id: string,
 *   temperature: float,
 *   top_p: float,
 *   max_tokens: int,
 *   thinking_mode: string,
 *   system_prompt: string,
 * }
 */
final readonly class AiTitleInferenceSettings {

  /**
   * Constructs AI title inference settings.
   *
   * @param string $pluginId
   *   OCHA AI completion plugin ID.
   * @param float $temperature
   *   Sampling temperature.
   * @param float $topP
   *   Nucleus sampling top_p.
   * @param int $maxTokens
   *   Maximum tokens to generate.
   * @param string $thinkingMode
   *   Thinking mode (none, low, medium, high).
   * @param string $systemPrompt
   *   System prompt for title generation.
   */
  public function __construct(
    public string $pluginId,
    public float $temperature,
    public float $topP,
    public int $maxTokens,
    public string $thinkingMode,
    public string $systemPrompt,
  ) {}

  /**
   * Builds settings from the ai_title_inference config array.
   *
   * @param array<string, mixed> $config
   *   Raw ai_title_inference config from Drupal config.
   *
   * @throws \InvalidArgumentException
   *   When a required key is missing or has an invalid type.
   *
   * @return self
   *   Typed inference settings instance.
   */
  public static function fromConfigArray(array $config): self {
    return new self(
      pluginId: self::requireString($config, 'plugin_id'),
      temperature: self::requireFloat($config, 'temperature'),
      topP: self::requireFloat($config, 'top_p'),
      maxTokens: self::requireInt($config, 'max_tokens'),
      thinkingMode: self::requireString($config, 'thinking_mode'),
      systemPrompt: self::requireString($config, 'system_prompt'),
    );
  }

  /**
   * Returns settings in the shape expected by ReportSeriesMatcher.
   *
   * @return InferenceSettings
   *   Inference settings array for completion plugin calls.
   */
  public function toInferenceArray(): array {
    return [
      'plugin_id' => $this->pluginId,
      'temperature' => $this->temperature,
      'top_p' => $this->topP,
      'max_tokens' => $this->maxTokens,
      'thinking_mode' => $this->thinkingMode,
      'system_prompt' => $this->systemPrompt,
    ];
  }

  /**
   * Reads a required integer value from inference config.
   *
   * @param array<string, mixed> $config
   *   Raw inference config.
   * @param string $key
   *   Config key.
   *
   * @return int
   *   Parsed integer value.
   */
  private static function requireInt(array $config, string $key): int {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("AI title inference config missing required key: {$key}.");
    }
    if (!is_int($config[$key]) && !is_float($config[$key]) && !is_string($config[$key])) {
      throw new \InvalidArgumentException("AI title inference config key {$key} must be numeric.");
    }
    return (int) $config[$key];
  }

  /**
   * Reads a required float value from inference config.
   *
   * @param array<string, mixed> $config
   *   Raw inference config.
   * @param string $key
   *   Config key.
   *
   * @return float
   *   Parsed float value.
   */
  private static function requireFloat(array $config, string $key): float {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("AI title inference config missing required key: {$key}.");
    }
    if (!is_int($config[$key]) && !is_float($config[$key]) && !is_string($config[$key])) {
      throw new \InvalidArgumentException("AI title inference config key {$key} must be numeric.");
    }
    return (float) $config[$key];
  }

  /**
   * Reads a required string value from inference config.
   *
   * @param array<string, mixed> $config
   *   Raw inference config.
   * @param string $key
   *   Config key.
   *
   * @return string
   *   Parsed string value.
   */
  private static function requireString(array $config, string $key): string {
    if (!array_key_exists($key, $config)) {
      throw new \InvalidArgumentException("AI title inference config missing required key: {$key}.");
    }
    if (!is_string($config[$key])) {
      throw new \InvalidArgumentException("AI title inference config key {$key} must be a string.");
    }
    return $config[$key];
  }

}
