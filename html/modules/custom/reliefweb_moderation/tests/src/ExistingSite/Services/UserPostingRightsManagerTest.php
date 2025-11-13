<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Services;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Drupal\user\EntityOwnerInterface;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the UserPostingRightsManager service.
 */
#[CoversClass(UserPostingRightsManager::class)]
#[Group('reliefweb_moderation')]
#[RunTestsInSeparateProcesses]
class UserPostingRightsManagerTest extends ExistingSiteBase {

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
   * User posting rights manager service.
   */
  protected UserPostingRightsManager $userPostingRightsManager;

  /**
   * Original posting rights status mapping.
   */
  protected array $originalPostingRightsStatusMapping;

  /**
   * Original privileged domains.
   */
  protected ?array $originalPrivilegedDomains;

  /**
   * Original default domain posting rights.
   */
  protected ?string $originalDefaultDomainPostingRights;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Get the service.
    $this->userPostingRightsManager = \Drupal::service('reliefweb_moderation.user_posting_rights');

    // Get the state service.
    $state = \Drupal::service('state');

    // Save the original posting rights status mapping.
    $this->originalPostingRightsStatusMapping = $this->userPostingRightsManager->getUserPostingRightsToModerationStatusMapping();

    // Save the original privileged domains state.
    $this->originalPrivilegedDomains = $state->get('reliefweb_users_privileged_domains', NULL);

    // Save the original default domain posting rights state.
    $this->originalDefaultDomainPostingRights = $state->get('reliefweb_users_privileged_domains_default_posting_rights', NULL);

    // Create a role with the "apply posting rights" permission.
    $posting_right_role_id = $this->createRole([
      'apply posting rights',
    ]);

