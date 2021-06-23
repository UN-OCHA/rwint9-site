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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
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
  public function buildForm(array $form, FormStateInterface $form_state, AccountInterface $user = NULL) {
    $sids = $this->userSubscriptions($user->id());
    $sids = array_flip($sids);

    $subscriptions = reliefweb_subscriptions_subscriptions();

    $options = [];
    $defaults = [];
    foreach ($subscriptions as $sid => $subscription) {
      if (strpos($sid, 'country_updates') === 0) {
        $group = 'country_updates';
        $options[$group][$sid] = $this->t('@country', [
          '@country' => $subscription['country'],
        ]);
      }
      else {
        $group = 'global';
        $options[$group][$sid] = $subscription['description'];
      }

      if (isset($sids[$sid])) {
        $defaults[$group][] = $sid;
      }
    }

    foreach ($subscriptions as $subscription) {
      $options[$subscription['id']] = $subscription['name'];
    }

    $form['uid'] = [
      '#type' => 'value',
      '#value' => $user->id(),
    ];

    $form['global'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Global notifications'),
      '#options' => $options['global'],
      '#default_value' => $defaults['global'] ?? [],
      '#optional' => FALSE,
    ];

    $form['country_updates'] = [
      '#type' => 'select',
      '#title' => $this->t('Updates by Country (daily)'),
      '#options' => ['_none' => $this->t('- None -')] + $options['country_updates'],
      '#default_value' => $defaults['country_updates'] ?? [],
      '#multiple' => TRUE,
      '#empty_value' => '_none',
      '#attributes' => [
        'data-with-autocomplete' => '',
        'data-autocomplete-placeholder' => $this->t('Type and select a country'),
      ],
      '#optional' => FALSE,
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
    $subscriptions = $form_state->getValue('global');
    foreach ($subscriptions as $sid => $value) {
      if (!$value) {
        $this->unsubscribe($form_state->getValue('uid'), $sid);
      }
      else {
        $this->subscribe($form_state->getValue('uid'), $sid);
      }
    }

    $subscriptions = $form_state->getValue('country_updates');
    foreach ($subscriptions as $sid => $value) {
      if (!$value) {
        $this->unsubscribe($form_state->getValue('uid'), $sid);
      }
      else {
        $this->subscribe($form_state->getValue('uid'), $sid);
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
