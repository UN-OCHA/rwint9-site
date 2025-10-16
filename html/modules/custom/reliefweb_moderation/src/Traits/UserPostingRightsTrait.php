<?php

declare(strict_types=1);

namespace Drupal\reliefweb_moderation\Traits;

use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;

/**
 * Trait for accessing the user posting rights manager service.
 */
trait UserPostingRightsTrait {

  /**
   * The user posting rights manager service.
   *
   * @var \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface
   */
  protected $userPostingRightsManager;

  /**
   * Get the user posting rights manager service.
   *
   * @return \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface
   *   The user posting rights manager service.
   */
  protected function getUserPostingRightsManager(): UserPostingRightsManagerInterface {
    if (!isset($this->userPostingRightsManager)) {
      $this->userPostingRightsManager = \Drupal::service('reliefweb_moderation.user_posting_rights');
    }
    return $this->userPostingRightsManager;
  }

}
