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
    $original_items = [];

    // Extract the original items so that we can process replaced files,
    // create revisions for old ones etc.
    $original = $this->getEntity()->original;
    if (isset($original)) {
      foreach ($original->get($this->definition->getName()) as $item) {
        if (!$item->isEmpty()) {
          $original_items[$item->get('uuid')->getValue()] = $item;
        }
      }
    }

    // Add the original items to the replaced ones.
    foreach ($this->list as $item) {
      $uuid = $item->get('uuid')->getValue();
      $revision_id = $item->get('revision_id')->getValue();
      // Add the original item, if it's been replaced.
      if (isset($original_items[$uuid]) && empty($revision_id)) {
        $item->_original_item = $original_items[$uuid];
      }

      // Remove items that exists in both the current list and old one so
      // that only the items that need to be deleted are left.
      unset($original_items[$uuid]);
    }

    // Mark all "deleted" items as private.
    foreach ($original_items as $item) {
      $item->updateFileStatus(FALSE);
    }

    // Call "preSave" on each item.
    $this->delegateMethod('preSave');
  }

}
