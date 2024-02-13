<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Services;

use Drupal\reliefweb_post_api\Entity\ProviderInterface;

/**
 * Post API provider manager interface.
 */
interface ProviderManagerInterface {

  /**
   * Get a provider.
   *
   * @param string $id
   *   Provider ID.
   *
   * @return \Drupal\reliefweb_post_api\Entity\ProviderInterface|null
   *   The provider.
   */
  public function getProvider(string $id): ?ProviderInterface;

}
