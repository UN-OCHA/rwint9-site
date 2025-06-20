<?php

namespace Drupal\reliefweb_import\Exception;

/**
 * Exceptions on missing source tag.
 */
class ReliefwebImportExceptioNoSourceTag extends ReliefwebImportException {
  /**
   * {@inheritdoc}
   */
  protected string $status = 'no_source_tag';

}
