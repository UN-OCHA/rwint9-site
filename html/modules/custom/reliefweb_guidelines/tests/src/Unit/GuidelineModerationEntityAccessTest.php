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
use Drupal\reliefweb_guidelines\Entity\Node\Guideline;
use Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList;
use Drupal\reliefweb_guidelines\Services\GuidelineAccessChecker;
use Drupal\reliefweb_guidelines\Services\GuidelineModeration;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests GuidelineModeration entity access for role-scoped guidelines.
 */
#[CoversClass(GuidelineModeration::class)]
#[Group('reliefweb_guidelines')]
class GuidelineModerationEntityAccessTest extends UnitTestCase {

  /**
   * The moderation service under test.
   */
  protected GuidelineModeration $moderation;

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
    $this->moderation = new GuidelineModeration(
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
   * Test contributor cannot view editor guidelines via entity access.
   */
  public function testContributorCannotViewEditorGuideline(): void {
    $account = $this->createAccountWithPermissions([
      'access editorial guidelines',
      'view contributor guideline content',
    ]);

    $guideline = $this->createPublishedGuideline($this->createGuidelineList('editor'));

    $result = $this->moderation->entityAccess($guideline, 'view', $account);
    $this->assertFalse($result->isAllowed());
  }

  /**
   * Test contributor can view contributor guidelines via entity access.
   */
  public function testContributorCanViewContributorGuideline(): void {
    $account = $this->createAccountWithPermissions([
      'access editorial guidelines',
      'view contributor guideline content',
    ]);

    $guideline = $this->createPublishedGuideline($this->createGuidelineList('contributor'));

    $result = $this->moderation->entityAccess($guideline, 'view', $account);
    $this->assertTrue($result->isAllowed());
  }

  /**
   * Test webmaster bypasses role checks.
   */
  public function testWebmasterCanViewAnyGuideline(): void {
    $account = $this->createAccountWithPermissions([
      'access editorial guidelines',
      'view any guideline content',
    ]);

    $guideline = $this->createPublishedGuideline($this->createGuidelineList('editor'));

    $result = $this->moderation->entityAccess($guideline, 'view', $account);
    $this->assertTrue($result->isAllowed());
  }

  /**
   * Create a mock guideline list with a role target.
   *
   * @param string $role_id
   *   Role ID referenced by the list.
   *
   * @return \Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList
   *   Mock guideline list.
   */
  protected function createGuidelineList(string $role_id): GuidelineList {
    $field = (object) [
      'target_id' => $role_id,
      'isEmpty' => static fn(): bool => FALSE,
    ];

    $list = $this->createMock(GuidelineList::class);
    $list->method('get')->with('field_role')->willReturn($field);
    return $list;
  }

  /**
   * Create a mock published guideline with a parent list.
   *
   * @param \Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList|null $list
   *   Parent guideline list.
   *
   * @return \Drupal\reliefweb_guidelines\Entity\Node\Guideline
   *   Mock guideline.
   */
  protected function createPublishedGuideline(?GuidelineList $list): Guideline {
    $guideline = $this->createMock(Guideline::class);
    $guideline->method('getModerationStatus')->willReturn('published');
    $guideline->method('getGuidelineList')->willReturn($list);
    return $guideline;
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
