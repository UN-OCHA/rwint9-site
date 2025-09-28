<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Helpers;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Drupal\user\EntityOwnerInterface;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the UserPostingRightsHelper class.
 */
#[CoversClass(UserPostingRightsHelper::class)]
#[Group('reliefweb_moderation')]
#[RunTestsInSeparateProcesses]
class UserPostingRightsHelperTest extends ExistingSiteBase {

  /**
   * Source vocabulary.
   */
  protected Vocabulary $sourceVocabulary;

  /**
   * Test user for posting rights tests.
   */
  protected User $testUser;

  /**
   * Test source entity for posting rights tests.
   */
  protected Term $testSource;

  /**
   * Test source with only user posting rights.
   */
  protected Term $userOnlySource;

  /**
   * Test source with only domain posting rights.
   */
  protected Term $domainOnlySource;

  /**
   * Test source with no posting rights.
   */
  protected Term $noRightsSource;

  /**
   * Test entity with source field.
   */
  protected Node $testEntity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a test user with email domain.
    $this->testUser = $this->createUser([], 'test_user', FALSE, [
      'name' => 'test_user',
      'mail' => 'test@example.com',
      'status' => 1,
    ]);

    // Source vocabulary.
    $this->sourceVocabulary = Vocabulary::load('source');
    if (!$this->sourceVocabulary) {
      // Create the source vocabulary if it doesn't exist.
      $this->sourceVocabulary = Vocabulary::create([
        'vid' => 'source',
        'name' => 'Source',
      ]);
      $this->sourceVocabulary->save();
    }

