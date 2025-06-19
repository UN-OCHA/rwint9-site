<?php

namespace Drupal\reliefweb_import\Exception;

/**
 * Exceptions on XML.
 */
class ReliefwebImportExceptionXml extends ReliefwebImportException {
  /**
   * {@inheritdoc}
   */
  protected string $status = 'xml_error';

}
