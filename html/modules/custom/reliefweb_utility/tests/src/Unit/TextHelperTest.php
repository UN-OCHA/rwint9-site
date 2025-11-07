<?php

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Helpers\TextHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests text helper.
 */
#[CoversClass(TextHelper::class)]
#[Group('reliefweb_utility')]
class TextHelperTest extends UnitTestCase {

  /**
   * Test clean text.
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
   */
  public function testTrimText() {
    $text = '   trim around ';
    $expected = 'trim around';
    $this->assertEquals(TextHelper::trimText($text), $expected);

    // This string contains `\u200b` (zero width space) characters at the start,
    // end and middle. The start and end ones should be removed.
    $text = '​自然​環境​​';
    $expected = '自然​環境';
    $this->assertEquals(TextHelper::trimText($text), $expected);
  }

  /**
   * Test sanitize text.
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
      [
        'input' => "Single\nNewline",
        'expected' => "Single\nNewline",
        'preserve_newline' => TRUE,
        'max_consecutive_newlines' => 2,
      ],
      [
        'input' => "Double\n\nNewlines",
        'expected' => "Double\n\nNewlines",
        'preserve_newline' => TRUE,
        'max_consecutive_newlines' => 2,
      ],
      [
        'input' => "Triple\n\n\nNewlines",
        'expected' => "Triple\n\nNewlines",
        'preserve_newline' => TRUE,
        'max_consecutive_newlines' => 2,
      ],
      [
        'input' => "Many\n\n\n\n\nNewlines",
        'expected' => "Many\n\n\nNewlines",
        'preserve_newline' => TRUE,
        'max_consecutive_newlines' => 3,
      ],
      [
        'input' => "Mixed\nSingle\n\n\nConsecutive\nLines",
        'expected' => "Mixed\nSingle\n\nConsecutive\nLines",
        'preserve_newline' => TRUE,
        'max_consecutive_newlines' => 2,
      ],
      [
        'input' => "Windows\r\n\r\n\r\nNewlines",
        'expected' => "Windows\n\nNewlines",
        'preserve_newline' => TRUE,
        'max_consecutive_newlines' => 2,
      ],
      [
        'input' => "Default\n\n\nBehavior",
        'expected' => "Default\nBehavior",
        'preserve_newline' => TRUE,
        // max_consecutive_newlines defaults to 1.
      ],
    ];

    foreach ($tests as $test) {
      $max_consecutive_newlines = $test['max_consecutive_newlines'] ?? 1;

      $this->assertEquals(
        $test['expected'],
        TextHelper::sanitizeText($test['input'], $test['preserve_newline'], $max_consecutive_newlines),
        'Failed sanitizing: ' . $test['input']
      );
    }
  }

  /**
   * Test strip embedded content.
   */
  public function testStripEmbeddedContent() {
    $text = 'just a string';
    $expected = $text;

    $this->assertEquals(TextHelper::stripEmbeddedContent($text), $expected);
  }

  /**
   * Test get text diff.
   */
  public function testGetTextDiff() {
    $from_text = 'Totam est quasi aliquam sit quibusdam';
    $to_text = 'Totam est quasi dignissimos sit quibusdam';
    $expected = 'Totam est quasi <del>al</del><ins>d</ins>i<del>qua</del><ins>gnissi</ins>m<ins>os</ins> sit quibusdam';

    $this->assertEquals(TextHelper::getTextDiff($from_text, $to_text), $expected);
  }

