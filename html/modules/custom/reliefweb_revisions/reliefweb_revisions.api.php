<?php

/**
 * @file
 * Hooks provided by the reliefweb_revisions module.
 */

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Alter the list of base fields that can be shown in the revision history.
 *
 * @param array $allowed
 *   List of fields keyed by the field name that can be shown in the revision
 *   history for the given entity type and bundle. Set the value to TRUE to
 *   allow computing differences for the field.
 * @param string $entity_type_id
 *   Entity type id.
 * @param string $bundle
 *   Entity bundle.
 */
function hook_reliefweb_revisions_allowed_base_fields_alter(array &$allowed, string $entity_type_id, string $bundle) {
  if ($entity_type_id === 'user') {
    $allowed['mail'] = TRUE;
  }
}

/**
 * Alter the list of fields that should NOT be shown in the revision history.
 *
 * @param array $disallowed
 *   List of fields keyed by the field name that should NOT be shown in the
 *   revision history for the given entity type and bundle. Set the value to
 *   TRUE to disallow computing differences for the field.
 * @param string $entity_type_id
 *   Entity type id.
 * @param string $bundle
 *   Entity bundle.
 */
function hook_reliefweb_revisions_disallowed_fields_alter(array &$disallowed, string $entity_type_id, string $bundle) {
  if ($entity_type_id === 'node') {
    $disallowed['body'] = TRUE;
  }
}

/**
 * Return a custom formatting callback for a field definition.
 *
 * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
 *   The definition for the field to format.
 *
 * @return callable|null
 *   A callback that takes the field definition and array of differences and
 *   return a renderable array of the differences or NULL.
 */
function hook_reliefweb_revisions_get_formatting_callback(FieldDefinitionInterface $field_definition) {
  return 'my_module_format_textfied_revision_diffs';
}
