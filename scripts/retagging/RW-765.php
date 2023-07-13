<?php

/**
 * @file
 * Retagging script for RW-765.
 */

$skip_if_differences = FALSE;
$proceed = TRUE;
$save = TRUE;

$nids = \Drupal::database()->query("
  SELECT DISTINCT n.nid
  FROM {node_field_data} AS n
  INNER JOIN {node__field_source} AS fs
  ON fs.entity_id = n.nid
  LEFT JOIN {node__field_ocha_product} AS fop
  ON fop.entity_id = n.nid
  WHERE n.type = :bundle
    AND fs.field_source_target_id = 1503
    AND fop.field_ocha_product_target_id IS NULL
  ORDER BY nid ASC
", [
  ":bundle" => "report",
])->fetchCol();

$payload = [
  "preset" => "reliefweb",
  "query" => [
    "value" => "source.id:1503 AND NOT _exists_:ocha_product",
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

echo "Found " . count($nids) . " nodes to update" . PHP_EOL;

if (!empty($proceed)) {
  $storage = \Drupal::entityTypeManager()->getStorage("node");
  $chunk_size = 100;
  $now = time();
  $progress = 1;

  $formats = [
    3 => "Analysis",
    4 => "Appeal",
    5 => "Assessment",
    6 => "Evaluation and Lessons Learned",
    7 => "Manual and Guideline",
    8 => "News and Press Release",
    9 => "Other",
    10 => "Situation Report",
    11 => "UN Document",
    12 => "Map",
    12570 => "Infographic",
    38974 => "Interactive",
  ];

  $ocha_products = [
    "Situation Report" => 12345,
    "Humanitarian Bulletin" => 12346,
    "Humanitarian Dashboard" => 12347,
    "Humanitarian Snapshot" => 12348,
    "Press Release" => 12350,
    "Press Review" => 12351,
    "Statement/Speech" => 12352,
    "Other" => 12353,
    "Thematic Map" => 12354,
    "Reference Map" => 12355,
    "Infographic" => 12356,
    "Flash Update" => 14471,
    "Humanitarian Needs Overview" => 20471,
  ];

  $conditions = [
    "Situation Report" => [
      "Flash Update" => "Flash Update",
      "Situation Report" => "Situation Report",
      "Rapport de situation" => "Situation Report",
      "Informe de situación" => "Situation Report",
      "Humanitarian Bulletin" => "Humanitarian Bulletin",
      "Bulletin Humanitaire" => "Humanitarian Bulletin",
      "" => "Other",
    ],
    "News and Press Release" => [
      "Statement" => "Statement/Speech",
      "Briefing" => "Statement/Speech",
      "" => "Press Release",
    ],
    "Map" => [
      "Reference Map" => "Reference Map",
      "" => "Thematic Map",
    ],
    "Analysis" => [
      "Humanitarian Needs Overview" => "Humanitarian Needs Overview",
      "" => "Other",
    ],
    "Infographic" => [
      "" => "Infographic",
    ],
  ];

  $results = [];
  foreach (array_chunk($nids, $chunk_size) as $chunk) {
    foreach ($storage->loadMultiple($chunk) as $node) {
      $format_id = $node->field_content_format?->target_id;
      if (!isset($format_id)) {
        $results["No content format"] = ($results["No content format"] ?? 0) + 1;
        continue;
      }

      $ocha_product = "Other";
      $format = $formats[$format_id] ?? "Unknown format";

      if (isset($conditions[$format])) {
        $title = $node->label();

        foreach ($conditions[$format] as $key => $value) {
          if (empty($key) || mb_stripos($title, $key) !== FALSE) {
            $ocha_product = $value;
            break;
          }
        }
      }

      $results[$format][$ocha_product] = ($results[$format][$ocha_product] ?? 0) + 1;

      $node->field_ocha_product->target_id = $ocha_products[$ocha_product];
      $node->notifications_content_disable = TRUE;
      $node->setRevisionLogMessage("Automatic retagging of ocha product (Ref: RW-765).");
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

  print_r($results);
}
