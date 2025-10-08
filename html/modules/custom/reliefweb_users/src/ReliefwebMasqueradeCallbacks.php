<?php

namespace Drupal\reliefweb_users;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\masquerade\Masquerade;
use Drupal\masquerade\MasqueradeCallbacks;

/**
 * Masquerade callbacks.
 */
class ReliefwebMasqueradeCallbacks extends MasqueradeCallbacks implements TrustedCallbackInterface {

  /**
   * The redirect destination helper.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $destination;

  /**
   * MasqueradeCallbacks constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\masquerade\Masquerade $masquerade
   *   The masquerade.
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $destination
   *   The redirect destination.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Masquerade $masquerade, RedirectDestinationInterface $destination) {
    $this->entityTypeManager = $entity_type_manager;
    $this->masquerade = $masquerade;
    $this->destination = $destination;
  }

  /**
   * The #post_render_cache callback; replaces placeholder with masquerade link.
   *
   * @param int $account_id
   *   The account ID.
   *
   * @return array
   *   A renderable array containing the masquerade link if allowed.
   */
  public function renderCacheLink($account_id) {
    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager->getStorage('user')->load($account_id);
    if (masquerade_target_user_access($account)) {
      // @todo Attaching a CSS class to this would be nice.
      return [
        'masquerade' => [
          '#type' => 'link',
          '#title' => new TranslatableMarkup('Masquerade as @name', ['@name' => $account->getDisplayName()]),
          '#url' => $account->toUrl('masquerade')->setOption('query', $this->destination->getAsArray()),
        ],
      ];
    }
    return ['#markup' => ''];
  }

}
