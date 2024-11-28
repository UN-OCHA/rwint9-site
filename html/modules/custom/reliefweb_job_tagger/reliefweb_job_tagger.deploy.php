<?php

/**
 * @file
 * Post deployment file for the reliefweb_job_tagger module.
 */

/**
 * Implements hook_deploy_NAME().
 *
 * Create ocha content classification progress records for the jobs queued in
 * the reliefweb_job_tagger queue and empty it.
 */
function reliefweb_job_tagger_deploy_content_classification_queue_migration(array &$sandbox): string {
  /** @var \Drupal\Core\Queue\QueueFactory $queue_factory */
  $queue_factory = \Drupal::service('queue');

  /** @var \Drupal\Core\Queue\QueueInterface $old_queue */
  $old_queue = $queue_factory->get('reliefweb_job_tagger');

  // Skip if there is no jobs in the queue.
  if ($old_queue->numberOfItems() === 0) {
    return t('No queued jobs found, skipping.');
  }

  /** @var \Drupal\Core\Queue\QueueInterface $new_queue */
  $new_queue = $queue_factory->get('ocha_classification_workflow:node_job', TRUE);

  // Move the queued items from the old queue to the new one.
  $processed = [];
  while ($item = $old_queue->claimItem()) {
    $old_queue->deleteItem($item);

    if (property_exists($item, 'data') && property_exists($item->data, 'nid')) {
      $id = $item->data->nid;

      if (!isset($processed[$id])) {
        $processed[$id] = TRUE;

        $new_item = [
          'entity_type_id' => 'node',
          'entity_bundle' => 'job',
          'entity_id' => $id,
        ];

        $new_queue->createItem($new_item);
      }
    }
  }

  // Empty the old queue.
  $old_queue->deleteQueue();

  return t('@count queued jobs migrated.', [
    '@count' => count($processed),
  ]);
}
