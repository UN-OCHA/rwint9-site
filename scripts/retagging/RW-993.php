<?php

/**
 * @file
 * Retagging script for RW-993.
 */

$proceed = TRUE;
$save = TRUE;

$sources = [
  1417 => 52035,
];

$nids = \Drupal::database()->query("
  SELECT DISTINCT n.nid
  FROM {node_field_data} AS n
  INNER JOIN {node__field_source} AS fs1
  ON fs1.entity_id = n.nid
  INNER JOIN {node__field_source} AS fs2
  ON fs2.entity_id = n.nid
  WHERE n.type = :bundle
  AND fs1.field_source_target_id = :sources1
  AND fs2.field_source_target_id = :sources2
  ORDER BY nid ASC
", [
  ":bundle" => "report",
  ":sources1" => key($sources),
  ":sources2" => 1503,
])->fetchCol();

$total = count($nids);

echo "Found " . $total . " nodes to update" . PHP_EOL;

if (!empty($proceed)) {
  $storage = \Drupal::entityTypeManager()->getStorage("node");
  $chunk_size = 100;
  $now = time();
  $progress = 1;

  foreach (array_chunk($nids, $chunk_size) as $chunk) {
    foreach ($storage->loadMultiple($chunk) as $node) {
      foreach ($node->field_source as $item) {
        $item->target_id = $sources[$item->target_id] ?? $item->target_id;
      }

      $node->notifications_content_disable = TRUE;
      $node->setRevisionLogMessage("Automatic retagging of source (Ref: RW-993).");
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
