<?php

$data = [];
$connection = \Drupal::database();

$date_start ??= gmdate("Y") . "-01-01";
$date_end ??= (gmdate("Y") + 1) . "-01-01";
$date_start = substr($date_start, 0, 10) . "T00:00:00+00:00";
$date_end = substr($date_end, 0, 10) . "T00:00:00+00:00";

$records = $connection->query("
  SELECT
    nr.nid AS nid,
    nr.vid AS vid,
    n.created AS created,
    IFNULL(GROUP_CONCAT(DISTINCT ur.roles_target_id ORDER BY ur.roles_target_id SEPARATOR :separator), :empty_string) AS roles,
    GROUP_CONCAT(DISTINCT nfcc.field_career_categories_target_id ORDER BY nfcc.field_career_categories_target_id SEPARATOR :separator) AS career_categories,
    GROUP_CONCAT(DISTINCT nft.field_theme_target_id ORDER BY nft.field_theme_target_id SEPARATOR :separator) AS themes,
    SUM(IF(njts.reliefweb_job_tagger_status_value IS NOT NULL OR njts.reliefweb_job_tagger_status_value = :empty_string, 1, 0)) AS ai_tagged
  FROM node_revision AS nr
  INNER JOIN node_field_data AS n
    ON n.nid = nr.nid
  LEFT JOIN user__roles AS urn
    ON urn.entity_id = n.uid
  LEFT JOIN node_revision__reliefweb_job_tagger_status AS njts
    ON njts.entity_id = n.nid
    AND njts.revision_id = nr.vid
    AND njts.reliefweb_job_tagger_status_value = :job_tagger_status
  LEFT JOIN user__roles AS ur
    ON ur.entity_id = nr.revision_uid
  LEFT JOIN node_revision__field_career_categories AS nfcc
    ON nfcc.entity_id = nr.nid
    AND nfcc.revision_id = nr.vid
  LEFT JOIN node_revision__field_theme AS nft
    ON nft.entity_id = nr.nid
    AND nft.revision_id = nr.vid
  WHERE n.type = :type
    AND FROM_UNIXTIME(n.created) BETWEEN :date_start AND :date_end
    AND urn.roles_target_id IS NULL
  GROUP BY nr.vid
  ORDER BY nr.vid
", [
  ":type" => "job",
  ":separator" => ",",
  ":empty_string" => "",
  ":job_tagger_status" => "processed",
  ":date_start" => $date_start,
  ":date_end" => $date_end,
])->fetchAll(\PDO::FETCH_ASSOC);

if (empty($records)) {
  return $data;
}

$jobs = [];
$changed_career_categories = [];
$changed_themes = [];
$jobs_tagged_by_ai = [];

// Group the records into the categories we want to report.
foreach ($records as $record) {
  $nid = $record["nid"];

  if (!empty($record["ai_tagged"])) {
    $jobs_tagged_by_ai[$nid] = TRUE;
  }

  if (isset($jobs[$nid])) {
    $previous = $jobs[$nid];

    if (strpos($record["roles"], "editor") !== FALSE) {
      if ($record["career_categories"] !== $previous["career_categories"]) {
        $changed_career_categories[$nid] = TRUE;
      }
      if ($record["themes"] !== $previous["themes"]) {
        $changed_themes[$nid] = TRUE;
      }
    }
  }

  $jobs[$nid] = $record;
}

$jobs_changed_by_editors = [];
$jobs_tagged_by_ai_changed_by_editors = [];
$jobs_tagged_by_ai_career_category_changed_by_editors = [];
$jobs_tagged_by_ai_theme_changed_by_editors = [];

foreach ($changed_career_categories as $nid => $changed) {
  $jobs_changed_by_editors[$nid] = $changed;

  if (isset($jobs_tagged_by_ai[$nid])) {
    $jobs_tagged_by_ai_changed_by_editors[$nid] = $changed;
    $jobs_tagged_by_ai_career_category_changed_by_editors[$nid] = $changed;
  }
}

foreach ($changed_themes as $nid => $changed) {
  $jobs_changed_by_editors[$nid] = $changed;

  if (isset($jobs_tagged_by_ai[$nid])) {
    $jobs_tagged_by_ai_changed_by_editors[$nid] = $changed;
    $jobs_tagged_by_ai_theme_changed_by_editors[$nid] = $changed;
  }
}

$ordered_jobs = [];
foreach ($jobs as $nid => $record) {
  $ordered_jobs[$nid] = $record["created"];
}
asort($ordered_jobs);

$timezone = new DateTimeZone("UTC");

  $data = [];
  foreach ($ordered_jobs as $nid => $timestamp) {
    $date = new DateTime("@" . $timestamp);
    // Move to the coming Sunday if not already a Sunday.
    if ($date->format("w") != 0) {
      $date->modify("next Sunday");
    }
    $key = $date->format("Y-m-d");

    if (!isset($data[$key])) {
      $data[$key] = [
        "date" => $key,
        "jobs_created" => 0,
        "jobs_changed_by_editors" => 0,
        "jobs_career_category_changed_by_editors" => 0,
        "jobs_career_theme_by_editors" => 0,
        "jobs_tagged_by_ai" => 0,
        "jobs_tagged_by_ai_changed_by_editors" => 0,
        "jobs_tagged_by_ai_career_category_changed_by_editors" => 0,
        "jobs_tagged_by_ai_theme_changed_by_editors" => 0,
      ];
    }

    $data[$key]["jobs_created"] += 1;
    $data[$key]["jobs_changed_by_editors"] += isset($jobs_changed_by_editors[$nid]) ? 1 : 0;
    $data[$key]["jobs_career_category_changed_by_editors"] += isset($changed_career_categories[$nid]) ? 1 : 0;
    $data[$key]["jobs_career_theme_by_editors"] += isset($changed_themes[$nid]) ? 1 : 0;
    $data[$key]["jobs_tagged_by_ai"] += isset($jobs_tagged_by_ai[$nid]) ? 1 : 0;
    $data[$key]["jobs_tagged_by_ai_changed_by_editors"] += isset($jobs_tagged_by_ai_changed_by_editors[$nid]) ? 1 : 0;
    $data[$key]["jobs_tagged_by_ai_career_category_changed_by_editors"] += isset($jobs_tagged_by_ai_career_category_changed_by_editors[$nid]) ? 1 : 0;
    $data[$key]["jobs_tagged_by_ai_theme_changed_by_editors"] += isset($jobs_tagged_by_ai_theme_changed_by_editors[$nid]) ? 1 : 0;
  }

$headers = [
  "date",
  "jobs_created",
  "jobs_changed_by_editors",
  "jobs_career_category_changed_by_editors",
  "jobs_career_theme_by_editors",
  "jobs_tagged_by_ai",
  "jobs_tagged_by_ai_changed_by_editors",
  "jobs_tagged_by_ai_career_category_changed_by_editors",
  "jobs_tagged_by_ai_theme_changed_by_editors",
];

$output = fopen("php://output", "w");

if ($output !== FALSE) {
  fputcsv($output, $headers);
  foreach ($data as $row) {
    fputcsv($output, $row);
  }
  fclose($output);
}
