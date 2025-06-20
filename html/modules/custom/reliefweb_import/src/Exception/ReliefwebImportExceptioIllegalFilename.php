<?php

namespace Drupal\reliefweb_import\Exception;

/**
 * Exceptions on illegal filename.
 */
class ReliefwebImportExceptioIllegalFilename extends ReliefwebImportException {
  /**
   * {@inheritdoc}
   */
  protected string $status = 'illegal_filename';

}
