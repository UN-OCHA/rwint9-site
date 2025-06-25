<?php

namespace Drupal\reliefweb_import\Exception;

/**
 * Exceptions on violations.
 */
class ReliefwebImportExceptionSoftViolation extends ReliefwebImportException {
  /**
   * {@inheritdoc}
   */
  protected string $statusType = 'soft_violation';

}
