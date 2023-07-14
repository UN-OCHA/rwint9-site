<?php

/**
 * @file
 * Retagging script for RW-719.
 */

$skip_if_differences = FALSE;
$proceed = TRUE;
$save = TRUE;

$nids = \Drupal::database()->query("
  SELECT DISTINCT n.nid
  FROM {node_field_data} AS n
  INNER JOIN {node__field_content_format} AS cf
  ON cf.entity_id = n.nid
  WHERE n.type = :bundle
    AND n.moderation_status = :status
    AND (
      n.title REGEXP :title1 OR
      n.title REGEXP :title2 OR
      n.title REGEXP :title3
    )
    AND cf.field_content_format_target_id = :format
  ORDER BY nid ASC
", [
  ":bundle" => "report",
  ":status" => "published",
  ":title1" => "Situation[^a-z]+report([^a-z]|\$)",
  ":title2" => "Rapport[^a-z]+de[^a-z]+Situation([^a-z]|\$)",
  ":title3" => "Informe[^a-z]+de[^a-z]+Situaci[oó]n([^a-z]|\$)",
  ":format" => 8,
])->fetchCol();

$payload = [
  "preset" => "reliefweb",
  "query" => [
    "value" => "(title:\"Situation report\" OR title:\"Rapport de Situation\" OR title:\"Informe de Situación\") AND format:\"News and Press Release\"",
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
      $node->field_content_format->target_id = 10;
      $node->notifications_content_disable = TRUE;
      $node->setRevisionLogMessage("Automatic retagging of content format (Ref: RW-719).");
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
