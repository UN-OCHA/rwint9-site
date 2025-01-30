<?php

declare(strict_types=1);

namespace Drupal\reliefweb_reporting;

use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Url;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;

/**
 * Retrieve data related to the automated classification.
 */
class AutomatedClassificationReporting {

  use LoggerChannelTrait;
  use EntityDatabaseInfoTrait;

  /**
   * Get a list of documents that manually retagged by editors.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string $bundle
   *   Entity bundle.
   * @param string $start
   *   Starting date.
   * @param string $end
   *   Ending date.
   *
   * @return array
   *   List of manually retagged content.
   */
  public function getManuallyRetaggedContent(
    string $entity_type_id = 'node',
    string $bundle = 'job',
    string $start = '-1 month',
    string $end = 'now',
  ) {
    $logger = $this->getLogger('reliefweb_reporting_classification');

    $database = $this->getDatabase();
    if (!$database->schema()->tableExists('ocha_content_classification_progress')) {
      $logger->error('Unable to locate the classification progress table');
      return [];
    }

    $entity_type_manager = $this->getEntityTypeManager();

    // Retrieve the classification workflow for the entity type and bundle.
    $workflows = $entity_type_manager
      ->getStorage('ocha_classification_workflow')
      ->loadByProperties([
        'target.entity_type_id' => $entity_type_id,
        'target.bundle' => $bundle,
        'status' => 1,
      ]);

    // Retrieve the fields that can be classified.
    $fields = reset($workflows)?->getEnabledClassifiableFields();
    if (empty($fields)) {
      $logger->warning(strtr('Unable to find a classification workflow for the entity type: @entity_type_id and bundle: @bundle.', [
        '@entity_type_id' => $entity_type_id,
        '@bundle' => $bundle,
      ]));
      return [];
    }
    $fields = array_keys($fields);

    // Retrieve the field definitions for the entity type so we can
    // retrieve the main column of the classifiable fields and their labels.
    $field_definitions = $this->getEntityFieldManager()
      ->getFieldDefinitions($entity_type_id, $bundle);

    // Retrieve the timestamps from the start and end dates.
    $timezone = new \DateTimeZone('UTC');
    $start_date = new \DateTime($start, $timezone);
    $end_date = new \DateTime($end, $timezone);
    $start_timestamp = $start_date->getTimestamp();
    $end_timestamp = $end_date->getTimestamp();

    // Retrieve the revision table for the entity.
    $revision_table = $this->getEntityTypeRevisionTable($entity_type_id);
    $revision_id_field = $this->getEntityTypeRevisionIdField($entity_type_id);
    $entity_id_field = $this->getEntityTypeIdField($entity_type_id);

    // Generate the query to retrieve the revisions of the tagged content for
    // the period.
    $query = $database->select($revision_table, $revision_table);

    // Add the entity and revision ID fields so we can group the records.
    $query->addField($revision_table, $entity_id_field, 'entity_id');
    $query->addField($revision_table, $revision_id_field, 'revision_id');

    // Restrict the content for which the classification succeeded.
    $classification_table = 'ocha_content_classification_progress';
    $query->innerJoin($classification_table, $classification_table, "%alias.entity_type_id = :entity_type_id AND %alias.entity_id = {$revision_table}.{$entity_id_field} AND %alias.status = :classification_status", [
      ':entity_type_id' => $entity_type_id,
      ':classification_status' => 'completed',
    ]);

    // Join the fields that can be classified so we can determine changes.
    $field_labels = [];
    foreach ($fields as $field) {
      $field_definition = $field_definitions[$field];
      $field_table = $this->getFieldRevisionTableName($entity_type_id, $field);
      $query->leftJoin($field_table, $field_table, "%alias.revision_id = {$revision_table}.{$revision_id_field}");

      $column = $field_definition->getFieldStorageDefinition()->getMainPropertyName();
      $property = $this->getFieldColumnName($entity_type_id, $field, $column);

      // Add the grouped and orderded field values so we can determine changes.
      $query->addExpression("IFNULL(GROUP_CONCAT(DISTINCT {$field_table}.{$property} ORDER BY {$field_table}.{$property} ASC SEPARATOR ','), '')", $field);

      // Store the field labels so we can use them directly later when building
      // the export data.
      $field_labels[$field] = $field_definition->getLabel();
    }

    // Restrict to the bundle, date range and revisions after the one of the
    // completion of the automated classification.
    $query->condition("{$classification_table}.entity_type_id", $entity_type_id, '=');
    $query->condition("{$classification_table}.entity_bundle", $bundle, '=');
    $query->condition("{$classification_table}.created", $start_timestamp, '>=');
    $query->condition("{$classification_table}.created", $end_timestamp, '<');
    $query->where("{$revision_table}.{$revision_id_field} >= {$classification_table}.entity_revision_id");

    // Group and order by revision ID.
    $query->groupBy("{$revision_table}.{$revision_id_field}");
    $query->orderBy("{$revision_table}.{$revision_id_field}");

    // Retrieve the revisions.
    $records = $query->execute()?->fetchAll(\PDO::FETCH_ASSOC);

    // Group the revisions by entity id and revision id.
    $entity_revisions = [];
    foreach ($records as $record) {
      $entity_revisions[$record['entity_id']][$record['revision_id']] = $record;
    }
    if (empty($entity_revisions)) {
      $logger->notice(strtr('No classified entities found for the entity type: @entity_type_id and bundle: @bundle.', [
        '@entity_type_id' => $entity_type_id,
        '@bundle' => $bundle,
      ]));
      return [];
    }

    $entities = [];
    $changed_entities = [];
    $stored_revisions = [];

    // Retrieve the tagging modifications.
    foreach ($entity_revisions as $id => $revisions) {
      foreach ($revisions as $revision) {
        // Store the entity field values.
        if (!isset($entities[$id])) {
          foreach ($fields as $field) {
            $entities[$id][$field]['old'] = $revision[$field] ?? '';
            $entities[$id][$field]['new'] = $revision[$field] ?? '';
          }
        }

        // Check if the entity was manually retagged and store the new field
        // values.
        if (isset($stored_revisions[$id])) {
          $previous = $stored_revisions[$id];

          foreach ($fields as $field) {
            if ($revision[$field] !== $previous[$field]) {
              $entities[$id][$field]['new'] = $revision[$field] ?? '';
              $changed_entities[$id] = TRUE;
            }
          }
        }

        // Store the latest revision.
        $stored_revisions[$id] = $revision;
      }
    }

    if (empty($changed_entities)) {
      $logger->notice(strtr('No changed entities found for the entity type: @entity_type_id and bundle: @bundle.', [
        '@entity_type_id' => $entity_type_id,
        '@bundle' => $bundle,
      ]));
      return [];
    }

    // Filter out entities that were not manually retagged.
    $entities = array_intersect_key($entities, $changed_entities);

    // Extract the term IDs so we can retrieve their corresponding labels.
    $term_ids = [];
    foreach ($entities as $id => $fields) {
      foreach ($fields as $field_name => $field) {
        foreach (['old', 'new'] as $key) {
          if (!empty($field[$key])) {
            $field_values = explode(',', $field[$key]);
            $entities[$id][$field_name][$key] = $field_values;
            $term_ids += array_flip($field_values);
          }
          else {
            $entities[$id][$field_name][$key] = [];
          }
        }
      }
    }

    // Retrieve the label of the changed entities.
    $entity_table = $this->getEntityTypeDataTable($entity_type_id);
    $label_field = $this->getEntityTypeLabelField($entity_type_id);

    $entity_titles = $database
      ->select($entity_table, $entity_table)
      ->fields($entity_table, [$entity_id_field, $label_field])
      ->condition("{$entity_table}.{$entity_id_field}", array_keys($changed_entities), 'IN')
      ->execute()
      ?->fetchAllKeyed();

    // Retrieve the taxonomy term names.
    $terms = $this->getTaxonomyTermNames(array_keys($term_ids));

    // Generate the export data.
    $data = [];
    foreach ($entities as $id => $entity_fields) {
      // Retrieve the canonical URL for the entity.
      $url = Url::fromRoute("entity.{$entity_type_id}.canonical", [
        $entity_type_id => $id,
      ], [
        'absolute' => TRUE,
        'alias' => FALSE,
      ])->toString();

      $entry = [
        'ID' => $id,
        'URL' => $url,
        'Title' => $entity_titles[$id],
      ];

      foreach ($field_labels as $field => $label) {
        foreach (['old', 'new'] as $key) {
          $field_values = $entity_fields[$field][$key] ?? [];
          $field_term_names = array_map(fn($id) => $terms[$id] ?? '', $field_values);
          $field_term_names = array_filter($field_term_names);
          $entry[$label . ' - ' . $key] = implode(',', $field_term_names) ?: '-';
        }
      }

      $data[] = $entry;
    }

    return $data;
  }

  /**
   * Get the taxonomy terms for the given term IDs.
   *
   * @param array $ids
   *   The IDs of the terms to retrieve.
   *
   * @return array
   *   An associative array of taxonomy term names keyed by IDs.
   */
  protected function getTaxonomyTermNames(array $ids) {
    $table = $this->getEntityTypeDataTable('taxonomy_term');
    $id_field = $this->getEntityTypeIdField('taxonomy_term');
    $label_field = $this->getEntityTypeLabelField('taxonomy_term');

    $terms = $this->getDatabase()
      ->select($table, $table)
      ->fields($table, [$id_field, $label_field])
      ->condition("{$table}.{$id_field}", $ids, 'IN')
      ->execute()
      ?->fetchAllKeyed() ?? [];

    return $terms;
  }

}
