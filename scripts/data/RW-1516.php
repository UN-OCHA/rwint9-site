<?php

/**
 * @file
 * RW-1516.
 *
 * Measure how often automated AI classification of reports was later corrected,
 * per field, by comparing the AI baseline revision to the latest revision.
 *
 * For each completed classification:
 *   1. Baseline = ocha_content_classification_progress.entity_revision_id
 *   2. Compare = current default field values (latest revision)
 *   3. Only fields listed in updated_fields (fields the AI actually set)
 *   4. Wrong  = later edits removed the AI value(s)
 *   5. Amend  = later edits added value(s) (multi-value fields only)
 *
 * Denominator per field is ai_set (classified reports where the AI set that
 * field). Single-value fields (content format, primary country, title) report
 * N/A for amend because a replacement is not incomplete tagging.
 * field_language is multi-value on the entity (cardinality unlimited) even
 * though the AI only sets one language, so amend applies there.
 *
 * Also reports flagging coverage: among documents where any AI-set field
 * changed vs the latest revision, how often a later revision log contains
 * #wrong or #amended.
 *
 * Output includes the timeframe (earliest to latest classification completion
 * date) for the reports included in the run.
 *
 * Usage (drush php:script, supports options):
 *   drush php:script scripts/data/RW-1516.php
 *   drush php:script scripts/data/RW-1516.php -- --limit=100
 *
 * Usage (drush php-eval, wrap the whole file content in single quotes):
 *   drush php-eval "$(cat scripts/data/RW-1516.php)"
 */

use Drupal\Core\Database\Statement\FetchAs;

$limit = 0;

if (isset($extra) && is_array($extra)) {
  foreach ($extra as $arg) {
    if (str_starts_with($arg, "--limit=")) {
      $limit = (int) substr($arg, 8);
    }
  }
}

// Classifiable taxonomy fields: machine name => column with target term ID.
$taxonomy_fields = [
  "field_content_format" => "field_content_format_target_id",
  "field_primary_country" => "field_primary_country_target_id",
  "field_country" => "field_country_target_id",
  "field_language" => "field_language_target_id",
  "field_theme" => "field_theme_target_id",
];

// Single-value fields: amendments (additive tagging) do not apply; show N/A.
// field_language is unlimited on the entity (editors may add languages) even
// though the AI workflow only sets one, so it is not listed here.
$single_value_fields = [
  "field_content_format" => TRUE,
  "field_primary_country" => TRUE,
  "title" => TRUE,
];

$text_fields = ["title"];
$all_fields = array_merge(array_keys($taxonomy_fields), $text_fields);

$database = \Drupal::database();

$rw1516_pct = static function (int $count, int $denominator): float {
  return $denominator > 0 ? ($count / $denominator * 100) : 0.0;
};

// 1. All completed report classifications.
$classified_rows = $database->query("
  SELECT
    entity_id AS nid,
    entity_revision_id AS baseline_vid,
    updated_fields AS updated_fields,
    changed AS classified_at
  FROM {ocha_content_classification_progress}
  WHERE entity_type_id = :entity_type_id
    AND entity_bundle = :entity_bundle
    AND status = :status
  ORDER BY entity_id ASC
", [
  ":entity_type_id" => "node",
  ":entity_bundle" => "report",
  ":status" => "completed",
])?->fetchAll(FetchAs::Associative) ?? [];

$total_classified = count($classified_rows);

if ($total_classified === 0) {
  echo "No automatically classified reports found." . PHP_EOL;
  return;
}

if ($limit > 0 && $total_classified > $limit) {
  $classified_rows = array_slice($classified_rows, 0, $limit);
  $total_classified = count($classified_rows);
  echo "Limiting to " . $total_classified . " reports (--limit=" . $limit . ")." . PHP_EOL;
}

$reports = [];
$nids = [];
$baseline_vids = [];
$ai_set_counts = [];
$period_start = NULL;
$period_end = NULL;
foreach ($all_fields as $field_name) {
  $ai_set_counts[$field_name] = 0;
}

foreach ($classified_rows as $row) {
  $nid = (int) $row["nid"];
  $baseline_vid = (int) $row["baseline_vid"];
  $classified_at = (int) $row["classified_at"];
  if ($period_start === NULL || $classified_at < $period_start) {
    $period_start = $classified_at;
  }
  if ($period_end === NULL || $classified_at > $period_end) {
    $period_end = $classified_at;
  }
  $decoded = json_decode((string) $row["updated_fields"], TRUE);
  $updated_fields = is_array($decoded) ? $decoded : [];

  $reports[$nid] = [
    "nid" => $nid,
    "baseline_vid" => $baseline_vid,
    "updated_fields" => $updated_fields,
  ];
  $nids[$nid] = $nid;
  $baseline_vids[$baseline_vid] = $baseline_vid;

  foreach ($updated_fields as $field_name) {
    if (isset($ai_set_counts[$field_name])) {
      $ai_set_counts[$field_name]++;
    }
  }
}

