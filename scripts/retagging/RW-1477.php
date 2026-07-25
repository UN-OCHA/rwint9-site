<?php

/**
 * @file
 * Retagging script for RW-1477.
 *
 * Directly updates node/taxonomy field data/revision tables (no entity save).
 *
 * Reports referencing country 170:
 * - replace primary country 170 with 24
 * - remove country 170
 * - add countries 24, 14893, 14892, 14894 if not already present
 *
 * Job/training revisions still referencing country 170:
 * - replace each dirty revision field_country with the node current
 *   (latest) field_country values
 * - if the latest revision has no country, clear field_country on those
 *   revisions as well
 *
 * Source/disaster term revisions still referencing country 170:
 * - if current (latest) is not tagged with 170, copy that country list onto
 *   dirty revisions
 * - if current still has 170, merge: remove 170, keep other countries, add
 *   new_countries, and update current + dirty revisions (preserves e.g. 20/21)
 * - disaster primary 170 follows the same latest-or-24 rule
 *
 * This avoids creating new revisions and skips entity hooks / preSave side
 * effects. Reindex affected entities afterwards if needed.
 *
 * Usage (drush php:script, supports options):
 *   drush php:script scripts/retagging/RW-1477.php
 *   drush php:script scripts/retagging/RW-1477.php -- --limit=10
 *   drush php:script scripts/retagging/RW-1477.php -- --save=0
 *
 * Usage (drush php-eval, wrap the whole file content in single quotes):
 *   drush php-eval "$(cat scripts/retagging/RW-1477.php)"
 */

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;

$proceed = TRUE;
$save = TRUE;
$reindex = TRUE;
$limit = 0;

if (isset($extra) && is_array($extra)) {
  foreach ($extra as $arg) {
    if (str_starts_with($arg, "--limit=")) {
      $limit = (int) substr($arg, 8);
    }
    elseif (str_starts_with($arg, "--save=")) {
      $save = (bool) (int) substr($arg, 7);
    }
    elseif (str_starts_with($arg, "--reindex=")) {
      $reindex = (bool) (int) substr($arg, 10);
    }
  }
}

$old_country = 170;
$new_countries = [
  24,
  14893,
  14892,
  14894,
];
$new_primary_country = 24;
$bundle = "report";

$results = [
  "revisions_updated" => 0,
  "nodes_updated" => 0,
  "primary_replaced" => 0,
  "countries_written" => 0,
  "job_training_revisions_updated" => 0,
  "job_training_countries_written" => 0,
  "job_training_countries_cleared" => 0,
  "term_revisions_updated" => 0,
  "term_countries_written" => 0,
  "term_current_updated" => 0,
  "term_primary_replaced" => 0,
  "affected_nids" => [],
  "affected_tids" => [],
];

/**
 * Build the replacement primary + country list for one field instance.
 */
$build_countries = static function (array $existing_tids, ?int $primary_tid, int $old_country, array $new_countries, int $new_primary_country): array {
  if ($primary_tid === $old_country) {
    $primary_tid = $new_primary_country;
  }

  $set = [];
  foreach ($existing_tids as $tid) {
    $tid = (int) $tid;
    if ($tid !== $old_country) {
      $set[$tid] = TRUE;
    }
  }
  foreach ($new_countries as $tid) {
    $set[(int) $tid] = TRUE;
  }

  $countries = [];
  if (!empty($primary_tid)) {
    $countries[] = $primary_tid;
    unset($set[$primary_tid]);
  }

  // Keep prior relative order, then append any remaining new countries.
  foreach ($existing_tids as $tid) {
    $tid = (int) $tid;
    if (isset($set[$tid])) {
      $countries[] = $tid;
      unset($set[$tid]);
    }
  }
  foreach ($new_countries as $tid) {
    $tid = (int) $tid;
    if (isset($set[$tid])) {
      $countries[] = $tid;
      unset($set[$tid]);
    }
  }
  foreach (array_keys($set) as $tid) {
    $countries[] = $tid;
  }

  return [$primary_tid, $countries];
};

/**
 * Rewrite country / primary country rows for revision or current field tables.
 *
 * @param bool $revision
 *   TRUE for node_revision__* tables (keyed by revision_id), FALSE for
 *   node__* tables (keyed by entity_id / default revision).
 */
