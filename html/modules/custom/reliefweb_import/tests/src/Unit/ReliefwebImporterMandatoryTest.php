<?php

namespace Drupal\Tests\reliefweb_import\Unit;

/**
 * Tests reliefweb importer.
 *
 * @covers \Drupal\reliefweb_import\Command\ReliefwebImportCommand
 */
class ReliefwebImporterMandatoryTest extends ReliefwebImporterTestBase {

  /**
   * Test validate base URL.
   */
  public function testvalidateBaseUrlEmpty() {
    $link = '';

    $this->expectExceptionMessage('Base URL is empty.');
    $this->reliefwebImporter->validateBaseUrl($link);
  }

  /**
   * Test validate base URL.
   */
  public function testvalidateBaseUrlSpaces() {
    $link = '';

    $this->expectExceptionMessage('Base URL is empty.');
    $this->reliefwebImporter->validateBaseUrl($link);
  }

  /**
   * Test validate base URL.
   */
  public function testvalidateBaseUrlInvalidUrl() {
    $link = 'not a link';

    $this->expectExceptionMessage('Base URL is not a valid link.');
    $this->reliefwebImporter->validateBaseUrl($link);
  }

  /**
   * Test validate link.
   */
  public function testvalidateLinkEmpty() {
    $link = '';

    $this->expectExceptionMessage('Feed item found without a link.');
    $this->reliefwebImporter->validateLink($link, '');
  }

  /**
   * Test validate link.
   */
  public function testvalidateLinkSpaces() {
    $link = '    ';

    $this->expectExceptionMessage('Feed item found without a link.');
    $this->reliefwebImporter->validateLink($link, '');
  }

  /**
   * Test validate link.
   */
  public function testvalidateLinkInvalidUrl() {
    $link = 'not a link';

    $this->expectExceptionMessage('Invalid feed item link.');
    $this->reliefwebImporter->validateLink($link, '');
  }

  /**
   * Test validate link.
   */
  public function testvalidateLinkBase() {
    $link = 'https://example.com';

    $this->expectExceptionMessage('Invalid feed item link base.');
    $this->reliefwebImporter->validateLink($link, 'https://another.com');
  }

  /**
   * Test validate title.
   */
  public function testvalidateTitleEmpty() {
    $title = '';

    $this->expectExceptionMessage('Job found with empty title.');
    $this->reliefwebImporter->validateTitle($title);
  }

  /**
   * Test validate title.
   */
  public function testvalidateTitleSpaces() {
    $title = '       ';

    $this->expectExceptionMessage('Job found with empty title.');
    $this->reliefwebImporter->validateTitle($title);
  }

  /**
   * Test validate title.
   */
  public function testvalidateTitleTooShort() {
    $title = 'a';

    $this->expectExceptionMessage('Invalid title length.');
    $this->reliefwebImporter->validateTitle($title);
  }

  /**
   * Test validate title.
   */
  public function testvalidateTitleTooLong() {
    $title = $this->random->sentences(100);

    $this->expectExceptionMessage('Invalid title length.');
    $this->reliefwebImporter->validateTitle($title);
  }

  /**
   * Test validate title.
   */
  public function testvalidateTitleOk() {
    $title = $this->random->sentences(10);
    $this->assertEquals($title, $this->reliefwebImporter->validateTitle($title));

    $title = 'This is the title';
    $this->assertEquals($title, $this->reliefwebImporter->validateTitle('<p>' . $title . '</p>'));

    $title = 'This is the title';
    $this->assertEquals($title, $this->reliefwebImporter->validateTitle('    <p>   ' . $title . '   </p>   '));
  }

  /**
   * Test validate source.
   */
  public function testvalidateSourceEmpty() {
    $source = '';
    $source_id = 2865;

    $this->expectExceptionMessage('Job found with empty source.');
    $this->reliefwebImporter->validateSource($source, $source_id);
  }

  /**
   * Test validate source.
   */
  public function testvalidateSourceNonNumeric() {
    $source = 'abcd';
    $source_id = 2865;

    $this->expectExceptionMessage('Job found with non numeric source.');
    $this->reliefwebImporter->validateSource($source, $source_id);
  }

  /**
   * Test validate source.
   */
  public function testvalidateSourceDifferent() {
    $source = 666;
    $source_id = 2865;

    $this->expectExceptionMessage(strtr('Invalid job source: expected @source_id, got @source.', [
      '@source_id' => $source_id,
      '@source' => $source,
    ]));
    $this->reliefwebImporter->validateSource($source, $source_id);
  }

  /**
   * Test validate source.
   */
  public function testvalidateSourceOk() {
    $source = $source_id = 2865;

    $this->assertEquals($source, $this->reliefwebImporter->validateSource($source, $source_id));
  }

  /**
   * Test validate user.
   */
  public function testvalidateUserEmpty() {
    $uid = ' ';

    $this->expectExceptionMessage('User Id is not defined.');
    $this->reliefwebImporter->validateUser($uid);
  }

  /**
   * Test validate user.
   */
  public function testvalidateUserNumeric() {
    $uid = 'abcd';

    $this->expectExceptionMessage('User Id is not numeric.');
    $this->reliefwebImporter->validateUser($uid);
  }

  /**
   * Test validate user.
   */
  public function testvalidateUserId() {
    $uid = 1;

    $this->expectExceptionMessage('User Id is an admin.');
    $this->reliefwebImporter->validateUser($uid);
  }

}
