<?php

namespace Drupal\reliefweb_subscriptions\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_subscriptions\ReliefwebSubscriptionsMailer;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The actual mailer.
   *
   * @var \Drupal\reliefweb_subscriptions\ReliefwebSubscriptionsMailer
   */
  protected $mailer;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    AccountInterface $account,
    RequestStack $request_stack,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    ReliefwebSubscriptionsMailer $mailer
  ) {
    $this->account = $account;
    $this->requestStack = $request_stack;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->mailer = $mailer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('reliefweb_subscriptions.mailer')
    );
  }

  /**
   * Get the current request.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   Current request.
   */
  public function getRequest(): Request {
    return $this->requestStack->getCurrentRequest();
  }

  /**
   * Get the current user.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   Current user.
   */
  public function getCurrentUser(): AccountInterface {
    return $this->account;
  }

  /**
   * Get the entity type manger.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   Entity type manager.
   */
  public function getEntityTypeManager(): EntityTypeManagerInterface {
    return $this->entityTypeManager;
  }

  /**
   * Unsubscribe a user.
   */
  public function unsubscribe(AccountInterface $user) {
    if ($this->account->isAnonymous()) {
      $request = $this->getRequest();
      $timestamp = $request->get('timestamp');
      $signature = $request->get('signature');

      if (empty($timestamp) || empty($signature)) {
        throw new AccessDeniedHttpException();
      }

      return $this->redirect('reliefweb_subscriptions.unsubscription_form', [
        'user' => $user->id(),
        'timestamp' => $timestamp,
        'signature' => $signature,
      ]);
    }

    if ($user->id() === $this->getCurrentUser()->id()) {
      return $this->redirect('reliefweb_subscriptions.subscription_form', [
        'user' => $user->id(),
      ]);
    }

    throw new AccessDeniedHttpException();
  }

  /**
   * Unsubscribe a user (one click).
   *
   * @param string $opaque
   *   Opaque data from the unsubscribe link.
   *
   * @return array
   *   Render array for the page.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Bad request if the link is invalid.
   */
  public function unsubscribeOneClick(string $opaque): array {
    $data = $this->mailer->decryptOneClickUnsubscribeLink($opaque);
    if ($data === FALSE) {
      throw new BadRequestHttpException();
    }

    [$uid, $sid] = $data;

    $subscriptions = reliefweb_subscriptions_subscriptions();
    if (!isset($subscriptions[$sid])) {
      throw new BadRequestHttpException();
    }

    $user = $this->getEntityTypeManager()->getStorage('user')->load($uid);
    if (is_null($user)) {
      throw new BadRequestHttpException();
    }

    // Remove the subscription.
    $this->database->delete('reliefweb_subscriptions_subscriptions')
      ->condition('sid', $sid)
      ->condition('uid', $user->id())
      ->execute();

    $subscription_page = '/user/' . $user->id() . '/notifications';

    if ($user->id() === $this->getCurrentUser()->id()) {
      $message = $this->t('To add new subscriptions or manage your list, please go to your <a href=":subscription_page">subscriptions</a> page.', [
        ':subscription_page' => $subscription_page,
      ]);
    }
    else {
      $login_link = Url::fromRoute('user.login', [], [
        'query' => ['destination' => $subscription_page],
      ]);

      $message = $this->t('To add new subscriptions or manage your list, please <a href=":login_link">log in</a>.', [
        ':login_link' => $login_link->toString(),
      ]);
    }

    return [
      '#type' => 'inline_template',
      '#template' => '<p><strong>You have successfully unsubscribed.</strong></p><p>You will not longer receive <em>{{ subscription_label }}</em> notifications.</p><p>{{ message }}</p>',
      '#context' => [
        'subscription_label' => $subscriptions[$sid]['name'],
        'message' => $message,
      ],
    ];
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
