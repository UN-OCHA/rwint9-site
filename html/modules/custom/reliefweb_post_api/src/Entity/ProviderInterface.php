<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Interface for a POST API provider.
 */
interface ProviderInterface extends ContentEntityInterface {

  /**
   * Get the URL pattern for the provider.
   *
   * @param string $type
   *   Type of URL pattern. One of 'document', 'file' or 'image'.
   *
   * @return string
   *   A regex pattern to match URLs against.
   */
  public function getUrlPattern(string $type = 'document'): string;

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
   * Get the default moderation status for the created/updated entities.
   *
   * @return string
   *   Moeration status. Defaults to 'draft'.
   */
  public function getDefaultResourceStatus(): string;

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
