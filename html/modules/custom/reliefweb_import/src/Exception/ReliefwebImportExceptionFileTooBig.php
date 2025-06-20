<?php

namespace Drupal\reliefweb_import\Exception;

/**
 * Exceptions on file too big.
 */
class ReliefwebImportExceptionFileTooBig extends ReliefwebImportException {
  /**
   * {@inheritdoc}
   */
  protected string $status = 'file_too_big';

}
