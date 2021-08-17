<?php

namespace Drupal\reliefweb_subscriptions\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
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
   * {@inheritdoc}
   */
  public function __construct(Connection $database, ReliefwebSubscriptionsMailer $mailer) {
    $this->database = $database;
    $this->mailer = $mailer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('reliefweb_subscriptions.mailer'),
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

    $form['uid'] = [
      '#type' => 'value',
      '#value' => $user->id(),
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
    }

    $subscriptions = $form_state->getValue('country_updates');
    foreach ($subscriptions as $sid => $value) {
      if (!$value) {
        $this->unsubscribe($form_state->getValue('uid'), $sid);
      }
    }

    $form_state->setRedirect('<front>');
  }

}
