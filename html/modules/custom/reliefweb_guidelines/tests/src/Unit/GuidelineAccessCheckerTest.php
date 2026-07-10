<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_guidelines\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\reliefweb_guidelines\Entity\Node\Guideline;
use Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList;
use Drupal\reliefweb_guidelines\GuidelinePermissions;
use Drupal\reliefweb_guidelines\Services\GuidelineAccessChecker;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the GuidelineAccessChecker service.
 */
#[CoversClass(GuidelineAccessChecker::class)]
#[Group('reliefweb_guidelines')]
class GuidelineAccessCheckerTest extends UnitTestCase {

  /**
   * The access checker under test.
   */
  protected GuidelineAccessChecker $accessChecker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->accessChecker = new GuidelineAccessChecker(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(Connection::class),
    );
  }

  /**
   * Test guideline list access by role permission.
   */
  public function testIsGuidelineListAccessible(): void {
    $contributor_list = $this->createGuidelineList('contributor');
    $editor_list = $this->createGuidelineList('editor');

    $contributor = $this->createAccountWithPermissions([
      'view contributor guideline content',
    ]);

    $editor = $this->createAccountWithPermissions([
      'view editor guideline content',
    ]);

    $webmaster = $this->createAccountWithPermissions([
      'view any guideline list',
    ]);

    $this->assertTrue($this->accessChecker->isGuidelineListAccessible($contributor_list, $contributor));
    $this->assertFalse($this->accessChecker->isGuidelineListAccessible($editor_list, $contributor));

    $this->assertTrue($this->accessChecker->isGuidelineListAccessible($editor_list, $editor));
    $this->assertFalse($this->accessChecker->isGuidelineListAccessible($contributor_list, $editor));

    $this->assertTrue($this->accessChecker->isGuidelineListAccessible($contributor_list, $webmaster));
    $this->assertTrue($this->accessChecker->isGuidelineListAccessible($editor_list, $webmaster));
  }

  /**
   * Test view any guideline content does not grant guideline list access.
   */
  public function testViewAnyGuidelineContentDoesNotGrantListAccess(): void {
    $editor_list = $this->createGuidelineList('editor');

    $account = $this->createAccountWithPermissions([
      'view any guideline content',
    ]);

    $this->assertFalse($this->accessChecker->isGuidelineListAccessible($editor_list, $account));
  }

  /**
   * Test guideline node access delegates to the parent list.
   */
  public function testIsGuidelineAccessible(): void {
    $contributor_list = $this->createGuidelineList('contributor');
    $editor_list = $this->createGuidelineList('editor');

    $contributor_guideline = $this->createGuideline($contributor_list);
    $editor_guideline = $this->createGuideline($editor_list);
    $orphan_guideline = $this->createGuideline(NULL);

    $contributor = $this->createAccountWithPermissions([
      'view contributor guideline content',
    ]);

    $this->assertTrue($this->accessChecker->isGuidelineAccessible($contributor_guideline, $contributor));
    $this->assertFalse($this->accessChecker->isGuidelineAccessible($editor_guideline, $contributor));
    $this->assertFalse($this->accessChecker->isGuidelineAccessible($orphan_guideline, $contributor));
  }

  /**
   * Test permission ID helper.
   */
  public function testGetViewPermissionId(): void {
    $this->assertSame('view editor guideline content', GuidelinePermissions::getViewPermissionId('editor'));
    $this->assertSame('view contributor guideline content', GuidelinePermissions::getViewPermissionId('contributor'));
    $this->assertSame('view any guideline list', GuidelinePermissions::getViewAnyListPermissionId());
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
    $account->method('getRoles')->willReturn(['authenticated']);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
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
   * Create a mock guideline with a parent list.
   *
   * @param \Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList|null $list
   *   Parent guideline list.
   *
   * @return \Drupal\reliefweb_guidelines\Entity\Node\Guideline
   *   Mock guideline.
   */
  protected function createGuideline(?GuidelineList $list): Guideline {
    $guideline = $this->createMock(Guideline::class);
    $guideline->method('getGuidelineList')->willReturn($list);
    return $guideline;
  }

}
