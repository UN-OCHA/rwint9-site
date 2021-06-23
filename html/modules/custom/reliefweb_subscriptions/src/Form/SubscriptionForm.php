<?php

namespace Drupal\reliefweb_subscriptions\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manage subscription for user.
 */
class SubscriptionForm extends FormBase {

  /**
   * User account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public function __construct(AccountInterface $account, Connection $database) {
    $this->account = $account;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'subscription_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $default_options = $this->userSubscriptions($this->account->id());

    $subscriptions = reliefweb_subscriptions_subscriptions();
    $options = [];

    foreach ($subscriptions as $subscription) {
      $options[$subscription['id']] = $subscription['name'];
    }

    $form['subscriptions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Subscriptions'),
      '#description' => $this->t('Select the lists you want to subscribe to.'),
      '#options' => $options,
      '#default_value' => $default_options,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save subscriptions'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $subscriptions = $form_state->getValue('subscriptions');
    foreach ($subscriptions as $sid => $value) {
      if (!$value) {
        $this->unsubscribe($this->account->id(), $sid);
      }
      else {
        $this->subscribe($this->account->id(), $sid);
      }
    }
  }

  /**
   * Get a user's subscriptions.
   *
   * @param int $uid
   *   User id.
   *
   * @return array
   *   Array of subscription ids.
   */
  protected function userSubscriptions($uid) {
    $result = $this->database
      ->select('reliefweb_subscriptions_subscriptions', 'rss')
      ->condition('uid', $uid)
      ->fields('rss', ['sid'])
      ->execute();

    return !empty($result) ? $result->fetchCol() : [];
  }

  /**
   * Add a user subscription.
   *
   * @param int $uid
   *   User id.
   * @param string $sid
   *   Subscription id.
   */
  public function subscribe($uid, $sid) {
    $this->database->merge('reliefweb_subscriptions_subscriptions')
      ->key(['sid' => $sid, 'uid' => $uid])
      ->fields(['sid' => $sid, 'uid' => $uid])
      ->execute();
  }

  /**
   * Remove a user subscription.
   *
   * @param int $uid
   *   User id.
   * @param string $sid
   *   Subscription id.
   */
  public function unsubscribe($uid, $sid) {
    $this->database->delete('reliefweb_subscriptions_subscriptions')
      ->condition('sid', $sid)
      ->condition('uid', $uid)
      ->execute();
  }

}
