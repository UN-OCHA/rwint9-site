<?php

namespace Drupal\Tests\reliefweb_import\Unit;

/**
 * Tests reliefweb importer.
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

}
