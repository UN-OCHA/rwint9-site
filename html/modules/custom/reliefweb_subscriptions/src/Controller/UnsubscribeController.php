<?php

namespace Drupal\reliefweb_subscriptions\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Unsubscribe controller.
 */
class UnsubscribeController extends ControllerBase {

  /**
   * Account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * Account.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * {@inheritdoc}
   */
  public function __construct(AccountInterface $account, RequestStack $request_stack) {
    $this->account = $account;
    $this->request = $request_stack->getCurrentRequest();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('request_stack')
    );
  }

  /**
   * Unsubscribe a user.
   */
  public function unsubscribe(AccountInterface $user, $timestamp = NULL, $signature = NULL) {
    if ($this->account->isAnonymous()) {
      $timestamp = $timestamp ?? $this->request->get('timestamp');
      $signature = $signature ?? $this->request->get('signature');
      if (empty($timestamp) || empty($signature)) {
        throw new AccessDeniedHttpException();
      }

      return $this->redirect('reliefweb_subscriptions.unsubscription_form', [
        'user' => $user->id(),
        'timestamp' => $timestamp,
        'signature' => $signature,
      ]);
    }

    if ($this->account->id() === $user->id()) {
      return $this->redirect('reliefweb_subscriptions.subscription_form', [
        'user' => $user->id(),
      ]);
    }

    throw new AccessDeniedHttpException();
  }

  /**
   * Check the access to the page.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account to check access for.
   * @param \Drupal\user\UserInterface $user
   *   User account for the user posts page.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkUserAccess(AccountInterface $account, UserInterface $user) {
    if ($account->id() == $user->id()) {
      return AccessResult::allowedIf($account->hasPermission('manage own subscriptions'));
    }
    return AccessResult::allowedIf($account->hasPermission('manage other subscriptions') || $account->hasPermission('administer subscriptions'));
  }

}
