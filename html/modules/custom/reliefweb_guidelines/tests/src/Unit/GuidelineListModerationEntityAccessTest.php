<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_guidelines\Unit;

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
use Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList;
use Drupal\reliefweb_guidelines\Services\GuidelineAccessChecker;
use Drupal\reliefweb_guidelines\Services\GuidelineListModeration;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests GuidelineListModeration entity access for role-scoped lists.
 */
#[CoversClass(GuidelineListModeration::class)]
#[Group('reliefweb_guidelines')]
class GuidelineListModerationEntityAccessTest extends UnitTestCase {

  /**
   * The moderation service under test.
   */
  protected GuidelineListModeration $moderation;

  /**
   * The access checker used by the moderation service.
   */
  protected GuidelineAccessChecker $accessChecker;

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

    $this->accessChecker = new GuidelineAccessChecker(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(Connection::class),
    );
    $this->moderation = new GuidelineListModeration(
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
      $this->accessChecker,
    );
  }

  /**
   * Test webmaster with view any guideline list can view a draft list.
   */
  public function testWebmasterCanViewDraftGuidelineList(): void {
    $account = $this->createAccountWithPermissions([
      'access editorial guidelines',
      'view any guideline list',
    ]);

    $list = $this->createGuidelineList('draft');

    $result = $this->moderation->entityAccess($list, 'view', $account);
    $this->assertTrue($result->isAllowed());
    $this->assertContains('user.permissions', $result->getCacheContexts());
    $this->assertContains('taxonomy_term:1', $result->getCacheTags());
  }

  /**
   * Test edit terms permission does not bypass view access for draft lists.
   */
  public function testEditTermsDoesNotGrantDraftListViewAccess(): void {
    $account = $this->createAccountWithPermissions([
      'access editorial guidelines',
      'edit terms in guideline_list',
    ]);

    $list = $this->createGuidelineList('draft');

    $result = $this->moderation->entityAccess($list, 'view', $account);
    $this->assertFalse($result->isAllowed());
  }

  /**
   * Test role-scoped user can view a published list matching their role.
   */
  public function testContributorCanViewContributorPublishedList(): void {
    $account = $this->createAccountWithPermissions([
      'access editorial guidelines',
      'view contributor guideline content',
    ]);

    $list = $this->createGuidelineList('published', 'contributor');

    $result = $this->moderation->entityAccess($list, 'view', $account);
    $this->assertTrue($result->isAllowed());
  }

  /**
   * Test user without access editorial guidelines is denied.
   */
  public function testUserWithoutEditorialAccessIsDenied(): void {
    $account = $this->createAccountWithPermissions([
      'view contributor guideline content',
    ]);

    $list = $this->createGuidelineList('published', 'contributor');

    $result = $this->moderation->entityAccess($list, 'view', $account);
    $this->assertFalse($result->isAllowed());
  }

  /**
   * Create a mock guideline list with a moderation status and role.
   *
   * @param string $status
   *   Moderation status.
   * @param string $role_id
   *   Role ID referenced by the list.
   *
   * @return \Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList
   *   Mock guideline list.
   */
  protected function createGuidelineList(string $status, string $role_id = 'contributor'): GuidelineList {
    $field = (object) [
      'target_id' => $role_id,
      'isEmpty' => static fn(): bool => FALSE,
    ];

    $list = $this->createMock(GuidelineList::class);
    $list->method('getModerationStatus')->willReturn($status);
    $list->method('get')->with('field_role')->willReturn($field);
    $list->method('getCacheTags')->willReturn(['taxonomy_term:1']);
    $list->method('getCacheContexts')->willReturn([]);
    $list->method('getCacheMaxAge')->willReturn(-1);
    return $list;
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
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
  }

}
