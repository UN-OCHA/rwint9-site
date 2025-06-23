<?php

namespace Drupal\reliefweb_import\Exception;

/**
 * Exceptions on empty body.
 */
class ReliefwebImportExceptionEmptyBody extends ReliefwebImportException {
  /**
   * {@inheritdoc}
   */
  protected string $statusType = 'empty_body';

}
