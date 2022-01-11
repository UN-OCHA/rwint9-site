<?php

namespace Drupal\reliefweb_subscriptions\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_subscriptions\ReliefwebSubscriptionsMailer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Manage subscription for user.
 */
class UnsubscribeForm extends SubscriptionForm {

  /**
   * The actual mailer.
   *
   * @var \Drupal\reliefweb_subscriptions\ReliefwebSubscriptionsMailer
   */
  protected $mailer;

  /**
   * The kill switch.
   *
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  protected $killSwitch;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database, ReliefwebSubscriptionsMailer $mailer, KillSwitch $kill_switch) {
    $this->database = $database;
    $this->mailer = $mailer;
    $this->killSwitch = $kill_switch;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('reliefweb_subscriptions.mailer'),
      $container->get('page_cache_kill_switch'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'unsubscribe_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, AccountInterface $user = NULL, $timestamp = NULL, $signature = NULL) {
    if (!$this->mailer->checkUnsubscribeLink($user->id(), $timestamp, $signature)) {
      throw new AccessDeniedHttpException();
    }

    $sids = $this->userSubscriptions($user->id());
    $sids = array_flip($sids);

    $subscriptions = reliefweb_subscriptions_subscriptions();

    $options = [];
    $defaults = [];
    foreach ($subscriptions as $sid => $subscription) {
      // Only display active subscriptions.
      if (isset($sids[$sid])) {
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

        $defaults[$group][] = $sid;
      }
    }

    foreach ($subscriptions as $subscription) {
      $options[$subscription['id']] = $subscription['name'];
    }

    // No active subscriptions.
    if (empty($options['global']) && empty($options['country_updates'])) {
      $form['empty']['#markup'] = $this->t('You currently have no active subscriptions.');
      return $form;
    }

    // Store uid.
    $form['uid'] = [
      '#type' => 'value',
      '#value' => $user->id(),
    ];

    $login_link = Url::fromRoute('user.login', [], [
      'query' => ['destination' => '/user/' . $user->id() . '/notifications'],
    ]);

    $form['description'] = [
      '#prefix' => '<p class="notice">',
      '#suffix' => '</p>',
      '#markup' => $this->t('This is the list of your currently active subscriptions. Please unselect the notifications you no longer want to receive. To add new subscriptions or manage your list, please <a href=":login_link">log in</a>.', [
        ':login_link' => $login_link->toString(),
      ]),
    ];

    if (!empty($options['global'])) {
      $form['global'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Global notifications'),
        '#options' => $options['global'],
        '#default_value' => $defaults['global'] ?? [],
        '#optional' => FALSE,
      ];
    }

    if (!empty($options['country_updates'])) {
      $form['country_updates'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Updates by Country (daily)'),
        '#options' => $options['country_updates'],
        '#default_value' => $defaults['country_updates'] ?? [],
        '#optional' => FALSE,
      ];
    }

    $form['actions'] = [
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save subscriptions'),
      ],
      'cancel' => [
        '#markup' => $this->t('<a href=":url">Cancel</a>', [
          ':url' => Url::fromRoute('<front>')->toString(),
        ]),
      ],
    ];

    // Disable caching.
    $this->killSwitch->trigger();

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($subscriptions = $form_state->getValue('global')) {
      foreach ($subscriptions as $sid => $value) {
        if (!$value) {
          $this->unsubscribe($form_state->getValue('uid'), $sid);
        }
      }
    }

    if ($subscriptions = $form_state->getValue('country_updates')) {
      foreach ($subscriptions as $sid => $value) {
        if (!$value) {
          $this->unsubscribe($form_state->getValue('uid'), $sid);
        }
      }
    }

    // Show the user a message.
    $this->messenger()->addMessage($this->t('You have successfully updated your subscriptions'), MessengerInterface::TYPE_STATUS);

    $form_state->setRedirect('<front>');
  }

}
