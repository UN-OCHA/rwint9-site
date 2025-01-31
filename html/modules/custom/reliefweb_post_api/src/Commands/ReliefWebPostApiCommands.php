<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Commands;

use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use Drupal\reliefweb_post_api\Queue\ReliefWebPostApiDatabaseQueueFactory;
use Drush\Commands\DrushCommands;

/**
 * ReliefWeb Post API Drush commandfile.
 */
class ReliefWebPostApiCommands extends DrushCommands {

  /**
   * Constructor.
   *
   * @param \Drupal\reliefweb_post_api\Queue\ReliefWebPostApiDatabaseQueueFactory $queueFactory
   *   The ReliefWeb Post API queue factory.
   * @param \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface $contentProcessorPluginManager
   *   The ReliefWeb Post API content processor plugin manager.
   */
  public function __construct(
    protected ReliefWebPostApiDatabaseQueueFactory $queueFactory,
    protected ContentProcessorPluginManagerInterface $contentProcessorPluginManager,
  ) {}

  /**
   * Process content submitted to the ReliefWeb Post API.
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
  public function process(
    array $options = [
      'limit' => 10,
      'bundles' => '',
    ],
  ): void {
    $queue = $this->queueFactory->get('reliefweb_post_api');

    $count = 0;
    for ($i = 0; $i < $options['limit']; $i++) {
      $item = $queue->claimItem();
      if ($item === FALSE) {
        continue;
      }

      $data = $item->data;
      $item_id = $item->item_id;
      $bundle = $data['bundle'] ?? 'unknown';
      $uuid = $data['uuid'] ?? 'missing UUID';
      $plugin = $this->contentProcessorPluginManager->getPluginByBundle($bundle);

      if (isset($plugin)) {
        $this->logger->info(strtr('Processing queued @bundle @item_id (@uuid).', [
          '@bundle' => $bundle,
          '@uuid' => $uuid,
          '@item_id' => $item_id,
        ]));

        // Attempt to create/update a resource based on the provided data.
        try {
          $entity = $plugin->process($data);

          $this->logger->info(strtr('Successfully @action @bundle entity with ID: @id (@uuid).', [
            '@action' => mb_stripos($entity->getRevisionLogMessage(), 'automatic creation') !== FALSE ? 'created' : 'updated',
            '@bundle' => $entity->bundle(),
            '@id' => $entity->id(),
            '@uuid' => $entity->uuid(),
          ]));
        }
        catch (\Exception $exception) {
          $this->logger->error(strtr('Error processing @bundle @item_id (@uuid): @error.', [
            '@bundle' => $bundle,
            '@item_id' => $item_id,
            '@uuid' => $uuid,
            '@error' => $exception->getMessage(),
          ]));
        }
      }
      else {
        $this->logger->error(strtr('Unsupported bundle: @bundle, skipping item: @item_id.', [
          '@bundle' => $bundle,
          '@item_id' => $item_id,
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
