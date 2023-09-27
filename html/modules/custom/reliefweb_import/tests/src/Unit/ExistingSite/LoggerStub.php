<?php

// phpcs:ignoreFile

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
   * @var array.
   */
  protected $messages = [];

  /**
   * Sets the request stack.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack|null $requestStack
   *   The current request object.
   */
  public function setRequestStack(RequestStack $requestStack = NULL) {
  }

  /**
   * Sets the current user.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $current_user
   *   The current user object.
   */
  public function setCurrentUser(AccountInterface $current_user = NULL) {
  }

  /**
   * Sets the loggers for this channel.
   *
   * @param array $loggers
   *   An array of arrays of \Psr\Log\LoggerInterface keyed by priority.
   */
  public function setLoggers(array $loggers) {
  }

  /**
   * Adds a logger.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The PSR-3 logger to add.
   * @param int $priority
   *   The priority of the logger being added.
   */
  public function addLogger(LoggerInterface $logger, $priority = 0) {
  }

  /**
   * System is unusable.
   *
   * @param string  $message
   * @param mixed[] $context
   *
   * @return void
   */
  public function emergency($message, array $context = array()): void {
    $this->messages['emergency'][] = $message;
  }

  /**
   * Action must be taken immediately.
   *
   * Example: Entire website down, database unavailable, etc. This should
   * trigger the SMS alerts and wake you up.
   *
   * @param string  $message
   * @param mixed[] $context
   *
   * @return void
   */
  public function alert($message, array $context = array()): void {
    $this->messages['alert'][] = $message;
  }

  /**
   * Critical conditions.
   *
   * Example: Application component unavailable, unexpected exception.
   *
   * @param string  $message
   * @param mixed[] $context
   *
   * @return void
   */
  public function critical($message, array $context = array()): void {
    $this->messages['critical'][] = $message;
  }

  /**
   * Runtime errors that do not require immediate action but should typically
   * be logged and monitored.
   *
   * @param string  $message
   * @param mixed[] $context
   *
   * @return void
   */
  public function error($message, array $context = array()): void {
    $this->messages['error'][] = $message;
  }

  /**
   * Exceptional occurrences that are not errors.
   *
   * Example: Use of deprecated APIs, poor use of an API, undesirable things
   * that are not necessarily wrong.
   *
   * @param string  $message
   * @param mixed[] $context
   *
   * @return void
   */
  public function warning($message, array $context = array()): void {
    $this->messages['warning'][] = $message;
  }

  /**
   * Normal but significant events.
   *
   * @param string  $message
   * @param mixed[] $context
   *
   * @return void
   */
  public function notice($message, array $context = array()): void {
    $this->messages['notice'][] = $message;
  }

  /**
   * Interesting events.
   *
   * Example: User logs in, SQL logs.
   *
   * @param string  $message
   * @param mixed[] $context
   *
   * @return void
   */
  public function info($message, array $context = array()): void {
    $this->messages['info'][] = $message;
  }

  /**
   * Detailed debug information.
   *
   * @param string  $message
   * @param mixed[] $context
   *
   * @return void
   */
  public function debug($message, array $context = array()): void {
    $this->messages['debug'][] = $message;
  }

  /**
   * Logs with an arbitrary level.
   *
   * @param mixed   $level
   * @param string  $message
   * @param mixed[] $context
   *
   * @return void
   *
   * @throws \Psr\Log\InvalidArgumentException
   */
  public function log($level, $message, array $context = array()): void {
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
  public function getMessages($type) {
    return implode("\n", $this->messages[$type] ?? []);
  }

  /**
   * Check if there are messages of the given type.
   *
   * @param string $type
   *   Message type (ex: error).
   *
   * @return bool.
   */
  public function hasMessages($type) {
    return !empty($this->messages[$type]);
  }

  /**
   * Reset messages.
   */
  public function resetMessages() {
    $this->messages = [];
  }

}
