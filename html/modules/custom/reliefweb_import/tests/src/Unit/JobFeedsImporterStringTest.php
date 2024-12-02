<?php

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\Component\Utility\Html;

/**
 * Tests reliefweb importer.
 *
 * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter
 */
class JobFeedsImporterStringTest extends JobFeedsImporterTestBase {

  /**
   * Tests for sanize text.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateBody
   */
  public function testsanitizeTextEmpty(): void {
    $test_string = '';

    $this->expectExceptionMessage(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->jobImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateBody
   */
  public function testsanitizeTextSpaces(): void {
    $test_string = '      ';

    $this->expectExceptionMessage(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
      '@length' => 0,
    ]));
    $this->jobImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateBody
   */
  public function testsanitizeTextTooShort(): void {
    $test_string = $this->random->sentences(5);

    $this->expectExceptionMessage(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->jobImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateBody
   */
  public function testsanitizeTextTooLong(): void {
    $test_string = $this->random->sentences(25000);

    $this->expectExceptionMessage(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->jobImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateBody
   */
  public function testsanitizeTextPlain(): void {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->jobImporter->validateBody($test_string));
  }

  /**
   * Tests for sanize text.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeTextClosingTag(): void {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->jobImporter->sanitizeText('body', $test_string . '</body>'));
  }

  /**
   * Tests for sanize text wrapped in CDATA.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeTextCdata(): void {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->jobImporter->sanitizeText('body', '<![CDATA[' . $test_string . ']]>'));
  }

  /**
   * Tests for sanize markdown text.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeMarkdown(): void {
    $test_string = "This is a test.\n\nWith a bith of __bold__ text and a [link](https://example.test)";
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->jobImporter->sanitizeText('body', $test_string));
  }

  /**
   * Tests for sanize markdown text wrapped in CDATA.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeMarkdownWithCdata(): void {
    $test_string = "This is a test.\n\nWith a bith of __bold__ text and a [link](https://example.test)";
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->jobImporter->sanitizeText('body', '<![CDATA[' . $test_string . ']]>'));
  }

  /**
   * Tests for sanize markdown text wrapped in encoded CDATA.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeMarkdownWithEncodedCdata(): void {
    $test_string = "This is a test.\n\nWith a bith of __bold__ text and a [link](https://example.test)";
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->jobImporter->sanitizeText('body', '&lt;![CDATA[' . $test_string . ']]&gt;'));
  }

  /**
   * Tests for sanize text with raw HTML.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeHtml(): void {
    $test_string = '<p>This is a test.</p><p style="font-size: 16px;">With a bith of <strong>bold</strong> text and a <a unrecognized="attribute" href="https://example.test">link</a></p>';
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->jobImporter->sanitizeText('body', $test_string));
  }

  /**
   * Tests for sanize text with raw HTML wrapped in CDATA.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeHtmlWithCdata(): void {
    $test_string = '<p>This is a test.</p><p style="font-size: 16px;">With a bith of <strong>bold</strong> text and a <a unrecognized="attribute" href="https://example.test">link</a></p>';
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->jobImporter->sanitizeText('body', '<![CDATA[' . $test_string . ']]>'));
  }

  /**
   * Tests for sanize text with raw HTML wrapped in CDATA.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeHtmlWithEncodedCdata(): void {
    $test_string = '<p>This is a test.</p><p style="font-size: 16px;">With a bith of <strong>bold</strong> text and a <a unrecognized="attribute" href="https://example.test">link</a></p>';
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->jobImporter->sanitizeText('body', '&lt;![CDATA[' . $test_string . ']]&gt;'));
  }

  /**
   * Tests for sanize text with encoded HTML.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeEncodedHtml(): void {
    $test_string = '<p>This is a test.</p><p style="font-size: 16px;">With a bith of <strong>bold</strong> text and a <a unrecognized="attribute" href="https://example.test">link</a></p>';
    $test_string = Html::escape($test_string);
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->jobImporter->sanitizeText('body', $test_string));
  }

  /**
   * Tests for sanize text with encoded HTML wrapped in encoded CDATA.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeEncodedHtmlWithCdata(): void {
    $test_string = '<p>This is a test.</p><p style="font-size: 16px;">With a bith of <strong>bold</strong> text and a <a unrecognized="attribute" href="https://example.test">link</a></p>';
    $test_string = Html::escape($test_string);
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->jobImporter->sanitizeText('body', '<![CDATA[' . $test_string . ']]>'));
  }

  /**
   * Tests for sanize text with encoded HTML wrapped in encoded CDATA.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeEncodedHtmlWithEncodedCdata(): void {
    $test_string = '<p>This is a test.</p><p style="font-size: 16px;">With a bith of <strong>bold</strong> text and a <a unrecognized="attribute" href="https://example.test">link</a></p>';
    $test_string = Html::escape($test_string);
    $expected = "This is a test.\n\nWith a bith of **bold** text and a [link](https://example.test)";
    $this->assertEquals($expected, $this->jobImporter->sanitizeText('body', '&lt;![CDATA[' . $test_string . ']]&gt;'));
  }

  /**
   * Tests for sanize text.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeTextEmbedImage(): void {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->jobImporter->sanitizeText('body', $test_string . '<img src="">'));
  }

  /**
   * Tests for sanize text.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::sanitizeText
   */
  public function testsanitizeTextPtag(): void {
    $test_string = '<p style="font-family: Arial;">The Opportunity</p>';
    $this->assertEquals('The Opportunity', $this->jobImporter->sanitizeText('body', $test_string));
  }

  /**
   * Tests for validateCity.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateCity
   */
  public function testValidateCity(): void {
    $test_strings = [
      'Geneva' => 'Geneva',
      "Geneva\n\n" => 'Geneva',
      "Geneva\n\ncity" => 'Geneva city',
    ];

    foreach ($test_strings as $test_string => $expected) {
      $this->assertEquals($expected, $this->jobImporter->validateCity($test_string));
    }

    $test_string = 'a';
    $this->expectExceptionMessage(strtr('Invalid field size for field_city, @length characters found, has to be between 3 and 255', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->jobImporter->validateCity($test_string);
  }

  /**
   * Tests for validateCity.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateCity
   */
  public function testValidateCity2(): void {
    $test_string = $this->random->sentences(100);
    $this->expectExceptionMessage(strtr('Invalid field size for field_city, @length characters found, has to be between 3 and 255', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->jobImporter->validateCity($test_string);
  }

  /**
   * Tests for validateJobClosingDate.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateJobClosingDate
   */
  public function testValidateJobClosingDate(): void {
    $test_strings = [
      '' => '',
      '2010-01-01' => '2010-01-01',
      '2010-01-01T10:00:00' => '2010-01-01',
      '2010-01-01T10:00:00 +02:00' => '2010-01-01',
    ];

    foreach ($test_strings as $test_string => $expected) {
      $this->assertEquals($expected, $this->jobImporter->validateJobClosingDate($test_string));
    }

    $test_string = '2010-01';
    $this->expectExceptionMessage(strtr('Invalid data for field_job_closing_date, 7 characters found, format has to be yyyy-mm-dd', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->jobImporter->validateJobClosingDate($test_string);
  }

  /**
   * Tests for validateJobClosingDate.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateJobClosingDate
   */
  public function testValidateJobClosingDate2(): void {
    $test_string = 'aaaa-bb-cc';
    $this->expectExceptionMessage(strtr('Invalid data for field_job_closing_date, @test_string has to be in format yyyy-mm-dd', [
      '@test_string' => $test_string,
      '@length' => mb_strlen($test_string),
    ]));
    $this->jobImporter->validateJobClosingDate($test_string);
  }

  /**
   * Tests for validateHowToApply.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateHowToApply
   */
  public function testValidateHowToApply(): void {
    $test_string = $this->random->sentences(50);
    $this->assertEquals($test_string, $this->jobImporter->validateHowToApply($test_string));

    $test_string = '';
    $this->expectExceptionMessage(strtr('Invalid field size for field_how_to_apply, @length characters found, has to be between 100 and 10000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->jobImporter->validateHowToApply($test_string);
  }

  /**
   * Tests for validateHowToApply.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateHowToApply
   */
  public function testValidateHowToApply2(): void {
    $test_string = $this->random->sentences(5000);
    $this->expectExceptionMessage(strtr('Invalid field size for field_how_to_apply, @length characters found, has to be between 100 and 10000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->jobImporter->validateHowToApply($test_string);
  }

}
