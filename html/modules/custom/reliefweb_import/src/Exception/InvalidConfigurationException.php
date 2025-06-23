<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Exception;

/**
 * Invalid configuration.
 */
class InvalidConfigurationException extends ReliefwebImportException implements ExceptionInterface {
  /**
   * {@inheritdoc}
   */
  protected string $statusType = 'invalid_config';

}
