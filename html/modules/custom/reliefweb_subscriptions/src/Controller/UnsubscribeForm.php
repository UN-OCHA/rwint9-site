<?php

namespace Drupal\reliefweb_subscriptions\Controller;

/**
 * @file
 * Unsubscribe controller.
 */

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A Bookmarks toggle controller.
 */
class UnsubscribeForm extends ControllerBase {
  /**
   * Account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * {@inheritdoc}
   */
  public function __construct(AccountInterface $account) {
    $this->account = $account;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
    );
  }

  /**
   * Add or remove node from bookmarks.
   */
  public function unsubscribe(AccountInterface $user) {
    if ($this->account->isAnonymous()) {
      return $this->redirect('user.login', [], [
        'query' => ['destination' => '/user/' . $user->id() . '/notifications'],
      ]);
    }

    if ($this->account->id() === $user->id()) {
      return $this->redirect('reliefweb_subscriptions.subscription_form', [
        'user' => $user->id(),
      ]);
    }

    throw new AccessDeniedHttpException();
  }

}
