<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\Helpers\PlainTextNormalizer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests PlainTextNormalizer markdown/HTML stripping.
 */
#[CoversClass(PlainTextNormalizer::class)]
#[Group('reliefweb_content_analyzer')]
class PlainTextNormalizerTest extends UnitTestCase {

  /**
   * Emphasis variants normalize to the same plain text.
   */
  public function testAsteriskAndUnderscoreEmphasisAreEquivalent(): void {
    $star = PlainTextNormalizer::normalize('This is *important* and **critical** news.');
    $under = PlainTextNormalizer::normalize('This is _important_ and __critical__ news.');
    $this->assertSame($star, $under);
    $this->assertSame('this is important and critical news.', $star);
  }

  /**
   * Links keep text; images are dropped.
   */
  public function testLinksAndImages(): void {
    $text = PlainTextNormalizer::normalize(
      'See [ReliefWeb](https://reliefweb.int) and ![logo](https://example.com/a.png) here.',
    );
    $this->assertSame('see reliefweb and here.', $text);
  }

  /**
   * HTML tags are stripped.
   */
  public function testHtmlStripped(): void {
    $text = PlainTextNormalizer::normalize('<p>Hello <strong>world</strong></p>');
    $this->assertSame('hello world', $text);
  }

  /**
   * Data provider for miscellaneous strip cases.
   *
   * @return array<string, array{0: string, 1: string}>
   *   Input and expected normalized output.
   */
  public static function stripProvider(): array {
    return [
      'heading' => ['## Situation Update', 'situation update'],
      'list' => ["- First item\n- Second item", 'first item second item'],
      'blockquote' => ['> Quoted line', 'quoted line'],
      'inline code' => ['Use `status` field', 'use status field'],
      'empty' => ['', ''],
    ];
  }

  /**
   * Miscellaneous markdown constructs strip as expected.
   */
  #[DataProvider('stripProvider')]
  public function testStripCases(string $input, string $expected): void {
    $this->assertSame($expected, PlainTextNormalizer::normalize($input));
  }

}
