<?php

/**
 * @file
 * Retagging script for RW-1262.
 */

$proceed = FALSE;

$database = \Drupal::database();

$query = $database->select("reliefweb_import_records", "r")
  ->fields("r", ["imported_item_uuid", "extra"])
  ->condition("importer", "inoreader")
  ->condition("extra", "%feed%", "LIKE");

$results = $query->execute();

$total = 0;
$updated_count = 0;
$skipped_count = 0;

foreach ($results as $record) {
  $extra = json_decode($record->extra, TRUE);
  $total++;

  if (!isset($extra["inoreader"]["feed_url"])) {
    $skipped_count++;
    continue;
  }

  $feed_url = $extra["inoreader"]["feed_url"];
  $updated = FALSE;

  if (strpos($feed_url, "feed/webfeed://") !== FALSE) {
    $feed_pos = strpos($feed_url, "feed/") + 5;
    $new_url = substr($feed_url, 0, $feed_pos - 5) . "feed/" . urlencode(substr($feed_url, $feed_pos));
    $extra["inoreader"]["feed_url"] = $new_url;
    $updated = TRUE;
  }
  elseif (strpos($feed_url, "feed/http") !== FALSE) {
    $feed_pos = strpos($feed_url, "feed/") + 5;
    $new_url = substr($feed_url, 0, $feed_pos - 5) . "feed/" . urlencode(substr($feed_url, $feed_pos));
    $extra["inoreader"]["feed_url"] = $new_url;
    $updated = TRUE;
  }
  elseif (strpos($feed_url, "feed%2F") !== FALSE) {
    $extra["inoreader"]["feed_url"] = str_replace("feed%2F", "feed/", $feed_url);
    $updated = TRUE;
  }

  if ($updated) {
    if ($proceed) {
      $database->update("reliefweb_import_records")
        ->fields(["extra" => json_encode($extra)])
        ->condition("imported_item_uuid", $record->imported_item_uuid)
        ->execute();
    }
    else {
      print_r(['UPDATE' => $extra["inoreader"]["feed_url"]]);
    }
    $updated_count++;
  }
  else {
    print_r(['SKIP' => $extra["inoreader"]["feed_url"]]);
    $skipped_count++;
  }
}

echo "Found: {$total} records\n";
echo "Updated: {$updated_count} records\n";
echo "Skipped: {$skipped_count} records\n";