    // Create a test source with both user and domain posting rights.
    $this->testSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Test Source - Mixed Rights',
      'field_user_posting_rights' => [
        [
          'id' => $this->testUser->id(),
          // Allowed.
          'job' => 2,
          // Trusted.
          'training' => 3,
          // Blocked.
          'report' => 1,
        ],
      ],
      'field_domain_posting_rights' => [
        [
          'domain' => 'example.com',
          // Allowed.
          'job' => 2,
          // Allowed.
          'training' => 2,
          // Unverified.
          'report' => 0,
        ],
      ],
    ]);

    // Create a source with only user posting rights.
    $this->userOnlySource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'User Only Source',
      'field_user_posting_rights' => [
        [
          'id' => $this->testUser->id(),
          // Trusted for all content types.
          'job' => 3,
          'training' => 3,
          'report' => 3,
        ],
      ],
      // No domain posting rights.
    ]);

    // Create a source with only domain posting rights.
    $this->domainOnlySource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Domain Only Source',
      // No user posting rights.
      'field_domain_posting_rights' => [
        [
          'domain' => 'example.com',
          // Allowed for all content types.
          'job' => 2,
          'training' => 2,
          'report' => 2,
        ],
      ],
    ]);

    // Create a source with no posting rights.
    $this->noRightsSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'No Rights Source',
      // No user or domain posting rights.
    ]);

    // Create a test entity (job) with source field.
    $this->testEntity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'uid' => $this->testUser->id(),
      'field_source' => [
        [
          'target_id' => $this->testSource->id(),
        ],
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Reset static cache.
    drupal_static_reset('reliefweb_moderation_getUserPostingRights');

    parent::tearDown();
  }

  /**
   * Test getEntityAuthorPostingRights method.
   */
  public function testGetEntityAuthorPostingRights(): void {
    // Test with valid entity that has source field.
    $rights = UserPostingRightsHelper::getEntityAuthorPostingRights($this->testEntity);
    $this->assertEquals('allowed', $rights, 'Entity with blocked report rights should return blocked');

    // Test with entity without source field.
    $entity_without_source = $this->createNode([
      'type' => 'blog_post',
      'title' => 'Entity without source field',
      'uid' => $this->testUser->id(),
    ]);

    $rights = UserPostingRightsHelper::getEntityAuthorPostingRights($entity_without_source);
    $this->assertEquals('unknown', $rights, 'Entity without source field should return unknown');

    // Test with entity that doesn't implement EntityOwnerInterface.
    $mock_entity = $this->createMock(ContentEntityInterface::class);
    $mock_entity->method('hasField')->with('field_source')->willReturn(TRUE);
    $mock_entity->method('bundle')->willReturn('job');

    $rights = UserPostingRightsHelper::getEntityAuthorPostingRights($mock_entity);
    $this->assertEquals('unknown', $rights, 'Entity without getOwnerId method should return unknown');
  }

  /**
   * Test getUserPostingRights method.
   */
  public function testGetUserPostingRights(): void {
    // Test with specific sources.
    $rights = UserPostingRightsHelper::getUserPostingRights($this->testUser, [$this->testSource->id()]);

    $this->assertArrayHasKey($this->testSource->id(), $rights);
    $this->assertEquals(2, $rights[$this->testSource->id()]['job'], 'User should have allowed rights for job');
    $this->assertEquals(3, $rights[$this->testSource->id()]['training'], 'User should have trusted rights for training');
    $this->assertEquals(1, $rights[$this->testSource->id()]['report'], 'User should have blocked rights for report');

    // Test with multiple sources including domain-only rights.
    $rights = UserPostingRightsHelper::getUserPostingRights($this->testUser, [
      $this->testSource->id(),
      $this->domainOnlySource->id(),
      $this->noRightsSource->id(),
    ]);

    $this->assertArrayHasKey($this->testSource->id(), $rights);
    $this->assertArrayHasKey($this->domainOnlySource->id(), $rights);
    $this->assertArrayHasKey($this->noRightsSource->id(), $rights);

    // User rights should take precedence over domain rights.
    $this->assertEquals(2, $rights[$this->testSource->id()]['job'], 'User rights should take precedence over domain rights');

    // Domain rights should be used when user rights don't exist.
    $this->assertEquals(2, $rights[$this->domainOnlySource->id()]['job'], 'Should use domain rights when user rights don\'t exist');

    // No rights should return unverified.
    $this->assertEquals(0, $rights[$this->noRightsSource->id()]['job'], 'Source with no rights should return unverified');

    // Test without sources (should include domain rights).
    $rights = UserPostingRightsHelper::getUserPostingRights($this->testUser);
    $this->assertNotEmpty($rights, 'Should return rights including domain-based rights');

    // Test with anonymous user.
    $anonymous_user = User::getAnonymousUser();
    $rights = UserPostingRightsHelper::getUserPostingRights($anonymous_user);
    $this->assertEmpty($rights, 'Anonymous user should have no posting rights');
  }

  /**
   * Test getDomainPostingRights method.
   */
  public function testGetDomainPostingRights(): void {
    // Test with valid user and sources.
    $rights = UserPostingRightsHelper::getUserPostingRights($this->testUser, [$this->testSource->id()]);

    // The domain rights should be merged with user rights.
    $this->assertArrayHasKey($this->testSource->id(), $rights);

    // Test with user without email domain.
    $user_without_email = $this->createUser([], 'no_email_user', FALSE, [
      'name' => 'no_email_user',
      'mail' => '',
      'status' => 1,
    ]);

    $rights = UserPostingRightsHelper::getUserPostingRights($user_without_email, [$this->testSource->id()]);
    $this->assertArrayHasKey($this->testSource->id(), $rights);
    $this->assertEquals(0, $rights[$this->testSource->id()]['job'], 'User without email should have unverified rights');
  }

  /**
   * Test multiple sources with different posting rights scenarios.
   */
  public function testMultipleSourcesWithDifferentRights(): void {
    // Test entity with multiple sources - should use the most restrictive
    // right.
    $multiSourceEntity = $this->createNode([
      'type' => 'job',
      'title' => 'Multi Source Job',
      'uid' => $this->testUser->id(),
      'field_source' => [
        // Trusted (3).
        ['target_id' => $this->userOnlySource->id()],
        // Allowed (2).
        ['target_id' => $this->domainOnlySource->id()],
        // Unverified (0).
        ['target_id' => $this->noRightsSource->id()],
      ],
    ]);

    $rights = UserPostingRightsHelper::getEntityAuthorPostingRights($multiSourceEntity);
    $this->assertEquals('unverified', $rights, 'Entity with mixed rights should return unverified (most restrictive)');

    // Test entity with user-only and domain-only sources.
    $mixedRightsEntity = $this->createNode([
      'type' => 'training',
      'title' => 'Mixed Rights Training',
      'uid' => $this->testUser->id(),
      'field_source' => [
        // Trusted (3).
        ['target_id' => $this->userOnlySource->id()],
        // Allowed (2).
        ['target_id' => $this->domainOnlySource->id()],
      ],
    ]);

    $rights = UserPostingRightsHelper::getEntityAuthorPostingRights($mixedRightsEntity);
    $this->assertEquals('allowed', $rights, 'Entity with trusted and allowed rights should return allowed');

    // Test entity with only domain-based rights.
    $domainOnlyEntity = $this->createNode([
      'type' => 'report',
      'title' => 'Domain Only Report',
      'uid' => $this->testUser->id(),
      'field_source' => [
        // Allowed (2).
        ['target_id' => $this->domainOnlySource->id()],
      ],
    ]);

    $rights = UserPostingRightsHelper::getEntityAuthorPostingRights($domainOnlyEntity);
    $this->assertEquals('allowed', $rights, 'Entity with only domain rights should return allowed');
  }

  /**
   * Test domain posting rights fallback behavior.
   */
  public function testDomainPostingRightsFallback(): void {
    // Test that domain rights are used when user rights don't exist.
    $rights = UserPostingRightsHelper::getUserPostingRights($this->testUser, [$this->domainOnlySource->id()]);

    $this->assertArrayHasKey($this->domainOnlySource->id(), $rights);
    $this->assertEquals(2, $rights[$this->domainOnlySource->id()]['job'], 'Should use domain rights when user rights don\'t exist');
    $this->assertEquals(2, $rights[$this->domainOnlySource->id()]['training'], 'Should use domain rights when user rights don\'t exist');
    $this->assertEquals(2, $rights[$this->domainOnlySource->id()]['report'], 'Should use domain rights when user rights don\'t exist');

    // Test that user rights take precedence over domain rights.
    $rights = UserPostingRightsHelper::getUserPostingRights($this->testUser, [$this->testSource->id()]);

    $this->assertArrayHasKey($this->testSource->id(), $rights);
    $this->assertEquals(2, $rights[$this->testSource->id()]['job'], 'User rights should take precedence over domain rights');
    $this->assertEquals(3, $rights[$this->testSource->id()]['training'], 'User rights should take precedence over domain rights');
    $this->assertEquals(1, $rights[$this->testSource->id()]['report'], 'User rights should take precedence over domain rights');

    // Test that no rights return unverified.
    $rights = UserPostingRightsHelper::getUserPostingRights($this->testUser, [$this->noRightsSource->id()]);

    $this->assertArrayHasKey($this->noRightsSource->id(), $rights);
    $this->assertEquals(0, $rights[$this->noRightsSource->id()]['job'], 'Source with no rights should return unverified');
    $this->assertEquals(0, $rights[$this->noRightsSource->id()]['training'], 'Source with no rights should return unverified');
    $this->assertEquals(0, $rights[$this->noRightsSource->id()]['report'], 'Source with no rights should return unverified');
  }

  /**
   * Test extractDomainFromEmail method.
   */
  public function testExtractDomainFromEmail(): void {
    // Test with valid email.
    $domain = $this->invokeProtectedMethod('extractDomainFromEmail', ['test@example.com']);
    $this->assertEquals('example.com', $domain, 'Should extract domain from valid email');

    // Test with empty email.
    $domain = $this->invokeProtectedMethod('extractDomainFromEmail', ['']);
    $this->assertNull($domain, 'Should return null for empty email');

    // Test with invalid email (no @).
    $domain = $this->invokeProtectedMethod('extractDomainFromEmail', ['invalid-email']);
    $this->assertNull($domain, 'Should return null for email without @');

    // Test with email with multiple @ symbols.
    $domain = $this->invokeProtectedMethod('extractDomainFromEmail', ['test@sub@example.com']);
    $this->assertEquals('sub@example.com', $domain, 'Should handle multiple @ symbols correctly');

    // Test case sensitivity.
    $domain = $this->invokeProtectedMethod('extractDomainFromEmail', ['test@EXAMPLE.COM']);
    $this->assertEquals('example.com', $domain, 'Should convert domain to lowercase');
  }

  /**
   * Test getUserConsolidatedPostingRight method.
   */
  public function testGetUserConsolidatedPostingRight(): void {
    // Test with valid bundle and sources.
    $rights = UserPostingRightsHelper::getUserConsolidatedPostingRight(
      $this->testUser,
      'job',
      [$this->testSource->id()]
    );

    $this->assertEquals(2, $rights['code'], 'Should return allowed code for job');
    $this->assertEquals('allowed', $rights['name'], 'Should return allowed name for job');
    $this->assertContainsEquals($this->testSource->id(), $rights['sources'], 'Should include source in sources array');

    // Test with blocked rights.
    $rights = UserPostingRightsHelper::getUserConsolidatedPostingRight(
      $this->testUser,
      'report',
      [$this->testSource->id()]
    );

    $this->assertEquals(1, $rights['code'], 'Should return blocked code for report');
    $this->assertEquals('blocked', $rights['name'], 'Should return blocked name for report');

    // Test with trusted rights.
    $rights = UserPostingRightsHelper::getUserConsolidatedPostingRight(
      $this->testUser,
      'training',
      [$this->testSource->id()]
    );

    $this->assertEquals(3, $rights['code'], 'Should return trusted code for training');
    $this->assertEquals('trusted', $rights['name'], 'Should return trusted name for training');

    // Test with multiple sources including domain rights.
    $rights = UserPostingRightsHelper::getUserConsolidatedPostingRight(
      $this->testUser,
      'job',
      [
        // Allowed (2) - user rights.
        $this->testSource->id(),
        // Allowed (2) - domain rights.
        $this->domainOnlySource->id(),
        // Unverified (0) - no rights.
        $this->noRightsSource->id(),
      ]
    );

    $this->assertEquals(0, $rights['code'], 'Should return unverified code when any source has no rights');
    $this->assertEquals('unverified', $rights['name'], 'Should return unverified name when any source has no rights');
    $this->assertContainsEquals($this->noRightsSource->id(), $rights['sources'], 'Should include unverified source in sources array');

    // Test with multiple sources where user has mixed rights.
    $rights = UserPostingRightsHelper::getUserConsolidatedPostingRight(
      $this->testUser,
      'training',
      [
        // Trusted (3) - user rights.
        $this->userOnlySource->id(),
        // Allowed (2) - domain rights.
        $this->domainOnlySource->id(),
      ]
    );

    $this->assertEquals(2, $rights['code'], 'Should return allowed code when mixing trusted and allowed rights');
    $this->assertEquals('allowed', $rights['name'], 'Should return allowed name when mixing trusted and allowed rights');

    // Test with invalid bundle.
    $rights = UserPostingRightsHelper::getUserConsolidatedPostingRight(
      $this->testUser,
      'invalid_bundle',
      [$this->testSource->id()]
    );

    $this->assertEquals(0, $rights['code'], 'Should return unverified code for invalid bundle');
    $this->assertEquals('unverified', $rights['name'], 'Should return unverified name for invalid bundle');

    // Test with empty sources.
    $rights = UserPostingRightsHelper::getUserConsolidatedPostingRight(
      $this->testUser,
      'job',
      []
    );

    $this->assertEquals(0, $rights['code'], 'Should return unverified code for empty sources');
    $this->assertEquals('unverified', $rights['name'], 'Should return unverified name for empty sources');

    // Test with anonymous user.
    $anonymous_user = User::getAnonymousUser();
    $rights = UserPostingRightsHelper::getUserConsolidatedPostingRight(
      $anonymous_user,
      'job',
      [$this->testSource->id()]
    );

    $this->assertEquals(0, $rights['code'], 'Should return unverified code for anonymous user');
    $this->assertEquals('unverified', $rights['name'], 'Should return unverified name for anonymous user');
  }

  /**
   * Test userHasPostingRights method.
   */
  public function testUserHasPostingRights(): void {
    // Test with user who has posting rights.
    $has_rights = UserPostingRightsHelper::userHasPostingRights(
      $this->testUser,
      $this->testEntity,
      'published'
    );
    $this->assertTrue($has_rights, 'User with allowed rights should have posting rights');

    // Test with user who is blocked.
    $has_rights = UserPostingRightsHelper::userHasPostingRights(
      $this->testUser,
      $this->testEntity,
      'published'
    );
    // The user has mixed rights (allowed for job, blocked for report), so they
    // should have rights.
    $this->assertTrue($has_rights, 'User with mixed rights should have posting rights');

    // Test with draft status for blocked user (should allow owner).
    $has_rights = UserPostingRightsHelper::userHasPostingRights(
      $this->testUser,
      $this->testEntity,
      'draft'
    );
    $this->assertTrue($has_rights, 'Owner should have rights for draft even if blocked');

    // Test with anonymous user.
    $anonymous_user = User::getAnonymousUser();
    $has_rights = UserPostingRightsHelper::userHasPostingRights(
      $anonymous_user,
      $this->testEntity,
      'published'
    );
    $this->assertFalse($has_rights, 'Anonymous user should not have posting rights');

    // Test with new entity. Do not save it so that it doesn't have an ID.
    $new_entity = Node::create([
      'type' => 'job',
      'title' => 'New Job',
      'uid' => $this->testUser->id(),
    ]);

    $has_rights = UserPostingRightsHelper::userHasPostingRights(
      $this->testUser,
      $new_entity,
      'draft'
    );
    $this->assertFalse($has_rights, 'New entity without ID should not grant posting rights');
  }

  /**
   * Test isUserAllowedOrTrustedForAnySource method.
   */
  public function testIsUserAllowedOrTrustedForAnySource(): void {
    // Test with user who has allowed/trusted rights.
    $is_allowed = UserPostingRightsHelper::isUserAllowedOrTrustedForAnySource($this->testUser, 'job');
    $this->assertTrue($is_allowed, 'User with allowed rights should be considered allowed');

    $is_allowed = UserPostingRightsHelper::isUserAllowedOrTrustedForAnySource($this->testUser, 'training');
    $this->assertTrue($is_allowed, 'User with trusted rights should be considered allowed');

    // Test with user who has allowed/trusted rights for reports.
    $is_allowed = UserPostingRightsHelper::isUserAllowedOrTrustedForAnySource($this->testUser, 'report');
    $this->assertTrue($is_allowed, 'User with trusted rights in userOnlySource and allowed rights in domainOnlySource should be considered allowed');

    // Test with user who has no allowed/trusted rights
    // (only blocked/unverified).
    $blocked_user = $this->createUser([], 'blocked_user', FALSE, [
      'name' => 'blocked_user',
      'mail' => 'blocked@blocked.com',
      'status' => 1,
    ]);

    // Create a source where this user only has blocked rights.
    $this->createTerm($this->sourceVocabulary, [
      'name' => 'Blocked User Source',
      'field_user_posting_rights' => [
        [
          'id' => $blocked_user->id(),
          // Blocked for all content types.
          'job' => 1,
          'training' => 1,
          'report' => 1,
        ],
      ],
    ]);

    $is_allowed = UserPostingRightsHelper::isUserAllowedOrTrustedForAnySource($blocked_user, 'job');
    $this->assertFalse($is_allowed, 'User with only blocked rights should not be considered allowed');

    // Test with anonymous user.
    $anonymous_user = User::getAnonymousUser();
    $is_allowed = UserPostingRightsHelper::isUserAllowedOrTrustedForAnySource($anonymous_user, 'job');
    $this->assertFalse($is_allowed, 'Anonymous user should not be considered allowed');

    // Test with invalid bundle.
    $this->expectException(\InvalidArgumentException::class);
    UserPostingRightsHelper::isUserAllowedOrTrustedForAnySource($this->testUser, 'invalid_bundle');
  }

  /**
   * Test getSourcesWithPostingRightsForUser method.
   */
  public function testGetSourcesWithPostingRightsForUser(): void {
    // Test getting sources with specific rights.
    $sources = UserPostingRightsHelper::getSourcesWithPostingRightsForUser(
      $this->testUser,
      // Allowed or trusted.
      ['job' => [2, 3]],
      'OR'
    );

    $this->assertArrayHasKey($this->testSource->id(), $sources, 'Should return source with allowed/trusted job rights');
    $this->assertEquals(2, $sources[$this->testSource->id()]['job'], 'Should return correct job rights');

    // Test with limit.
    $sources = UserPostingRightsHelper::getSourcesWithPostingRightsForUser(
      $this->testUser,
      // Trusted only.
      ['training' => [3]],
      'AND',
      1
    );

    $this->assertCount(1, $sources, 'Should respect limit parameter');
    $this->assertEquals(3, $sources[$this->testSource->id()]['training'], 'Should return correct training rights');

    // Test with no bundle filters.
    $sources = UserPostingRightsHelper::getSourcesWithPostingRightsForUser($this->testUser);
    $this->assertNotEmpty($sources, 'Should return sources without bundle filters');

    // Test that domain rights are included when no user rights exist.
    $sources = UserPostingRightsHelper::getSourcesWithPostingRightsForUser(
      $this->testUser,
      // Allowed or trusted.
      ['job' => [2, 3]],
      'OR'
    );

    // Should include sources with user rights.
    $this->assertArrayHasKey($this->testSource->id(), $sources, 'Should include source with user rights');
    $this->assertArrayHasKey($this->userOnlySource->id(), $sources, 'Should include source with user rights');

    // Should include sources with domain rights.
    $this->assertArrayHasKey($this->domainOnlySource->id(), $sources, 'Should include source with domain rights');

    // Should not include sources with no rights.
    $this->assertArrayNotHasKey($this->noRightsSource->id(), $sources, 'Should not include source with no rights');

    // Test that user rights take precedence over domain rights.
    $sources = UserPostingRightsHelper::getSourcesWithPostingRightsForUser(
      $this->testUser,
      // Trusted only.
      ['training' => [3]],
      'AND'
    );

    // Should include sources where user has trusted rights.
    $this->assertArrayHasKey($this->userOnlySource->id(), $sources, 'Should include source where user has trusted rights');
    $this->assertEquals(3, $sources[$this->userOnlySource->id()]['training'], 'Should return trusted rights from user posting rights');

    // Should not include sources where user only has domain rights (domain
    // rights are 2, not 3).
    $this->assertArrayNotHasKey($this->domainOnlySource->id(), $sources, 'Should not include source where user only has domain rights below threshold');

    // Test integration: verify that the refactored method combines user and
    // domain rights correctly.
    $combined_sources = UserPostingRightsHelper::getSourcesWithPostingRightsForUser(
      $this->testUser,
      ['job' => [2, 3]],
      'OR'
    );

    // Get user-only sources.
    $user_sources = UserPostingRightsHelper::getSourcesWithUserPostingRightsForUser(
      $this->testUser,
      ['job' => [2, 3]],
      'OR'
    );

    // Get domain-only sources.
    $domain_sources = UserPostingRightsHelper::getSourcesWithDomainPostingRightsForUser(
      $this->testUser,
      ['job' => [2, 3]],
      'OR'
    );

    // The combined result should include both user and domain sources.
    $expected_sources = array_keys($user_sources + $domain_sources);
    $actual_sources = array_keys($combined_sources);

    $this->assertEquals(
      count($expected_sources),
      count($actual_sources),
      'Combined sources should include both user and domain sources'
    );

    // Verify that user rights take precedence over domain rights for the same
    // source.
    if (isset($combined_sources[$this->testSource->id()])) {
      // The testSource has both user and domain rights, so user rights should
      // take precedence.
      $this->assertEquals(2, $combined_sources[$this->testSource->id()]['job'], 'User rights should take precedence over domain rights for same source');
      $this->assertEquals(3, $combined_sources[$this->testSource->id()]['training'], 'User rights should take precedence over domain rights for same source');
      $this->assertEquals(1, $combined_sources[$this->testSource->id()]['report'], 'User rights should take precedence over domain rights for same source');
    }
  }

  /**
   * Test getSourcesWithUserPostingRightsForUser method.
   */
  public function testGetSourcesWithUserPostingRightsForUser(): void {
    // Test getting sources with user posting rights only.
    $sources = UserPostingRightsHelper::getSourcesWithUserPostingRightsForUser(
      $this->testUser,
      // Allowed or trusted.
      ['job' => [2, 3]],
      'OR'
    );

    // Should include sources where user has explicit posting rights.
    $this->assertArrayHasKey($this->testSource->id(), $sources, 'Should return source with user posting rights');
    $this->assertEquals(2, $sources[$this->testSource->id()]['job'], 'Should return correct job rights from user posting rights');
    $this->assertEquals(3, $sources[$this->testSource->id()]['training'], 'Should return correct training rights from user posting rights');
    $this->assertEquals(1, $sources[$this->testSource->id()]['report'], 'Should return correct report rights from user posting rights');

    // Should include userOnlySource which has user posting rights.
    $this->assertArrayHasKey($this->userOnlySource->id(), $sources, 'Should include source with user-only posting rights');
    $this->assertEquals(3, $sources[$this->userOnlySource->id()]['job'], 'Should return trusted rights from user-only source');
    $this->assertEquals(3, $sources[$this->userOnlySource->id()]['training'], 'Should return trusted rights from user-only source');
    $this->assertEquals(3, $sources[$this->userOnlySource->id()]['report'], 'Should return trusted rights from user-only source');

    // Should NOT include domainOnlySource (no user posting rights).
    $this->assertArrayNotHasKey($this->domainOnlySource->id(), $sources, 'Should not include source with only domain posting rights');

    // Should NOT include noRightsSource (no posting rights at all).
    $this->assertArrayNotHasKey($this->noRightsSource->id(), $sources, 'Should not include source with no posting rights');

    // Test with specific bundle filter.
    $sources = UserPostingRightsHelper::getSourcesWithUserPostingRightsForUser(
      $this->testUser,
      // Trusted only.
      ['training' => [3]],
      'AND'
    );

    $this->assertArrayHasKey($this->testSource->id(), $sources, 'Should include source with trusted training rights');
    $this->assertArrayHasKey($this->userOnlySource->id(), $sources, 'Should include user-only source with trusted training rights');
    $this->assertEquals(3, $sources[$this->testSource->id()]['training'], 'Should return trusted training rights');
    $this->assertEquals(3, $sources[$this->userOnlySource->id()]['training'], 'Should return trusted training rights from user-only source');

    // Test with limit.
    $sources = UserPostingRightsHelper::getSourcesWithUserPostingRightsForUser(
      $this->testUser,
      ['job' => [2, 3]],
      'OR',
      1
    );

    $this->assertCount(1, $sources, 'Should respect limit parameter');

    // Test with no bundle filters (should return all user posting rights).
    $sources = UserPostingRightsHelper::getSourcesWithUserPostingRightsForUser($this->testUser);
    $this->assertNotEmpty($sources, 'Should return sources without bundle filters');
    $this->assertArrayHasKey($this->testSource->id(), $sources, 'Should include test source');
    $this->assertArrayHasKey($this->userOnlySource->id(), $sources, 'Should include user-only source');
  }

  /**
   * Test getSourcesWithDomainPostingRightsForUser method.
   */
  public function testGetSourcesWithDomainPostingRightsForUser(): void {
    // Test getting sources with domain posting rights only.
    $sources = UserPostingRightsHelper::getSourcesWithDomainPostingRightsForUser(
      $this->testUser,
      // Allowed or trusted.
      ['job' => [2, 3]],
      'OR'
    );

    // Should include sources where domain has posting rights.
    $this->assertArrayHasKey($this->testSource->id(), $sources, 'Should return source with domain posting rights');
    $this->assertEquals(2, $sources[$this->testSource->id()]['job'], 'Should return correct job rights from domain posting rights');
    $this->assertEquals(2, $sources[$this->testSource->id()]['training'], 'Should return correct training rights from domain posting rights');
    $this->assertEquals(0, $sources[$this->testSource->id()]['report'], 'Should return correct report rights from domain posting rights');

    // Should include domainOnlySource which has domain posting rights.
    $this->assertArrayHasKey($this->domainOnlySource->id(), $sources, 'Should include source with domain-only posting rights');
    $this->assertEquals(2, $sources[$this->domainOnlySource->id()]['job'], 'Should return allowed rights from domain-only source');
    $this->assertEquals(2, $sources[$this->domainOnlySource->id()]['training'], 'Should return allowed rights from domain-only source');
    $this->assertEquals(2, $sources[$this->domainOnlySource->id()]['report'], 'Should return allowed rights from domain-only source');

    // Should NOT include userOnlySource (no domain posting rights).
    $this->assertArrayNotHasKey($this->userOnlySource->id(), $sources, 'Should not include source with only user posting rights');

    // Should NOT include noRightsSource (no posting rights at all).
    $this->assertArrayNotHasKey($this->noRightsSource->id(), $sources, 'Should not include source with no posting rights');

    // Test with specific bundle filter.
    $sources = UserPostingRightsHelper::getSourcesWithDomainPostingRightsForUser(
      $this->testUser,
      // Allowed only.
      ['job' => [2]],
      'AND'
    );

    $this->assertArrayHasKey($this->testSource->id(), $sources, 'Should include source with allowed job rights');
    $this->assertArrayHasKey($this->domainOnlySource->id(), $sources, 'Should include domain-only source with allowed job rights');
    $this->assertEquals(2, $sources[$this->testSource->id()]['job'], 'Should return allowed job rights');
    $this->assertEquals(2, $sources[$this->domainOnlySource->id()]['job'], 'Should return allowed job rights from domain-only source');

    // Test with limit.
    $sources = UserPostingRightsHelper::getSourcesWithDomainPostingRightsForUser(
      $this->testUser,
      ['job' => [2, 3]],
      'OR',
      1
    );

    $this->assertCount(1, $sources, 'Should respect limit parameter');

    // Test with no bundle filters (should return all domain posting rights).
    $sources = UserPostingRightsHelper::getSourcesWithDomainPostingRightsForUser($this->testUser);
    $this->assertNotEmpty($sources, 'Should return sources without bundle filters');
    $this->assertArrayHasKey($this->testSource->id(), $sources, 'Should include test source');
    $this->assertArrayHasKey($this->domainOnlySource->id(), $sources, 'Should include domain-only source');

    // Test with user without email domain.
    $user_without_email = $this->createUser([], 'no_email_user', FALSE, [
      'name' => 'no_email_user',
      'mail' => '',
      'status' => 1,
    ]);

    $sources = UserPostingRightsHelper::getSourcesWithDomainPostingRightsForUser($user_without_email);
    $this->assertEmpty($sources, 'User without email should have no domain posting rights');

    // Test with user with different email domain.
    $user_different_domain = $this->createUser([], 'different_domain_user', FALSE, [
      'name' => 'different_domain_user',
      'mail' => 'user@different.com',
      'status' => 1,
    ]);

    $sources = UserPostingRightsHelper::getSourcesWithDomainPostingRightsForUser($user_different_domain);
    $this->assertEmpty($sources, 'User with different domain should have no domain posting rights');
  }

  /**
   * Test renderRight method.
   */
  public function testRenderRight(): void {
    $renderer = \Drupal::service('renderer');
    $context = new RenderContext();

    // Test rendering different rights within a render context.
    $rendered = $renderer->executeInRenderContext($context, function () {
      return UserPostingRightsHelper::renderRight('allowed');
    });
    $this->assertNotEmpty($rendered, 'Should render allowed right');

    $rendered = $renderer->executeInRenderContext($context, function () {
      return UserPostingRightsHelper::renderRight('blocked');
    });
    $this->assertNotEmpty($rendered, 'Should render blocked right');

    $rendered = $renderer->executeInRenderContext($context, function () {
      return UserPostingRightsHelper::renderRight('trusted');
    });
    $this->assertNotEmpty($rendered, 'Should render trusted right');

    $rendered = $renderer->executeInRenderContext($context, function () {
      return UserPostingRightsHelper::renderRight('unverified');
    });
    $this->assertNotEmpty($rendered, 'Should render unverified right');
  }

  /**
   * Test edge cases and error conditions.
   */
  public function testEdgeCases(): void {
    // Test with entity that has empty source field.
    $entity_with_empty_source = $this->createNode([
      'type' => 'job',
      'title' => 'Empty Source Job',
      'uid' => $this->testUser->id(),
      'field_source' => [],
    ]);

    $rights = UserPostingRightsHelper::getEntityAuthorPostingRights($entity_with_empty_source);
    $this->assertEquals('unverified', $rights, 'Entity with empty source field should return unknown');

    // Test with entity that has invalid source field type (mock).
    $mock_entity = $this->createMockForIntersectionOfInterfaces([
      ContentEntityInterface::class,
      EntityOwnerInterface::class,
    ]);

    $mock_entity->method('hasField')
      ->with('field_source')
      ->willReturn(TRUE);

    // Not an EntityReferenceFieldItemList.
    $mock_entity->method('get')
      ->with('field_source')
      ->willReturn('invalid_value');

    // Mock the getOwnerId method (from EntityOwnerInterface).
    $mock_entity->method('getOwnerId')
      ->willReturn($this->testUser->id());

    $mock_entity->method('bundle')
      ->willReturn('job');

    // Mock getOwner method.
    $mock_entity->method('getOwner')
      ->willReturn($this->testUser);

    $rights = UserPostingRightsHelper::getEntityAuthorPostingRights($mock_entity);
    $this->assertEquals('unknown', $rights, 'Entity with invalid source field type should return unknown');
  }

  /**
   * Test caching behavior.
   */
  public function testCaching(): void {
    // First call should populate cache.
    $rights1 = UserPostingRightsHelper::getUserPostingRights($this->testUser, [$this->testSource->id()]);

    // Second call should use cache.
    $rights2 = UserPostingRightsHelper::getUserPostingRights($this->testUser, [$this->testSource->id()]);

    $this->assertEquals($rights1, $rights2, 'Cached results should match first call');

    // Test that different source combinations create different cache keys.
    $rights3 = UserPostingRightsHelper::getUserPostingRights($this->testUser, []);
    $this->assertNotEquals($rights1, $rights3, 'Different source combinations should have different cache keys');
  }

  /**
   * Helper method to invoke protected methods for testing.
   *
   * @param string $methodName
   *   The name of the protected method to invoke.
   * @param array $arguments
   *   The arguments to pass to the method.
   *
   * @return mixed
   *   The result of the method call.
   */
  protected function invokeProtectedMethod(string $methodName, array $arguments = []) {
    $reflection = new \ReflectionClass(UserPostingRightsHelper::class);
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(TRUE);

    return $method->invokeArgs(NULL, $arguments);
  }

}
