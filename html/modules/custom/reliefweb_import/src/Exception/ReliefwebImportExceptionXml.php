<?php

namespace Drupal\reliefweb_import\Exception;

/**
 * Exceptions on XML.
 */
class ReliefwebImportExceptionXml extends ReliefwebImportException {
  /**
   * {@inheritdoc}
   */
  protected string $statusType = 'xml_error';

}
