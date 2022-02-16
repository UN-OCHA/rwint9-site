<?php

namespace Drupal\reliefweb_entities;

/**
 * Interface for bundle entity storages.
 *
 * Storages implementing this interface should extends their ::save() method
 * and call `$this->invokeHook('after_save', $entity);` to allow other modules
 * to interact with an entity after it is saved in the database because Drupal
 * doesn't provide such functionality...
 *
 * @see https://www.drupal.org/project/drupal/issues/2992426
 */
interface BundleEntityStorageInterface {

}
