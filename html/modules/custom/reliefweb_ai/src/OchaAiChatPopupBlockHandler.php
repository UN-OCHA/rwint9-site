<?php

declare(strict_types=1);

namespace Drupal\reliefweb_ai;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Handle modification to the OCHA AI chat popup block.
 */
class OchaAiChatPopupBlockHandler implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['alterBuild'];
  }

  /**
   * Alter the block build.
   *
   * @param array $build
   *   Block build.
   *
   * @return array
   *   Modified block build.
   */
  public static function alterBuild(array $build): array {
    unset($build['content']['#cache']['max-age']);
    $build['content']['#cache']['contexts'][] = 'user.roles';
    $build['content']['#cache']['contexts'][] = 'url.path';
    return $build;
  }

}
