<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Services;

use Drupal\Core\Site\Settings;
use Drupal\reliefweb_post_api\Entity\Provider;
use Drupal\reliefweb_post_api\Entity\ProviderInterface;

/**
 * Post API provider manager.
 */
class ProviderManager implements ProviderManagerInterface {

  /**
   * List of providers.
   *
   * @var \Drupal\reliefweb_post_api\Entity\ProviderInterface[]
   */
  protected array $providers = [];

  /**
   * {@inheritdoc}
   */
  public function getProvider(string $id): ?ProviderInterface {
    if ($id === '') {
      return NULL;
    }

    if (!array_key_exists($id, $this->providers)) {
      $providers = Settings::get('reliefweb_post_api.providers', []);
      if (!empty($providers[$id])) {
        $this->providers[$id] = new Provider($providers[$id] + ['id' => $id]);
      }
      else {
        $this->providers[$id] = NULL;
      }
    }

    return $this->providers[$id];
  }

}