$update_table_pair = static function (Connection $database, bool $revision, array $ids, string $bundle, int $old_country, array $new_countries, int $new_primary_country, bool $save, callable $build_countries) use (&$results): void {
  if (empty($ids)) {
    return;
  }

  $country_table = $revision ? "node_revision__field_country" : "node__field_country";
  $primary_table = $revision ? "node_revision__field_primary_country" : "node__field_primary_country";
  $id_key = $revision ? "revision_id" : "entity_id";

  // Load existing country rows.
  $country_rows = $database->select($country_table, "fc")
    ->fields("fc", [
      "bundle",
      "deleted",
      "entity_id",
      "revision_id",
      "langcode",
      "delta",
      "field_country_target_id",
    ])
    ->condition($id_key, $ids, "IN")
    ->condition("bundle", $bundle)
    ->condition("deleted", 0)
    ->orderBy($id_key)
    ->orderBy("langcode")
    ->orderBy("delta")
    ->execute()
    ->fetchAll(\PDO::FETCH_ASSOC);

  // Load existing primary rows.
  $primary_rows = $database->select($primary_table, "fp")
    ->fields("fp", [
      "bundle",
      "deleted",
      "entity_id",
      "revision_id",
      "langcode",
      "delta",
      "field_primary_country_target_id",
    ])
    ->condition($id_key, $ids, "IN")
    ->condition("bundle", $bundle)
    ->condition("deleted", 0)
    ->execute()
    ->fetchAll(\PDO::FETCH_ASSOC);

  // Group by id + langcode.
  $countries_by_key = [];
  $meta_by_key = [];
  foreach ($country_rows as $row) {
    $key = $row[$id_key] . ":" . $row["langcode"];
    $countries_by_key[$key][] = (int) $row["field_country_target_id"];
    $meta_by_key[$key] = [
      "bundle" => $row["bundle"],
      "deleted" => (int) $row["deleted"],
      "entity_id" => (int) $row["entity_id"],
      "revision_id" => (int) $row["revision_id"],
      "langcode" => $row["langcode"],
    ];
  }

  $primary_by_key = [];
  foreach ($primary_rows as $row) {
    $key = $row[$id_key] . ":" . $row["langcode"];
    $primary_by_key[$key] = (int) $row["field_primary_country_target_id"];
    if (!isset($meta_by_key[$key])) {
      $meta_by_key[$key] = [
        "bundle" => $row["bundle"],
        "deleted" => (int) $row["deleted"],
        "entity_id" => (int) $row["entity_id"],
        "revision_id" => (int) $row["revision_id"],
        "langcode" => $row["langcode"],
      ];
    }
  }

  $keys = array_unique(array_merge(array_keys($countries_by_key), array_keys($primary_by_key)));
  sort($keys);

  foreach ($keys as $key) {
    $meta = $meta_by_key[$key];
    $existing = $countries_by_key[$key] ?? [];
    $primary = $primary_by_key[$key] ?? NULL;

    // Only rewrite rows that still reference the old country.
    $needs_update = in_array($old_country, $existing, TRUE) || $primary === $old_country;
    if (!$needs_update) {
      continue;
    }

    [$new_primary, $new_country_list] = $build_countries(
      $existing,
      $primary,
      $old_country,
      $new_countries,
      $new_primary_country
    );

    $primary_changed = $primary !== $new_primary;
    $countries_changed = array_values($existing) !== array_values($new_country_list);
    if (!$primary_changed && !$countries_changed) {
      continue;
    }

    $results[$revision ? "revisions_updated" : "nodes_updated"]++;
    if ($primary_changed) {
      $results["primary_replaced"]++;
    }
    $results["affected_nids"][$meta["entity_id"]] = TRUE;

    echo ($revision ? "Revision" : "Node") . " " . $meta[$id_key] . " (nid " . $meta["entity_id"] . ", lang " . $meta["langcode"] . ")" . PHP_EOL;
    echo "  primary: " . var_export($primary, TRUE) . " => " . var_export($new_primary, TRUE) . PHP_EOL;
    echo "  countries: " . implode(",", $existing) . " => " . implode(",", $new_country_list) . PHP_EOL;

    if (!$save) {
      continue;
    }

    $transaction = $database->startTransaction();
    try {
      if ($primary_changed && $primary === $old_country) {
        $database->update($primary_table)
          ->fields(["field_primary_country_target_id" => $new_primary])
          ->condition($id_key, $meta[$id_key])
          ->condition("langcode", $meta["langcode"])
          ->condition("bundle", $bundle)
          ->condition("deleted", 0)
          ->condition("field_primary_country_target_id", $old_country)
          ->execute();
      }

      if ($countries_changed) {
        $database->delete($country_table)
          ->condition($id_key, $meta[$id_key])
          ->condition("langcode", $meta["langcode"])
          ->condition("bundle", $bundle)
          ->condition("deleted", 0)
          ->execute();

        $delta = 0;
        foreach ($new_country_list as $tid) {
          $database->insert($country_table)
            ->fields([
              "bundle" => $meta["bundle"],
              "deleted" => 0,
              "entity_id" => $meta["entity_id"],
              "revision_id" => $meta["revision_id"],
              "langcode" => $meta["langcode"],
              "delta" => $delta,
              "field_country_target_id" => $tid,
            ])
            ->execute();
          $delta++;
          $results["countries_written"]++;
        }
      }
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
  }
};

$database = \Drupal::database();

// Revisions that reference country 170 (as country or primary country).
$vids = $database->query("
  SELECT DISTINCT revision_id FROM (
    SELECT revision_id
    FROM {node_revision__field_country}
    WHERE bundle = :bundle
      AND deleted = 0
      AND field_country_target_id = :old
    UNION
    SELECT revision_id
    FROM {node_revision__field_primary_country}
    WHERE bundle = :bundle
      AND deleted = 0
      AND field_primary_country_target_id = :old
  ) AS affected
  ORDER BY revision_id ASC
", [
  ":bundle" => $bundle,
  ":old" => $old_country,
])->fetchCol();

