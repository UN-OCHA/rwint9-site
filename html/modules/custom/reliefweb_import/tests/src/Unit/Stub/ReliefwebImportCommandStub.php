<?php

namespace Drupal\Tests\reliefweb_import\Unit\Stub;

use Drupal\reliefweb_import\Command\ReliefwebImportCommand;

/**
 * Stub class for testing.
 */
class ReliefwebImportCommandStub extends ReliefwebImportCommand {
  // @codingStandardsIgnoreStart

  /**
   * {@inheritdoc}
   */
  public function validateBody($data) {
    return parent::validateBody($data);
  }

  /**
   * {@inheritdoc}
   */
  public function sanitizeText($field, $text, $format = 'plain_text') {
    return parent::sanitizeText($field, $text, $format);
  }

  // @codingStandardsIgnoreEnd

}
