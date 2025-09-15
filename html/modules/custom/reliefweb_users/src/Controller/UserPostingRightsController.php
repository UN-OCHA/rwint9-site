<?php

namespace Drupal\reliefweb_users\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;

/**
 * Controller for the Posting Rights page.
 */
class UserPostingRightsController extends ControllerBase {

  /**
   * Builds the content for the Posting Rights page.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account for which to display posting rights.
   *
   * @return array
   *   A render array representing the page content.
   */
  public function content(AccountInterface $user): array {
    // Get all sources with posting rights for the user (both user and domain).
    $sources_with_rights = UserPostingRightsHelper::getSourcesWithPostingRightsForUser($user);

    if (empty($sources_with_rights)) {
      return [
        '#theme' => 'table',
        '#header' => [
          $this->t('Source'),
          $this->t('Type'),
          $this->t('Job'),
          $this->t('Training'),
          $this->t('Report'),
        ],
        '#rows' => [],
        '#empty' => $this->t('No posting rights found.'),
        '#attached' => [
          'library' => [
            'common_design_subtheme/rw-user-posting-right',
          ],
        ],
      ];
    }

    // Load all sources at once for efficiency.
    $source_ids = array_keys($sources_with_rights);
    $sources = $this->entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadMultiple($source_ids);

    // Extract user domain once for efficiency.
    $user_entity = $this->entityTypeManager()->getStorage('user')->load($user->id());
    $user_domain = NULL;
    if ($user_entity && $user_entity->getEmail()) {
      $user_domain = $this->extractDomainFromEmail($user_entity->getEmail());
    }

    $header = [
      $this->t('Source'),
      $this->t('Type'),
      $this->t('Job'),
      $this->t('Training'),
      $this->t('Report'),
    ];

    $rows = [];
    foreach ($sources_with_rights as $source_id => $source_data) {
      $source = $sources[$source_id] ?? NULL;

      if (!$source) {
        continue;
      }

      // Determine if the rights come from user or domain posting rights.
      $type = $this->determinePostingRightsType($source, $user, $user_domain);

      // Build the source title with shortname if available and different from
      // label.
      $source_title = $source->label();
      if ($source->hasField('field_shortname') && !$source->field_shortname->isEmpty()) {
        $shortname = $source->field_shortname->value;
        if (!empty($shortname) && $shortname !== $source_title) {
          $source_title = $source_title . ' (' . $shortname . ')';
        }
      }

      $rows[] = [
        [
          'data' => [
            '#type' => 'link',
            '#title' => $source_title,
            '#url' => Url::fromRoute(
              'reliefweb_fields.taxonomy_term.user_posting_rights_form',
              ['taxonomy_term' => $source->id()],
              ['fragment' => ':~:text=' . $user->id()]
            ),
          ],
        ],
        ['data' => $this->formatType($type)],
        ['data' => $this->formatPostingRights($source_data['job'] ?? 0)],
        ['data' => $this->formatPostingRights($source_data['training'] ?? 0)],
        ['data' => $this->formatPostingRights($source_data['report'] ?? 0)],
      ];
    }

    return [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No posting rights found.'),
      '#attached' => [
        'library' => [
          'common_design_subtheme/rw-user-posting-right',
        ],
      ],
    ];
  }

  /**
   * Determine if posting rights come from user or domain settings.
   *
   * @param \Drupal\taxonomy\Entity\Term $source
   *   The source taxonomy term.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   * @param string|null $user_domain
   *   The user's email domain (pre-extracted for efficiency).
   *
   * @return string
   *   Either 'user' or 'domain'.
   */
  protected function determinePostingRightsType($source, AccountInterface $user, ?string $user_domain = NULL): string {
    // First check if the user has user posting rights for this source.
    if ($source->hasField('field_user_posting_rights')) {
      foreach ($source->field_user_posting_rights as $item) {
        if ($item->id == $user->id()) {
          return 'user';
        }
      }
    }

    // If no user rights found, check domain posting rights.
    if ($source->hasField('field_domain_posting_rights') && $user_domain) {
      foreach ($source->field_domain_posting_rights as $item) {
        if ($item->domain === $user_domain) {
          return 'domain';
        }
      }
    }

    // Default to 'user' if we can't determine the source.
    return 'user';
  }

  /**
   * Extract domain from email address.
   *
   * @param string $email
   *   Email address.
   *
   * @return string|null
   *   Domain part of the email address or NULL if invalid.
   */
  protected function extractDomainFromEmail(string $email): ?string {
    if (empty($email) || strpos($email, '@') === FALSE) {
      return NULL;
    }

    [, $domain] = explode('@', $email, 2);
    return mb_strtolower(trim($domain));
  }

  /**
   * Formats the type value for display.
   *
   * @param string $type
   *   The type of posting right (user or domain).
   *
   * @return array
   *   A render array for the formatted type.
   */
  protected function formatType(string $type): array {
    $labels = [
      'user' => $this->t('User'),
      'domain' => $this->t('Domain'),
    ];

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $labels[$type] ?? $this->t('Unknown'),
      '#attributes' => [
        'class' => ['rw-user-posting-right-type', 'rw-user-posting-right-type--' . $type],
      ],
    ];
  }

  /**
   * Formats the posting rights value for display.
   *
   * @param int $right
   *   The numeric value of the posting right.
   *
   * @return array
   *   A render array for the formatted posting right.
   */
  protected function formatPostingRights(int $right): array {
    $rights = [
      0 => 'unverified',
      1 => 'blocked',
      2 => 'allowed',
      3 => 'trusted',
    ];

    $labels = [
      0 => $this->t('Unverified'),
      1 => $this->t('Blocked'),
      2 => $this->t('Allowed'),
      3 => $this->t('Trusted'),
    ];

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $labels[$right] ?? $this->t('unknown'),
      '#attributes' => [
        'class' => ['rw-user-posting-right', 'rw-user-posting-right--large'],
        'data-user-posting-right' => $rights[$right] ?? 'unknown',
      ],
    ];
  }

}
