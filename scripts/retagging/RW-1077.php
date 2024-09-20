<?php

/**
 * @file
 * Retagging script for RW-1077.
 */

$proceed = TRUE;
$save = TRUE;

$content_format = 38974;

$nids = \Drupal::database()->query("
  SELECT DISTINCT n.nid
  FROM {node_field_data} AS n
  INNER JOIN {node__field_content_format} AS fcf
  ON fcf.entity_id = n.nid
  WHERE n.type = :bundle
  AND fcf.field_content_format_target_id = :content_format
  ORDER BY nid ASC
", [
  ":bundle" => "report",
  ":content_format" => $content_format,
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
      $node->setModerationStatus('archive');
      $node->notifications_content_disable = TRUE;
      $node->setRevisionLogMessage("Automatic archiving of interactive content (Ref: RW-1077).");
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