// Current (default) field rows that still reference country 170.
$nids = $database->query("
  SELECT DISTINCT entity_id FROM (
    SELECT entity_id
    FROM {node__field_country}
    WHERE bundle = :bundle
      AND deleted = 0
      AND field_country_target_id = :old
    UNION
    SELECT entity_id
    FROM {node__field_primary_country}
    WHERE bundle = :bundle
      AND deleted = 0
      AND field_primary_country_target_id = :old
  ) AS affected
  ORDER BY entity_id ASC
", [
  ":bundle" => $bundle,
  ":old" => $old_country,
])->fetchCol();

if ($limit > 0) {
  $vids = array_slice($vids, 0, $limit);
  $nids = array_slice($nids, 0, $limit);
}

echo "Found " . count($vids) . " revisions and " . count($nids) . " current nodes referencing country " . $old_country . PHP_EOL;

if (empty($proceed)) {
  return;
}

$chunk_size = 100;
foreach (array_chunk($vids, $chunk_size) as $chunk) {
  $update_table_pair($database, TRUE, $chunk, $bundle, $old_country, $new_countries, $new_primary_country, $save, $build_countries);
}
foreach (array_chunk($nids, $chunk_size) as $chunk) {
  $update_table_pair($database, FALSE, $chunk, $bundle, $old_country, $new_countries, $new_primary_country, $save, $build_countries);
}

// ---------------------------------------------------------------------------
// Pass 2: job/training old revisions still referencing country 170.
// Replace with the node current (latest) field_country, or clear if empty.
// ---------------------------------------------------------------------------
$opportunity_bundles = ["job", "training"];

$opportunity_rows = $database->select("node_revision__field_country", "fc")
  ->fields("fc", [
    "bundle",
    "entity_id",
    "revision_id",
    "langcode",
  ])
  ->condition("bundle", $opportunity_bundles, "IN")
  ->condition("deleted", 0)
  ->condition("field_country_target_id", $old_country)
  ->orderBy("entity_id")
  ->orderBy("revision_id")
  ->orderBy("langcode")
  ->execute()
  ->fetchAll(\PDO::FETCH_ASSOC);

