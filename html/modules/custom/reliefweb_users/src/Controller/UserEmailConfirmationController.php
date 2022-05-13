<?php

namespace Drupal\reliefweb_users\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Email confirmation controller.
 */
class UserEmailConfirmationController extends ControllerBase {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    RequestStack $request_stack,
    TimeInterface $time
  ) {
    $this->configFactory = $config_factory;
    $this->requestStack = $request_stack;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('request_stack'),
      $container->get('datetime.time')
    );
  }

  /**
   * Redirects to the user email confirmation page.
   *
   * In order to never disclose an email confirmation link via a referrer header
   * this controller must always return a redirect response.
   *
   * @param int $uid
   *   User ID of the user requesting reset.
   * @param int $timestamp
   *   The current timestamp.
   * @param string $hash
   *   Email confirmation link hash.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The redirect response.
   */
  public function redirectConfirmation($uid, $timestamp, $hash) {
    $account = $this->currentUser();

    // The link is not valid if it is not for the current logged in user.
    if ($account->isAuthenticated() && $account->id() != $uid) {
      $this->messenger()->addError($this->t("The one-time email confirmation link you clicked is invalid for the current logged in account."));
      return $this->redirect('<front>');
    }

    $session = $this->requestStack->getCurrentRequest()->getSession();
    $session->set('email_confirmation_hash', $hash);
    $session->set('email_confirmation_timestamp', $timestamp);
    return $this->redirect(
      'user.email.confirmation.process',
      ['uid' => $uid]
    );
  }

  /**
   * Confirm the user email.
   *
   * In order to never disclose an email confirmation link via a referrer header
   * this controller must always return a redirect response.
   *
   * @param int $uid
   *   User ID of the user requesting reset.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The redirect response.
   */
  public function processConfirmation($uid) {
    $session = $this->requestStack->getCurrentRequest()->getSession();
    $hash = $session->get('email_confirmation_hash');
    $timestamp = $session->get('email_confirmation_timestamp');

    // As soon as the session variables are used they are removed to prevent the
    // hash and timestamp from being leaked unexpectedly.
    $session->remove('email_confirmation_hash');
    $session->remove('email_confirmation_timestamp');

    if (empty($hash) || empty($timestamp)) {
      throw new AccessDeniedHttpException();
    }

    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if ($user === NULL || !$user->isActive()) {
      // Blocked or invalid user ID, so deny access. The parameters will be in
      // the watchdog's URL for the administrator to check.
      throw new AccessDeniedHttpException();
    }

    $current = $this->time
      ->getRequestTime();

    $timeout = $this->configFactory
      ->get('reliefweb_user.settings')
      ->get('email_confirmation_timeout');

    if (reliefweb_user_get_email_confirmation_hash($user, $timestamp) !== $hash) {
      $this->messenger()->addError($this->t('Invalid email confirmation link.'));
    }
    elseif (!empty($timeout) && $current - $timestamp > $timeout) {
      $this->messenger()->addError($this->t('The email confirmation link has expired. Please go to your account settings, check your email address and save to generate a new verification link.'));
    }
    else {
      // Note: this seems to delete sessions for the user, forcing any person
      // logged in with that account to be logged out.
      $user->field_email_confirmed->value = 1;
      $user->save();
      $this->messenger()->addStatus($this->t('Email successfully confirmed.'));
    }
    return $this->redirect('<front>');
  }

}
