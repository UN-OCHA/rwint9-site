<?php

namespace Drupal\reliefweb_sync_orgs\Traits;

/**
 * Trait for cleaning ID fields.
 */
trait CleanIdFieldTrait {

  /**
   * Clean the ID field by removing non-ANSI characters and truncating it.
   *
   * @param string $id
   *   The ID to clean.
   *
   * @return string
   *   The cleaned ID.
   */
  protected function cleanId(string $id): string {
    // Remove any / from the ID to avoid issues with routing.
    $id = str_replace('/', '', $id);

    // Remove all non-ANSI characters from the Id.
    $id = preg_replace('/[^\x20-\x7E]/', '', $id);

    // Truncate ID to 127 characters to avoid issues with long IDs.
    if (strlen($id) > 127) {
      $id = substr($id, 0, 127);
    }

    return $id;
  }

}
