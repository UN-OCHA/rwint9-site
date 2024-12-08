<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit\ExistingSite;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\reliefweb_import\Service\JobFeedsImporter;

/**
 * Wrapper class to be able to override the logger.
 *
 * We need to put that in /tests/src/Unit for the class to be loadable when
 * running the existing site tests.
 */
class JobFeedsImporterWrapper extends JobFeedsImporter {

  /**
   * Logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */

  /**
   * {@inheritdoc}
   */
  public function getLogger(): LoggerChannelInterface {
    return $this->logger;
  }

  /**
   * {@inheritdoc}
   */
  public function setLogger(LoggerChannelInterface $logger): void {
    $this->logger = $logger;
  }

}
