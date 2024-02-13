<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Entity;

/**
 * Defines a provider entity.
 *
 * @todo this is currently a real entity and simply implements the
 * ProviderInterface interface with data retrieved from the site settings.
 * This could become a real entity if needed.
 */
class Provider implements ProviderInterface {

  /**
   * Constructor.
   *
   * @param array $data
   *   The provider data from the settings.
   */
  public function __construct(protected array $data) {
  }

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return $this->data['id'] ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getUrlPattern(): string {
    return $this->data['url_pattern'] ?? '#^https://.+$#';
  }

  /**
   * {@inheritdoc}
   */
  public function getEmailsToNotify(): array {
    return $this->data['notify'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getAllowedSources(): array {
    return $this->data['sources'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getUserId(): int {
    return $this->data['uid'] ?? 2;
  }

  /**
   * {@inheritdoc}
   */
  public function validateKey(string $key): bool {
    return isset($this->data['key']) && $this->data['key'] === $key;
  }

}
