<?php

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\Component\Utility\Html;

/**
 * Tests reliefweb importer.
 *
 * @covers \Drupal\reliefweb_import\Command\ReliefwebImportCommand
 */
class ReliefwebImporterStringTest extends ReliefwebImporterTestBase {

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextEmpty() {
    $test_string = '';

    $this->expectExceptionMessage(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextSpaces() {
    $test_string = '      ';

    $this->expectExceptionMessage(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
      '@length' => 0,
    ]));
    $this->reliefwebImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextTooShort() {
    $test_string = $this->random->sentences(5);

    $this->expectExceptionMessage(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextTooLong() {
    $test_string = $this->random->sentences(25000);

    $this->expectExceptionMessage(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextArray() {
    $test_string = [];
    $this->expectExceptionMessage('Invalid field size for body, 0 characters found, has to be between 400 and 50000');
    $this->reliefwebImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextPlain() {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->reliefwebImporter->validateBody($test_string));
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextClosingTag() {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->reliefwebImporter->sanitizeText('body', $test_string . '</body>'));
  }

  /**
   * Tests for sanize text wrapped in CDATA.
   */
  public function testsanitizeTextCdata() {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->reliefwebImporter->sanitizeText('body', '<![CDATA[' . $test_string . ']]>'));
  }

  /**
   * Tests for sanize markdown text.
   */
  public function testsanitizeMarkdown() {
    $test_string = "This is a test.\n\nWith a bith of __bold__ text and a [link](https://example.test)";
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->reliefwebImporter->sanitizeText('body', $test_string));
  }

  /**
   * Tests for sanize markdown text wrapped in CDATA.
   */
  public function testsanitizeMarkdownWithCdata() {
    $test_string = "This is a test.\n\nWith a bith of __bold__ text and a [link](https://example.test)";
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->reliefwebImporter->sanitizeText('body', '<![CDATA[' . $test_string . ']]>'));
  }

  /**
   * Tests for sanize markdown text wrapped in encoded CDATA.
   */
  public function testsanitizeMarkdownWithEncodedCdata() {
    $test_string = "This is a test.\n\nWith a bith of __bold__ text and a [link](https://example.test)";
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->reliefwebImporter->sanitizeText('body', '&lt;![CDATA[' . $test_string . ']]&gt;'));
  }

  /**
   * Tests for sanize text with raw HTML.
   */
  public function testsanitizeHtml() {
    $test_string = '<p>This is a test.</p><p style="font-size: 16px;">With a bith of <strong>bold</strong> text and a <a unrecognized="attribute" href="https://example.test">link</a></p>';
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->reliefwebImporter->sanitizeText('body', $test_string));
  }

