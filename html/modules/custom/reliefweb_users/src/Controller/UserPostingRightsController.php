<?php

namespace Drupal\reliefweb_users\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

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
    $sources = $this->entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'source',
        'field_user_posting_rights.id' => $user->id(),
      ]);

    $header = [
      $this->t('Source'),
      $this->t('Job'),
      $this->t('Training'),
      $this->t('Report'),
    ];

    $rows = [];
    foreach ($sources as $source) {
      $rights = NULL;
      // Retrieve the rights corresponding to the user.
      foreach ($source->field_user_posting_rights as $item) {
        if ($item->id == $user->id()) {
          $rights = $item->toArray();
          break;
        }
      }
      if (empty($rights)) {
        continue;
      }

      $rows[] = [
        [
          'data' => [
            '#type' => 'link',
            '#title' => $source->label(),
            '#url' => Url::fromRoute(
              'reliefweb_fields.taxonomy_term.user_posting_rights_form',
              ['taxonomy_term' => $source->id()],
              ['fragment' => ':~:text=' . $user->id()]
            ),
          ],
        ],
        ['data' => $this->formatPostingRights($rights['job'] ?? 0)],
        ['data' => $this->formatPostingRights($rights['training'] ?? 0)],
        ['data' => $this->formatPostingRights($rights['report'] ?? 0)],
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
