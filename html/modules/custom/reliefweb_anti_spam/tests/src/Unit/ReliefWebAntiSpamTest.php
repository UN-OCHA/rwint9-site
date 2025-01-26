<?php

namespace Drupal\Tests\reliefweb_anti_spam\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for the reliefweb_anti_spam module.
 *
 * @group reliefweb_anti_spam
 */
class ReliefWebAntiSpamTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // DEBUG.
    require_once DRUPAL_ROOT . '/modules/custom/reliefweb_anti_spam/reliefweb_anti_spam.module';

    parent::setUp();

    $container = new ContainerBuilder();

    $this->setUpConfigFactory($container);
    $this->setUpTimeService($container);

    \Drupal::setContainer($container);
  }

  /**
   * Sets up the configuration factory mock.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerInterface $container
   *   The container to which set the service.
   * @param array $settings
   *   Settings overrides.
   */
  protected function setUpConfigFactory(ContainerInterface $container, array $settings = []): void {
    $settings += [
      'post_limit.enabled' => TRUE,
      'post_limit.number' => 5,
      'post_limit.frequency' => 'day',
      'validation.blacklisted_domains' => 'badsite.com',
      'validation.title_min_words' => 3,
      'validation.title_min_length' => 10,
      'validation.body_min_words' => 5,
      'validation.body_min_length' => 20,
    ];

    // Create a mock configuration.
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnMap([
      ['post_limit.enabled', $settings['post_limit.enabled']],
      ['post_limit.number', $settings['post_limit.number']],
      ['post_limit.frequency', $settings['post_limit.frequency']],
      ['validation.blacklisted_domains', $settings['validation.blacklisted_domains']],
      ['validation.title_min_words', $settings['validation.title_min_words']],
      ['validation.title_min_length', $settings['validation.title_min_length']],
      ['validation.body_min_words', $settings['validation.body_min_words']],
      ['validation.body_min_length', $settings['validation.body_min_length']],
    ]);

    // Mock the config factory service.
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($config);

    // Set up the container with the mocked config factory.
    $container->set('config.factory', $config_factory);
  }

  /**
   * Sets up the time service mock.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerInterface $container
   *   The container to which set the service.
   */
  protected function setUpTimeService(ContainerInterface $container) {
    // Create a mock for the TimeInterface service.
    $time_service = $this->createMock(TimeInterface::class);

    // Set up expected behavior for time-related methods.
    $time_service->method('getRequestTime')->willReturn(time());

    // Set up container with mocked time service.
    $container->set('datetime.time', $time_service);
  }

  /**
   * Sets up the entity query mock.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerInterface $container
   *   The container to which set the service.
   * @param int $result
   *   Result of the count query.
   */
  protected function setUpEntityQuery(ContainerInterface $container, int $result): void {
    // Mock the entity storage.
    $entity_storage = $this->createMock(EntityStorageInterface::class);

    // Mock the query object.
    $query = $this->createMock(QueryInterface::class);

    // Set up expected behavior for the query.
    $query->method('condition')->willReturn($query);
    $query->method('accessCheck')->willReturn($query);
    $query->method('count')->willReturn($query);
    $query->method('execute')->willReturn($result);

    // Mock the getQuery method to return our mocked query.
    $entity_storage->method('getQuery')->willReturn($query);

    // Mock the entity type manager.
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    // Set up expected behavior for getting storage.
    $entity_type_manager->method('getStorage')->willReturn($entity_storage);

    // Set up the container with the mocked entity type manager.
    $container->set('entity_type.manager', $entity_type_manager);
    $this->query = $query;
  }

  /**
   * Test submission frequency checking.
   */
  public function testCheckSubmissionFrequency(): void {
    // Create a mock user.
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(123);

    $container = \Drupal::getContainer();

    // Test with frequency set to 'day' and limit set to 5.
    $this->setUpConfigFactory($container, [
      'post_limit.number' => 5,
      'post_limit.frequency' => 'day',
    ]);

    // Simulate that the user has made 3 submissions.
    $this->setUpEntityQuery($container, 3);
    $result = reliefweb_anti_spam_check_submission_frequency($user, 'job');
    $this->assertTrue($result, 'User should be allowed to submit, as they have not exceeded the limit.');

    // Simulate that the user has made 5 submissions.
    $this->setUpEntityQuery($container, 5);
    $result = reliefweb_anti_spam_check_submission_frequency($user, 'job');
    $this->assertFalse($result, 'User should not be allowed to submit, as they have reached the limit.');

    // Simulate that the user has made 6 submissions.
    $this->setUpEntityQuery($container, 6);
    $result = reliefweb_anti_spam_check_submission_frequency($user, 'job');
    $this->assertFalse($result, 'User should not be allowed to submit, as they have exceeded the limit.');

    // Now test with a frequency of 'ever'.
    $this->setUpConfigFactory($container, [
      'post_limit.number' => 3,
      'post_limit.frequency' => 'ever',
    ]);

    // Simulate that the user has made 2 submissions.
    $this->setUpEntityQuery($container, 2);
    $result = reliefweb_anti_spam_check_submission_frequency($user, 'job');
    $this->assertTrue($result, 'User should be allowed to submit, as they have not exceeded the limit.');

    // Simulate that the user has made 3 submissions.
    $this->setUpEntityQuery($container, 3);
    $result = reliefweb_anti_spam_check_submission_frequency($user, 'job');
    $this->assertFalse($result, 'User should not be allowed to submit, as they have reached the limit.');

    // Simulate that the user has made 4 submissions.
    $this->setUpEntityQuery($container, 4);
    $result = reliefweb_anti_spam_check_submission_frequency($user, 'job');
    $this->assertFalse($result, 'User should not be allowed to submit, as they have exceeded the limit.');
  }

  /**
   * Test blacklisted domains checking.
   */
  public function testCheckBlacklistedDomains(): void {
    // Test content with a blacklisted domain.
    $content = 'This is a test with a badsite.com link.';
    $result = reliefweb_anti_spam_check_blacklisted_domains($content);
    $this->assertTrue($result);

    // Test clean content.
    $content = 'This is a clean test.';
    $result = reliefweb_anti_spam_check_blacklisted_domains($content);
    $this->assertFalse($result);
  }

  /**
   * Test URL detection in text.
   */
  public function testContainsUrl(): void {
    // Test text containing URL.
    $text_with_url = 'Check this link: http://example.com';
    $text_without_url = 'This is just text.';

    $this->assertTrue(reliefweb_anti_spam_contains_url($text_with_url));
    $this->assertFalse(reliefweb_anti_spam_contains_url($text_without_url));
  }

  /**
   * Test if text only contains URLs.
   */
  public function testOnlyContainsUrls(): void {
    // Test text that only contains URLs.
    $url_text = 'http://example.com http://example.org';
    $mixed_text = 'http://example.com and some text.';

    $this->assertTrue(reliefweb_anti_spam_only_contains_urls($url_text));
    $this->assertFalse(reliefweb_anti_spam_only_contains_urls($mixed_text));
  }

  /**
   * Test content word count requirements.
   */
  public function testCheckWordCount(): void {
    // Valid content.
    $title = 'A Valid Title';
    $body = 'This body has enough words to pass validation.';

    // Assuming configuration values are set correctly in setUpConfigFactory().
    $this->assertTrue(reliefweb_anti_spam_check_word_count('title', $title));
    $this->assertTrue(reliefweb_anti_spam_check_word_count('body', $body));

    // Invalid content.
    $title_short = 'Short';
    $body_short = 'Too short.';

    $this->assertFalse(reliefweb_anti_spam_check_word_count('title', $title_short));
    $this->assertFalse(reliefweb_anti_spam_check_word_count('title', $body_short));
  }

  /**
   * Test content length requirements.
   */
  public function testCheckContentLength(): void {
    // Valid content.
    $title = 'A Valid Title';
    $body = 'This body has enough words to pass validation.';

    // Assuming configuration values are set correctly in setUpConfigFactory().
    $this->assertTrue(reliefweb_anti_spam_check_content_length('title', $title));
    $this->assertTrue(reliefweb_anti_spam_check_content_length('body', $body));

    // Invalid content.
    $title_short = 'Short';
    $body_short = 'Too short.';

    $this->assertFalse(reliefweb_anti_spam_check_content_length('title', $title_short));
    $this->assertFalse(reliefweb_anti_spam_check_content_length('body', $body_short));
  }

}
