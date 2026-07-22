<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\AiTitleInferenceSettings;
use Drupal\Tests\reliefweb_content_analyzer\Unit\Fixture\SeriesMatchMatcherConfigFixture;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests AiTitleInferenceSettings DTO factory and array export.
 */
#[CoversClass(AiTitleInferenceSettings::class)]
#[Group('reliefweb_content_analyzer')]
class AiTitleInferenceSettingsTest extends UnitTestCase {

  /**
   * FromConfigArray maps install-default values to typed properties.
   */
  public function testFromConfigArray(): void {
    $config = SeriesMatchMatcherConfigFixture::defaults()['ai_title_inference'];
    $settings = AiTitleInferenceSettings::fromConfigArray($config);

    $this->assertSame('aws_bedrock_nova_lite_v1', $settings->pluginId);
    $this->assertSame(0.0, $settings->temperature);
    $this->assertSame(0.9, $settings->topP);
    $this->assertSame(512, $settings->maxTokens);
    $this->assertSame('none', $settings->thinkingMode);
    $this->assertStringContainsString('structured_output', $settings->systemPrompt);
  }

  /**
   * ToInferenceArray returns keys expected by ReportSeriesMatcher.
   */
  public function testToInferenceArray(): void {
    $config = SeriesMatchMatcherConfigFixture::defaults()['ai_title_inference'];
    $settings = AiTitleInferenceSettings::fromConfigArray($config);
    $array = $settings->toInferenceArray();

    $this->assertSame([
      'plugin_id',
      'temperature',
      'top_p',
      'max_tokens',
      'thinking_mode',
      'system_prompt',
    ], array_keys($array));
    $this->assertSame($settings->pluginId, $array['plugin_id']);
    $this->assertSame($settings->temperature, $array['temperature']);
    $this->assertSame($settings->topP, $array['top_p']);
    $this->assertSame($settings->maxTokens, $array['max_tokens']);
    $this->assertSame($settings->thinkingMode, $array['thinking_mode']);
    $this->assertSame($settings->systemPrompt, $array['system_prompt']);
  }

  /**
   * Missing required key throws InvalidArgumentException.
   */
  public function testFromConfigArrayMissingKey(): void {
    $config = SeriesMatchMatcherConfigFixture::defaults()['ai_title_inference'];
    unset($config['plugin_id']);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('plugin_id');
    AiTitleInferenceSettings::fromConfigArray($config);
  }

}