    // Create a test user with email domain.
    $this->testUser = $this->createUser([], 'test_user', FALSE, [
      'mail' => 'test@example.com',
      'status' => 1,
      'roles' => [$posting_right_role_id],
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
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Training.
        ['value' => 2],
        // Report.
        ['value' => 1],
      ],
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
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Training.
        ['value' => 2],
        // Report.
        ['value' => 1],
      ],
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
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Training.
        ['value' => 2],
        // Report.
        ['value' => 1],
      ],
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
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Training.
        ['value' => 2],
        // Report.
        ['value' => 1],
      ],
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
    $this->userPostingRightsManager->resetCache();

    // Restore the original posting rights status mapping.
    $this->userPostingRightsManager->setUserPostingRightsToModerationStatusMapping($this->originalPostingRightsStatusMapping);

    // Get the state service.
    $state = \Drupal::service('state');

    // Restore the original privileged domains state.
    if ($this->originalPrivilegedDomains !== NULL) {
      $state->set('reliefweb_users_privileged_domains', $this->originalPrivilegedDomains);
    }
    else {
      $state->delete('reliefweb_users_privileged_domains');
    }

    // Restore the original default domain posting rights state.
    if ($this->originalDefaultDomainPostingRights !== NULL) {
      $state->set('reliefweb_users_privileged_domains_default_posting_rights', $this->originalDefaultDomainPostingRights);
    }
    else {
      $state->delete('reliefweb_users_privileged_domains_default_posting_rights');
    }

    parent::tearDown();
  }

  /**
   * Test getEntityAuthorPostingRights method.
   */
  public function testGetEntityAuthorPostingRights(): void {
    // Test with valid entity that has source field.
    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($this->testEntity);
    $this->assertEquals('allowed', $rights, 'Entity with allowed job rights should return allowed');

    // Test with entity without source field.
    $entity_without_source = $this->createNode([
      'type' => 'blog_post',
      'title' => 'Entity without source field',
      'uid' => $this->testUser->id(),
    ]);

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($entity_without_source);
    $this->assertEquals('unknown', $rights, 'Entity without source field should return unknown');

    // Test with entity that doesn't implement EntityOwnerInterface.
    $mock_entity = $this->createMock(ContentEntityInterface::class);
    $mock_entity->method('hasField')->with('field_source')->willReturn(TRUE);
    $mock_entity->method('bundle')->willReturn('job');

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($mock_entity);
    $this->assertEquals('unknown', $rights, 'Entity without getOwnerId method should return unknown');
  }

  /**
   * Test getUserPostingRights method.
   */
  public function testGetUserPostingRights(): void {
    // Test with specific sources.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->testSource->id()]);

    $this->assertArrayHasKey($this->testSource->id(), $rights);
    $this->assertEquals(2, $rights[$this->testSource->id()]['job'], 'User should have allowed rights for job');
    $this->assertEquals(3, $rights[$this->testSource->id()]['training'], 'User should have trusted rights for training');
    $this->assertEquals(1, $rights[$this->testSource->id()]['report'], 'User should have blocked rights for report');

    // Test with multiple sources including domain-only rights.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [
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
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser);
    $this->assertNotEmpty($rights, 'Should return rights including domain-based rights');

    // Test with anonymous user.
    $anonymous_user = User::getAnonymousUser();
    $rights = $this->userPostingRightsManager->getUserPostingRights($anonymous_user);
    $this->assertEmpty($rights, 'Anonymous user should have no posting rights');
  }

  /**
   * Test getDomainPostingRights method.
   */
  public function testGetDomainPostingRights(): void {
    // Test with valid user and sources.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->testSource->id()]);

    // The domain rights should be merged with user rights.
    $this->assertArrayHasKey($this->testSource->id(), $rights);

    // Test with user without email domain.
    $user_without_email = $this->createUser([], 'no_email_user', FALSE, [
      'name' => 'no_email_user',
      'mail' => '',
      'status' => 1,
    ]);

    $rights = $this->userPostingRightsManager->getUserPostingRights($user_without_email, [$this->testSource->id()]);
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

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($multiSourceEntity);
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

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($mixedRightsEntity);
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

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($domainOnlyEntity);
    $this->assertEquals('allowed', $rights, 'Entity with only domain rights should return allowed');
  }

  /**
   * Test domain posting rights fallback behavior.
   */
  public function testDomainPostingRightsFallback(): void {
    // Test that domain rights are used when user rights don't exist.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->domainOnlySource->id()]);

    $this->assertArrayHasKey($this->domainOnlySource->id(), $rights);
    $this->assertEquals(2, $rights[$this->domainOnlySource->id()]['job'], 'Should use domain rights when user rights don\'t exist');
    $this->assertEquals(2, $rights[$this->domainOnlySource->id()]['training'], 'Should use domain rights when user rights don\'t exist');
    $this->assertEquals(2, $rights[$this->domainOnlySource->id()]['report'], 'Should use domain rights when user rights don\'t exist');

    // Test that user rights take precedence over domain rights.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->testSource->id()]);

    $this->assertArrayHasKey($this->testSource->id(), $rights);
    $this->assertEquals(2, $rights[$this->testSource->id()]['job'], 'User rights should take precedence over domain rights');
    $this->assertEquals(3, $rights[$this->testSource->id()]['training'], 'User rights should take precedence over domain rights');
    $this->assertEquals(1, $rights[$this->testSource->id()]['report'], 'User rights should take precedence over domain rights');

    // Test that no rights return unverified.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->noRightsSource->id()]);

    $this->assertArrayHasKey($this->noRightsSource->id(), $rights);
    $this->assertEquals(0, $rights[$this->noRightsSource->id()]['job'], 'Source with no rights should return unverified');
    $this->assertEquals(0, $rights[$this->noRightsSource->id()]['training'], 'Source with no rights should return unverified');
    $this->assertEquals(0, $rights[$this->noRightsSource->id()]['report'], 'Source with no rights should return unverified');
  }

  /**
   * Test whitelisted domains with default posting rights.
   */
  public function testWhitelistedDomainsDefaultPostingRights(): void {
    $state = \Drupal::service('state');

    // Set up privileged domain for example.com with default "allowed" rights.
    $state->set('reliefweb_users_privileged_domains', ['example.com']);
    $state->set('reliefweb_users_privileged_domains_default_posting_rights', 'allowed');

    // Reset cache to ensure new state values are used.
    $this->userPostingRightsManager->resetCache();

    // Test getUserPostingRights with whitelisted domain and no domain posting
    // rights.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->noRightsSource->id()]);

    $this->assertArrayHasKey($this->noRightsSource->id(), $rights);
    // Should use default "allowed" (2) instead of unverified (0).
    $this->assertEquals(2, $rights[$this->noRightsSource->id()]['job'], 'Whitelisted domain should use default allowed rights for job');
    $this->assertEquals(2, $rights[$this->noRightsSource->id()]['training'], 'Whitelisted domain should use default allowed rights for training');
    $this->assertEquals(2, $rights[$this->noRightsSource->id()]['report'], 'Whitelisted domain should use default allowed rights for report');

    // Test getEntityAuthorPostingRights with whitelisted domain.
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Job with Whitelisted Domain',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $this->noRightsSource->id()],
      ],
    ]);

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($entity);
    $this->assertEquals('allowed', $rights, 'Entity with whitelisted domain should return allowed');

    // Test getDomainPostingRights with whitelisted domain.
    $domain_rights = $this->userPostingRightsManager->getDomainPostingRights($this->testUser, [$this->noRightsSource->id()]);
    $this->assertArrayHasKey($this->noRightsSource->id(), $domain_rights);
    $this->assertEquals(2, $domain_rights[$this->noRightsSource->id()]['job'], 'Domain posting rights should return default allowed for whitelisted domain');
    $this->assertEquals(2, $domain_rights[$this->noRightsSource->id()]['training'], 'Domain posting rights should return default allowed for whitelisted domain');
    $this->assertEquals(2, $domain_rights[$this->noRightsSource->id()]['report'], 'Domain posting rights should return default allowed for whitelisted domain');
  }

  /**
   * Test whitelisted domains with trusted default posting rights.
   */
  public function testWhitelistedDomainsTrustedDefault(): void {
    $state = \Drupal::service('state');

    // Set up privileged domain with default "trusted" rights.
    $state->set('reliefweb_users_privileged_domains', ['example.com']);
    $state->set('reliefweb_users_privileged_domains_default_posting_rights', 'trusted');

    // Reset cache.
    $this->userPostingRightsManager->resetCache();

    // Test with no domain posting rights.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->noRightsSource->id()]);

    $this->assertArrayHasKey($this->noRightsSource->id(), $rights);
    // Should use default "trusted" (3) instead of unverified (0).
    $this->assertEquals(3, $rights[$this->noRightsSource->id()]['job'], 'Whitelisted domain should use default trusted rights for job');
    $this->assertEquals(3, $rights[$this->noRightsSource->id()]['training'], 'Whitelisted domain should use default trusted rights for training');
    $this->assertEquals(3, $rights[$this->noRightsSource->id()]['report'], 'Whitelisted domain should use default trusted rights for report');

    // Test getEntityAuthorPostingRights.
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Job with Trusted Whitelisted Domain',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $this->noRightsSource->id()],
      ],
    ]);

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($entity);
    $this->assertEquals('trusted', $rights, 'Entity with trusted whitelisted domain should return trusted');
  }

  /**
   * Test whitelisted domains with blocked default posting rights.
   */
  public function testWhitelistedDomainsBlockedDefault(): void {
    $state = \Drupal::service('state');

    // Set up privileged domain with default "blocked" rights.
    $state->set('reliefweb_users_privileged_domains', ['example.com']);
    $state->set('reliefweb_users_privileged_domains_default_posting_rights', 'blocked');

    // Reset cache.
    $this->userPostingRightsManager->resetCache();

    // Test with no domain posting rights.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->noRightsSource->id()]);

    $this->assertArrayHasKey($this->noRightsSource->id(), $rights);
    // Should use default "blocked" (1) instead of unverified (0).
    $this->assertEquals(1, $rights[$this->noRightsSource->id()]['job'], 'Whitelisted domain should use default blocked rights for job');
    $this->assertEquals(1, $rights[$this->noRightsSource->id()]['training'], 'Whitelisted domain should use default blocked rights for training');
    $this->assertEquals(1, $rights[$this->noRightsSource->id()]['report'], 'Whitelisted domain should use default blocked rights for report');

    // Test getEntityAuthorPostingRights.
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Job with Blocked Whitelisted Domain',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $this->noRightsSource->id()],
      ],
    ]);

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($entity);
    $this->assertEquals('blocked', $rights, 'Entity with blocked whitelisted domain should return blocked');
  }

  /**
   * Test that whitelisted domains don't override existing domain rights.
   */
  public function testWhitelistedDomainsDontOverrideExistingRights(): void {
    $state = \Drupal::service('state');

    // Set up privileged domain with default "trusted" rights.
    $state->set('reliefweb_users_privileged_domains', ['example.com']);
    $state->set('reliefweb_users_privileged_domains_default_posting_rights', 'trusted');

    // Reset cache.
    $this->userPostingRightsManager->resetCache();

    // Test with source that has existing domain posting rights.
    // The domainOnlySource has domain posting rights set to "allowed" (2).
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->domainOnlySource->id()]);

    $this->assertArrayHasKey($this->domainOnlySource->id(), $rights);
    // Should use existing domain posting rights (allowed = 2), not the default.
    $this->assertEquals(2, $rights[$this->domainOnlySource->id()]['job'], 'Should use existing domain posting rights, not default');
    $this->assertEquals(2, $rights[$this->domainOnlySource->id()]['training'], 'Should use existing domain posting rights, not default');
    $this->assertEquals(2, $rights[$this->domainOnlySource->id()]['report'], 'Should use existing domain posting rights, not default');

    // Test getEntityAuthorPostingRights with existing domain rights.
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Job with Existing Domain Rights',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $this->domainOnlySource->id()],
      ],
    ]);

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($entity);
    $this->assertEquals('allowed', $rights, 'Should use existing domain posting rights, not default');
  }

  /**
   * Test that non-whitelisted domains still return unverified.
   */
  public function testNonWhitelistedDomainsReturnUnverified(): void {
    $state = \Drupal::service('state');

    // Set up privileged domain (different domain).
    $state->set('reliefweb_users_privileged_domains', ['otherdomain.com']);
    $state->set('reliefweb_users_privileged_domains_default_posting_rights', 'allowed');

    // Reset cache.
    $this->userPostingRightsManager->resetCache();

    // Test with user from example.com (not whitelisted).
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->noRightsSource->id()]);

    $this->assertArrayHasKey($this->noRightsSource->id(), $rights);
    // Should return unverified (0) since domain is not whitelisted.
    $this->assertEquals(0, $rights[$this->noRightsSource->id()]['job'], 'Non-whitelisted domain should return unverified');
    $this->assertEquals(0, $rights[$this->noRightsSource->id()]['training'], 'Non-whitelisted domain should return unverified');
    $this->assertEquals(0, $rights[$this->noRightsSource->id()]['report'], 'Non-whitelisted domain should return unverified');

    // Test getEntityAuthorPostingRights.
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Job with Non-Whitelisted Domain',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $this->noRightsSource->id()],
      ],
    ]);

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($entity);
    $this->assertEquals('unverified', $rights, 'Entity with non-whitelisted domain should return unverified');
  }

  /**
   * Test whitelisted domains with multiple sources.
   */
  public function testWhitelistedDomainsWithMultipleSources(): void {
    $state = \Drupal::service('state');

    // Set up whitelisted domain with default "allowed" rights.
    $state->set('reliefweb_users_privileged_domains', ['example.com']);
    $state->set('reliefweb_users_privileged_domains_default_posting_rights', 'allowed');

    // Reset cache.
    $this->userPostingRightsManager->resetCache();

    // Test with multiple sources: one with existing domain rights, one
    // without.
    // Has existing domain rights (allowed = 2).
    $domain_source_id = $this->domainOnlySource->id();
    // No domain rights, should use default (allowed = 2).
    $no_rights_source_id = $this->noRightsSource->id();
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [
      $domain_source_id,
      $no_rights_source_id,
    ]);

    $this->assertArrayHasKey($this->domainOnlySource->id(), $rights);
    $this->assertArrayHasKey($this->noRightsSource->id(), $rights);

    // Source with existing domain rights should use those.
    $this->assertEquals(2, $rights[$this->domainOnlySource->id()]['job'], 'Should use existing domain posting rights');

    // Source without domain rights should use default.
    $this->assertEquals(2, $rights[$this->noRightsSource->id()]['job'], 'Should use default posting rights for whitelisted domain');

    // Test getEntityAuthorPostingRights with multiple sources.
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Job with Multiple Sources',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $this->domainOnlySource->id()],
        ['target_id' => $this->noRightsSource->id()],
      ],
    ]);

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($entity);
    $this->assertEquals('allowed', $rights, 'Entity with multiple sources should return allowed when all are allowed');
  }

  /**
   * Test whitelisted domains with user posting rights taking precedence.
   */
  public function testWhitelistedDomainsUserRightsPrecedence(): void {
    $state = \Drupal::service('state');

    // Set up privileged domain with default "trusted" rights.
    $state->set('reliefweb_users_privileged_domains', ['example.com']);
    $state->set('reliefweb_users_privileged_domains_default_posting_rights', 'trusted');

    // Reset cache.
    $this->userPostingRightsManager->resetCache();

    // Test with source that has user posting rights.
    // The testSource has user posting rights that should take precedence.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->testSource->id()]);

    $this->assertArrayHasKey($this->testSource->id(), $rights);
    // User rights should take precedence over default domain rights.
    $this->assertEquals(2, $rights[$this->testSource->id()]['job'], 'User rights should take precedence over default domain rights');
    $this->assertEquals(3, $rights[$this->testSource->id()]['training'], 'User rights should take precedence over default domain rights');
    $this->assertEquals(1, $rights[$this->testSource->id()]['report'], 'User rights should take precedence over default domain rights');

    // Test with source that has no user or domain posting rights.
    // Should use default domain posting rights.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->noRightsSource->id()]);

    $this->assertArrayHasKey($this->noRightsSource->id(), $rights);
    $this->assertEquals(3, $rights[$this->noRightsSource->id()]['job'], 'Should use default trusted rights when no user or domain rights exist');
  }

  /**
   * Test getAllowedContentTypes method.
   */
  public function testGetAllowedContentTypes(): void {
    // Create a source with allowed content types.
    $sourceWithTypes = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Source with Allowed Types',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Report.
        ['value' => 1],
      ],
    ]);

    $allowed_types = $this->userPostingRightsManager->getAllowedContentTypes($sourceWithTypes);
    $this->assertArrayHasKey('job', $allowed_types, 'Should include job as allowed content type');
    $this->assertArrayHasKey('report', $allowed_types, 'Should include report as allowed content type');
    $this->assertArrayNotHasKey('training', $allowed_types, 'Should not include training as allowed content type');
    $this->assertTrue($allowed_types['job'], 'Job should be marked as allowed');
    $this->assertTrue($allowed_types['report'], 'Report should be marked as allowed');

    // Create a source with no allowed content types.
    $sourceWithoutTypes = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Source without Allowed Types',
    ]);

    $allowed_types = $this->userPostingRightsManager->getAllowedContentTypes($sourceWithoutTypes);
    $this->assertEmpty($allowed_types, 'Source without allowed content types should return empty array');

    // Create a source with all content types allowed.
    $sourceWithAllTypes = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Source with All Types',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Report.
        ['value' => 1],
        // Training.
        ['value' => 2],
      ],
    ]);

    $allowed_types = $this->userPostingRightsManager->getAllowedContentTypes($sourceWithAllTypes);
    $this->assertArrayHasKey('job', $allowed_types, 'Should include job as allowed content type');
    $this->assertArrayHasKey('report', $allowed_types, 'Should include report as allowed content type');
    $this->assertArrayHasKey('training', $allowed_types, 'Should include training as allowed content type');
    $this->assertTrue($allowed_types['job'], 'Job should be marked as allowed');
    $this->assertTrue($allowed_types['report'], 'Report should be marked as allowed');
    $this->assertTrue($allowed_types['training'], 'Training should be marked as allowed');
  }

  /**
   * Test filterPostingRightsByAllowedContentTypes method.
   */
  public function testFilterPostingRightsByAllowedContentTypes(): void {
    // Create test data with posting rights.
    $results = [
      1 => ['job' => 2, 'report' => 1, 'training' => 3],
      2 => ['job' => 0, 'report' => 2, 'training' => 0],
    ];

    // Create sources with different allowed content types.
    $source1 = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Source 1 - Job and Training Only',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Training.
        ['value' => 2],
      ],
    ]);

    $source2 = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Source 2 - Report Only',
      'field_allowed_content_types' => [
        // Report.
        ['value' => 1],
      ],
    ]);

    // Update results to use actual source IDs.
    $results = [
      $source1->id() => ['job' => 2, 'report' => 1, 'training' => 3],
      $source2->id() => ['job' => 0, 'report' => 2, 'training' => 0],
    ];

    $filtered_results = $this->userPostingRightsManager->filterPostingRightsByAllowedContentTypes($results);

    // Source 1 should only have job and training rights (report should be 0).
    $this->assertEquals(2, $filtered_results[$source1->id()]['job'], 'Source 1 should keep job rights');
    $this->assertEquals(0, $filtered_results[$source1->id()]['report'], 'Source 1 should reset report rights');
    $this->assertEquals(3, $filtered_results[$source1->id()]['training'], 'Source 1 should keep training rights');

    // Source 2 should only have report rights (job and training should be 0).
    $this->assertEquals(0, $filtered_results[$source2->id()]['job'], 'Source 2 should reset job rights');
    $this->assertEquals(2, $filtered_results[$source2->id()]['report'], 'Source 2 should keep report rights');
    $this->assertEquals(0, $filtered_results[$source2->id()]['training'], 'Source 2 should reset training rights');

    // Test with empty results.
    $empty_results = $this->userPostingRightsManager->filterPostingRightsByAllowedContentTypes([]);
    $this->assertEmpty($empty_results, 'Empty results should return empty array');

    // Test with source that has no allowed content types.
    $sourceNoTypes = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Source with No Types',
    ]);

    $results_no_types = [
      $sourceNoTypes->id() => ['job' => 2, 'report' => 1, 'training' => 3],
    ];

    $filtered_results = $this->userPostingRightsManager->filterPostingRightsByAllowedContentTypes($results_no_types);
    $this->assertEquals(0, $filtered_results[$sourceNoTypes->id()]['job'], 'Source with no allowed types should reset all rights');
    $this->assertEquals(0, $filtered_results[$sourceNoTypes->id()]['report'], 'Source with no allowed types should reset all rights');
    $this->assertEquals(0, $filtered_results[$sourceNoTypes->id()]['training'], 'Source with no allowed types should reset all rights');
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
    $rights = $this->userPostingRightsManager->getUserConsolidatedPostingRight(
      $this->testUser,
      'job',
      [$this->testSource->id()]
    );

    $this->assertEquals(2, $rights['code'], 'Should return allowed code for job');
    $this->assertEquals('allowed', $rights['name'], 'Should return allowed name for job');
    $this->assertContainsEquals($this->testSource->id(), $rights['sources'], 'Should include source in sources array');

    // Test with blocked rights.
    $rights = $this->userPostingRightsManager->getUserConsolidatedPostingRight(
      $this->testUser,
      'report',
      [$this->testSource->id()]
    );

    $this->assertEquals(1, $rights['code'], 'Should return blocked code for report');
    $this->assertEquals('blocked', $rights['name'], 'Should return blocked name for report');

    // Test with trusted rights.
    $rights = $this->userPostingRightsManager->getUserConsolidatedPostingRight(
      $this->testUser,
      'training',
      [$this->testSource->id()]
    );

    $this->assertEquals(3, $rights['code'], 'Should return trusted code for training');
    $this->assertEquals('trusted', $rights['name'], 'Should return trusted name for training');

    // Test with multiple sources including domain rights.
    $rights = $this->userPostingRightsManager->getUserConsolidatedPostingRight(
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
    $rights = $this->userPostingRightsManager->getUserConsolidatedPostingRight(
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
    $rights = $this->userPostingRightsManager->getUserConsolidatedPostingRight(
      $this->testUser,
      'invalid_bundle',
      [$this->testSource->id()]
    );

    $this->assertEquals(0, $rights['code'], 'Should return unverified code for invalid bundle');
    $this->assertEquals('unverified', $rights['name'], 'Should return unverified name for invalid bundle');

    // Test with empty sources.
    $rights = $this->userPostingRightsManager->getUserConsolidatedPostingRight(
      $this->testUser,
      'job',
      []
    );

    $this->assertEquals(0, $rights['code'], 'Should return unverified code for empty sources');
    $this->assertEquals('unverified', $rights['name'], 'Should return unverified name for empty sources');

    // Test with anonymous user.
    $anonymous_user = User::getAnonymousUser();
    $rights = $this->userPostingRightsManager->getUserConsolidatedPostingRight(
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
    $has_rights = $this->userPostingRightsManager->userHasPostingRights(
      $this->testUser,
      $this->testEntity,
      'published'
    );
    $this->assertTrue($has_rights, 'User with allowed rights should have posting rights');

    // Test with user who is blocked.
    $has_rights = $this->userPostingRightsManager->userHasPostingRights(
      $this->testUser,
      $this->testEntity,
      'published'
    );
    // The user has mixed rights (allowed for job, blocked for report), so they
    // should have rights.
    $this->assertTrue($has_rights, 'User with mixed rights should have posting rights');

    // Test with draft status for blocked user (should allow owner).
    $has_rights = $this->userPostingRightsManager->userHasPostingRights(
      $this->testUser,
      $this->testEntity,
      'draft'
    );
    $this->assertTrue($has_rights, 'Owner should have rights for draft even if blocked');

    // Test with anonymous user.
    $anonymous_user = User::getAnonymousUser();
    $has_rights = $this->userPostingRightsManager->userHasPostingRights(
      $anonymous_user,
      $this->testEntity,
      'published'
    );
    $this->assertFalse($has_rights, 'Anonymous user should not have posting rights');

    // Test with new entity owned by the user - should grant posting rights.
    $new_entity_owned = Node::create([
      'type' => 'job',
      'title' => 'New Job Owned by User',
      'uid' => $this->testUser->id(),
    ]);

    $has_rights = $this->userPostingRightsManager->userHasPostingRights(
      $this->testUser,
      $new_entity_owned,
      'draft'
    );
    $this->assertTrue($has_rights, 'New entity without ID owned by user should grant posting rights');

    // Test with new entity owned by a different user - should not grant
    // posting rights.
    $different_user = $this->createUser([], 'different_user', FALSE, [
      'name' => 'different_user',
      'mail' => 'different@example.com',
      'status' => 1,
    ]);

    $new_entity_not_owned = Node::create([
      'type' => 'job',
      'title' => 'New Job Owned by Different User',
      'uid' => $different_user->id(),
    ]);

    $has_rights = $this->userPostingRightsManager->userHasPostingRights(
      $this->testUser,
      $new_entity_not_owned,
      'draft'
    );
    $this->assertFalse($has_rights, 'New entity without ID not owned by user should not grant posting rights');
  }

  /**
   * Test isUserAllowedOrTrustedForAnySource method.
   */
  public function testIsUserAllowedOrTrustedForAnySource(): void {
    // Test with user who has allowed/trusted rights.
    $is_allowed = $this->userPostingRightsManager->isUserAllowedOrTrustedForAnySource($this->testUser, 'job');
    $this->assertTrue($is_allowed, 'User with allowed rights should be considered allowed');

    $is_allowed = $this->userPostingRightsManager->isUserAllowedOrTrustedForAnySource($this->testUser, 'training');
    $this->assertTrue($is_allowed, 'User with trusted rights should be considered allowed');

    // Test with user who has allowed/trusted rights for reports.
    $is_allowed = $this->userPostingRightsManager->isUserAllowedOrTrustedForAnySource($this->testUser, 'report');
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
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Training.
        ['value' => 2],
        // Report.
        ['value' => 1],
      ],
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

    $is_allowed = $this->userPostingRightsManager->isUserAllowedOrTrustedForAnySource($blocked_user, 'job');
    $this->assertFalse($is_allowed, 'User with only blocked rights should not be considered allowed');

    // Test with anonymous user.
    $anonymous_user = User::getAnonymousUser();
    $is_allowed = $this->userPostingRightsManager->isUserAllowedOrTrustedForAnySource($anonymous_user, 'job');
    $this->assertFalse($is_allowed, 'Anonymous user should not be considered allowed');

    // Test with invalid bundle.
    $this->expectException(\InvalidArgumentException::class);
    $this->userPostingRightsManager->isUserAllowedOrTrustedForAnySource($this->testUser, 'invalid_bundle');
  }

  /**
   * Test getSourcesWithPostingRightsForUser method.
   */
  public function testGetSourcesWithPostingRightsForUser(): void {
    // Test getting sources with specific rights.
    $sources = $this->userPostingRightsManager->getSourcesWithPostingRightsForUser(
      $this->testUser,
      // Allowed or trusted.
      ['job' => [2, 3]],
      'OR'
    );

    $this->assertArrayHasKey($this->testSource->id(), $sources, 'Should return source with allowed/trusted job rights');
    $this->assertEquals(2, $sources[$this->testSource->id()]['job'], 'Should return correct job rights');

    // Test with limit.
    $sources = $this->userPostingRightsManager->getSourcesWithPostingRightsForUser(
      $this->testUser,
      // Trusted only.
      ['training' => [3]],
      'AND',
      1
    );

    $this->assertCount(1, $sources, 'Should respect limit parameter');
    $this->assertEquals(3, $sources[$this->testSource->id()]['training'], 'Should return correct training rights');

    // Test with no bundle filters.
    $sources = $this->userPostingRightsManager->getSourcesWithPostingRightsForUser($this->testUser);
    $this->assertNotEmpty($sources, 'Should return sources without bundle filters');

    // Test that domain rights are included when no user rights exist.
    $sources = $this->userPostingRightsManager->getSourcesWithPostingRightsForUser(
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
    $sources = $this->userPostingRightsManager->getSourcesWithPostingRightsForUser(
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
    $combined_sources = $this->userPostingRightsManager->getSourcesWithPostingRightsForUser(
      $this->testUser,
      ['job' => [2, 3]],
      'OR'
    );

    // Get user-only sources.
    $user_sources = $this->userPostingRightsManager->getSourcesWithUserPostingRightsForUser(
      $this->testUser,
      ['job' => [2, 3]],
      'OR'
    );

    // Get domain-only sources.
    $domain_sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForUser(
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
    $sources = $this->userPostingRightsManager->getSourcesWithUserPostingRightsForUser(
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
    $sources = $this->userPostingRightsManager->getSourcesWithUserPostingRightsForUser(
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
    $sources = $this->userPostingRightsManager->getSourcesWithUserPostingRightsForUser(
      $this->testUser,
      ['job' => [2, 3]],
      'OR',
      1
    );

    $this->assertCount(1, $sources, 'Should respect limit parameter');

    // Test with no bundle filters (should return all user posting rights).
    $sources = $this->userPostingRightsManager->getSourcesWithUserPostingRightsForUser($this->testUser);
    $this->assertNotEmpty($sources, 'Should return sources without bundle filters');
    $this->assertArrayHasKey($this->testSource->id(), $sources, 'Should include test source');
    $this->assertArrayHasKey($this->userOnlySource->id(), $sources, 'Should include user-only source');
  }

  /**
   * Test bundle filtering with AND operator for user posting rights.
   */
  public function testGetSourcesWithUserPostingRightsForUserWithAndOperator(): void {
    // Create a source with mixed rights for the test user.
    $mixedRightsSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Mixed Rights Source',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Training.
        ['value' => 2],
        // Report.
        ['value' => 1],
      ],
      'field_user_posting_rights' => [
        [
          'id' => $this->testUser->id(),
          // Trusted.
          'job' => 3,
          // Allowed.
          'training' => 2,
          // Blocked.
          'report' => 1,
        ],
      ],
    ]);

    // Test AND operator: should only return sources that match ALL bundle
    // conditions. Looking for sources where user is trusted for job (3) AND
    // allowed for training (2).
    $sources = $this->userPostingRightsManager->getSourcesWithUserPostingRightsForUser(
      $this->testUser,
      [
        // Trusted.
        'job' => [3],
        // Allowed.
        'training' => [2],
      ],
      'AND'
    );

    // Should include the mixed rights source since it matches both conditions.
    $this->assertArrayHasKey($mixedRightsSource->id(), $sources, 'Should include source that matches ALL AND conditions');
    $this->assertEquals(3, $sources[$mixedRightsSource->id()]['job'], 'Should have trusted job rights');
    $this->assertEquals(2, $sources[$mixedRightsSource->id()]['training'], 'Should have allowed training rights');

    // Test AND operator with conflicting conditions.
    $sources = $this->userPostingRightsManager->getSourcesWithUserPostingRightsForUser(
      $this->testUser,
      [
        // Trusted.
        'job' => [3],
        // Trusted to have a mismatch since the user is allowed (2).
        'training' => [3],
      ],
      'AND'
    );

    // Should NOT include the mixed rights source since it doesn't match both
    // conditions.
    $this->assertArrayNotHasKey($mixedRightsSource->id(), $sources, 'Should not include source that does not match ALL AND conditions');
  }

  /**
   * Test bundle filtering with OR operator for user posting rights.
   */
  public function testGetSourcesWithUserPostingRightsForUserWithOrOperator(): void {
    // Create a source with mixed rights for the test user.
    $mixedRightsSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Mixed Rights Source',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Training.
        ['value' => 2],
        // Report.
        ['value' => 1],
      ],
      'field_user_posting_rights' => [
        [
          'id' => $this->testUser->id(),
          // Trusted.
          'job' => 3,
          // Allowed.
          'training' => 2,
          // Blocked.
          'report' => 1,
        ],
      ],
    ]);

    // Test OR operator: should return sources that match ANY bundle condition.
    // Looking for sources where user is trusted for job (3) OR allowed for
    // training (2).
    $sources = $this->userPostingRightsManager->getSourcesWithUserPostingRightsForUser(
      $this->testUser,
      [
        // Trusted.
        'job' => [3],
        // Allowed.
        'training' => [2],
      ],
      'OR'
    );

    // Should include the mixed rights source since it matches at least one
    // condition.
    $this->assertArrayHasKey($mixedRightsSource->id(), $sources, 'Should include source that matches ANY OR condition');
    $this->assertEquals(3, $sources[$mixedRightsSource->id()]['job'], 'Should have trusted job rights');
    $this->assertEquals(2, $sources[$mixedRightsSource->id()]['training'], 'Should have allowed training rights');

    // Test OR operator with one matching condition.
    $sources = $this->userPostingRightsManager->getSourcesWithUserPostingRightsForUser(
      $this->testUser,
      [
        // Trusted.
        'job' => [3],
        // Trusted to have a mismatch since the user is allowed (2).
        'training' => [3],
      ],
      'OR'
    );

    // Should include the mixed rights source since it matches the job
    // condition.
    $this->assertArrayHasKey($mixedRightsSource->id(), $sources, 'Should include source that matches at least one OR condition');
  }

  /**
   * Test bundle filtering with AND operator for domain posting rights.
   */
  public function testGetSourcesWithDomainPostingRightsForUserWithAndOperator(): void {
    // Create a source with domain posting rights.
    $domainRightsSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Domain Rights Source',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Training.
        ['value' => 2],
        // Report.
        ['value' => 1],
      ],
      'field_domain_posting_rights' => [
        [
          'domain' => 'example.com',
          // Trusted.
          'job' => 3,
          // Allowed.
          'training' => 2,
          // Unverified.
          'report' => 0,
        ],
      ],
    ]);

    // Test AND operator: should only return sources that match ALL bundle
    // conditions.
    $sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForUser(
      $this->testUser,
      [
        // Trusted.
        'job' => [3],
        // Allowed.
        'training' => [2],
      ],
      'AND'
    );

    // Should include the domain rights source since it matches both conditions.
    $this->assertArrayHasKey($domainRightsSource->id(), $sources, 'Should include source that matches ALL AND conditions');
    $this->assertEquals(3, $sources[$domainRightsSource->id()]['job'], 'Should have trusted job rights');
    $this->assertEquals(2, $sources[$domainRightsSource->id()]['training'], 'Should have allowed training rights');
  }

  /**
   * Test bundle filtering with OR operator for domain posting rights.
   */
  public function testGetSourcesWithDomainPostingRightsForUserWithOrOperator(): void {
    // Create a source with domain posting rights.
    $domainRightsSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Domain Rights Source',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Training.
        ['value' => 2],
        // Report.
        ['value' => 1],
      ],
      'field_domain_posting_rights' => [
        [
          'domain' => 'example.com',
          // Trusted.
          'job' => 3,
          // Allowed.
          'training' => 2,
          // Unverified.
          'report' => 0,
        ],
      ],
    ]);

    // Test OR operator: should return sources that match ANY bundle condition.
    $sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForUser(
      $this->testUser,
      [
        // Trusted.
        'job' => [3],
        // Allowed.
        'training' => [2],
      ],
      'OR'
    );

    // Should include the domain rights source since it matches at least one
    // condition.
    $this->assertArrayHasKey($domainRightsSource->id(), $sources, 'Should include source that matches ANY OR condition');
    $this->assertEquals(3, $sources[$domainRightsSource->id()]['job'], 'Should have trusted job rights');
    $this->assertEquals(2, $sources[$domainRightsSource->id()]['training'], 'Should have allowed training rights');

    // Test OR operator with one matching condition.
    $sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForUser(
      $this->testUser,
      [
        // Trusted.
        'job' => [3],
        // Trusted to have a mismatch since the user is allowed (2).
        'training' => [3],
      ],
      'OR'
    );

    // Should include the domain rights source since it matches the job
    // condition.
    $this->assertArrayHasKey($domainRightsSource->id(), $sources, 'Should include source that matches at least one OR condition');
  }

  /**
   * Test getSourcesWithDomainPostingRightsForUser method.
   */
  public function testGetSourcesWithDomainPostingRightsForUser(): void {
    // Test getting sources with domain posting rights only.
    $sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForUser(
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
    $sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForUser(
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
    $sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForUser(
      $this->testUser,
      ['job' => [2, 3]],
      'OR',
      1
    );

    $this->assertCount(1, $sources, 'Should respect limit parameter');

    // Test with no bundle filters (should return all domain posting rights).
    $sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForUser($this->testUser);
    $this->assertNotEmpty($sources, 'Should return sources without bundle filters');
    $this->assertArrayHasKey($this->testSource->id(), $sources, 'Should include test source');
    $this->assertArrayHasKey($this->domainOnlySource->id(), $sources, 'Should include domain-only source');

    // Test with user without email domain.
    $user_without_email = $this->createUser([], 'no_email_user', FALSE, [
      'name' => 'no_email_user',
      'mail' => '',
      'status' => 1,
    ]);

    $sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForUser($user_without_email);
    $this->assertEmpty($sources, 'User without email should have no domain posting rights');

    // Test with user with different email domain.
    $user_different_domain = $this->createUser([], 'different_domain_user', FALSE, [
      'name' => 'different_domain_user',
      'mail' => 'user@different.com',
      'status' => 1,
    ]);

    $sources = $this->userPostingRightsManager->getSourcesWithDomainPostingRightsForUser($user_different_domain);
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
      return $this->userPostingRightsManager->renderRight('allowed');
    });
    $this->assertNotEmpty($rendered, 'Should render allowed right');

    $rendered = $renderer->executeInRenderContext($context, function () {
      return $this->userPostingRightsManager->renderRight('blocked');
    });
    $this->assertNotEmpty($rendered, 'Should render blocked right');

    $rendered = $renderer->executeInRenderContext($context, function () {
      return $this->userPostingRightsManager->renderRight('trusted');
    });
    $this->assertNotEmpty($rendered, 'Should render trusted right');

    $rendered = $renderer->executeInRenderContext($context, function () {
      return $this->userPostingRightsManager->renderRight('unverified');
    });
    $this->assertNotEmpty($rendered, 'Should render unverified right');
  }

  /**
   * Test posting rights filtering with allowed content types.
   */
  public function testPostingRightsWithAllowedContentTypes(): void {
    // Create a source that only allows job content.
    $jobOnlySource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Job Only Source',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
      ],
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
    ]);

    // Test getUserPostingRights with content type filtering.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$jobOnlySource->id()]);
    $this->assertArrayHasKey($jobOnlySource->id(), $rights);
    $this->assertEquals(2, $rights[$jobOnlySource->id()]['job'], 'Should keep job rights for allowed content type');
    $this->assertEquals(0, $rights[$jobOnlySource->id()]['training'], 'Should reset training rights for non-allowed content type');
    $this->assertEquals(0, $rights[$jobOnlySource->id()]['report'], 'Should reset report rights for non-allowed content type');

    // Create a source that only allows report content.
    $reportOnlySource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Report Only Source',
      'field_allowed_content_types' => [
        // Report.
        ['value' => 1],
      ],
      'field_domain_posting_rights' => [
        [
          'domain' => 'example.com',
          // Allowed.
          'job' => 2,
          // Trusted.
          'training' => 3,
          // Allowed.
          'report' => 2,
        ],
      ],
    ]);

    // Test domain posting rights with content type filtering.
    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$reportOnlySource->id()]);
    $this->assertArrayHasKey($reportOnlySource->id(), $rights);
    $this->assertEquals(0, $rights[$reportOnlySource->id()]['job'], 'Should reset job rights for non-allowed content type');
    $this->assertEquals(0, $rights[$reportOnlySource->id()]['training'], 'Should reset training rights for non-allowed content type');
    $this->assertEquals(2, $rights[$reportOnlySource->id()]['report'], 'Should keep report rights for allowed content type');

    // Test getEntityAuthorPostingRights with content type filtering.
    $jobEntity = $this->createNode([
      'type' => 'job',
      'title' => 'Job with Job-Only Source',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $jobOnlySource->id()],
      ],
    ]);

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($jobEntity);
    $this->assertEquals('allowed', $rights, 'Job entity with job-only source should have allowed rights');

    $reportEntity = $this->createNode([
      'type' => 'report',
      'title' => 'Report with Job-Only Source',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $jobOnlySource->id()],
      ],
    ]);

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($reportEntity);
    $this->assertEquals('unverified', $rights, 'Report entity with job-only source should have unverified rights (source not allowed for reports)');

    // Test with source that has no allowed content types.
    $noTypesSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'No Types Source',
      // No field_allowed_content_types - this is intentional for testing.
      'field_user_posting_rights' => [
        [
          'id' => $this->testUser->id(),
          'job' => 2,
          'training' => 3,
          'report' => 2,
        ],
      ],
    ]);

    $rights = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$noTypesSource->id()]);
    $this->assertArrayHasKey($noTypesSource->id(), $rights);
    $this->assertEquals(0, $rights[$noTypesSource->id()]['job'], 'Should reset all rights for source with no allowed content types');
    $this->assertEquals(0, $rights[$noTypesSource->id()]['training'], 'Should reset all rights for source with no allowed content types');
    $this->assertEquals(0, $rights[$noTypesSource->id()]['report'], 'Should reset all rights for source with no allowed content types');
  }

  /**
   * Test getSourcesWithPostingRightsForUser filtering by allowed content types.
   */
  public function testGetSourcesWithPostingRightsForUserWithContentTypes(): void {
    // Create sources with different allowed content types.
    $jobTrainingSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Job and Training Source',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
        // Training.
        ['value' => 2],
      ],
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
    ]);

    $reportOnlySource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Report Only Source',
      'field_allowed_content_types' => [
        // Report.
        ['value' => 1],
      ],
      'field_domain_posting_rights' => [
        [
          'domain' => 'example.com',
          // Allowed.
          'job' => 2,
          // Trusted.
          'training' => 3,
          // Allowed.
          'report' => 2,
        ],
      ],
    ]);

    // Test getting sources with job rights - should include jobTrainingSource
    // but not reportOnlySource.
    $sources = $this->userPostingRightsManager->getSourcesWithPostingRightsForUser(
      $this->testUser,
      ['job' => [2, 3]],
      'OR'
    );

    $this->assertArrayHasKey($jobTrainingSource->id(), $sources, 'Should include source with allowed job content type');
    $this->assertEquals(2, $sources[$jobTrainingSource->id()]['job'], 'Should return job rights for allowed content type');
    $this->assertEquals(0, $sources[$jobTrainingSource->id()]['report'], 'Should reset report rights for non-allowed content type');

    // Test getting sources with report rights - should include reportOnlySource
    // but not jobTrainingSource.
    $sources = $this->userPostingRightsManager->getSourcesWithPostingRightsForUser(
      $this->testUser,
      ['report' => [2, 3]],
      'OR'
    );

    $this->assertArrayHasKey($reportOnlySource->id(), $sources, 'Should include source with allowed report content type');
    $this->assertEquals(2, $sources[$reportOnlySource->id()]['report'], 'Should return report rights for allowed content type');
    $this->assertEquals(0, $sources[$reportOnlySource->id()]['job'], 'Should reset job rights for non-allowed content type');
  }

  /**
   * Test userHasPostingRights with allowed content types filtering.
   */
  public function testUserHasPostingRightsWithContentTypes(): void {
    // Create a source that only allows job content.
    $jobOnlySource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Job Only Source',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
      ],
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
    ]);

    // Test with job entity - should have rights.
    $jobEntity = $this->createNode([
      'type' => 'job',
      'title' => 'Job with Job-Only Source',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $jobOnlySource->id()],
      ],
    ]);

    $has_rights = $this->userPostingRightsManager->userHasPostingRights(
      $this->testUser,
      $jobEntity,
      'published'
    );
    $this->assertTrue($has_rights, 'User should have rights for job entity with job-only source');

    // Test with report entity - should have rights because user is owner
    // (content type filtering only affects posting rights values, not access).
    $reportEntity = $this->createNode([
      'type' => 'report',
      'title' => 'Report with Job-Only Source',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $jobOnlySource->id()],
      ],
    ]);

    $has_rights = $this->userPostingRightsManager->userHasPostingRights(
      $this->testUser,
      $reportEntity,
      'published'
    );
    $this->assertTrue($has_rights, 'User should have rights for report entity because they are the owner');

    // Test with training entity - should have rights because user is owner
    // (content type filtering only affects posting rights values, not access).
    $trainingEntity = $this->createNode([
      'type' => 'training',
      'title' => 'Training with Job-Only Source',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $jobOnlySource->id()],
      ],
    ]);

    $has_rights = $this->userPostingRightsManager->userHasPostingRights(
      $this->testUser,
      $trainingEntity,
      'published'
    );
    $this->assertTrue($has_rights, 'User should have rights for training entity because they are the owner');
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

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($entity_with_empty_source);
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

    $rights = $this->userPostingRightsManager->getEntityAuthorPostingRights($mock_entity);
    $this->assertEquals('unknown', $rights, 'Entity with invalid source field type should return unknown');
  }

  /**
   * Test caching behavior.
   */
  public function testCaching(): void {
    // First call should populate cache.
    $rights1 = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->testSource->id()]);

    // Second call should use cache.
    $rights2 = $this->userPostingRightsManager->getUserPostingRights($this->testUser, [$this->testSource->id()]);

    $this->assertEquals($rights1, $rights2, 'Cached results should match first call');

    // Test that different source combinations create different cache keys.
    $rights3 = $this->userPostingRightsManager->getUserPostingRights($this->testUser, []);
    $this->assertNotEquals($rights1, $rights3, 'Different source combinations should have different cache keys');
  }

  /**
   * Test updateModerationStatusFromPostingRights with basic functionality.
   */
  public function testUpdateModerationStatusFromPostingRights(): void {
    // Set up a test mapping for the method.
    $mapping = [
      'advertiser' => [
        'job' => [
          'blocked' => 'refused',
          'trusted_all' => 'published',
          'trusted_some_allowed' => 'published',
          'trusted_some_unverified' => 'pending',
          'allowed_all' => 'published',
          'allowed_some_unverified' => 'pending',
          'unverified_all' => 'pending',
        ],
      ],
    ];
    $this->userPostingRightsManager->setUserPostingRightsToModerationStatusMapping($mapping);

    // Create a test user with advertiser role.
    $advertiser_user = $this->createUser([], 'advertiser_user', FALSE, [
      'name' => 'advertiser_user',
      'mail' => 'advertiser@example.com',
      'status' => 1,
      'roles' => ['advertiser'],
    ]);

    // Create a job entity with pending status.
    $job_entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job for Status Update',
      'uid' => $advertiser_user->id(),
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
      'moderation_status' => 'draft',
      'field_job_closing_date' => date('Y-m-d', strtotime('+2 days')),
    ]);

    // Set the entity to pending status (simulating the moderation status).
    $job_entity->set('moderation_status', 'pending');
    $job_entity->save();

    // Test the method - should update status based on posting rights.
    $this->userPostingRightsManager->updateModerationStatusFromPostingRights(
      $job_entity,
      $advertiser_user,
      ['pending']
    );

    // Reload the entity to get updated values.
    $job_entity = \Drupal::entityTypeManager()->getStorage('node')->load($job_entity->id());

    // Check that the moderation status was updated.
    $this->assertEquals('published', $job_entity->get('moderation_status')->value, 'Entity status should be updated to published for allowed user');

    // Check that a revision log message was added.
    $revision_log = $job_entity->get('revision_log')->value;
    $this->assertNotEmpty($revision_log, 'Revision log should contain posting rights information');
    $this->assertStringContainsString('Allowed user for', $revision_log, 'Revision log should mention allowed user');
  }

  /**
   * Test updateModerationStatusFromPostingRights with blocked user scenario.
   */
  public function testUpdateModerationStatusFromPostingRightsBlocked(): void {
    // Set up a test mapping for blocked users.
    $mapping = [
      'submitter' => [
        'report' => [
          'blocked' => 'refused',
          'trusted_all' => 'published',
          'trusted_some_allowed' => 'published',
          'trusted_some_unverified' => 'pending',
          'allowed_all' => 'published',
          'allowed_some_unverified' => 'pending',
          'unverified_all' => 'pending',
        ],
      ],
    ];
    $this->userPostingRightsManager->setUserPostingRightsToModerationStatusMapping($mapping);

    // Create a test user with submitter role.
    $submitter_user = $this->createUser([], 'blocked_submitter', FALSE, [
      'name' => 'blocked_submitter',
      'mail' => 'blocked@blocked.test',
      'status' => 1,
      'roles' => ['submitter'],
    ]);

    // Create a source with blocked posting rights.
    $blocked_source = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Blocked Source',
      'field_allowed_content_types' => [
        // Report.
        ['value' => 1],
      ],
      'field_user_posting_rights' => [
        [
          'id' => $submitter_user->id(),
           // Blocked.
          'report' => '1',
          'job' => '0',
          'training' => '0',
        ],
      ],
    ]);

    // Create a report entity with pending status.
    $report_entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report for Blocked User',
      'uid' => $submitter_user->id(),
      'field_source' => [
        ['target_id' => $blocked_source->id()],
      ],
      'moderation_status' => 'draft',
    ]);

    // Set the entity to pending status.
    $report_entity->set('moderation_status', 'pending');
    $report_entity->save();

    // Test the method - should update status to refused for blocked user.
    $this->userPostingRightsManager->updateModerationStatusFromPostingRights(
      $report_entity,
      $submitter_user,
      ['pending']
    );

    // Reload the entity to get updated values.
    $report_entity = \Drupal::entityTypeManager()->getStorage('node')->load($report_entity->id());

    // Check that the moderation status was updated to refused.
    $this->assertEquals('refused', $report_entity->get('moderation_status')->value, 'Entity status should be updated to refused for blocked user');

    // Check that a revision log message was added.
    $revision_log = $report_entity->get('revision_log')->value;
    $this->assertNotEmpty($revision_log, 'Revision log should contain posting rights information');
    $this->assertStringContainsString('Blocked user for', $revision_log, 'Revision log should mention blocked user');
  }

  /**
   * Test updateModerationStatusFromPostingRights with trusted user scenario.
   */
  public function testUpdateModerationStatusFromPostingRightsTrusted(): void {
    // Set up a test mapping for trusted users.
    $mapping = [
      'advertiser' => [
        'training' => [
          'blocked' => 'refused',
          'trusted_all' => 'published',
          'trusted_some_allowed' => 'published',
          'trusted_some_unverified' => 'pending',
          'allowed_all' => 'published',
          'allowed_some_unverified' => 'pending',
          'unverified_all' => 'pending',
        ],
      ],
    ];
    $this->userPostingRightsManager->setUserPostingRightsToModerationStatusMapping($mapping);

    // Create a test user with advertiser role.
    $advertiser_user = $this->createUser([], 'trusted_advertiser', FALSE, [
      'name' => 'trusted_advertiser',
      'mail' => 'trusted@example.com',
      'status' => 1,
      'roles' => ['advertiser'],
    ]);

    // Create a source that explicitly grants trusted training rights
    // to this advertiser user and allows training content.
    $trusted_training_source = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Trusted Training Source (Per-User)',
      'field_allowed_content_types' => [
        // Training.
        ['value' => 2],
      ],
      'field_user_posting_rights' => [
        [
          'id' => $advertiser_user->id(),
          'job' => 0,
          // Trusted.
          'training' => 3,
          'report' => 0,
        ],
      ],
    ]);

    // Create a training entity with pending status.
    $training_entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training for Trusted User',
      'uid' => $advertiser_user->id(),
      'field_source' => [
        ['target_id' => $trusted_training_source->id()],
      ],
      'moderation_status' => 'draft',
      'field_registration_deadline' => date('Y-m-d', strtotime('+2 days')),
      'field_training_date' => [
        'start' => date('Y-m-d', strtotime('+3 days')),
        'end' => date('Y-m-d', strtotime('+4 days')),
      ],
    ]);

    // Set the entity to pending status.
    $training_entity->set('moderation_status', 'pending');
    $training_entity->save();

    // Test the method - should update status to published for trusted user.
    $this->userPostingRightsManager->updateModerationStatusFromPostingRights(
      $training_entity,
      $advertiser_user,
      ['pending']
    );

    // Reload the entity to get updated values.
    $training_entity = \Drupal::entityTypeManager()->getStorage('node')->load($training_entity->id());

    // Check that the moderation status was updated to published.
    $this->assertEquals('published', $training_entity->get('moderation_status')->value, 'Entity status should be updated to published for trusted user');

    // Check that a revision log message was added.
    $revision_log = $training_entity->get('revision_log')->value;
    $this->assertNotEmpty($revision_log, 'Revision log should contain posting rights information');
    $this->assertStringContainsString('Trusted user for', $revision_log, 'Revision log should mention trusted user');
  }

  /**
   * Test updateModerationStatusFromPostingRights with unverified user scenario.
   */
  public function testUpdateModerationStatusFromPostingRightsUnverified(): void {
    // Set up a test mapping for unverified users.
    $mapping = [
      'advertiser' => [
        'job' => [
          'blocked' => 'refused',
          'trusted_all' => 'published',
          'trusted_some_allowed' => 'published',
          'trusted_some_unverified' => 'pending',
          'allowed_all' => 'published',
          'allowed_some_unverified' => 'pending',
          'unverified_all' => 'pending',
        ],
      ],
    ];
    $this->userPostingRightsManager->setUserPostingRightsToModerationStatusMapping($mapping);

    // Create a test user with advertiser role.
    $advertiser_user = $this->createUser([], 'unverified_advertiser', FALSE, [
      'name' => 'unverified_advertiser',
      'mail' => 'unverified@example.com',
      'status' => 1,
      'roles' => ['advertiser'],
    ]);

    // Create a job entity with pending status.
    $job_entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job for Unverified User',
      'uid' => $advertiser_user->id(),
      'field_source' => [
        ['target_id' => $this->noRightsSource->id()],
      ],
      'moderation_status' => 'draft',
    ]);

    // Set the entity to pending status.
    $job_entity->set('moderation_status', 'pending');
    $job_entity->save();

    // Test the method - should keep status as pending for unverified user.
    $this->userPostingRightsManager->updateModerationStatusFromPostingRights(
      $job_entity,
      $advertiser_user,
      ['pending']
    );

    // Reload the entity to get updated values.
    $job_entity = \Drupal::entityTypeManager()->getStorage('node')->load($job_entity->id());

    // Check that the moderation status remains pending.
    $this->assertEquals('pending', $job_entity->get('moderation_status')->value, 'Entity status should remain pending for unverified user');

    // Check that a revision log message was added.
    $revision_log = $job_entity->get('revision_log')->value;
    $this->assertNotEmpty($revision_log, 'Revision log should contain posting rights information');
    $this->assertStringContainsString('Unverified user for', $revision_log, 'Revision log should mention unverified user');
  }

  /**
   * Test updateModerationStatusFromPostingRights edge cases.
   */
  public function testUpdateModerationStatusFromPostingRightsEdgeCases(): void {
    // Test with anonymous user - should not update status.
    $anonymous_user = User::getAnonymousUser();
    $job_entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job for Anonymous User',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
      'moderation_status' => 'draft',
    ]);

    $job_entity->set('moderation_status', 'pending');
    $job_entity->save();

    $original_status = $job_entity->get('moderation_status')->value;
    $this->userPostingRightsManager->updateModerationStatusFromPostingRights(
      $job_entity,
      $anonymous_user,
      ['pending']
    );

    $job_entity = \Drupal::entityTypeManager()->getStorage('node')->load($job_entity->id());
    $this->assertEquals($original_status, $job_entity->get('moderation_status')->value, 'Status should not change for anonymous user');

    // Test with entity that has no sources - should not update status.
    $job_entity_no_sources = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job without Sources',
      'uid' => $this->testUser->id(),
      'moderation_status' => 'draft',
    ]);

    $job_entity_no_sources->set('moderation_status', 'pending');
    $job_entity_no_sources->save();

    $original_status = $job_entity_no_sources->get('moderation_status')->value;
    $this->userPostingRightsManager->updateModerationStatusFromPostingRights(
      $job_entity_no_sources,
      $this->testUser,
      ['pending']
    );

    $job_entity_no_sources = \Drupal::entityTypeManager()->getStorage('node')->load($job_entity_no_sources->id());
    $this->assertEquals($original_status, $job_entity_no_sources->get('moderation_status')->value, 'Status should not change for entity without sources');

    // Test with entity that has different status than expected.
    $job_entity_published = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job with Published Status',
      'uid' => $this->testUser->id(),
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
      'moderation_status' => 'draft',
    ]);

    $job_entity_published->set('moderation_status', 'published');
    $job_entity_published->save();

    $original_status = $job_entity_published->get('moderation_status')->value;
    $this->userPostingRightsManager->updateModerationStatusFromPostingRights(
      $job_entity_published,
      $this->testUser,
      ['pending']
    );

    $job_entity_published = \Drupal::entityTypeManager()->getStorage('node')->load($job_entity_published->id());
    $this->assertEquals($original_status, $job_entity_published->get('moderation_status')->value, 'Status should not change for entity with non-target status');

    // Test with user that has no role mapping - should not update status.
    $user_no_role = $this->createUser([], 'no_role_user', FALSE, [
      'name' => 'no_role_user',
      'mail' => 'norole@example.com',
      'status' => 1,
    ]);

    $job_entity_no_role = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job for User with No Role',
      'uid' => $user_no_role->id(),
      'field_source' => [
        ['target_id' => $this->testSource->id()],
      ],
      'moderation_status' => 'draft',
      'field_job_closing_date' => date('Y-m-d', strtotime('+2 days')),
    ]);

    $job_entity_no_role->set('moderation_status', 'pending');
    $job_entity_no_role->save();

    $original_status = $job_entity_no_role->get('moderation_status')->value;
    $this->userPostingRightsManager->updateModerationStatusFromPostingRights(
      $job_entity_no_role,
      $user_no_role,
      ['pending']
    );

    $job_entity_no_role = \Drupal::entityTypeManager()->getStorage('node')->load($job_entity_no_role->id());
    $this->assertEquals($original_status, $job_entity_no_role->get('moderation_status')->value, 'Status should not change for user with no role mapping');
  }

  /**
   * Test updateModerationStatusFromPostingRights with mixed posting rights.
   */
  public function testUpdateModerationStatusFromPostingRightsMixedRights(): void {
    // Set up a test mapping for mixed rights scenarios.
    $mapping = [
      'advertiser' => [
        'job' => [
          'blocked' => 'refused',
          'trusted_all' => 'published',
          'trusted_some_allowed' => 'published',
          'trusted_some_unverified' => 'pending',
          'allowed_all' => 'published',
          'allowed_some_unverified' => 'pending',
          'unverified_all' => 'pending',
        ],
      ],
    ];
    $this->userPostingRightsManager->setUserPostingRightsToModerationStatusMapping($mapping);

    // Create a test user with advertiser role.
    $advertiser_user = $this->createUser([], 'mixed_rights_user', FALSE, [
      'name' => 'mixed_rights_user',
      'mail' => 'mixed@example.com',
      'status' => 1,
      'roles' => ['advertiser'],
    ]);

    // Create sources that explicitly grant trusted and allowed rights for job
    // and allow job content.
    $trusted_job_source = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Trusted Job Source (Per-User)',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
      ],
      'field_user_posting_rights' => [
        [
          'id' => $advertiser_user->id(),
          // Trusted job.
          'job' => 3,
          'training' => 0,
          'report' => 0,
        ],
      ],
    ]);

    $allowed_job_source = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Allowed Job Source (Domain)',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
      ],
      'field_domain_posting_rights' => [
        [
          'domain' => 'example.com',
          // Allowed job.
          'job' => 2,
          'training' => 0,
          'report' => 0,
        ],
      ],
    ]);

    // Create a job entity with multiple sources (trusted + allowed).
    $job_entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job with Mixed Rights',
      'uid' => $advertiser_user->id(),
      'field_source' => [
        ['target_id' => $trusted_job_source->id()],
        ['target_id' => $allowed_job_source->id()],
      ],
      'moderation_status' => 'draft',
      'field_job_closing_date' => date('Y-m-d', strtotime('+2 days')),
    ]);

    $job_entity->set('moderation_status', 'pending');
    $job_entity->save();

    // Test the method - should update status based on mixed rights.
    $this->userPostingRightsManager->updateModerationStatusFromPostingRights(
      $job_entity,
      $advertiser_user,
      ['pending']
    );

    // Reload the entity to get updated values.
    $job_entity = \Drupal::entityTypeManager()->getStorage('node')->load($job_entity->id());

    // Check that the moderation status was updated to published.
    $this->assertEquals('published', $job_entity->get('moderation_status')->value, 'Entity status should be updated to published for mixed trusted/allowed rights');

    // Check that a revision log message was added with both rights mentioned.
    $revision_log = $job_entity->get('revision_log')->value;
    $this->assertNotEmpty($revision_log, 'Revision log should contain posting rights information');
    $this->assertStringContainsString('Trusted user for', $revision_log, 'Revision log should mention trusted user');
    $this->assertStringContainsString('Allowed user for', $revision_log, 'Revision log should mention allowed user');
  }

  /**
   * Test job status publication and automatic expiration based on closing date.
   */
  public function testJobPublicationAndExpirationWithClosingDate(): void {
    // Set up a test mapping for advertiser on job.
    $mapping = [
      'advertiser' => [
        'job' => [
          'blocked' => 'refused',
          'trusted_all' => 'published',
          'trusted_some_allowed' => 'published',
          'trusted_some_unverified' => 'pending',
          'allowed_all' => 'published',
          'allowed_some_unverified' => 'pending',
          'unverified_all' => 'pending',
        ],
      ],
    ];
    $this->userPostingRightsManager->setUserPostingRightsToModerationStatusMapping($mapping);

    // Create a test user with advertiser role.
    $advertiser_user = $this->createUser([], 'expiry_advertiser', FALSE, [
      'name' => 'expiry_advertiser',
      'mail' => 'expiry@example.com',
      'status' => 1,
      'roles' => ['advertiser'],
    ]);

    // Create a job source that allows job content and grants trusted job rights
    // to this advertiser user.
    $trusted_job_source = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Trusted Job Source for Expiry Test',
      'field_allowed_content_types' => [
        // Job.
        ['value' => 0],
      ],
      'field_user_posting_rights' => [
        [
          'id' => $advertiser_user->id(),
          // Trusted job.
          'job' => 3,
          'training' => 0,
          'report' => 0,
        ],
      ],
    ]);

    // Case 1: Closing date in the future -> status should be published.
    $future_job = $this->createNode([
      'type' => 'job',
      'title' => 'Job with Future Closing Date',
      'uid' => $advertiser_user->id(),
      'field_source' => [
        ['target_id' => $trusted_job_source->id()],
      ],
      'moderation_status' => 'draft',
      'field_job_closing_date' => date('Y-m-d', strtotime('+2 days')),
    ]);

    // Move to pending and save first to simulate workflow before update.
    $future_job->set('moderation_status', 'pending');
    $future_job->save();

    // Update based on posting rights.
    $this->userPostingRightsManager->updateModerationStatusFromPostingRights(
      $future_job,
      $advertiser_user,
      ['pending']
    );

    // Save to persist the new status.
    $future_job->save();

    // Reload and check final status.
    $future_job = \Drupal::entityTypeManager()->getStorage('node')->load($future_job->id());
    $this->assertEquals('published', $future_job->get('moderation_status')->value, 'Job with future closing date should be published');

    // Case 2: Closing date in the past -> status becomes expired after save.
    $past_job = $this->createNode([
      'type' => 'job',
      'title' => 'Job with Past Closing Date',
      'uid' => $advertiser_user->id(),
      'field_source' => [
        ['target_id' => $trusted_job_source->id()],
      ],
      'moderation_status' => 'draft',
      'field_job_closing_date' => date('Y-m-d', strtotime('-2 days')),
    ]);

    // Move to pending and save first.
    $past_job->set('moderation_status', 'pending');
    $past_job->save();

    // Update based on posting rights; this would set to published first.
    $this->userPostingRightsManager->updateModerationStatusFromPostingRights(
      $past_job,
      $advertiser_user,
      ['pending']
    );

    // Save to trigger preSave hooks that will convert published -> expired.
    $past_job->save();

    // Reload and check final status.
    $past_job = \Drupal::entityTypeManager()->getStorage('node')->load($past_job->id());
    $this->assertEquals('expired', $past_job->get('moderation_status')->value, 'Job with past closing date should be expired');
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
    $reflection = new \ReflectionClass($this->userPostingRightsManager);
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(TRUE);

    return $method->invokeArgs($this->userPostingRightsManager, $arguments);
  }

}
