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

    // This string contains `\u200b` (zero width space) characters at the start,
    // end and middle. The start and end ones should be removed.
    $text = '​自然​環境​​';
    $expected = '自然​環境';
    $options = [];
    $this->assertEquals(TextHelper::cleanText($text, $options), $expected);
  }

  /**
   * Test trim text.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\TextHelper::trimText
   */
  public function testTrimText() {
    $text = '   trim around ';
    $expected = 'trim around';
    $options = [];
    $this->assertEquals(TextHelper::trimText($text), $expected);

    // This string contains `\u200b` (zero width space) characters at the start,
    // end and middle. The start and end ones should be removed.
    $text = '​自然​環境​​';
    $expected = '自然​環境';
    $options = [];
    $this->assertEquals(TextHelper::trimText($text), $expected);
  }

  /**
   * Test sanitize text.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\TextHelper::sanitizeText
   */
  public function testSanitizeText() {
    $tests = [
      [
        'input' => "  Multiple   spaces   and\ttabs  ",
        'expected' => "Multiple spaces and tabs",
        'preserve_newline' => FALSE,
      ],
      [
        'input' => "Line breaks\nshould be\r\nremoved",
        'expected' => "Line breaks should be removed",
        'preserve_newline' => FALSE,
      ],
      [
        'input' => "Line breaks\nshould be\r\npreserved",
        'expected' => "Line breaks\nshould be\npreserved",
        'preserve_newline' => TRUE,
      ],
      [
        'input' => "Unicode\u{200B}zero-width\u{200B}space",
        'expected' => "Unicodezero-widthspace",
        'preserve_newline' => FALSE,
      ],
      [
        'input' => "Multiple\n\n\nNewlines",
        'expected' => "Multiple\nNewlines",
        'preserve_newline' => TRUE,
      ],
      [
        'input' => "Non-breaking\u{00A0}space",
        'expected' => "Non-breaking space",
        'preserve_newline' => FALSE,
      ],
      [
        'input' => "Control\u{0007}character",
        'expected' => "Controlcharacter",
        'preserve_newline' => FALSE,
      ],
      [
        'input' => "HTML&nbsp;non-breaking-space",
        'expected' => "HTML non-breaking-space",
        'preserve_newline' => FALSE,
      ],
    ];

    foreach ($tests as $test) {
      $this->assertEquals(
        $test['expected'],
        TextHelper::sanitizeText($test['input'], $test['preserve_newline']),
        'Failed sanitizing: ' . $test['input']
      );
    }
  }

  /**
   * Test strip embedded content..
   *
   * @covers \Drupal\reliefweb_utility\Helpers\TextHelper::stripEmbeddedContent
   */
  public function testStripEmbeddedContent() {
    $text = 'just a string';
    $expected = $text;

    $this->assertEquals(TextHelper::stripEmbeddedContent($text), $expected);
  }

  /**
   * Test get text diff.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\TextHelper::getTextDiff
   */
  public function testGetTextDiff() {
    $from_text = 'Totam est quasi aliquam sit quibusdam';
    $to_text = 'Totam est quasi dignissimos sit quibusdam';
    $expected = 'Totam est quasi <del>al</del><ins>d</ins>i<del>qua</del><ins>gnissi</ins>m<ins>os</ins> sit quibusdam';

    $this->assertEquals(TextHelper::getTextDiff($from_text, $to_text), $expected);
  }

}