  /**
   * Tests for sanize text with raw HTML wrapped in CDATA.
   */
  public function testsanitizeHtmlWithCdata() {
    $test_string = '<p>This is a test.</p><p style="font-size: 16px;">With a bith of <strong>bold</strong> text and a <a unrecognized="attribute" href="https://example.test">link</a></p>';
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->reliefwebImporter->sanitizeText('body', '<![CDATA[' . $test_string . ']]>'));
  }

  /**
   * Tests for sanize text with raw HTML wrapped in CDATA.
   */
  public function testsanitizeHtmlWithEncodedCdata() {
    $test_string = '<p>This is a test.</p><p style="font-size: 16px;">With a bith of <strong>bold</strong> text and a <a unrecognized="attribute" href="https://example.test">link</a></p>';
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->reliefwebImporter->sanitizeText('body', '&lt;![CDATA[' . $test_string . ']]&gt;'));
  }

  /**
   * Tests for sanize text with encoded HTML.
   */
  public function testsanitizeEncodedHtml() {
    $test_string = '<p>This is a test.</p><p style="font-size: 16px;">With a bith of <strong>bold</strong> text and a <a unrecognized="attribute" href="https://example.test">link</a></p>';
    $test_string = Html::escape($test_string);
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->reliefwebImporter->sanitizeText('body', $test_string));
  }

  /**
   * Tests for sanize text with encoded HTML wrapped in encoded CDATA.
   */
  public function testsanitizeEncodedHtmlWithCdata() {
    $test_string = '<p>This is a test.</p><p style="font-size: 16px;">With a bith of <strong>bold</strong> text and a <a unrecognized="attribute" href="https://example.test">link</a></p>';
    $test_string = Html::escape($test_string);
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->reliefwebImporter->sanitizeText('body', '<![CDATA[' . $test_string . ']]>'));
  }

  /**
   * Tests for sanize text with encoded HTML wrapped in encoded CDATA.
   */
  public function testsanitizeEncodedHtmlWithEncodedCdata() {
    $test_string = '<p>This is a test.</p><p style="font-size: 16px;">With a bith of <strong>bold</strong> text and a <a unrecognized="attribute" href="https://example.test">link</a></p>';
    $test_string = Html::escape($test_string);
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->reliefwebImporter->sanitizeText('body', '&lt;![CDATA[' . $test_string . ']]&gt;'));
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextEmbedImage() {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->reliefwebImporter->sanitizeText('body', $test_string . '<img src="">'));
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextPtag() {
    $test_string = '<p style="font-family: Arial;">The Opportunity</p>';
    $this->assertEquals('The Opportunity', $this->reliefwebImporter->sanitizeText('body', $test_string));
  }

  /**
   * Tests for validateCity.
   */
  public function testValidateCity() {
    $test_strings = [
      'Geneva' => 'Geneva',
      "Geneva\n\n" => 'Geneva',
      "Geneva\n\ncity" => 'Geneva city',
    ];

    foreach ($test_strings as $test_string => $expected) {
      $this->assertEquals($expected, $this->reliefwebImporter->validateCity($test_string));
    }

    $test_string = '';
    $this->expectExceptionMessage(strtr('Invalid field size for field_city, @length characters found, has to be between 3 and 255', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateCity($test_string);
  }

  /**
   * Tests for validateCity.
   */
  public function testValidateCity2() {
    $test_string = $this->random->sentences(100);
    $this->expectExceptionMessage(strtr('Invalid field size for field_city, @length characters found, has to be between 3 and 255', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateCity($test_string);
  }

  /**
   * Tests for validateJobClosingDate.
   */
  public function testValidateJobClosingDate() {
    $test_strings = [
      '' => '',
      '2010-01-01' => '2010-01-01',
      '2010-01-01T10:00:00' => '2010-01-01',
      '2010-01-01T10:00:00 +02:00' => '2010-01-01',
    ];

    foreach ($test_strings as $test_string => $expected) {
      $this->assertEquals($expected, $this->reliefwebImporter->validateJobClosingDate($test_string));
    }

    $test_string = '2010-01';
    $this->expectExceptionMessage(strtr('Invalid data for field_job_closing_date, 7 characters found, format has to be yyyy-mm-dd', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateJobClosingDate($test_string);
  }

  /**
   * Tests for validateJobClosingDate.
   */
  public function testValidateJobClosingDate2() {
    $test_string = 'aaaa-bb-cc';
    $this->expectExceptionMessage(strtr('Invalid data for field_job_closing_date, @test_string has to be in format yyyy-mm-dd', [
      '@test_string' => $test_string,
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateJobClosingDate($test_string);
  }

  /**
   * Tests for validateCity.
   */
  public function testValidateHowToApply() {
    $test_string = $this->random->sentences(50);
    $this->assertEquals($test_string, $this->reliefwebImporter->validateHowToApply($test_string));

    $test_string = '';
    $this->expectExceptionMessage(strtr('Invalid field size for field_how_to_apply, @length characters found, has to be between 100 and 10000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateHowToApply($test_string);
  }

  /**
   * Tests for validateHowToApply.
   */
  public function testValidateHowToApply2() {
    $test_string = $this->random->sentences(5000);
    $this->expectExceptionMessage(strtr('Invalid field size for field_how_to_apply, @length characters found, has to be between 100 and 10000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateHowToApply($test_string);
  }

}
