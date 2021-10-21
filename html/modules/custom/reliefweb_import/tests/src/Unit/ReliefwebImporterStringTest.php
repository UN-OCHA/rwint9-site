<?php

namespace Drupal\Tests\reliefweb_import\Unit;

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
   * Tests for sanize text.
   */
  public function testsanitizeTextCdata() {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->reliefwebImporter->sanitizeText('body', '<![CDATA[' . $test_string . ']]>'));
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

    $this->expectExceptionMessage(strtr('Invalid field size for field_city, 0 characters found, has to be between 3 and 255', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateCity($test_string);

    $test_string = $this->random->string(500);

    $this->expectExceptionMessage(strtr('Invalid field size for field_city, 0 characters found, has to be between 3 and 255', [
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

    $test_string = 'aaaa-bb-cc';
    $this->expectExceptionMessage(strtr('Invalid data for field_job_closing_date, 7 characters found, format has to be yyyy-mm-dd', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateJobClosingDate($test_string);
  }

}
