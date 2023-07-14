<?php

/**
 * @file
 * Retagging script for RW-766.
 */

$proceed = TRUE;
$save = TRUE;

$sources = [
  4062 => 1591,
  2528 => 1591,
  45499 => 1591,
];

$nids = \Drupal::database()->query("
  SELECT DISTINCT n.nid
  FROM {node_field_data} AS n
  INNER JOIN {node__field_source} AS fs
  ON fs.entity_id = n.nid
  WHERE fs.field_source_target_id IN (:sources[])
  ORDER BY nid ASC
", [
  ":sources[]" => array_keys($sources),
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
      foreach ($node->field_source as $item) {
        if (isset($sources[$item->target_id])) {
          $results[$node->bundle()][$item->target_id] = ($results[$node->bundle()][$item->target_id] ?? 0) + 1;
          $item->target_id = $sources[$item->target_id];
        }
      }

      $node->notifications_content_disable = TRUE;
      $node->setRevisionLogMessage("Automatic retagging of source (Ref: RW-767).");
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
