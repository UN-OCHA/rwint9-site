<?php

/**
 * @file
 * Retagging script for RW-1251.
 */

$proceed = TRUE;
$save = TRUE;

$source = 582;
$new_source = 52378;

$payload = [
  "preset" => "reliefweb",
  "query" => [
    "value" => "(\"KSRelief\") OR (\"KS Relief\") OR (\"King Salman\") OR ((\"KS\" OR \"King Shalman\") AND \"Development Fund\")",
  ],
  "filter" => [
    "conditions" => [
      [
        "field" => "source.id",
        "value" => $source,
      ],
      [
        "field" => "source.id",
        "value" => $new_source,
        "negate" => TRUE,
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

$nids = [];
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
    $nids[] = intval($item["id"], 10);
  }

  $offset += 1000;
}

$total = count($nids);

echo "Found " . $total . " nodes to update" . PHP_EOL;

if (!empty($proceed)) {
  $storage = \Drupal::entityTypeManager()->getStorage("node");
  $chunk_size = 100;
  $now = time();
  $progress = 1;

  foreach (array_chunk($nids, $chunk_size) as $chunk) {
    foreach ($storage->loadMultiple($chunk) as $node) {
      $node->field_source->appendItem(['target_id' => $new_source]);

      $node->notifications_content_disable = TRUE;
      $node->setRevisionLogMessage("Automatic addition of source (Ref: RW-1251).");
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