$opportunity_keys = [];
$opportunity_nids = [];
foreach ($opportunity_rows as $row) {
  $key = (int) $row["revision_id"] . ":" . $row["langcode"];
  if (!isset($opportunity_keys[$key])) {
    $opportunity_keys[$key] = [
      "bundle" => $row["bundle"],
      "entity_id" => (int) $row["entity_id"],
      "revision_id" => (int) $row["revision_id"],
      "langcode" => $row["langcode"],
    ];
    $opportunity_nids[(int) $row["entity_id"]] = TRUE;
  }
}

$opportunity_nids = array_map("intval", array_keys($opportunity_nids));
sort($opportunity_nids);

if ($limit > 0) {
  $opportunity_keys = array_slice($opportunity_keys, 0, $limit, TRUE);
  $opportunity_nids_limited = [];
  foreach ($opportunity_keys as $meta) {
    $opportunity_nids_limited[$meta["entity_id"]] = TRUE;
  }
  $opportunity_nids = array_map("intval", array_keys($opportunity_nids_limited));
  sort($opportunity_nids);
}

echo "Found " . count($opportunity_keys) . " job/training revisions referencing country " . $old_country . PHP_EOL;

// Latest (current) countries keyed by entity_id:langcode.
$latest_by_key = [];
if (!empty($opportunity_nids)) {
  $latest_rows = $database->select("node__field_country", "fc")
    ->fields("fc", [
      "entity_id",
      "langcode",
      "delta",
      "field_country_target_id",
    ])
    ->condition("entity_id", $opportunity_nids, "IN")
    ->condition("bundle", $opportunity_bundles, "IN")
    ->condition("deleted", 0)
    ->orderBy("entity_id")
    ->orderBy("langcode")
    ->orderBy("delta")
    ->execute()
    ->fetchAll(\PDO::FETCH_ASSOC);

  foreach ($latest_rows as $row) {
    $key = (int) $row["entity_id"] . ":" . $row["langcode"];
    $latest_by_key[$key][] = (int) $row["field_country_target_id"];
  }
}

foreach ($opportunity_keys as $meta) {
  $nid = $meta["entity_id"];
  $vid = $meta["revision_id"];
  $langcode = $meta["langcode"];
  $latest_key = $nid . ":" . $langcode;
  $replacement = $latest_by_key[$latest_key] ?? [];

  // Load existing countries on this revision for logging / change detection.
  $existing = $database->select("node_revision__field_country", "fc")
    ->fields("fc", ["field_country_target_id"])
    ->condition("revision_id", $vid)
    ->condition("langcode", $langcode)
    ->condition("deleted", 0)
    ->orderBy("delta")
    ->execute()
    ->fetchCol();
  $existing = array_map("intval", $existing);

  if (array_values($existing) === array_values($replacement)) {
    continue;
  }

  $results["job_training_revisions_updated"]++;
  $results["affected_nids"][$nid] = TRUE;

  echo "Job/training revision " . $vid . " (nid " . $nid . ", bundle " . $meta["bundle"] . ", lang " . $langcode . ")" . PHP_EOL;
  echo "  countries: " . implode(",", $existing) . " => " . (empty($replacement) ? "(cleared)" : implode(",", $replacement)) . PHP_EOL;

  if (!$save) {
    continue;
  }

  $transaction = $database->startTransaction();
  try {
    $database->delete("node_revision__field_country")
      ->condition("revision_id", $vid)
      ->condition("langcode", $langcode)
      ->condition("deleted", 0)
      ->execute();

    if (empty($replacement)) {
      $results["job_training_countries_cleared"]++;
    }
    else {
      $delta = 0;
      foreach ($replacement as $tid) {
        $database->insert("node_revision__field_country")
          ->fields([
            "bundle" => $meta["bundle"],
            "deleted" => 0,
            "entity_id" => $nid,
            "revision_id" => $vid,
            "langcode" => $langcode,
            "delta" => $delta,
            "field_country_target_id" => $tid,
          ])
          ->execute();
        $delta++;
        $results["job_training_countries_written"]++;
      }
    }
  }
  catch (\Exception $e) {
    $transaction->rollBack();
    throw $e;
  }
  unset($transaction);
}

// ---------------------------------------------------------------------------
// Pass 3: source/disaster term revisions still referencing country 170.
// If latest has no 170, copy latest onto dirty revisions.
// If latest still has 170, merge (drop 170, keep others, add new_countries)
// onto dirty revisions and current field rows.
// ---------------------------------------------------------------------------
$term_bundles = ["source", "disaster"];

