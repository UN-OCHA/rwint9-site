<?php

/**
 * @file
 * Post update file for reliefweb_entities.
 */

/**
 * Implements hook_post_update_NAME().
 *
 * Populate the created field of taxonomy terms.
 */
function reliefweb_entities_post_update_populate_created_value() {
  $database = \Drupal::database();
  $timestamp = time();

  // Set the created value to the oldest revision timestamp if it exists or
  // the current time otherwise.
  $database->query('
    UPDATE {taxonomy_term_field_data} AS td
    LEFT JOIN (
      SELECT tr.tid AS tid, MIN(tr.revision_created) AS timestamp
      FROM {taxonomy_term_revision} AS tr
      GROUP BY tr.tid
    ) AS subquery
    ON subquery.tid = td.tid
    SET td.created = COALESCE(subquery.timestamp, :timestamp)
  ', [
    ':timestamp' => $timestamp,
  ]);

  // For disasters with an event date, override the created date with it.
  $database->query('
    UPDATE {taxonomy_term_field_data} AS td
    INNER JOIN {taxonomy_term__field_disaster_date} AS fd
    ON fd.entity_id = td.tid AND fd.bundle = :bundle
    SET td.created = UNIX_TIMESTAMP(fd.field_disaster_date_value)
  ', [
    ':bundle' => 'disaster',
  ]);

  // Invalidate the taxonomy term cache.
  \Drupal::service('cache_tags.invalidator')
    ->invalidateTags(['taxonomy_term_list']);

  return t('Taxonomy term created value populated.');
}
