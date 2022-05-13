<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_import\Unit\ExistingSite;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\reliefweb_import\Command\ReliefwebImportCommand;

/**
 * Wrapper class to be able to override the logger.
 *
 * We need to put that in /tests/src/Unit for the class to be loadable when
 * running the existing site tests.
 */
class ReliefwebImportCommandWrapper extends ReliefwebImportCommand {

  /**
   * Get the logger.
   */
  public function getLogger(): LoggerChannelInterface {
    return $this->logger;
  }

}
