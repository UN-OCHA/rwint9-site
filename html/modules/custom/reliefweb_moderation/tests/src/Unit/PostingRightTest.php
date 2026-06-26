<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\Unit;

use Drupal\reliefweb_moderation\Enum\PostingRight;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the PostingRight enum.
 */
#[CoversClass(PostingRight::class)]
#[Group('reliefweb_moderation')]
class PostingRightTest extends UnitTestCase {

  /**
   * Test value and machine name round-trip.
   */
  public function testValueAndMachineNameRoundTrip(): void {
    foreach (PostingRight::cases() as $case) {
      $this->assertSame($case, PostingRight::fromValue($case->value));
      $this->assertSame($case, PostingRight::fromMachineName($case->machineName()));
      $this->assertSame($case->value, PostingRight::fromMachineName($case->machineName())->value);
    }
  }

  /**
   * Test tryFromMachineName handles nullable and invalid values.
   */
  public function testTryFromMachineName(): void {
    $this->assertNull(PostingRight::tryFromMachineName(NULL));
    $this->assertNull(PostingRight::tryFromMachineName(''));
    $this->assertNull(PostingRight::tryFromMachineName('invalid'));
    $this->assertSame(PostingRight::Allowed, PostingRight::tryFromMachineName('allowed'));
  }

  /**
   * Test invalid machine names default to unverified.
   */
  public function testFromMachineNameInvalidDefaultsToUnverified(): void {
    $this->assertSame(PostingRight::Unverified, PostingRight::fromMachineName('invalid'));
    $this->assertSame(PostingRight::Unverified, PostingRight::fromMachineName(NULL));
  }

  /**
   * Test tryFromValue handles nullable and string values.
   */
  public function testTryFromValue(): void {
    $this->assertNull(PostingRight::tryFromValue(NULL));
    $this->assertSame(PostingRight::Allowed, PostingRight::tryFromValue('2'));
    $this->assertSame(PostingRight::Blocked, PostingRight::fromValue('1'));
    $this->assertSame(PostingRight::Unverified, PostingRight::fromValue('invalid'));
  }

  /**
   * Test options and labeled cases key order.
   */
  public function testOptionsAndLabeledCases(): void {
    $this->assertSame([0, 1, 2, 3], PostingRight::values());
    $this->assertSame(array_keys(PostingRight::options()), PostingRight::values());
    $this->assertSame(array_keys(PostingRight::labeledCases()), PostingRight::values());
    $this->assertSame('unverified', PostingRight::labeledCases()[0]['type']);
    $this->assertSame('trusted', PostingRight::labeledCases()[3]['type']);
  }

  /**
   * Test predicate helpers.
   */
  public function testPredicates(): void {
    $this->assertTrue(PostingRight::Blocked->isBlocked());
    $this->assertFalse(PostingRight::Allowed->isBlocked());
    $this->assertTrue(PostingRight::Allowed->isAllowedOrTrusted());
    $this->assertTrue(PostingRight::Trusted->isAllowedOrTrusted());
    $this->assertFalse(PostingRight::Unverified->isAllowedOrTrusted());
    $this->assertTrue(PostingRight::Trusted->isTrusted());
  }

}
