<?php

/**
 * @file
 * Retagging script for RW-1179.
 */

$proceed = FALSE;
$save = FALSE;

$nids = \Drupal::database()->query("
  SELECT DISTINCT g.id
  FROM {guideline} AS g
  LEFT JOIN {guideline__field_role} AS fr
  ON fr.entity_id = g.id
  WHERE g.type = :bundle
  AND fr.field_role_target_id IS NULL
  ORDER BY g.id ASC
", [
  ":bundle" => "guideline_list",
])->fetchCol();

$total = count($nids);

echo "Found " . $total . " guidelines to update" . PHP_EOL;

if (!empty($proceed)) {
  $storage = \Drupal::entityTypeManager()->getStorage("guideline");
  $chunk_size = 100;
  $now = time();
  $progress = 1;

  foreach (array_chunk($nids, $chunk_size) as $chunk) {
    foreach ($storage->loadMultiple($chunk) as $entity) {
      $entity->field_role->target_id = 'editor';
      $entity->setRevisionLogMessage("Automatic addition of editor role (Ref: RW-1179).");
      $entity->setRevisionUserId(2);
      $entity->setRevisionCreationTime($now);
      $entity->setNewRevision(TRUE);
      if ($save) {
        $entity->save();
      }

      echo "Progress: $progress / $total..." . PHP_EOL;
      $progress++;
    }
  }
}
