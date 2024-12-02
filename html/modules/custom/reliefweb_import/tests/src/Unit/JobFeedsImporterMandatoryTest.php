<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit;

/**
 * Tests reliefweb importer.
 *
 * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter
 */
class JobFeedsImporterMandatoryTest extends JobFeedsImporterTestBase {

  /**
   * Test validate base URL.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateBaseUrl
   */
  public function testvalidateBaseUrlEmpty(): void {
    $link = '';

    $this->expectExceptionMessage('Base URL is empty.');
    $this->jobImporter->validateBaseUrl($link);
  }

  /**
   * Test validate base URL.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateBaseUrl
   */
  public function testvalidateBaseUrlSpaces(): void {
    $link = '';

    $this->expectExceptionMessage('Base URL is empty.');
    $this->jobImporter->validateBaseUrl($link);
  }

  /**
   * Test validate base URL.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateBaseUrl
   */
  public function testvalidateBaseUrlInvalidUrl(): void {
    $link = 'not a link';

    $this->expectExceptionMessage('Base URL is not a valid link.');
    $this->jobImporter->validateBaseUrl($link);
  }

  /**
   * Test validate link.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateLink
   */
  public function testvalidateLinkEmpty(): void {
    $link = '';

    $this->expectExceptionMessage('Feed item found without a link.');
    $this->jobImporter->validateLink($link, '');
  }

  /**
   * Test validate link.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateLink
   */
  public function testvalidateLinkSpaces(): void {
    $link = '    ';

    $this->expectExceptionMessage('Feed item found without a link.');
    $this->jobImporter->validateLink($link, '');
  }

  /**
   * Test validate link.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateLink
   */
  public function testvalidateLinkInvalidUrl(): void {
    $link = 'not a link';

    $this->expectExceptionMessage('Invalid feed item link.');
    $this->jobImporter->validateLink($link, '');
  }

  /**
   * Test validate link.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateLink
   */
  public function testvalidateLinkBase(): void {
    $link = 'https://example.com';

    $this->expectExceptionMessage('Invalid feed item link base.');
    $this->jobImporter->validateLink($link, 'https://another.com');
  }

  /**
   * Test validate title.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateTitle
   */
  public function testvalidateTitleEmpty(): void {
    $title = '';

    $this->expectExceptionMessage('Job found with empty title.');
    $this->jobImporter->validateTitle($title);
  }

  /**
   * Test validate title.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateTitle
   */
  public function testvalidateTitleSpaces(): void {
    $title = '       ';

    $this->expectExceptionMessage('Job found with empty title.');
    $this->jobImporter->validateTitle($title);
  }

  /**
   * Test validate title.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateTitle
   */
  public function testvalidateTitleTooShort(): void {
    $title = 'a';

    $this->expectExceptionMessage('Invalid title length.');
    $this->jobImporter->validateTitle($title);
  }

  /**
   * Test validate title.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateTitle
   */
  public function testvalidateTitleTooLong(): void {
    $title = $this->random->sentences(100);

    $this->expectExceptionMessage('Invalid title length.');
    $this->jobImporter->validateTitle($title);
  }

  /**
   * Test validate title.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateTitle
   */
  public function testvalidateTitleOk(): void {
    $title = $this->random->sentences(10);
    $this->assertEquals($title, $this->jobImporter->validateTitle($title));

    $title = 'This is the title';
    $this->assertEquals($title, $this->jobImporter->validateTitle('<p>' . $title . '</p>'));

    $title = 'This is the title';
    $this->assertEquals($title, $this->jobImporter->validateTitle('    <p>   ' . $title . '   </p>   '));
  }

  /**
   * Test validate source.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateTitle
   */
  public function testvalidateSourceEmpty(): void {
    $source = '';
    $source_id = 2865;

    $this->expectExceptionMessage('Job found with empty source.');
    $this->jobImporter->validateSource($source, $source_id);
  }

  /**
   * Test validate source.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateTitle
   */
  public function testvalidateSourceNonNumeric(): void {
    $source = 'abcd';
    $source_id = 2865;

    $this->expectExceptionMessage('Job found with non numeric source.');
    $this->jobImporter->validateSource($source, $source_id);
  }

  /**
   * Test validate source.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateTitle
   */
  public function testvalidateSourceDifferent(): void {
    $source = '666';
    $source_id = 2865;

    $this->expectExceptionMessage(strtr('Invalid job source: expected @source_id, got @source.', [
      '@source_id' => $source_id,
      '@source' => $source,
    ]));
    $this->jobImporter->validateSource($source, $source_id);
  }

  /**
   * Test validate source.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateTitle
   */
  public function testvalidateSourceOk(): void {
    $source = '2865';
    $source_id = 2865;

    $this->assertEquals($source, $this->jobImporter->validateSource($source, $source_id));
  }

  /**
   * Test validate user.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateTitle
   */
  public function testvalidateUserEmpty(): void {
    $uid = ' ';

    $this->expectExceptionMessage('User Id is not defined.');
    $this->jobImporter->validateUser($uid);
  }

  /**
   * Test validate user.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateTitle
   */
  public function testvalidateUserNumeric(): void {
    $uid = 'abcd';

    $this->expectExceptionMessage('User Id is not numeric.');
    $this->jobImporter->validateUser($uid);
  }

  /**
   * Test validate user.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::validateTitle
   */
  public function testvalidateUserId(): void {
    $uid = 1;

    $this->expectExceptionMessage('User Id is an admin.');
    $this->jobImporter->validateUser($uid);
  }

}