$nids = array_values($nids);
$baseline_vids = array_values($baseline_vids);

// 2. Current (latest) title from node_field_data.
$current_titles = [];
$current_rows = $database->query("
  SELECT nid, title
  FROM {node_field_data}
  WHERE nid IN (:nids[])
    AND default_langcode = 1
", [
  ":nids[]" => $nids,
])?->fetchAll(FetchAs::Associative) ?? [];
foreach ($current_rows as $row) {
  $current_titles[(int) $row["nid"]] = (string) $row["title"];
}

// 3. Baseline taxonomy values (AI revision) and current taxonomy values.
$baseline_taxonomy = [];
$current_taxonomy = [];
foreach ($taxonomy_fields as $field_name => $column) {
  $baseline_taxonomy[$field_name] = [];
  $current_taxonomy[$field_name] = [];

  $baseline_query = "
    SELECT revision_id, $column AS target_id
    FROM {node_revision__$field_name}
    WHERE revision_id IN (:vids[])
      AND deleted = 0
  ";
  $baseline_field_rows = $database->query($baseline_query, [
    ":vids[]" => $baseline_vids,
  ])?->fetchAll(FetchAs::Associative) ?? [];
  foreach ($baseline_field_rows as $field_row) {
    $revision_id = (int) $field_row["revision_id"];
    $target_id = (int) $field_row["target_id"];
    $baseline_taxonomy[$field_name][$revision_id][$target_id] = $target_id;
  }

  $current_query = "
    SELECT entity_id, $column AS target_id
    FROM {node__$field_name}
    WHERE entity_id IN (:nids[])
      AND deleted = 0
  ";
  $current_field_rows = $database->query($current_query, [
    ":nids[]" => $nids,
  ])?->fetchAll(FetchAs::Associative) ?? [];
  foreach ($current_field_rows as $field_row) {
    $entity_id = (int) $field_row["entity_id"];
    $target_id = (int) $field_row["target_id"];
    $current_taxonomy[$field_name][$entity_id][$target_id] = $target_id;
  }
}

// Baseline titles from the AI classification revision.
$baseline_titles = [];
$title_rows = $database->query("
  SELECT vid, title
  FROM {node_field_revision}
  WHERE vid IN (:vids[])
    AND default_langcode = 1
", [
  ":vids[]" => $baseline_vids,
])?->fetchAll(FetchAs::Associative) ?? [];
foreach ($title_rows as $title_row) {
  $baseline_titles[(int) $title_row["vid"]] = (string) $title_row["title"];
}

// 4. Documents with a #wrong / #amended revision after the AI baseline.
$flagged_nids = [];
$flag_rows = $database->query("
  SELECT DISTINCT
    ocp.entity_id AS nid
  FROM {ocha_content_classification_progress} AS ocp
  INNER JOIN {node_revision} AS nr
    ON nr.nid = ocp.entity_id
    AND nr.vid > ocp.entity_revision_id
    AND (nr.revision_log LIKE :wrong OR nr.revision_log LIKE :amended)
  WHERE ocp.entity_type_id = :entity_type_id
    AND ocp.entity_bundle = :entity_bundle
    AND ocp.status = :status
    AND ocp.entity_id IN (:nids[])
", [
  ":wrong" => "%#wrong%",
  ":amended" => "%#amended%",
  ":entity_type_id" => "node",
  ":entity_bundle" => "report",
  ":status" => "completed",
  ":nids[]" => $nids,
])?->fetchAll(FetchAs::Associative) ?? [];
foreach ($flag_rows as $flag_row) {
  $flagged_nids[(int) $flag_row["nid"]] = TRUE;
}

// 5. Diff AI baseline vs latest per AI-set field.
$stats = [];
foreach ($all_fields as $field_name) {
  $stats[$field_name] = [
    "wrong" => 0,
    "amend" => 0,
  ];
}

$changed_docs = 0;
$flagged_changed_docs = 0;

