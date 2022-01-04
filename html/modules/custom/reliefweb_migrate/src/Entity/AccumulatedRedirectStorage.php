<?php

namespace Drupal\reliefweb_migrate\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Redirect SQL storage for the migrations that regroup queries.
 */
class AccumulatedRedirectStorage extends SqlContentEntityStorage implements AccumulatedSqlContentEntityStorageInterface {

  use AccumulatedSqlContentEntityStorageTrait;

  /**
   * Save the accumulated table data in the database.
   */
  public function flushAccumulated() {
    // Remove duplicate redirects.
    $this->removeDuplicates();

    // Insert the accumulated data.
    $this->doFlushAccumulated();

    // Invalidate the entity tags and reset the accumulator.
    Cache::invalidateTags($this->accumulatedTagsToInvalidate);
    $this->accumulatedTagsToInvalidate = [];

    // Reset the accumulator.
    $this->accumulator = [];

    // Reset the accumulation counter.
    $this->accumulationCounter = 0;
  }

  /**
   * Remove duplicate redirects.
   */
  protected function removeDuplicates() {
    if (!empty($this->accumulator)) {
      $hashes = [];

      // Extract the redirect hashes from the accumulated data and remove
      // duplicates.
      foreach ($this->accumulator as $table => $entries) {
        foreach ($entries as $index_rows => $rows) {
          foreach ($rows as $index_row => $row) {
            $hash = is_array($row) ? $row['hash'] : $row->hash;
            if (isset($hashes[$hash])) {
              unset($this->accumulator[$table][$index_rows][$index_row]);
            }
            else {
              $hashes[$hash] = $hash;
            }
          }
        }
      }

      // Retrieve any existing hash.
      $existing_hashes = $this->getExistingHashes($hashes);

      // Remove the entries if there is already a redirect with the same hash
      // in the database.
      if (!empty($existing_hashes)) {
        foreach ($this->accumulator as $table => $entries) {
          foreach ($entries as $index_rows => $rows) {
            foreach ($rows as $index_row => $row) {
              $hash = is_array($row) ? $row['hash'] : $row->hash;
              if (isset($existing_hashes[$hash])) {
                unset($this->accumulator[$table][$index_rows][$index_row]);
              }
            }
          }
        }
      }
    }
  }

  /**
   * Get the list of existing hashes in the database for the given hashes.
   *
   * @param array $hashes
   *   Current redirect hashes to save in the database.
   *
   * @return array
   *   Existing hashes in the database.
   */
  protected function getExistingHashes(array $hashes) {
    // Retrieve any existing hash.
    return array_flip($this->database
      ->select('redirect', 'r')
      ->fields('r', ['hash'])
      ->condition('r.hash', $hashes, 'IN')
      ->execute()
      ?->fetchCol() ?? []);
  }

}
