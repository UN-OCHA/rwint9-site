<?php

namespace Drupal\reliefweb_import\Exception;

/**
 * Exceptions on missing source tag.
 */
class ReliefwebImportExceptionNoSourceTag extends ReliefwebImportException {
  /**
   * {@inheritdoc}
   */
  protected string $statusType = 'no_source_tag';

}
