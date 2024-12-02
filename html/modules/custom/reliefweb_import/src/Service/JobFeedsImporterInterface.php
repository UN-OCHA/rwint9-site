<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Service;

/**
 * Interface for the Job feeds importer service.
 */
interface JobFeedsImporterInterface {

  /**
   * Import jobs.
   *
   * @param int $limit
   *   Max number of items to send.
   */
  public function importJobs(int $limit = 50): void;

}
