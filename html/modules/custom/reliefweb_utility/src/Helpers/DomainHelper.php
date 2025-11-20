<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\user\UserInterface;

/**
 * Helper for domain validation and normalization.
 */
class DomainHelper {

  /**
   * Normalize a domain.
   *
   * This method:
   * - Trims whitespace
   * - Converts to lowercase
   * - Removes leading '@' character by default.
   *
   * @param string $domain
   *   Domain to normalize.
   * @param bool $remove_at
   *   Whether to remove the leading '@' character. Defaults to TRUE.
   *
   * @return string
   *   Normalized domain.
   */
  public static function normalizeDomain(string $domain, bool $remove_at = TRUE): string {
    $domain = mb_strtolower(trim($domain));
    if ($remove_at) {
      $domain = ltrim($domain, '@');
    }
    return $domain;
  }

  /**
   * Validate a domain.
   *
   * @param string $domain
   *   Domain.
   * @param bool $check_tld
   *   Whether to check the TLD or not.
   *
   * @return bool
   *   TRUE if the domain is valid, FALSE otherwise.
   */
  public static function validateDomain(string $domain, bool $check_tld = TRUE): bool {
    if (empty($domain)) {
      return FALSE;
    }

    if ($check_tld && !str_contains($domain, '.')) {
      return FALSE;
    }

    $ascii_domain = idn_to_ascii($domain);
    if (empty($ascii_domain)) {
      return FALSE;
    }

    return filter_var($ascii_domain, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME) !== FALSE;
  }

  /**
   * Extract domain from email address.
   *
   * @param string $email
   *   Email address.
   *
   * @return string|null
   *   Normalized domain part of the email address or null if invalid.
   */
  public static function extractDomainFromEmail(string $email): ?string {
    if (empty($email) || !str_contains($email, '@')) {
      return NULL;
    }

    [, $domain] = explode('@', $email, 2);
    return static::normalizeDomain($domain);
  }

  /**
   * Extract domain from user's email address.
   *
   * @param \Drupal\user\UserInterface $user
   *   User entity.
   *
   * @return string|null
   *   Normalized domain part of the user's email address or null if invalid.
   */
  public static function extractDomainFromUser(UserInterface $user): ?string {
    $email = $user->getEmail();
    if (empty($email)) {
      return NULL;
    }

    return static::extractDomainFromEmail($email);
  }

}