  /**
   * Test get text similarity.
   */
  public function testGetTextSimilarity() {
    // Test identical strings.
    $this->assertEquals(100.0, TextHelper::getTextSimilarity('hello', 'hello'));

    // Test empty strings.
    $this->assertEquals(100.0, TextHelper::getTextSimilarity('', ''));
    $this->assertEquals(0.0, TextHelper::getTextSimilarity('hello', ''));
    $this->assertEquals(0.0, TextHelper::getTextSimilarity('', 'hello'));

    // Test completely different strings.
    $this->assertEquals(0.0, TextHelper::getTextSimilarity('abc', 'xyz'));

    // Test case sensitivity.
    $this->assertEquals(100.0, TextHelper::getTextSimilarity('Hello', 'hello', FALSE));
    $this->assertEquals(80.0, TextHelper::getTextSimilarity('Hello', 'hello', TRUE));

    // Test partial similarity.
    $similarity = TextHelper::getTextSimilarity('hello world', 'hello world!');
    $this->assertGreaterThan(90.0, $similarity);
    $this->assertLessThan(100.0, $similarity);

    // Test Unicode characters.
    $this->assertEquals(100.0, TextHelper::getTextSimilarity('café', 'café'));
    $similarity = TextHelper::getTextSimilarity('café', 'cafe');
    $this->assertGreaterThan(70.0, $similarity);
    $this->assertLessThan(80.0, $similarity);

    // Test whitespace normalization.
    $this->assertEquals(100.0, TextHelper::getTextSimilarity('hello  world', 'hello world', FALSE, TRUE));
    $similarity = TextHelper::getTextSimilarity('hello  world', 'hello world', FALSE, FALSE);
    $this->assertGreaterThan(90.0, $similarity);
    $this->assertLessThan(100.0, $similarity);

    // Test complex Unicode text.
    $text1 = '自然環境保護';
    $text2 = '自然環境保全';
    $similarity = TextHelper::getTextSimilarity($text1, $text2);
    $this->assertGreaterThan(80.0, $similarity);
    $this->assertLessThan(100.0, $similarity);

    // Test with special characters and whitespace.
    $text1 = "Hello\n\tWorld!";
    $text2 = "Hello World!";
    $similarity = TextHelper::getTextSimilarity($text1, $text2, FALSE, TRUE);
    $this->assertEquals(100.0, $similarity);

    // Test longer texts.
    $text1 = 'The quick brown fox jumps over the lazy dog';
    $text2 = 'The quick brown fox jumps over a lazy dog';
    $similarity = TextHelper::getTextSimilarity($text1, $text2);
    $this->assertGreaterThan(90.0, $similarity);
    $this->assertLessThan(100.0, $similarity);
  }

  /**
   * Test calculate Unicode Levenshtein distance.
   */
  public function testCalculateUnicodeLevenshteinDistance() {
    // Use reflection to access the protected method.
    $reflection = new \ReflectionClass(TextHelper::class);
    $method = $reflection->getMethod('calculateUnicodeLevenshteinDistance');
    $method->setAccessible(TRUE);

    // Test identical strings.
    $this->assertEquals(0, $method->invoke(NULL, 'hello', 'hello'));

    // Test empty strings.
    $this->assertEquals(0, $method->invoke(NULL, '', ''));
    $this->assertEquals(5, $method->invoke(NULL, 'hello', ''));
    $this->assertEquals(5, $method->invoke(NULL, '', 'hello'));

    // Test single character difference.
    $this->assertEquals(1, $method->invoke(NULL, 'hello', 'hallo'));

    // Test insertion.
    $this->assertEquals(1, $method->invoke(NULL, 'hello', 'helloo'));

    // Test deletion.
    $this->assertEquals(1, $method->invoke(NULL, 'hello', 'hell'));

    // Test substitution.
    $this->assertEquals(1, $method->invoke(NULL, 'hello', 'hxllo'));

    // Test Unicode characters.
    $this->assertEquals(0, $method->invoke(NULL, 'café', 'café'));
    $this->assertEquals(1, $method->invoke(NULL, 'café', 'cafe'));

    // Test complex Unicode.
    $this->assertEquals(1, $method->invoke(NULL, '自然環境', '自然環保'));

    // Test multiple operations.
    $this->assertEquals(3, $method->invoke(NULL, 'kitten', 'sitting'));

    // Test completely different strings.
    $this->assertEquals(3, $method->invoke(NULL, 'abc', 'xyz'));

    // Test case sensitivity (method should be case sensitive).
    $this->assertEquals(1, $method->invoke(NULL, 'Hello', 'hello'));

    // Test with special Unicode characters (`​` between test and space).
    $this->assertEquals(1, $method->invoke(NULL, 'test​space', 'testspace'));
  }

}
