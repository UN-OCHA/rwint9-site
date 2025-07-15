<?php

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Helpers\MarkdownHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests markdown helper.
 */
#[CoversClass(MarkdownHelper::class)]
#[Group('reliefweb_utility')]
class MarkdownHelperTest extends UnitTestCase {

  /**
   * Test convert to html.
   *
   * @param string $text
   *   Markdown text.
   * @param array $expected
   *   Expected output.
   * @param array $internal_hosts
   *   List of internal hosts to determine if a link is external or not.
   */
  #[DataProvider('providerMarkdown')]
  public function testConvertToHtml($text, $expected, array $internal_hosts = ['reliefweb.int']) {
    $this->assertEquals($expected, MarkdownHelper::convertToHtml($text, $internal_hosts));
  }

  /**
   * Provides data for testMarkdown.
   *
   * @return array
   *   Test data.
   */
  public static function providerMarkdown() {
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
      [
        '[Internal](https://internal.test)',
        '<p><a rel="noopener noreferrer" target="_blank" href="https://internal.test">Internal</a></p>' . "\n",
      ],
      [
        '[Internal](https://internal.test)',
        '<p><a href="https://internal.test">Internal</a></p>' . "\n",
        ['internal.test'],
      ],
      [
        '[Internal](https://reliefweb.int)',
        '<p><a href="https://reliefweb.int">Internal</a></p>' . "\n",
      ],
      [
        '[External](https://external.test)',
        '<p><a rel="noopener noreferrer" target="_blank" href="https://external.test">External</a></p>' . "\n",
      ],
    ];
  }

  /**
   * Test convert inlines only.
   */
  public function testConvertInlinesOnly() {
    $text = "test [link](https://test.test)\n\nthis **not** a paragraph\n\n* list item 1\n* list item 2\n";
    $expected = "test <a rel=\"noopener noreferrer\" target=\"_blank\" href=\"https://test.test\">link</a>\n\nthis <strong>not</strong> a paragraph\n\n* list item 1\n* list item 2\n";
    $this->assertEquals($expected, MarkdownHelper::convertInlinesOnly($text));

    $text = "[test]: /test";
    $expected = "[test]: /test\n";
    $this->assertEquals($expected, MarkdownHelper::convertInlinesOnly($text));
  }

}
