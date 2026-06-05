<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_users\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\reliefweb_users\Service\InactiveUserDeletionService;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests InactiveUserDeletionService.
 */
#[CoversClass(InactiveUserDeletionService::class)]
#[Group('reliefweb_users')]
class InactiveUserDeletionServiceTest extends UnitTestCase {

  /**
   * Effective activity uses last access when the user has visited the site.
   */
  public function testGetEffectiveActivityTimestampUsesAccess(): void {
    $service = $this->createService();
    $this->assertSame(1_700_000_000, $service->getEffectiveActivityTimestamp(1_700_000_000, 1_600_000_000));
  }

  /**
   * Effective activity falls back to account creation for never-accessed users.
   */
  public function testGetEffectiveActivityTimestampUsesCreatedWhenNeverAccessed(): void {
    $service = $this->createService();
    $this->assertSame(1_600_000_000, $service->getEffectiveActivityTimestamp(0, 1_600_000_000));
  }

  /**
   * Never-accessed users are labeled with their account creation date.
   */
  public function testFormatActivityForLogNeverAccessed(): void {
    $service = $this->createService();
    $created = 1_600_000_000;
    $this->assertSame(
      'never accessed (created ' . date('Y-m-d H:i:s', $created) . ')',
      $service->formatActivityForLog(0, $created),
    );
  }

  /**
   * Accessed users are labeled with their last access timestamp.
   */
  public function testFormatActivityForLogWithAccess(): void {
    $service = $this->createService();
    $access = 1_700_000_000;
    $this->assertSame(
      date('Y-m-d H:i:s', $access),
      $service->formatActivityForLog($access, 1_600_000_000),
    );
  }

  /**
   * Cutoff timestamp subtracts the requested number of weeks from request time.
   */
  public function testGetCutoffTimestamp(): void {
    $service = $this->createService(requestTime: 1_700_000_000);
    $expected = 1_700_000_000 - (26 * 7 * 86400);
    $this->assertSame($expected, $service->getCutoffTimestamp(26));
  }

  /**
   * System and anonymous users are never eligible.
   */
  public function testIsEligibleRejectsSystemUsers(): void {
    $service = $this->createService();
    $this->assertFalse($service->isEligible(0, 0));
    $this->assertFalse($service->isEligible(1, 0));
    $this->assertFalse($service->isEligible(2, 0));
  }

  /**
   * DeleteUser() returns FALSE for ineligible accounts without loading storage.
   */
  public function testDeleteUserReturnsFalseForSystemUser(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');

    $service = $this->createService(entityTypeManager: $entity_type_manager);
    $this->assertFalse($service->deleteUser(2, 0));
  }

  /**
   * DeleteUser() returns FALSE when the user entity no longer exists.
   */
  public function testDeleteUserReturnsFalseWhenUserMissing(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with(100)
      ->willReturn(NULL);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->once())
      ->method('getStorage')
      ->with('user')
      ->willReturn($storage);

    $service = $this->createPartialService(
      entityTypeManager: $entity_type_manager,
      eligible: TRUE,
    );

    $this->assertFalse($service->deleteUser(100, 0));
  }

  /**
   * DeleteUser() deletes eligible users and returns TRUE.
   */
  public function testDeleteUserDeletesEligibleUser(): void {
    $user = $this->createMock(UserInterface::class);
    $user->expects($this->once())->method('getEmail')->willReturn('inactive@example.com');
    $user->expects($this->once())->method('getLastAccessedTime')->willReturn(1_000_000);
    $user->expects($this->once())->method('getCreatedTime')->willReturn(900_000);
    $user->expects($this->once())->method('delete');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with(100)
      ->willReturn($user);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->once())
      ->method('getStorage')
      ->with('user')
      ->willReturn($storage);

    $service = $this->createPartialService(
      entityTypeManager: $entity_type_manager,
      eligible: TRUE,
    );

    $this->assertTrue($service->deleteUser(100, 0));
  }

  /**
   * DeleteUser() logs creation date for never-accessed users.
   */
  public function testDeleteUserLogsNeverAccessedActivity(): void {
    $user = $this->createMock(UserInterface::class);
    $user->expects($this->once())->method('getEmail')->willReturn('never@example.com');
    $user->expects($this->once())->method('getLastAccessedTime')->willReturn(0);
    $user->expects($this->once())->method('getCreatedTime')->willReturn(1_600_000_000);
    $user->expects($this->once())->method('delete');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with(100)
      ->willReturn($user);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->once())
      ->method('getStorage')
      ->with('user')
      ->willReturn($storage);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $created = 1_600_000_000;
    $logger->expects($this->once())
      ->method('info')
      ->with(
        'Deleting inactive user account @uid (@mail), last activity @activity.',
        [
          '@uid' => 100,
          '@mail' => 'never@example.com',
          '@activity' => 'never accessed (created ' . date('Y-m-d H:i:s', $created) . ')',
        ],
      );

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    $service = $this->getMockBuilder(InactiveUserDeletionService::class)
      ->setConstructorArgs([
        $this->createMock(Connection::class),
        $entity_type_manager,
        $this->createTimeMock(),
        $logger_factory,
      ])
      ->onlyMethods(['isEligible'])
      ->getMock();

    $service->expects($this->once())
      ->method('isEligible')
      ->with(100, 0)
      ->willReturn(TRUE);

    $this->assertTrue($service->deleteUser(100, 0));
  }

  /**
   * Create a service instance with mocked dependencies.
   */
  protected function createService(
    int $requestTime = 1_700_000_000,
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ): InactiveUserDeletionService {
    return new InactiveUserDeletionService(
      $this->createMock(Connection::class),
      $entityTypeManager ?? $this->createMock(EntityTypeManagerInterface::class),
      $this->createTimeMock($requestTime),
      $this->createLoggerFactoryMock(),
    );
  }

  /**
   * Create a partial mock with controlled isEligible() behavior.
   */
  protected function createPartialService(
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    bool $eligible = FALSE,
  ): InactiveUserDeletionService {
    $service = $this->getMockBuilder(InactiveUserDeletionService::class)
      ->setConstructorArgs([
        $this->createMock(Connection::class),
        $entityTypeManager ?? $this->createMock(EntityTypeManagerInterface::class),
        $this->createTimeMock(),
        $this->createLoggerFactoryMock(),
      ])
      ->onlyMethods(['isEligible'])
      ->getMock();

    $service->expects($this->once())
      ->method('isEligible')
      ->with(100, 0)
      ->willReturn($eligible);

    return $service;
  }

  /**
   * Create a time service mock.
   */
  protected function createTimeMock(int $requestTime = 1_700_000_000): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($requestTime);
    return $time;
  }

  /**
   * Create a logger factory mock.
   */
  protected function createLoggerFactoryMock(): LoggerChannelFactoryInterface {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);
    return $logger_factory;
  }

}