foreach ($reports as $nid => $report) {
  $baseline_vid = $report["baseline_vid"];
  $updated_fields = array_flip($report["updated_fields"]);
  $doc_changed = FALSE;

  foreach ($taxonomy_fields as $field_name => $column) {
    if (!isset($updated_fields[$field_name])) {
      continue;
    }

    $before = array_values($baseline_taxonomy[$field_name][$baseline_vid] ?? []);
    $after = array_values($current_taxonomy[$field_name][$nid] ?? []);
    $ai_removed = array_values(array_diff($before, $after));
    $editor_added = array_values(array_diff($after, $before));
    $is_single_value = isset($single_value_fields[$field_name]);

    if ($is_single_value) {
      // Replacement or removal of the AI value counts as wrong only.
      if (!empty($ai_removed) || (!empty($before) && !empty($editor_added))) {
        $stats[$field_name]["wrong"]++;
        $doc_changed = TRUE;
      }
      elseif (empty($before) && !empty($editor_added)) {
        // AI claimed to set the field but baseline was empty; editor filled it.
        // Still a post-AI change; count as wrong for single-value (N/A amend).
        $stats[$field_name]["wrong"]++;
        $doc_changed = TRUE;
      }
    }
    else {
      if (!empty($ai_removed)) {
        $stats[$field_name]["wrong"]++;
        $doc_changed = TRUE;
      }
      if (!empty($editor_added)) {
        $stats[$field_name]["amend"]++;
        $doc_changed = TRUE;
      }
    }
  }

  foreach ($text_fields as $field_name) {
    if (!isset($updated_fields[$field_name])) {
      continue;
    }

    $before = $baseline_titles[$baseline_vid] ?? "";
    $after = $current_titles[$nid] ?? "";

    // Title is single-value: any change to the AI title counts as wrong.
    if ($before !== $after && $before !== "") {
      $stats[$field_name]["wrong"]++;
      $doc_changed = TRUE;
    }
    elseif ($before === "" && $after !== "") {
      $stats[$field_name]["wrong"]++;
      $doc_changed = TRUE;
    }
  }

  if ($doc_changed) {
    $changed_docs++;
    if (isset($flagged_nids[$nid])) {
      $flagged_changed_docs++;
    }
  }
}

// 6. Print results.
$changed_pct = round($rw1516_pct($changed_docs, $total_classified), 1);
$flagged_pct = round($rw1516_pct($flagged_changed_docs, $changed_docs), 1);

echo PHP_EOL;
echo "Automatically classified reports: " . $total_classified . PHP_EOL;
if ($period_start !== NULL && $period_end !== NULL) {
  echo "Timeframe: " . gmdate("Y-m-d", $period_start) . " to " . gmdate("Y-m-d", $period_end);
  echo " (classification completion date, UTC)" . PHP_EOL;
}
echo "Reports with any AI-set field changed after classification: " . $changed_docs . " (" . $changed_pct . "%)" . PHP_EOL;
echo PHP_EOL;
echo "Per field (among reports where the AI set that field):" . PHP_EOL;
echo "- AI set: count of such reports" . PHP_EOL;
echo "- Wrong: later edits removed the AI value(s) (often indicates incorrect AI tagging)" . PHP_EOL;
echo "- Amend: later edits added value(s) on top of those set by the AI (often indicates incomplete AI tagging; multi-value fields only)" . PHP_EOL;
echo PHP_EOL;

echo str_repeat("-", 72) . PHP_EOL;
echo sprintf(
  "%-24s %8s %8s %8s %8s %8s\n",
  "field",
  "ai_set",
  "wrong",
  "%wrong",
  "amend",
  "%amend"
);
echo str_repeat("-", 72) . PHP_EOL;

foreach ($all_fields as $field_name) {
  $ai_set_count = $ai_set_counts[$field_name];
  $wrong = $stats[$field_name]["wrong"];
  $amend = $stats[$field_name]["amend"];
  $is_single_value = isset($single_value_fields[$field_name]);

  if ($is_single_value) {
    echo sprintf(
      "%-24s %8d %8d %7.1f%% %8s %8s\n",
      $field_name,
      $ai_set_count,
      $wrong,
      $rw1516_pct($wrong, $ai_set_count),
      "N/A",
      "N/A"
    );
  }
  else {
    echo sprintf(
      "%-24s %8d %8d %7.1f%% %8d %7.1f%%\n",
      $field_name,
      $ai_set_count,
      $wrong,
      $rw1516_pct($wrong, $ai_set_count),
      $amend,
      $rw1516_pct($amend, $ai_set_count)
    );
  }
}
echo str_repeat("-", 72) . PHP_EOL;
echo "Note: for the title, wrong means the title set by the AI was altered rather than only removed." . PHP_EOL;

echo PHP_EOL;
echo "Editor flagging coverage:" . PHP_EOL;
echo "- Changed reports: " . $changed_docs . PHP_EOL;
echo "- Flagged with #wrong/#amended: " . $flagged_changed_docs;
if ($changed_docs > 0) {
  echo " (" . $flagged_pct . "%)";
}
echo PHP_EOL;
