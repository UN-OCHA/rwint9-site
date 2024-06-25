<?php

namespace Drupal\reliefweb_job_tagger\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ocha_ai_tag\Services\OchaAiTagTagger;
use Drush\Commands\DrushCommands;

/**
 * ReliefWeb Job Tagger Drush commandfile.
 */
class ReliefJobTaggerCommands extends DrushCommands {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected OchaAiTagTagger $ochaTagger,
  ) {}

  /**
   * Clear the index.
   *
   * @command reliefweb-jobtagger:clear-index
   *
   * @usage reliefweb-jobtagger:clear-index
   *   Clear vector index.
   *
   * @validate-module-enabled reliefweb_job_tagger
   *
   * @aliases rw-job:clear
   */
  public function clearIndex() {
    $this->ochaTagger->clearIndex();
  }

  /**
   * Index jobs.
   *
   * @command reliefweb-jobtagger:index-jobs
   *
   * @usage reliefweb-jobtagger:index-jobs
   *   Create vector index for jobs.
   *
   * @validate-module-enabled reliefweb_job_tagger
   *
   * @aliases rw-job:index
   */
  public function indexJobs() {
    // Only index jobs approved by reliefweb.int editors.
    $uids = $this->entityTypeManager->getStorage('user')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', 'editor')
      ->execute();

    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'job', '=')
      ->condition('moderation_status', ['published', 'expired'], 'IN')
      ->sort('nid', 'desc');

    if (!empty($uids)) {
      // Limit to documents that have been reviewed by an editor to augment
      // the likeliness that the documents were tagged properly.
      $query->condition('revision_uid', $uids, 'IN');
    }

    $job_ids = $query->execute() ?? [];

    foreach ($job_ids as $id) {
      $this->output->writeln('Processing ' . $id);
      $this->ochaTagger->embedDocument($id);
    }
  }

}
