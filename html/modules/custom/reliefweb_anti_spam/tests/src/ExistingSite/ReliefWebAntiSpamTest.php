<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_anti_spam\ExistingSite;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests for the ReliefWeb Anti-Spam module.
 */
#[CoversNothing]
#[Group('reliefweb_anti_spam')]
class ReliefWebAntiSpamTest extends ExistingSiteBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'config',
    'dblog',
    'node',
    'taxonomy',
    'reliefweb_anti_spam',
    'reliefweb_moderation',
    'reliefweb_utility',
  ];

  /**
   * Original configuration values for restoration after the test run.
   *
   * @var array
   */
  protected array $originalConfiguration = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->container->get('database')
      ->delete('watchdog')
      ->condition('type', 'reliefweb_anti_spam')
      ->execute();

    $this->setConfigValues('reliefweb_anti_spam.settings', [
      'post_limit.enabled' => TRUE,
      'post_limit.number' => 1,
      'post_limit.frequency' => 'day',
      'validation.blacklisted_domains' => '',
      'validation.title_min_words' => 1,
      'validation.title_min_length' => 1,
      'validation.body_min_words' => 1,
      'validation.body_min_length' => 1,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->resetConfigValues();
    parent::tearDown();
  }

  /**
   * Replace configuration values and store the original ones.
   *
   * @param string $name
   *   Config name.
   * @param array $values
   *   Associative array of config values to override.
   */
  protected function setConfigValues(string $name, array $values): void {
    $config = $this->container->get('config.factory')->getEditable($name);
    foreach ($values as $key => $value) {
      if (!isset($this->originalConfiguration[$name][$key])) {
        $this->originalConfiguration[$name][$key] = $config->get($key);
      }
      $config->set($key, $value);
    }
    $config->save();
  }

  /**
   * Reset the configuration changes.
   */
  protected function resetConfigValues(): void {
    $config_factory = $this->container->get('config.factory');
    foreach ($this->originalConfiguration as $name => $values) {
      $config = $config_factory->getEditable($name);
      foreach ($values as $key => $value) {
        $config->set($key, $value);
      }
      $config->save();
    }
  }

  /**
   * Assert that a log entry exists in the database.
   *
   * @param string $expected_raw_message
   *   The expected raw message template with placeholders (e.g.,
   *   'User @uid exceeded limit').
   * @param array $expected_variables
   *   Array of expected variable replacements (e.g., ['@uid' => 123]).
   * @param string $type
   *   The log type to filter by. Defaults to 'reliefweb_anti_spam'.
   * @param bool $clear
   *   Whether to clear matching log entries after assertion. Defaults to TRUE.
   */
  protected function assertLogMessageExists(string $expected_raw_message, array $expected_variables = [], string $type = 'reliefweb_anti_spam', bool $clear = TRUE): void {
    $database = $this->container->get('database');

    // Compute the expected message by replacing variables.
    $expected_message = strtr($expected_raw_message, $expected_variables);

    // Query for records matching the raw message template.
    $records = $database->select('watchdog', 'w')
      ->fields('w', ['wid', 'message', 'variables'])
      ->condition('type', $type)
      ->condition('message', $expected_raw_message)
      ->orderBy('wid', 'DESC')
      ->execute();

    $messages = [];
    foreach ($records as $record) {
      $variables = [];
      if (!empty($record->variables)) {
        // phpcs:ignore
        $unserialized = unserialize($record->variables);
        if ($unserialized !== FALSE && is_array($unserialized)) {
          $variables = $unserialized;
        }
      }

      $messages[$record->wid] = strtr($record->message, $variables);
    }

    $this->assertContains($expected_message, $messages, "Expected log message '{$expected_message}' was not found in database logs.");

    // Clear matching log entries if requested.
    if ($clear && !empty($messages)) {
      $database->delete('watchdog')
        ->condition('wid', array_keys($messages), 'IN')
        ->execute();
    }
  }

  /**
   * Tests that a user cannot submit a job with a blacklisted domain.
   */
  public function testBlacklistedDomainSubmission(): void {
    // Create a user with permissions to create job nodes and log in.
    $user = $this->createUser(['create job content']);
    $this->drupalLogin($user);

    // Set up configuration with a blacklisted domain.
    $this->setConfigValues('reliefweb_anti_spam.settings', [
      'validation.blacklisted_domains' => 'blacklisteddomain.com',
    ]);

    // Navigate to the form page.
    $this->drupalGet('/node/add/job');

    // Submit the form.
    $this->submitForm([
      'title[0][value]' => 'Job Title with blacklisteddomain.com',
      'body[0][value]' => 'This is a job description.',
      'field_how_to_apply[0][value]' => 'Apply here.',
    ], 'Submit');

    // Assert that the corresponding log message was recorded.
    $this->assertLogMessageExists('Blacklisted domain detected in submission from user @uid', ['@uid' => $user->id()]);

    // Get the error message.
    $message = $this->container->get('config.factory')
      ->get('reliefweb_anti_spam.settings')
      ->get('error_messages.content_quality');

    // Assert that an error message is displayed.
    $this->assertSession()->pageTextContains($message);
  }

  /**
   * Tests that a user cannot exceed submission frequency limits.
   */
  public function testSubmissionFrequencyLimit(): void {
    // Create a user with permissions to create job nodes and log in.
    $user = $this->createUser(['create job content']);
    $this->drupalLogin($user);

    // Set up configuration for post limit.
    $this->setConfigValues('reliefweb_anti_spam.settings', [
      'post_limit.enabled' => TRUE,
       // Allow only one submission.
      'post_limit.number' => 1,
      // Set frequency to daily.
      'post_limit.frequency' => 'day',
    ]);

    // Create a job posted by the user to simulate a previous submission.
    $this->createNode([
      'type' => 'job',
      'uid' => $user->id(),
    ]);

    // Navigate to the form page.
    $this->drupalGet('/node/add/job');

    // Attempt to create a second job node submission within the same frequency
    // period.
    $this->submitForm([
      'title[0][value]' => 'Second Job Title',
      'body[0][value]' => 'This is the second job description.',
      'field_how_to_apply[0][value]' => 'Apply here.',
    ], 'Submit');

    // Assert that the corresponding log message was recorded.
    $this->assertLogMessageExists('Submission frequency limit exceeded for user @uid', ['@uid' => $user->id()]);

    // Assert that an error message is displayed for exceeding submission limit.
    $message = $this->container->get('config.factory')
      ->get('reliefweb_anti_spam.settings')
      ->get('error_messages.submission_frequency');

    // Assert that the error message is displayed on the page.
    $this->assertSession()->pageTextContains($message);
  }

  /**
   * Tests that valid submissions are accepted without errors.
   */
  public function testValidSubmission(): void {
    // Create a user with permissions to create job nodes and log in.
    $user = $this->createUser(['create job content']);
    $this->drupalLogin($user);

    // Set up configuration without blacklisted domains and allow submissions.
    $this->setConfigValues('reliefweb_anti_spam.settings', [
      'validation.blacklisted_domains' => '',
      // Disable post limit for this test.
      'post_limit.enabled' => FALSE,
    ]);

    // Navigate to the form page.
    $this->drupalGet('/node/add/job');

    // Create a valid job node submission.
    $this->submitForm([
      'title[0][value]' => 'Valid Job Title',
      'body[0][value]' => 'This is a valid job description.',
      'field_how_to_apply[0][value]' => 'Apply here.',
    ], 'Submit');

    // Assert that the submission was successful and redirected to the node
    // page.
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that a user cannot submit a job with a URL in the title.
   */
  public function testUrlInTitleSubmission(): void {
    // Create a user with permissions to create job nodes and log in.
    $user = $this->createUser(['create job content']);
    $this->drupalLogin($user);

    // Navigate to the form page.
    $this->drupalGet('/node/add/job');

    // Submit the form with URL in title.
    $this->submitForm([
      'title[0][value]' => 'Job Title with http://example.com',
      'body[0][value]' => 'This is a valid job description.',
      'field_how_to_apply[0][value]' => 'Apply here.',
    ], 'Submit');

    // Assert that the corresponding log message was recorded.
    $this->assertLogMessageExists('URL detected in title of submission from user @uid', ['@uid' => $user->id()]);

    // Get the error message for URL in title validation.
    $message = $this->container->get('config.factory')
      ->get('reliefweb_anti_spam.settings')
      ->get('error_messages.content_quality');

    // Assert that an error message is displayed for URL in title.
    $this->assertSession()->pageTextContains($message);
  }

  /**
   * Tests that a user cannot submit a body containing only URLs.
   */
  public function testBodyOnlyUrlsSubmission(): void {
    // Create a user with permissions to create job nodes and log in.
    $user = $this->createUser(['create job content']);
    $this->drupalLogin($user);

    // Navigate to the form page.
    $this->drupalGet('/node/add/job');

    // Submit the form with body containing only URLs.
    $this->submitForm([
      'title[0][value]' => 'Valid Job Title',
      'body[0][value]' => "http://example.com\nhttp://anotherexample.com",
      'field_how_to_apply[0][value]' => '',
    ], 'Submit');

    // Assert that the corresponding log message was recorded.
    $this->assertLogMessageExists('Body only contains URLs in submission from user @uid', ['@uid' => $user->id()]);

    // Get the error message for body only URLs validation.
    $message = $this->container->get('config.factory')
      ->get('reliefweb_anti_spam.settings')
      ->get('error_messages.content_quality');

    // Assert that an error message is displayed for body containing only URLs.
    $this->assertSession()->pageTextContains($message);
  }

  /**
   * Tests that a user cannot submit a body containing only URLs.
   */
  public function testTextFieldsWithSameContent(): void {
    // Create a user with permissions to create job nodes and log in.
    $user = $this->createUser(['create job content']);
    $this->drupalLogin($user);

    // Navigate to the form page.
    $this->drupalGet('/node/add/job');

    // Submit the form with body containing only URLs.
    $this->submitForm([
      'title[0][value]' => 'Valid Job Title',
      'body[0][value]' => "This is some text.",
      'field_how_to_apply[0][value]' => 'This is some text.',
    ], 'Submit');

    // Assert that the corresponding log message was recorded.
    $this->assertLogMessageExists('Field texts cannot have the same content in submission from user @uid', ['@uid' => $user->id()]);

    // Get the error message for body only URLs validation.
    $message = $this->container->get('config.factory')
      ->get('reliefweb_anti_spam.settings')
      ->get('error_messages.content_quality');

    // Assert that an error message is displayed for body containing only URLs.
    $this->assertSession()->pageTextContains($message);
  }

}
