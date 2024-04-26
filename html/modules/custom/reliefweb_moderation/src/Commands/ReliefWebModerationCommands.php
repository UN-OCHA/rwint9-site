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
    StateInterface $state,
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
  public function updateInactiveSources(
    array $options = [
      'dry-run' => FALSE,
    ],
  ) {
    if (!empty($this->state->get('system.maintenance_mode', 0))) {
      $this->logger()->warning(dt('Maintenance mode, aborting.'));
      return TRUE;
    }

    // Retrieve sources with status to update.
    $sources = $this->database->query("
      SELECT
        query.id AS id,
        query.status AS status
      FROM (
        SELECT
          subquery.id,
          CASE
            WHEN subquery.active > 0 THEN 'active'
            WHEN subquery.inactive > 0 THEN 'inactive'
            ELSE 'archive'
          END AS status,
          subquery.current_status AS current_status
        FROM (
          SELECT
            tfd.tid AS id,
            tfd.moderation_status AS current_status,
            SUM(CASE
              # Published jobs or training.
              WHEN n.type IN ('job', 'training') AND n.status = 1 THEN 1
              # Jobs that were open during the past 2 months.
              WHEN n.type = 'job' AND UNIX_TIMESTAMP(fjcd.field_job_closing_date_value) > UNIX_TIMESTAMP(NOW() - INTERVAL 2 MONTH) THEN 1
              # Training that were open during the past 2 months.
              WHEN n.type = 'training' AND UNIX_TIMESTAMP(frd.field_registration_deadline_value) > UNIX_TIMESTAMP(NOW() - INTERVAL 2 MONTH) THEN 1
              # Reports created during the past 3 years.
              WHEN n.type = 'report' AND n.created > UNIX_TIMESTAMP(NOW() - INTERVAL 3 YEAR) THEN 1
              # Ongoing training that were published during the past 2 months.
              WHEN n.type = 'training' AND frd.field_registration_deadline_value IS NULL AND (
                  SELECT MAX(tnr.revision_timestamp)
                  FROM node_field_revision AS tnfr
                  INNER JOIN node_revision AS tnr
                  ON tnr.vid = tnfr.vid
                  WHERE tnfr.moderation_status = 'published'
                    AND tnfr.nid = n.nid
                ) > UNIX_TIMESTAMP(NOW() - INTERVAL 2 MONTH) THEN 1
              ELSE 0
            END) AS active,
            SUM(CASE
              # Jobs that were open during the past 1 year.
              WHEN n.type = 'job' AND UNIX_TIMESTAMP(fjcd.field_job_closing_date_value) > UNIX_TIMESTAMP(NOW() - INTERVAL 1 YEAR) THEN 1
              # Training that were open during the past 1 year.
              WHEN n.type = 'training' AND UNIX_TIMESTAMP(frd.field_registration_deadline_value) > UNIX_TIMESTAMP(NOW() - INTERVAL 1 YEAR) THEN 1
              # Published reports.
              WHEN n.type = 'report' AND n.status = 1 THEN 1
              # Ongoing training that were published during the past year.
              WHEN n.type = 'training' AND frd.field_registration_deadline_value IS NULL AND (
                  SELECT MAX(tnr.revision_timestamp)
                  FROM node_field_revision AS tnfr
                  INNER JOIN node_revision AS tnr
                  ON tnr.vid = tnfr.vid
                  WHERE tnfr.moderation_status = 'published'
                    AND tnfr.nid = n.nid
                ) > UNIX_TIMESTAMP(NOW() - INTERVAL 1 YEAR) THEN 1
              ELSE 0
            END) AS inactive
          FROM taxonomy_term_field_data AS tfd
          LEFT JOIN node__field_source AS fs
            ON fs.field_source_target_id = tfd.tid
          LEFT JOIN node_field_data AS n
            ON n.nid = fs.entity_id
            AND n.type IN ('job', 'training', 'report')
          LEFT JOIN node__field_job_closing_date AS fjcd
            ON fjcd.entity_id = fs.entity_id
          LEFT JOIN node__field_registration_deadline AS frd
            ON frd.entity_id = fs.entity_id
          WHERE tfd.vid = 'source'
            # Skip blocked or duplicate sources.
            AND tfd.moderation_status IN ('active', 'inactive', 'archive')
            # Skip recently created organizations.
            AND tfd.created < UNIX_TIMESTAMP(NOW() - INTERVAL 2 WEEK)
          GROUP BY tfd.tid
        ) AS subquery
      ) AS query
      WHERE query.status IN ('inactive', 'archive') AND query.status <> query.current_status
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
          $source->setRevisionCreationTime(time());
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
