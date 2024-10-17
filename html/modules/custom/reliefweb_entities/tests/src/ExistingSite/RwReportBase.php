<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_entities\ExistingSite;

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Add a report using browser.
 */
class RwReportBase extends ExistingSiteBase {

  /**
   * Create terms.
   */
  protected function createTermIfNeeded($vocabulary, $id, $title, array $extra = []) : Term {
    if ($term = Term::load($id)) {
      return $term;
    }

    $term = Term::create([
      'vid' => $vocabulary,
      'tid' => $id,
      'name' => $title,
    ] + $extra);
    $term->save();

    return $term;
  }

  /**
   * Create user if needed.
   */
  protected function createUserIfNeeded($id, $name, array $extra = []) : User {
    if ($user = User::load($id)) {
      return $user;
    }

    $user = User::create([
      'uid' => $id,
      'name' => $name,
      'mail' => $this->randomMachineName(32) . '@localhost.localdomain',
      'status' => 1,
    ] + $extra);
    $user->save();

    return $user;
  }

}
