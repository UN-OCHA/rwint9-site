<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\reliefweb_utility\Helpers\TextHelper;

/**
 * Tests date helper.
 *
 * @covers \Drupal\reliefweb_utility\Helpers\TextHelper
 */
class TextHelperTest extends UnitTestCase {

  /**
   * Test clean text.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\TextHelper::cleanText
   */
  public function testCleanText() {
    $text = $expected = '';
    $options = [];
    $this->assertEquals(TextHelper::cleanText($text, $options), $expected);

    $text = 'keep two  spaces';
    $expected = 'keep two  spaces';
    $options = [];
    $this->assertEquals(TextHelper::cleanText($text, $options), $expected);

    $text = 'remove two  spaces';
    $expected = 'remove two spaces';
    $options = [
      'consecutive' => TRUE,
    ];
    $this->assertEquals(TextHelper::cleanText($text, $options), $expected);

    $text = "keep two\n\nlines";
    $expected = "keep two\n\nlines";
    $options = [];
    $this->assertEquals(TextHelper::cleanText($text, $options), $expected);

    $text = "remove two\n\nlines";
    $expected = "remove two lines";
    $options = [
      'line_breaks' => TRUE,
    ];
    $this->assertEquals(TextHelper::cleanText($text, $options), $expected);

    $text = '   trim around ';
    $expected = 'trim around';
    $options = [];
    $this->assertEquals(TextHelper::cleanText($text, $options), $expected);
  }

  /**
   * Test clean text.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\TextHelper::stripEmbeddedContent
   */
  public function testStripEmbeddedContent() {
    $text = 'just a string';
    $expected = $text;

    $this->assertEquals(TextHelper::stripEmbeddedContent($text), $expected);
  }

}
