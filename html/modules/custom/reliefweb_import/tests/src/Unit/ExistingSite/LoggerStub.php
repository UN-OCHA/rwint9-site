<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit\ExistingSite;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Logger stub to be able to retrieve the logged messages.
 *
 * We need to put that in /tests/src/Unit for the class to be loadable when
 * running the existing site tests.
 */
class LoggerStub implements LoggerChannelInterface {

  /**
   * Store the logs.
   *
   * @var array
   */
  protected array $messages = [];

  /**
   * Sets the request stack.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack|null $requestStack
   *   The current request object.
   */
  public function setRequestStack(?RequestStack $requestStack = NULL): void {
  }

  /**
   * Sets the current user.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $current_user
   *   The current user object.
   */
  public function setCurrentUser(?AccountInterface $current_user = NULL): void {
  }

  /**
   * Sets the loggers for this channel.
   *
   * @param array $loggers
   *   An array of arrays of \Psr\Log\LoggerInterface keyed by priority.
   */
  public function setLoggers(array $loggers): void {
  }

  /**
   * Adds a logger.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The PSR-3 logger to add.
   * @param int $priority
   *   The priority of the logger being added.
   */
  public function addLogger(LoggerInterface $logger, $priority = 0): void {
  }

  /**
   * System is unusable.
   *
   * @param \Stringable|string $message
   *   Message.
   * @param mixed[] $context
   *   Context.
   */
  public function emergency(\Stringable|string $message, array $context = []): void {
    $this->messages['emergency'][] = $message;
  }

  /**
   * Action must be taken immediately.
   *
   * Example: Entire website down, database unavailable, etc. This should
   * trigger the SMS alerts and wake you up.
   *
   * @param \Stringable|string $message
   *   Message.
   * @param mixed[] $context
   *   Context.
   */
  public function alert(\Stringable|string $message, array $context = []): void {
    $this->messages['alert'][] = $message;
  }

  /**
   * Critical conditions.
   *
   * Example: Application component unavailable, unexpected exception.
   *
   * @param \Stringable|string $message
   *   Message.
   * @param mixed[] $context
   *   Context.
   */
  public function critical(\Stringable|string $message, array $context = []): void {
    $this->messages['critical'][] = $message;
  }

  /**
   * Runtime errors that do not require immediate action.
   *
   * @param \Stringable|string $message
   *   Message.
   * @param mixed[] $context
   *   Context.
   */
  public function error(\Stringable|string $message, array $context = []): void {
    $this->messages['error'][] = $message;
  }

  /**
   * Exceptional occurrences that are not errors.
   *
   * Example: Use of deprecated APIs, poor use of an API, undesirable things
   * that are not necessarily wrong.
   *
   * @param \Stringable|string $message
   *   Message.
   * @param mixed[] $context
   *   Context.
   */
  public function warning(\Stringable|string $message, array $context = []): void {
    $this->messages['warning'][] = $message;
  }

  /**
   * Normal but significant events.
   *
   * @param \Stringable|string $message
   *   Message.
   * @param mixed[] $context
   *   Context.
   */
  public function notice(\Stringable|string $message, array $context = []): void {
    $this->messages['notice'][] = $message;
  }

  /**
   * Interesting events.
   *
   * Example: User logs in, SQL logs.
   *
   * @param \Stringable|string $message
   *   Message.
   * @param mixed[] $context
   *   Context.
   */
  public function info(\Stringable|string $message, array $context = []): void {
    $this->messages['info'][] = $message;
  }

  /**
   * Detailed debug information.
   *
   * @param \Stringable|string $message
   *   Message.
   * @param mixed[] $context
   *   Context.
   */
  public function debug(\Stringable|string $message, array $context = []): void {
    $this->messages['debug'][] = $message;
  }

  /**
   * Logs with an arbitrary level.
   *
   * @param string $level
   *   Log level.
   * @param \Stringable|string $message
   *   Message.
   * @param mixed[] $context
   *   Context.
   *
   * @throws \Psr\Log\InvalidArgumentException
   */
  public function log($level, \Stringable|string $message, array $context = []): void {
    $this->messages[$level][] = $message;
  }

  /**
   * Get concatenated logged messages.
   *
   * @param string $type
   *   Message type (ex: error).
   *
   * @return string
   *   Concatenated logged messages.
   */
  public function getMessages(string $type): string {
    return implode("\n", $this->messages[$type] ?? []);
  }

  /**
   * Check if there are messages of the given type.
   *
   * @param string $type
   *   Message type (ex: error).
   *
   * @return bool
   *   TRUE if the logger has messages.
   */
  public function hasMessages(string $type): bool {
    return !empty($this->messages[$type]);
  }

  /**
   * Reset messages.
   */
  public function resetMessages(): void {
    $this->messages = [];
  }

}
