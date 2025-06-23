<?php

namespace Drupal\reliefweb_import\Exception;

/**
 * Exceptions during import.
 */
class ReliefwebImportException extends \Exception {
  /**
   * The status_type of the exception.
   *
   * @var string
   */
  protected string $statusType = 'general_error';

  /**
   * Get the status of the exception.
   */
  public function getStatusType(): string {
    return $this->statusType;
  }

}
