<?php

namespace Drupal\reliefweb_import\Exception;

/**
 * Exceptions on illegal filename.
 */
class ReliefwebImportExceptionIllegalFilename extends ReliefwebImportException {
  /**
   * {@inheritdoc}
   */
  protected string $statusType = 'illegal_filename';

}
