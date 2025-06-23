<?php

namespace Drupal\reliefweb_import\Exception;

/**
 * Exceptions on violations.
 */
class ReliefwebImportExceptionViolation extends ReliefwebImportException {
  /**
   * {@inheritdoc}
   */
  protected string $statusType = 'violation';

}
