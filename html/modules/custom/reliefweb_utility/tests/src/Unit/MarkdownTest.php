<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Plugin\Filter\Markdown;
use Drupal\Tests\UnitTestCase;

/**
 * Tests date helper.
 *
 * @covers \Drupal\reliefweb_utility\Plugin\Filter\Markdown
 * @coversDefaultClass \Drupal\reliefweb_utility\Plugin\Filter\Markdown
 */
class MarkdownTest extends UnitTestCase {

  /**
   * @var \Drupal\reliefweb_utility\Plugin\Filter\Markdown
   */
  protected $filter;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $configuration['settings'] = [];
    $this->filter = new Markdown($configuration, 'filter_markdown', [
      'provider' => 'test',
    ]);

    $this->filter
      ->setStringTranslation($this
      ->getStringTranslationStub());
  }

  /**
   * @covers ::process
   *
   * @dataProvider providerMarkdown
   *
   * @param string $html
   *   Input HTML.
   * @param array $expected
   *   The expected output string.
   */
  public function testMarkdown($html, $expected) {
    $this
      ->assertSame($expected, $this->filter
      ->process($html, 'en')->__toString());
  }

  /**
   * Provides data for testMarkdown.
   *
   * @return array
   *   An array of test data.
   */
  public function providerMarkdown() {
    return [
      [
        'Just a string',
        '<p>Just a string</p>' . "\n",
      ],
      [
        '#foo',
        '<h1>foo</h1>' . "\n",
      ],
      [
        '#bar #',
        '<h1>bar</h1>' . "\n",
      ],
      [
        '#bar#',
        '<h1>bar#</h1>' . "\n",
      ],
      [
        '##baz',
        '<h2>baz</h2>' . "\n",
      ],
      [
        '##baz #',
        '<h2>baz</h2>' . "\n",
      ],
      [
        '##quz ##',
        '<h2>quz</h2>' . "\n",
      ],
      [
        '##quz##',
        '<h2>quz##</h2>' . "\n",
      ],
      [
        'heading' . "\n" . '===============',
        '<h1>heading</h1>' . "\n",
      ],
    ];
  }

}
