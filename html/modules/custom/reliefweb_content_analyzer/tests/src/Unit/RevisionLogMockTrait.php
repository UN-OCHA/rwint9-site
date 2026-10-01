<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_utility\Helpers\RevisionLogHelper;

/**
 * Simulates EntityRevisionedInterface::updateRevisionLogMessage() on mocks.
 *
 * Delegates to RevisionLogHelper so mocks match production behavior.
 */
trait RevisionLogMockTrait {

  /**
   * Wires getRevisionLogMessage / updateRevisionLogMessage on a mock entity.
   *
   * @param object $entity
   *   PHPUnit mock that includes the revision log methods.
   * @param string $log
   *   Mutable revision log storage (passed by reference).
   */
  protected function wireRevisionLogMock(object $entity, string &$log): void {
    $entity->method('getRevisionLogMessage')
      ->willReturnCallback(static function () use (&$log): string {
        return $log;
      });
    $entity->method('updateRevisionLogMessage')
      ->willReturnCallback(static function (
        string $message,
        string $action = 'append',
        bool $skip_if_present = TRUE,
        string $separator = ' ',
      ) use (&$log): void {
        $log = RevisionLogHelper::updateMessage(
          $log,
          $message,
          $action,
          $skip_if_present,
          $separator,
        );
      });
  }

}
