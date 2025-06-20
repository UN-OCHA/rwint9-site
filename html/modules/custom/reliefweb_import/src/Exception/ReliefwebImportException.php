<?php

namespace Drupal\reliefweb_import\Exception;

/**
 * Exceptions during import.
 */
class ReliefwebImportException extends \Exception {
  /**
   * The status of the exception.
   *
   * @var string
   */
  protected string $status = 'general_error';

  /**
   * Get the status of the exception.
   */
  public function getStatus(): string {
    return $this->status;
  }

}
