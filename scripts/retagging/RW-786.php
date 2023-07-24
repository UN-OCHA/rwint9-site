<?php

/**
 * @file
 * Retagging script for RW-786.
 */

$proceed = TRUE;
$save = TRUE;

$nids = \Drupal::database()->query("
  SELECT DISTINCT n.nid
  FROM {node_field_data} AS n
  INNER JOIN {node__field_origin_notes} AS fs
  ON fs.entity_id = n.nid
  WHERE fs.field_origin_notes_value REGEXP :origin
  ORDER BY nid ASC
", [
  ":origin" => "^(https?://)?(www.)?unocha.org/",
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
      $node->field_origin_notes = NULL;

      $node->notifications_content_disable = TRUE;
      $node->setRevisionLogMessage("Automatic removal of origin URL (Ref: RW-786).");
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
