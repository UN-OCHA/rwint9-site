<?php

namespace Drupal\reliefweb_subscriptions\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manage subscription for user.
 */
class SubscriptionForm extends FormBase {

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    protected Connection $database,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
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
  public function buildForm(array $form, FormStateInterface $form_state, ?AccountInterface $user = NULL) {
    $sids = $this->userSubscriptions($user->id());
    $sids = array_flip($sids);

    $subscriptions = reliefweb_subscriptions_subscriptions();

    $options = [];
    $defaults = [];
    foreach ($subscriptions as $sid => $subscription) {
      if (strpos($sid, 'country_updates') === 0) {
        if (!$this->currentUser->hasPermission($subscription['permission'])) {
          continue;
        }
        $group = 'country_updates';
        $options[$group][$sid] = $this->t('@country', [
          '@country' => $subscription['country'],
        ]);
      }
      else {
        if (!$this->currentUser->hasPermission($subscription['permission'])) {
          continue;
        }
        $group = 'global';
        $options[$group][$sid] = $subscription['description'];
      }

      if (isset($sids[$sid])) {
        $defaults[$group][] = $sid;
      }
    }

    $form['uid'] = [
      '#type' => 'value',
      '#value' => $user->id(),
    ];

    $form['information'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email information'),
      '#not_required' => TRUE,
    ];

    $email = $user->getEmail();
    if (empty($user->field_email_confirmed->value)) {
      $form['information']['email'] = [
        '#type' => 'inline_template',
        '#template' => "<p>{% trans %}Your email address <em>{{ email }}</em> <strong>has not been verified</strong> so notifications will not be sent.</p><p>Please go to your account settings: {{ link }} and save to receive a new email verification link.{% endtrans %}</p>",
        '#context' => [
          'email' => $email,
          'link' => $user->toLink('here', 'edit-form')->toString(),
        ],
      ];
    }
    else {
      $form['information']['email'] = [
        '#type' => 'inline_template',
        '#template' => "<p>{% trans %}Notifications will be send to <em>{{ email }}</em>. You can change it in your account settings: {{ link }}.{% endtrans %}</p>",
        '#context' => [
          'email' => $email,
          'link' => $user->toLink('here', 'edit-form')->toString(),
        ],
      ];
    }

    if (empty($options)) {
      $form['no_subscriptions'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Notifications'),
        '#not_required' => FALSE,
        'message' => [
          '#markup' => $this->t('No subscriptions currently available.'),
        ],
      ];
    }
    else {
      if (!empty($options['global'])) {
        $form['global'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Global notifications'),
          '#options' => $options['global'] ?? [],
          '#default_value' => $defaults['global'] ?? [],
          '#optional' => FALSE,
        ];
      }

      if (!empty($options['country_updates'])) {
        $form['country_updates'] = [
          '#type' => 'select',
          '#title' => $this->t('Updates by Country (daily)'),
          '#options' => ['_none' => $this->t('- None -')] + ($options['country_updates'] ?? []),
          '#default_value' => $defaults['country_updates'] ?? [],
          '#multiple' => TRUE,
          '#empty_value' => '_none',
          '#attributes' => [
            'data-with-autocomplete' => '',
            'data-autocomplete-placeholder' => $this->t('Type and select a country'),
          ],
          '#optional' => FALSE,
        ];
      }

      $form['actions'] = [
        '#type' => 'actions',
        '#theme_wrappers' => [
          'fieldset' => [
            '#id' => 'actions',
            '#title' => $this->t('Form actions'),
            '#title_display' => 'invisible',
          ],
        ],
        '#weight' => 99,
      ];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save subscriptions'),
      ];
    }

    // Mark the form for enhancement by the reliefweb_form module.
    $form['#attributes']['data-enhanced'] = '';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (isset($form['global'])) {
      $subscriptions = $form_state->getValue('global');
      foreach ($subscriptions as $sid => $value) {
        if ($sid === '_none') {
          continue;
        }
        if (!$value) {
          $this->unsubscribe($form_state->getValue('uid'), $sid);
        }
        else {
          $this->subscribe($form_state->getValue('uid'), $sid);
        }
      }
    }

    if (isset($form['country_updates'])) {
      $active_subscriptions = [];
      $subscriptions = $form_state->getValue('country_updates');
      foreach ($subscriptions as $sid => $value) {
        if ($sid === '_none') {
          continue;
        }
        if (!$value) {
          $this->unsubscribe($form_state->getValue('uid'), $sid);
        }
        else {
          $this->subscribe($form_state->getValue('uid'), $sid);
          $active_subscriptions[] = $sid;
        }
      }
      $this->unsubscribeOtherCountries($form_state->getValue('uid'), $active_subscriptions);
    }

    // Show the user a message.
    $this->messenger()->addStatus($this->t('Subscriptions successfully updated.'));
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

  /**
   * Remove a user subscription.
   *
   * @param int $uid
   *   User id.
   * @param array $sids
   *   Subscription id.
   */
  public function unsubscribeOtherCountries($uid, array $sids) {
    if (empty($sids)) {
      $this->database->delete('reliefweb_subscriptions_subscriptions')
        ->condition('sid', 'country_updates_%', 'LIKE')
        ->condition('uid', $uid)
        ->execute();
    }
    else {
      $this->database->delete('reliefweb_subscriptions_subscriptions')
        ->condition('sid', 'country_updates_%', 'LIKE')
        ->condition('sid', $sids, 'NOT IN')
        ->condition('uid', $uid)
        ->execute();
    }
  }

}
