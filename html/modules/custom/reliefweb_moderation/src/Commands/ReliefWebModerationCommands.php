<?php

namespace Drupal\reliefweb_moderation\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drush\Commands\DrushCommands;

/**
 * ReliefWeb Moderation Drush commandfile.
 */
class ReliefWebModerationCommands extends DrushCommands {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The state manager.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    StateInterface $state
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
  }

  /**
   * Update status of inactive sources to inactive or archive.
   *
   * Conditions:
   * - No reports.
   * - No currently open jobs or training.
   * - Open job or training during past year -> inactive, otherwise -> archive.
   *
   * @param array $options
   *   Drush options.
   *
   * @command reliefweb_moderation:update-inactive-sources
   *
   * @option dry-run If set, subscriptions will not be deleted.
   *
   * @default options [
   *   'dry-run' => FALSE,
   * ]
   *
   * @usage reliefweb_moderation:update-inactive-sources
   *   Mark sources as inactive.
   *
   * @aliases reliefweb-organizations-make-inactive,ro-mi
   *
   * @validate-module-enabled reliefweb_moderation
   */
  public function updateInactiveSources(array $options = [
    'dry-run' => FALSE,
  ]) {
    if (!empty($this->state->get('system.maintenance_mode', 0))) {
      $this->logger()->warning(dt('Maintenance mode, aborting.'));
      return TRUE;
    }

    $sources = $this->database->query("
      SELECT query.id, query.status
      FROM (
        SELECT subquery.id,
        CASE
          WHEN subquery.published > 0 THEN 'active'
          WHEN subquery.recent > 0 THEN 'inactive'
          ELSE 'archive'
        END AS status
        FROM (
          SELECT fs.field_source_target_id AS id,
          SUM(CASE
            WHEN n.type = 'job' AND UNIX_TIMESTAMP(fjcd.field_job_closing_date_value) > UNIX_TIMESTAMP(NOW() - INTERVAL 1 YEAR) THEN 1
            WHEN n.type = 'training' AND UNIX_TIMESTAMP(frd.field_registration_deadline_value) > UNIX_TIMESTAMP(NOW() - INTERVAL 1 YEAR) THEN 1
            ELSE 0
          END) AS recent,
          SUM(IF(n.moderation_status = 'published', 1, 0)) AS published
          FROM node__field_source AS fs
          INNER JOIN node_field_data AS n
            ON n.nid = fs.entity_id
            AND n.type IN ('job', 'training')
          LEFT JOIN node__field_job_closing_date AS fjcd
            ON fjcd.entity_id = fs.entity_id
          LEFT JOIN node__field_registration_deadline AS frd
            ON frd.entity_id = fs.entity_id
          WHERE fs.field_source_target_id NOT IN (SELECT field_source_target_id FROM node__field_source WHERE bundle = 'report')
          GROUP BY fs.field_source_target_id
        ) AS subquery
      ) AS query
      INNER JOIN taxonomy_term_field_data AS tfd
        ON tfd.tid = query.id
        AND tfd.vid = 'source'
        AND tfd.moderation_status NOT IN ('duplicate', 'blocked', query.status)
      WHERE query.status <> 'active'
    ")?->fetchAllKeyed(0, 1) ?? [];

    if (empty($sources)) {
      $this->logger()->info(dt('No sources to update'));
      return TRUE;
    }

    $dry_run = !empty($options['dry-run']);
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $count = 0;

    foreach ($sources as $id => $status) {
      $source = $storage->load($id);
      if (!empty($source)) {
        if (!$dry_run) {
          $source->notifications_content_disable = TRUE;
          $source->setModerationStatus($status);
          $source->setNewRevision(TRUE);
          $source->setRevisionLogMessage('Automatic status update due to inactivity.');
          $source->setRevisionUserId(2);
          $source->save();
        }
        $count++;
      }
    }

    $this->logger()->info(dt('Updated @count sources', [
      '@count' => $count,
    ]));
    return TRUE;
  }

}