$term_dirty_rows = $database->query("
  SELECT DISTINCT bundle, entity_id, revision_id, langcode FROM (
    SELECT bundle, entity_id, revision_id, langcode
    FROM {taxonomy_term_revision__field_country}
    WHERE bundle IN (:bundles[])
      AND deleted = 0
      AND field_country_target_id = :old
    UNION
    SELECT bundle, entity_id, revision_id, langcode
    FROM {taxonomy_term_revision__field_primary_country}
    WHERE bundle = :disaster
      AND deleted = 0
      AND field_primary_country_target_id = :old
  ) AS affected
  ORDER BY entity_id ASC, revision_id ASC, langcode ASC
", [
  ":bundles[]" => $term_bundles,
  ":disaster" => "disaster",
  ":old" => $old_country,
])->fetchAll(\PDO::FETCH_ASSOC);

$term_keys = [];
$term_tids = [];
foreach ($term_dirty_rows as $row) {
  $key = (int) $row["revision_id"] . ":" . $row["langcode"];
  if (!isset($term_keys[$key])) {
    $term_keys[$key] = [
      "bundle" => $row["bundle"],
      "entity_id" => (int) $row["entity_id"],
      "revision_id" => (int) $row["revision_id"],
      "langcode" => $row["langcode"],
    ];
    $term_tids[(int) $row["entity_id"]] = TRUE;
  }
}

$term_tids = array_map("intval", array_keys($term_tids));
sort($term_tids);

if ($limit > 0) {
  $term_keys = array_slice($term_keys, 0, $limit, TRUE);
  $term_tids_limited = [];
  foreach ($term_keys as $meta) {
    $term_tids_limited[$meta["entity_id"]] = TRUE;
  }
  $term_tids = array_map("intval", array_keys($term_tids_limited));
  sort($term_tids);
}

echo "Found " . count($term_keys) . " source/disaster term revisions referencing country " . $old_country . PHP_EOL;

$term_latest_countries = [];
$term_latest_primary = [];
$term_current_meta = [];
if (!empty($term_tids)) {
  $latest_country_rows = $database->select("taxonomy_term__field_country", "fc")
    ->fields("fc", [
      "bundle",
      "entity_id",
      "revision_id",
      "langcode",
      "delta",
      "field_country_target_id",
    ])
    ->condition("entity_id", $term_tids, "IN")
    ->condition("bundle", $term_bundles, "IN")
    ->condition("deleted", 0)
    ->orderBy("entity_id")
    ->orderBy("langcode")
    ->orderBy("delta")
    ->execute()
    ->fetchAll(\PDO::FETCH_ASSOC);

  foreach ($latest_country_rows as $row) {
    $key = (int) $row["entity_id"] . ":" . $row["langcode"];
    $term_latest_countries[$key][] = (int) $row["field_country_target_id"];
    $term_current_meta[$key] = [
      "bundle" => $row["bundle"],
      "entity_id" => (int) $row["entity_id"],
      "revision_id" => (int) $row["revision_id"],
      "langcode" => $row["langcode"],
    ];
  }

  $latest_primary_rows = $database->select("taxonomy_term__field_primary_country", "fp")
    ->fields("fp", [
      "bundle",
      "entity_id",
      "revision_id",
      "langcode",
      "field_primary_country_target_id",
    ])
    ->condition("entity_id", $term_tids, "IN")
    ->condition("bundle", "disaster")
    ->condition("deleted", 0)
    ->execute()
    ->fetchAll(\PDO::FETCH_ASSOC);

  foreach ($latest_primary_rows as $row) {
    $key = (int) $row["entity_id"] . ":" . $row["langcode"];
    $term_latest_primary[$key] = (int) $row["field_primary_country_target_id"];
    if (!isset($term_current_meta[$key])) {
      $term_current_meta[$key] = [
        "bundle" => $row["bundle"],
        "entity_id" => (int) $row["entity_id"],
        "revision_id" => (int) $row["revision_id"],
        "langcode" => $row["langcode"],
      ];
    }
  }
}

/**
 * Rewrite taxonomy_term country rows for a current or revision table key.
 */
