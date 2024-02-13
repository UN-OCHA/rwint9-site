<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Commands;

use Drupal\Core\Queue\QueueFactory;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * ReliefWeb POST API Drush commandfile.
 */
class ReliefWebPostApiCommands extends DrushCommands {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected QueueFactory $queueFactory,
    protected ContentProcessorPluginManagerInterface $contentProcessorPluginManager
  ) {}

  /**
   * Process content submitted to the ReliefWeb POST API.
   *
   * @param array $options
   *   Options for the command.
   *
   * @command reliefweb-post-api:process
   *
   * @option limit Number of submitted content to process, defaults to 10.
   * @option bundles Comma separated list of bundles to process, all if empty.
   *
   * @default $options []
   *
   * @usage reliefweb-post-api:process --limit=5
   *   Process the 5 oldest submitted content.
   * @usage reliefweb-post-api:process --limit=5 --bundles=report,job
   *   Process the 5 oldest submitted content of type report or job.
   *
   * @validate-module-enabled reliefweb_post_api
   */
  public function process(array $options = [
    'limit' => 10,
    'bundles' => '',
  ]): void {
    $queue = $this->queueFactory->get('reliefweb_post_api');

    $count = 0;
    for ($i = 0; $i < $options['limit']; $i++) {
      $item = $queue->claimItem();
      if ($item === FALSE) {
        continue;
      }

      $data = $item->data;
      $plugin = $this->contentProcessorPluginManager->getPluginByBundle($data['bundle'] ?? '');

      // @todo Add the item UUID and/or URL to the logs to help identifying
      // the problematic document in the logs.
      if (isset($plugin)) {
        $this->logger->info(strtr('Processing queued @bundle: @item_id.', [
          '@bundle' => $data['bundle'],
          '@item_id' => $item->item_id,
        ]));

        // @todo log some info about the created entity.
        // @todo maybe return the created entity so we can do something with it.
        try {
          $entity = $plugin->process($data);

          $this->logger->info(strtr('Successfully @action @bundle entity with id @id.', [
            '@action' => mb_stripos($entity->getRevisionLogMessage(), 'automatic creation') !== FALSE ? 'created' : 'updated',
            '@bundle' => $data['bundle'],
            '@id' => $entity->id(),
          ]));
        }
        catch (\Exception $exception) {
          $this->logger->error(strtr('Error processing @bundle @item_id: @error.', [
            '@bundle' => $data['bundle'],
            '@item_id' => $item->item_id,
            '@error' => $exception->getMessage(),
          ]));
        }
      }
      else {
        $this->logger->error(strtr('Unsupported bundle: @bundle, skipping item: @item_id.', [
          '@bundle' => $data['bundle'] ?? 'unknown',
          '@item_id' => $item->item_id,
        ]));
      }

      $queue->deleteItem($item);
      $count++;
    }

    $this->logger->info(strtr('Processed @count queued submissions.', [
      '@count' => $count,
    ]));
  }

}
