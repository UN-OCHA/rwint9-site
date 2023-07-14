<?php

/**
 * @file
 * Retagging script for RW-720.
 */

$skip_if_differences = FALSE;
$proceed = TRUE;
$save = TRUE;

$nids = \Drupal::database()->query("
  SELECT DISTINCT n.nid
  FROM {node_field_data} AS n
  INNER JOIN {node__field_source} AS cs
  ON cs.entity_id = n.nid
  INNER JOIN {node__field_content_format} AS cf
  ON cf.entity_id = n.nid
  INNER JOIN {node__field_theme} AS ct
  ON ct.entity_id = n.nid
  WHERE n.type = :bundle
    AND n.moderation_status = :status
    AND cs.field_source_target_id = :source
    AND cf.field_content_format_target_id = :format
    AND ct.field_theme_target_id IN (:themes[])
  ORDER BY nid ASC
", [
  ":bundle" => "report",
  ":status" => "published",
  ":source" => 1242,
  ":format" => 4,
  ":themes[]" => [4589, 4590],
])->fetchCol();

$payload = [
  "preset" => "reliefweb",
  "filter" => [
    "conditions" => [
      [
        "field" => "source.id",
        "value" => 1242,
      ],
      [
        "field" => "format.id",
        "value" => 4,
      ],
      [
        "field" => "theme.id",
        "value" => [4589, 4590],
        "operator" => "OR",
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
      $themes = [];
      foreach ($node->field_theme as $item) {
        if (!in_array($item->target_id, [4589, 4590])) {
          $themes[] = $item->getValue();
        }
      }
      $node->field_theme->setValue($themes);
      $node->notifications_content_disable = TRUE;
      $node->setRevisionLogMessage("Automatic retagging of theme (Ref: RW-720).");
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
