<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Entity;

/**
 * Interface for a POST API provider.
 */
interface ProviderInterface {

  /**
   * Get the provider ID.
   *
   * @return string
   *   ID.
   */
  public function id(): string;

  /**
   * Get the URL pattern for the provider.
   *
   * @return string
   *   A regex pattern to match URLs against.
   */
  public function getUrlPattern(): string;

  /**
   * Get the list of sources the provider is allowed to post for.
   *
   * @return array
   *   List of source IDs the provider is allowed to post for.
   */
  public function getAllowedSources(): array;

  /**
   * Get the email addresses to notify when publishing a document.
   *
   * @return array
   *   List of email addresses to notify when publishing a document.
   */
  public function getEmailsToNotify(): array;

  /**
   * Get the user ID to use for the created entities.
   *
   * @return int
   *   User ID. Defaults to the system user.
   */
  public function getUserId(): int;

  /**
   * Check if a provider with the given ID exits and its API key is valid.
   *
   * @param string $key
   *   Provider API key.
   *
   * @return bool
   *   TRUE if the provider exists and the API key belongs to it.
   */
  public function validateKey(string $key): bool;

}
