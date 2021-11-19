<?php

namespace Drupal\reliefweb_docstore\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemList;

/**
 * List of ReliefWebFile field items.
 */
class ReliefWebFileList extends FieldItemList {

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    // Filter out empty items.
    $this->filterEmptyItems();

    // Extract the original items so that we can process replaced files,
    // create revisions for old ones etc.
    $original_items = [];
    $original = $this->getEntity()->original;
    if (isset($original)) {
      foreach ($original->get($this->definition->getName()) as $item) {
        if (!$item->isEmpty()) {
          $original_items[$item->getUuid()] = $item;
        }
      }
    }

    // Add the original items to the replaced ones.
    foreach ($this->list as $item) {
      $uuid = $item->getUuid();

      // Call preSave on the item with the original item so we can compare what
      // changed.
      $item->preSave($original_items[$uuid] ?? NULL);

      // Remove items that exists in both the current list and old one so
      // that only the items that need to be deleted are left.
      unset($original_items[$uuid]);
    }

    // Mark all "deleted" items as private.
    foreach ($original_items as $item) {
      $item->updateFileStatus(TRUE);
    }
  }

}