$rewrite_term_countries = static function (Connection $database, string $table, string $id_key, int $id_value, array $meta, array $countries, bool $save) use (&$results): int {
  if (!$save) {
    return count($countries);
  }

  $database->delete($table)
    ->condition($id_key, $id_value)
    ->condition("langcode", $meta["langcode"])
    ->condition("deleted", 0)
    ->execute();

  $written = 0;
  $delta = 0;
  foreach ($countries as $tid) {
    $database->insert($table)
      ->fields([
        "bundle" => $meta["bundle"],
        "deleted" => 0,
        "entity_id" => $meta["entity_id"],
        "revision_id" => $meta["revision_id"],
        "langcode" => $meta["langcode"],
        "delta" => $delta,
        "field_country_target_id" => $tid,
      ])
      ->execute();
    $delta++;
    $written++;
  }
  return $written;
};

$term_current_done = [];

foreach ($term_keys as $meta) {
  $tid = $meta["entity_id"];
  $vid = $meta["revision_id"];
  $langcode = $meta["langcode"];
  $latest_key = $tid . ":" . $langcode;

  $latest_countries = $term_latest_countries[$latest_key] ?? [];
  $latest_primary = $term_latest_primary[$latest_key] ?? NULL;
  $latest_has_old = in_array($old_country, $latest_countries, TRUE) || $latest_primary === $old_country;

  if ($latest_has_old) {
    [$replacement_primary, $replacement] = $build_countries(
      $latest_countries,
      $latest_primary,
      $old_country,
      $new_countries,
      $new_primary_country
    );
    $update_current = TRUE;
  }
  else {
    $replacement = $latest_countries;
    $replacement_primary = $latest_primary;
    $update_current = FALSE;
  }

  // Existing revision countries / primary.
  $existing = $database->select("taxonomy_term_revision__field_country", "fc")
    ->fields("fc", ["field_country_target_id"])
    ->condition("revision_id", $vid)
    ->condition("langcode", $langcode)
    ->condition("deleted", 0)
    ->orderBy("delta")
    ->execute()
    ->fetchCol();
  $existing = array_map("intval", $existing);

  $existing_primary = NULL;
  if ($meta["bundle"] === "disaster") {
    $existing_primary_value = $database->select("taxonomy_term_revision__field_primary_country", "fp")
      ->fields("fp", ["field_primary_country_target_id"])
      ->condition("revision_id", $vid)
      ->condition("langcode", $langcode)
      ->condition("deleted", 0)
      ->execute()
      ->fetchField();
    if ($existing_primary_value !== FALSE && $existing_primary_value !== NULL) {
      $existing_primary = (int) $existing_primary_value;
    }
  }

  $countries_changed = array_values($existing) !== array_values($replacement);
  $primary_changed = $meta["bundle"] === "disaster"
    && $existing_primary === $old_country
    && $replacement_primary !== $old_country;

  if (!$countries_changed && !$primary_changed && !($update_current && !isset($term_current_done[$latest_key]))) {
    continue;
  }

  if ($countries_changed || $primary_changed) {
    $results["term_revisions_updated"]++;
  }
  $results["affected_tids"][$tid] = TRUE;

  echo "Term revision " . $vid . " (tid " . $tid . ", bundle " . $meta["bundle"] . ", lang " . $langcode . ")" . PHP_EOL;
  if ($meta["bundle"] === "disaster") {
    echo "  primary: " . var_export($existing_primary, TRUE) . " => " . var_export($replacement_primary, TRUE) . PHP_EOL;
  }
  echo "  countries: " . implode(",", $existing) . " => " . (empty($replacement) ? "(cleared)" : implode(",", $replacement)) . PHP_EOL;
  if ($update_current && !isset($term_current_done[$latest_key])) {
    echo "  (also updating current term field rows via merge)" . PHP_EOL;
  }

  if ($save) {
    $transaction = $database->startTransaction();
    try {
      if ($countries_changed) {
        $written = $rewrite_term_countries(
          $database,
          "taxonomy_term_revision__field_country",
          "revision_id",
          $vid,
          $meta,
          $replacement,
          TRUE
        );
        $results["term_countries_written"] += $written;
      }

      if ($primary_changed && $replacement_primary !== NULL) {
        $database->update("taxonomy_term_revision__field_primary_country")
          ->fields(["field_primary_country_target_id" => $replacement_primary])
          ->condition("revision_id", $vid)
          ->condition("langcode", $langcode)
          ->condition("deleted", 0)
          ->condition("field_primary_country_target_id", $old_country)
          ->execute();
        $results["term_primary_replaced"]++;
      }

      if ($update_current && !isset($term_current_done[$latest_key])) {
        $current_meta = $term_current_meta[$latest_key] ?? [
          "bundle" => $meta["bundle"],
          "entity_id" => $tid,
          "revision_id" => $vid,
          "langcode" => $langcode,
        ];

        $written = $rewrite_term_countries(
          $database,
          "taxonomy_term__field_country",
          "entity_id",
          $tid,
          $current_meta,
          $replacement,
          TRUE
        );
        $results["term_countries_written"] += $written;

        // Keep default revision field rows in sync with current.
        if ((int) $current_meta["revision_id"] !== $vid || !$countries_changed) {
          $default_meta = $current_meta;
          $rewrite_term_countries(
            $database,
            "taxonomy_term_revision__field_country",
            "revision_id",
            (int) $current_meta["revision_id"],
            $default_meta,
            $replacement,
            TRUE
          );
        }

        if ($meta["bundle"] === "disaster" && $latest_primary === $old_country && $replacement_primary !== NULL) {
          $database->update("taxonomy_term__field_primary_country")
            ->fields(["field_primary_country_target_id" => $replacement_primary])
            ->condition("entity_id", $tid)
            ->condition("langcode", $langcode)
            ->condition("deleted", 0)
            ->condition("field_primary_country_target_id", $old_country)
            ->execute();
          $database->update("taxonomy_term_revision__field_primary_country")
            ->fields(["field_primary_country_target_id" => $replacement_primary])
            ->condition("revision_id", (int) $current_meta["revision_id"])
            ->condition("langcode", $langcode)
            ->condition("deleted", 0)
            ->condition("field_primary_country_target_id", $old_country)
            ->execute();
          $results["term_primary_replaced"]++;
        }

        $term_current_done[$latest_key] = TRUE;
        $results["term_current_updated"]++;
      }
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
  }
  else {
    if ($update_current && !isset($term_current_done[$latest_key])) {
      $term_current_done[$latest_key] = TRUE;
      $results["term_current_updated"]++;
    }
  }
}

$affected_nids = array_map("intval", array_keys($results["affected_nids"]));
sort($affected_nids);
$results["affected_nid_count"] = count($affected_nids);
unset($results["affected_nids"]);

$affected_tids = array_map("intval", array_keys($results["affected_tids"]));
sort($affected_tids);
$results["affected_tid_count"] = count($affected_tids);
unset($results["affected_tids"]);

if ($save && !empty($affected_nids)) {
  $node_storage = \Drupal::entityTypeManager()->getStorage("node");
  $node_storage->resetCache($affected_nids);
  Cache::invalidateTags(array_map(static function ($nid) {
    return "node:" . $nid;
  }, $affected_nids));

  if ($reindex && function_exists("reliefweb_api_handle_entity")) {
    echo "Reindexing " . count($affected_nids) . " nodes..." . PHP_EOL;
    foreach (array_chunk($affected_nids, $chunk_size) as $chunk) {
      foreach ($node_storage->loadMultiple($chunk) as $node) {
        reliefweb_api_handle_entity($node);
      }
    }
  }
}

if ($save && !empty($affected_tids)) {
  $term_storage = \Drupal::entityTypeManager()->getStorage("taxonomy_term");
  $term_storage->resetCache($affected_tids);
  Cache::invalidateTags(array_map(static function ($tid) {
    return "taxonomy_term:" . $tid;
  }, $affected_tids));

  if ($reindex && function_exists("reliefweb_api_handle_entity")) {
    echo "Reindexing " . count($affected_tids) . " taxonomy terms..." . PHP_EOL;
    foreach (array_chunk($affected_tids, $chunk_size) as $chunk) {
      foreach ($term_storage->loadMultiple($chunk) as $term) {
        reliefweb_api_handle_entity($term);
      }
    }
  }
}

print_r($results);
echo ($save ? "Done." : "Dry run only (save=FALSE).") . PHP_EOL;
