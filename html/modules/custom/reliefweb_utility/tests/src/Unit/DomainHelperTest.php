<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Helpers\DomainHelper;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests domain helper.
 */
#[CoversClass(DomainHelper::class)]
#[Group('reliefweb_utility')]
class DomainHelperTest extends UnitTestCase {

  /**
   * Test normalizeDomain normalizes various formats.
   */
  public function testNormalizeDomain(): void {
    // Test with @ prefix.
    $normalized = DomainHelper::normalizeDomain('@example.com');
    $this->assertEquals('example.com', $normalized);

    // Test with uppercase.
    $normalized = DomainHelper::normalizeDomain('EXAMPLE.COM');
    $this->assertEquals('example.com', $normalized);

    // Test with whitespace.
    $normalized = DomainHelper::normalizeDomain('  example.com  ');
    $this->assertEquals('example.com', $normalized);

    // Test with @ prefix and uppercase and whitespace.
    $normalized = DomainHelper::normalizeDomain('  @EXAMPLE.COM  ');
    $this->assertEquals('example.com', $normalized);

    // Test normal domain.
    $normalized = DomainHelper::normalizeDomain('example.com');
    $this->assertEquals('example.com', $normalized);

    // Test with remove_at = FALSE.
    $normalized = DomainHelper::normalizeDomain('@example.com', FALSE);
    $this->assertEquals('@example.com', $normalized);

    // Test with multiple @ symbols (should all be removed).
    $normalized = DomainHelper::normalizeDomain('@@example.com');
    $this->assertEquals('example.com', $normalized);

    // Test with @ in the middle (should not be removed).
    $normalized = DomainHelper::normalizeDomain('user@example.com');
    $this->assertEquals('user@example.com', $normalized);

    // Test empty string.
    $normalized = DomainHelper::normalizeDomain('');
    $this->assertEquals('', $normalized);

    // Test with only whitespace.
    $normalized = DomainHelper::normalizeDomain('   ');
    $this->assertEquals('', $normalized);

    // Test with only @ symbol.
    $normalized = DomainHelper::normalizeDomain('@');
    $this->assertEquals('', $normalized);

    // Test with subdomain.
    $normalized = DomainHelper::normalizeDomain('SUB.EXAMPLE.COM');
    $this->assertEquals('sub.example.com', $normalized);
  }

  /**
   * Test validateDomain validates domains correctly.
   */
  public function testValidateDomain(): void {
    // Test valid domain.
    $this->assertTrue(DomainHelper::validateDomain('example.com'));

    // Test valid domain with subdomain.
    $this->assertTrue(DomainHelper::validateDomain('sub.example.com'));

    // Test empty domain.
    $this->assertFalse(DomainHelper::validateDomain(''));

    // Test domain without TLD (with check_tld = TRUE).
    $this->assertFalse(DomainHelper::validateDomain('example'));

    // Test domain without TLD (with check_tld = FALSE).
    $this->assertTrue(DomainHelper::validateDomain('example', FALSE));

    // Test invalid domain with spaces.
    $this->assertFalse(DomainHelper::validateDomain('example .com'));

    // Test invalid domain with special characters.
    $this->assertFalse(DomainHelper::validateDomain('example@.com'));

    // Test valid internationalized domain.
    $this->assertTrue(DomainHelper::validateDomain('münchen.de'));

    // Test domain with port (should fail).
    $this->assertFalse(DomainHelper::validateDomain('example.com:80'));

    // Test domain with protocol (should fail).
    $this->assertFalse(DomainHelper::validateDomain('http://example.com'));

    // Test very long domain.
    $long_domain = str_repeat('a', 250) . '.com';
    $this->assertFalse(DomainHelper::validateDomain($long_domain));
  }

  /**
   * Test extractDomainFromEmail extracts and normalizes domains correctly.
   */
  public function testExtractDomainFromEmail(): void {
    // Test with valid email.
    $domain = DomainHelper::extractDomainFromEmail('test@example.com');
    $this->assertEquals('example.com', $domain);

    // Test with uppercase email.
    $domain = DomainHelper::extractDomainFromEmail('TEST@EXAMPLE.COM');
    $this->assertEquals('example.com', $domain);

    // Test with subdomain.
    $domain = DomainHelper::extractDomainFromEmail('user@mail.example.com');
    $this->assertEquals('mail.example.com', $domain);

    // Test with email containing multiple @ symbols.
    $domain = DomainHelper::extractDomainFromEmail('test@sub@example.com');
    $this->assertEquals('sub@example.com', $domain);

    // Test with empty email.
    $domain = DomainHelper::extractDomainFromEmail('');
    $this->assertNull($domain);

    // Test with email without @.
    $domain = DomainHelper::extractDomainFromEmail('invalid-email');
    $this->assertNull($domain);

    // Test with only @ symbol.
    $domain = DomainHelper::extractDomainFromEmail('@');
    $this->assertEquals('', $domain);

    // Test with @ at the start.
    $domain = DomainHelper::extractDomainFromEmail('@example.com');
    $this->assertEquals('example.com', $domain);

    // Test with whitespace in email.
    $domain = DomainHelper::extractDomainFromEmail('  test@example.com  ');
    $this->assertEquals('example.com', $domain);

    // Test with internationalized domain.
    $domain = DomainHelper::extractDomainFromEmail('test@münchen.de');
    $this->assertEquals('münchen.de', $domain);
  }

  /**
   * Test extractDomainFromUser extracts domain from user email.
   */
  public function testExtractDomainFromUser(): void {
    // Test with valid user email.
    $user = $this->createMock(UserInterface::class);
    $user->expects($this->once())
      ->method('getEmail')
      ->willReturn('test@example.com');
    $domain = DomainHelper::extractDomainFromUser($user);
    $this->assertEquals('example.com', $domain);

    // Test with empty email.
    $user = $this->createMock(UserInterface::class);
    $user->expects($this->once())
      ->method('getEmail')
      ->willReturn('');
    $domain = DomainHelper::extractDomainFromUser($user);
    $this->assertNull($domain);

    // Test with NULL email.
    $user = $this->createMock(UserInterface::class);
    $user->expects($this->once())
      ->method('getEmail')
      ->willReturn(NULL);
    $domain = DomainHelper::extractDomainFromUser($user);
    $this->assertNull($domain);

    // Test with uppercase email.
    $user = $this->createMock(UserInterface::class);
    $user->expects($this->once())
      ->method('getEmail')
      ->willReturn('TEST@EXAMPLE.COM');
    $domain = DomainHelper::extractDomainFromUser($user);
    $this->assertEquals('example.com', $domain);

    // Test with subdomain.
    $user = $this->createMock(UserInterface::class);
    $user->expects($this->once())
      ->method('getEmail')
      ->willReturn('user@mail.example.com');
    $domain = DomainHelper::extractDomainFromUser($user);
    $this->assertEquals('mail.example.com', $domain);
  }

}
