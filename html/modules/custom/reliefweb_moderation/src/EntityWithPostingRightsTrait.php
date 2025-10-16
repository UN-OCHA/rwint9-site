<?php

declare(strict_types=1);

namespace Drupal\reliefweb_moderation;

use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\reliefweb_moderation\Traits\UserPostingRightsTrait;

/**
 * Trait for entities with posting rights.
 */
trait EntityWithPostingRightsTrait {

  use UserPostingRightsTrait;

  /**
   * Update the status for the entity based on the user posting rights.
   */
  protected function updateModerationStatusFromPostingRights() {
    if (!($this instanceof EntityModeratedInterface) && !($this instanceof RevisionLogInterface)) {
      return;
    }

    // Get the current status and bundle.
    // In theory the revision user here, is the current user saving the entity.
    /** @var \Drupal\user\UserInterface|null $user */
    $user = $this->getRevisionUser();

    // Skip if there is no revision user. That should normally not happen with
    // new content but some old revisions may reference users that don't exist
    // anymore (which should not happen either but...).
    if (empty($user)) {
      return;
    }

    // Use the service to determine the new status and message.
    $this->getUserPostingRightsManager()->updateModerationStatusFromPostingRights(
      $this,
      $user,
      ['pending'],
    );
  }

}
