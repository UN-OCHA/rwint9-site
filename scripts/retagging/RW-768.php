<?php

/**
 * @file
 * Retagging script for RW-768.
 */

$skip_if_differences = FALSE;
$proceed = TRUE;
$save = TRUE;

$nids = \Drupal::database()->query("
  SELECT DISTINCT n.nid, n.title
  FROM {node_field_data} AS n
  INNER JOIN {node__field_source} AS fs
  ON fs.entity_id = n.nid
  INNER JOIN {node__field_content_format} AS fct
  ON fct.entity_id = n.nid
  WHERE n.type = :bundle
    AND fs.field_source_target_id = :source
    AND fct.field_content_format_target_id = :format
  ORDER BY nid ASC
", [
  ":bundle" => "report",
  ":source" => 529,
  ":format" => 10,
])->fetchAllKeyed();

$titles = [
  "Food Security Outlook",
  "Perspectives sur la sécurité alimentaire",
  "Perspectiva de Seguridad Alimentaria",
  "Key Message",
  "Mise à jour sur la sécurité alimentaire",
  "Food Security Alert",
  "Remote Monitoring Report",
  "Monitoreo Estacional",
  "Mise à jour du suivi à distance",
  "Seasonal Monitor",
];

$debug = array_flip($titles);
foreach ($nids as $nid => $node_title) {
  $skip = TRUE;
  foreach ($titles as $title) {
    if (mb_stripos($node_title, $title) !== FALSE) {
      $debug[$title] = ($debug[$title] ?? 0) + 1;
      $skip = FALSE;
      break;
    }
  }
  if ($skip) {
    unset($nids[$nid]);
  }
}
$nids = array_keys($nids);
print_r($debug);

$payload = [
  "preset" => "reliefweb",
  "query" => [
    "value" => "title:(\"" . implode("\" OR \"", $titles) . "\")",
  ],
  "filter" => [
    "conditions" => [
      [
        "field" => "source.id",
        "value" => 529,
      ],
      [
        "field" => "format.id",
        "value" => 10,
      ],
    ],
    "operator" => "AND",
  ],
  "fields" => [
    "exclude" => ["*"],
  ],
  "limit" => 1000,
  "sort" => [
    "id:desc",
  ],
];

$api_ids = [];
$offset = 0;
while (TRUE) {
  $data = \Drupal::service("reliefweb_api.client")
    ->request("reports", [
      "offset" => $offset,
    ] + $payload);

  if (empty($data["data"])) {
    break;
  }

  foreach ($data["data"] as $item) {
    $api_ids[] = intval($item["id"], 10);
  }

  $offset += 1000;
}

$total = count($nids);
$missing_from_db = array_values(array_diff($api_ids, $nids));
$missing_from_api = array_values(array_diff($nids, $api_ids));

if (!empty($missing_from_db) || !empty($missing_from_api)) {
  print_r([
    "missing from DB" => $missing_from_db,
    "missing from API" => $missing_from_api,
  ]);
  if ($skip_if_differences) {
    return;
  }
}

echo "Found " . $total . " nodes to update" . PHP_EOL;

if (!empty($proceed)) {
  $storage = \Drupal::entityTypeManager()->getStorage("node");
  $chunk_size = 100;
  $now = time();
  $progress = 1;

  foreach (array_chunk($nids, $chunk_size) as $chunk) {
    foreach ($storage->loadMultiple($chunk) as $node) {
      $node->field_content_format->target_id = 3;
      $node->notifications_content_disable = TRUE;
      $node->setRevisionLogMessage("Automatic retagging of content format (Ref: RW-768).");
      $node->setRevisionUserId(2);
      $node->setRevisionCreationTime($now);
      $node->setNewRevision(TRUE);
      if ($save) {
        $node->save();
      }

      echo "Progress: $progress / $total..." . PHP_EOL;
      $progress++;
    }
  }
}
