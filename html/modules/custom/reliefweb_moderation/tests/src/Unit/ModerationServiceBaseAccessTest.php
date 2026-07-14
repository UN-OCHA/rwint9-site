<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_entities\Entity\Country;
use Drupal\reliefweb_entities\Entity\Report;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests ModerationServiceBase access cache metadata.
 */
#[CoversClass(ModerationServiceBase::class)]
#[Group('reliefweb_moderation')]
class ModerationServiceBaseAccessTest extends UnitTestCase {

  /**
   * Node moderation service under test.
   */
  protected ModerationServiceBase $nodeModeration;

  /**
   * Taxonomy moderation service under test.
   */
  protected ModerationServiceBase $termModeration;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);

    $this->nodeModeration = $this->createModerationService('report', 'node');
    $this->termModeration = $this->createModerationService('country', 'taxonomy_term');
  }

  /**
   * Test entity access includes permission and entity cache metadata.
   */
  public function testEntityAccessAddsPermissionAndEntityCacheMetadata(): void {
    $account = $this->createAccountWithPermissions(['view any content']);
    $entity = $this->createReportMock('published', ['node:42']);

    $result = $this->nodeModeration->entityAccess($entity, 'view', $account);

    $this->assertTrue($result->isAllowed());
    $this->assertContains('user.permissions', $result->getCacheContexts());
    $this->assertContains('node:42', $result->getCacheTags());
  }

  /**
   * Test taxonomy term entity access includes the same cache metadata.
   */
  public function testTaxonomyTermEntityAccessAddsCacheMetadata(): void {
    $account = $this->createAccountWithPermissions(['access content']);
    $entity = $this->createCountryMock('published', ['taxonomy_term:7']);

    $result = $this->termModeration->entityAccess($entity, 'view', $account);

    $this->assertTrue($result->isAllowed());
    $this->assertContains('user.permissions', $result->getCacheContexts());
    $this->assertContains('taxonomy_term:7', $result->getCacheTags());
  }

  /**
   * Test create access includes only permission cache metadata.
   */
  public function testEntityCreateAccessAddsPermissionCacheMetadata(): void {
    $account = $this->createAccountWithPermissions(['create report content']);

    $result = $this->nodeModeration->entityCreateAccess($account);

    $this->assertTrue($result->isAllowed());
    $this->assertContains('user.permissions', $result->getCacheContexts());
    $this->assertSame([], $result->getCacheTags());
  }

  /**
   * Test unsupported operations stay neutral without permission contexts.
   */
  public function testUnsupportedOperationRemainsNeutral(): void {
    $account = $this->createAccountWithPermissions(['view any content']);
    $entity = $this->createReportMock('published', ['node:42']);

    $result = $this->nodeModeration->entityAccess($entity, 'unsupported', $account);

    $this->assertTrue($result->isNeutral());
    $this->assertSame([], $result->getCacheContexts());
  }

  /**
   * Create a concrete moderation service for testing.
   *
   * @param string $bundle
   *   Bundle ID.
   * @param string $entity_type_id
   *   Entity type ID.
   *
   * @return \Drupal\reliefweb_moderation\ModerationServiceBase
   *   Service instance.
   */
  protected function createModerationService(string $bundle, string $entity_type_id): ModerationServiceBase {
    return new class(
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(PagerManagerInterface::class),
      $this->createMock(PagerParametersInterface::class),
      $this->createMock(RequestStack::class),
      $this->createMock(TranslationInterface::class),
      $this->createMock(UserPostingRightsManagerInterface::class),
      $bundle,
      $entity_type_id,
    ) extends ModerationServiceBase {

      /**
       * {@inheritdoc}
       */
      public function __construct(
        $current_user,
        $database,
        $date_formatter,
        $entity_field_manager,
        $entity_type_manager,
        $pager_manager,
        $pager_parameters,
        $request_stack,
        $string_translation,
        $user_posting_rights_manager,
        protected string $testBundle,
        protected string $testEntityTypeId,
      ) {
        parent::__construct(
          $current_user,
          $database,
          $date_formatter,
          $entity_field_manager,
          $entity_type_manager,
          $pager_manager,
          $pager_parameters,
          $request_stack,
          $string_translation,
          $user_posting_rights_manager,
        );
      }

      /**
       * {@inheritdoc}
       */
      public function getBundle() {
        return $this->testBundle;
      }

      /**
       * {@inheritdoc}
       */
      public function getEntityTypeId() {
        return $this->testEntityTypeId;
      }

      /**
       * {@inheritdoc}
       */
      public function getTitle() {
        return 'Test';
      }

      /**
       * {@inheritdoc}
       */
      public function getHeaders() {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getRows(array $results) {
        return [];
      }

    };
  }

  /**
   * Create a mock report entity.
   *
   * @param string $status
   *   Moderation status.
   * @param string[] $cache_tags
   *   Cache tags returned by the entity.
   *
   * @return \Drupal\reliefweb_moderation\EntityModeratedInterface
   *   Mock entity.
   */
  protected function createReportMock(string $status, array $cache_tags): EntityModeratedInterface {
    $entity = $this->createMock(Report::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('report');
    $entity->method('getModerationStatus')->willReturn($status);
    $entity->method('getCacheTags')->willReturn($cache_tags);
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    return $entity;
  }

  /**
   * Create a mock country term.
   *
   * @param string $status
   *   Moderation status.
   * @param string[] $cache_tags
   *   Cache tags returned by the entity.
   *
   * @return \Drupal\reliefweb_moderation\EntityModeratedInterface
   *   Mock entity.
   */
  protected function createCountryMock(string $status, array $cache_tags): EntityModeratedInterface {
    $entity = $this->createMock(Country::class);
    $entity->method('getEntityTypeId')->willReturn('taxonomy_term');
    $entity->method('bundle')->willReturn('country');
    $entity->method('getModerationStatus')->willReturn($status);
    $entity->method('getCacheTags')->willReturn($cache_tags);
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    return $entity;
  }

  /**
   * Create a mock account with specific permissions.
   *
   * @param string[] $permissions
   *   Granted permissions.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   Mock account.
   */
  protected function createAccountWithPermissions(array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(2);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
  }

}
