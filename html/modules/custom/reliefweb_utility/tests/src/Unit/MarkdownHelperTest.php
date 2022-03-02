<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Helpers\MarkdownHelper;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests markdown helper.
 *
 * @covers \Drupal\reliefweb_utility\Helpers\MarkdownHelper
 */
class MarkdownHelperTest extends UnitTestCase {

  /**
   * @covers \Drupal\reliefweb_utility\Helpers\MarkdownHelper::convertToHtml
   *
   * @dataProvider providerMarkdown
   *
   * @param string $text
   *   Markdown text.
   * @param array $expected
   *   The expected output string.
   * @param array $internal_hosts
   *   List of internal hosts to determine if a link is external or not.
   */
  public function testConvertToHtml($text, $expected, array $internal_hosts = ['reliefweb.int']) {
    $this->assertEquals($expected, MarkdownHelper::convertToHtml($text, $internal_hosts));
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

}
