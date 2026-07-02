<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\reliefweb_import\Service\JobFeedsImporter;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests reliefweb importer.
 */
#[CoversClass(JobFeedsImporter::class)]
class JobFeedsImporterMandatoryTest extends JobFeedsImporterTestBase {

  /**
   * Test validate base URL.
   */
  public function testvalidateBaseUrlEmpty(): void {
    $link = '';

    $this->expectExceptionMessage('Base URL is empty.');
    $this->invokeProtectedMethod('validateBaseUrl', [$link]);
  }

  /**
   * Test validate base URL.
   */
  public function testvalidateBaseUrlSpaces(): void {
    $link = '';

    $this->expectExceptionMessage('Base URL is empty.');
    $this->invokeProtectedMethod('validateBaseUrl', [$link]);
  }

  /**
   * Test validate base URL.
   */
  public function testvalidateBaseUrlInvalidUrl(): void {
    $link = 'not a link';

    $this->expectExceptionMessage('Base URL is not a valid link.');
    $this->invokeProtectedMethod('validateBaseUrl', [$link]);
  }

  /**
   * Test validate link.
   */
  public function testvalidateLinkEmpty(): void {
    $link = '';

    $this->expectExceptionMessage('Feed item found without a link.');
    $this->invokeProtectedMethod('validateLink', [$link, '']);
  }

  /**
   * Test validate link.
   */
  public function testvalidateLinkSpaces(): void {
    $link = '    ';

    $this->expectExceptionMessage('Feed item found without a link.');
    $this->invokeProtectedMethod('validateLink', [$link, '']);
  }

  /**
   * Test validate link.
   */
  public function testvalidateLinkInvalidUrl(): void {
    $link = 'not a link';

    $this->expectExceptionMessage('Invalid feed item link.');
    $this->invokeProtectedMethod('validateLink', [$link, '']);
  }

  /**
   * Test validate link.
   */
  public function testvalidateLinkBase(): void {
    $link = 'https://example.com';

    $this->expectExceptionMessage('Invalid feed item link base.');
    $this->invokeProtectedMethod('validateLink', [$link, 'https://another.com']);
  }

  /**
   * Test validate title.
   */
  public function testvalidateTitleEmpty(): void {
    $title = '';

    $this->expectExceptionMessage('Job found with empty title.');
    $this->invokeProtectedMethod('validateTitle', [$title]);
  }

  /**
   * Test validate title.
   */
  public function testvalidateTitleSpaces(): void {
    $title = '       ';

    $this->expectExceptionMessage('Job found with empty title.');
    $this->invokeProtectedMethod('validateTitle', [$title]);
  }

  /**
   * Test validate title.
   */
  public function testvalidateTitleTooShort(): void {
    $title = 'a';

    $this->expectExceptionMessage('Invalid title length.');
    $this->invokeProtectedMethod('validateTitle', [$title]);
  }

  /**
   * Test validate title.
   */
  public function testvalidateTitleTooLong(): void {
    $title = $this->random->sentences(100);

    $this->expectExceptionMessage('Invalid title length.');
    $this->invokeProtectedMethod('validateTitle', [$title]);
  }

  /**
   * Test validate title.
   */
  public function testvalidateTitleOk(): void {
    $title = $this->random->sentences(10);
    $this->assertEquals($title, $this->invokeProtectedMethod('validateTitle', [$title]));

    $title = 'This is the title';
    $this->assertEquals($title, $this->invokeProtectedMethod('validateTitle', ['<p>' . $title . '</p>']));

    $title = 'This is the title';
    $this->assertEquals($title, $this->invokeProtectedMethod('validateTitle', ['    <p>   ' . $title . '   </p>   ']));
  }

  /**
   * Test validate source.
   */
  public function testvalidateSourceEmpty(): void {
    $source = '';
    $source_id = 2865;

    $this->expectExceptionMessage('Job found with empty source.');
    $this->invokeProtectedMethod('validateSource', [$source, $source_id]);
  }

  /**
   * Test validate source.
   */
  public function testvalidateSourceNonNumeric(): void {
    $source = 'abcd';
    $source_id = 2865;

    $this->expectExceptionMessage('Job found with non numeric source.');
    $this->invokeProtectedMethod('validateSource', [$source, $source_id]);
  }

  /**
   * Test validate source.
   */
  public function testvalidateSourceDifferent(): void {
    $source = '666';
    $source_id = 2865;

    $this->expectExceptionMessage(strtr('Invalid job source: expected @source_id, got @source.', [
      '@source_id' => $source_id,
      '@source' => $source,
    ]));
    $this->invokeProtectedMethod('validateSource', [$source, $source_id]);
  }

  /**
   * Test validate source.
   */
  public function testvalidateSourceOk(): void {
    $source = '2865';
    $source_id = 2865;

    $this->assertEquals($source, $this->invokeProtectedMethod('validateSource', [$source, $source_id]));
  }

  /**
   * Test validate user.
   */
  public function testvalidateUserEmpty(): void {
    $uid = ' ';

    $this->expectExceptionMessage('User Id is not defined.');
    $this->invokeProtectedMethod('validateUser', [$uid]);
  }

  /**
   * Test validate user.
   */
  public function testvalidateUserNumeric(): void {
    $uid = 'abcd';

    $this->expectExceptionMessage('User Id is not numeric.');
    $this->invokeProtectedMethod('validateUser', [$uid]);
  }

  /**
   * Test validate user.
   */
  public function testvalidateUserId(): void {
    $uid = 1;

    $this->expectExceptionMessage('User Id is an admin.');
    $this->invokeProtectedMethod('validateUser', [$uid]);
  }

}
