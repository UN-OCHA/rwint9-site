<?php

/**
 * @file
 * Retagging script for RW-1175.
 */

$proceed = TRUE;
$save = TRUE;

$terms = [
  12350 => "Press Release",
  12352 => "Statement/Speech",
  12353 => "Other",
];

$nids = \Drupal::database()->query("
  SELECT DISTINCT n.nid
  FROM {node_field_data} AS n
  INNER JOIN {node__field_ocha_product} AS fs
  ON fs.entity_id = n.nid
  WHERE fs.field_ocha_product_target_id IS NOT NULL
  AND fs.field_ocha_product_target_id NOT IN (:terms[])
  ORDER BY nid ASC
", [
  ":terms[]" => array_keys($terms),
])->fetchCol();

$total = count($nids);

echo "Found " . $total . " nodes to update" . PHP_EOL;

if (!empty($proceed)) {
  $storage = \Drupal::entityTypeManager()->getStorage("node");
  $chunk_size = 100;
  $now = time();
  $progress = 1;

  $results = [];
  foreach (array_chunk($nids, $chunk_size) as $chunk) {
    foreach ($storage->loadMultiple($chunk) as $node) {
      foreach ($node->field_ocha_product as $item) {
        if (!isset($terms[$item->target_id])) {
          $results[$node->bundle()][$item->target_id] = ($results[$node->bundle()][$item->target_id] ?? 0) + 1;
          $item->target_id = 12353;
        }
      }

      $node->notifications_content_disable = TRUE;
      $node->setRevisionLogMessage("Automatic retagging of OCHA product (Ref: RW-1175).");
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
