<?php

namespace Drupal\reliefweb_subscriptions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\reliefweb_subscriptions\CronExpressionParser;
use Drupal\reliefweb_subscriptions\ReliefwebSubscriptionsMailer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manage subscription for user.
 */
class SubscriptionAdminForm extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The actual mailer.
   *
   * @var \Drupal\reliefweb_subscriptions\ReliefwebSubscriptionsMailer
   */
  protected $mailer;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database, DateFormatter $date_formatter, ReliefwebSubscriptionsMailer $mailer) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->mailer = $mailer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('reliefweb_subscriptions.mailer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function adminOverview() {
    $subscriptions = reliefweb_subscriptions_subscriptions();

    // Get the sending log information for the subscriptions.
    $query = $this->database->select('reliefweb_subscriptions_logs', 'l');
    $query->fields('l', ['sid', 'last', 'next']);
    $result = $query->execute();
    $logs = !empty($result) ? $result->fetchAllAssoc('sid', \PDO::FETCH_ASSOC) : [];

    // Get number of subscribers per subscription.
    $query = $this->database->select('reliefweb_subscriptions_subscriptions', 's');
    $query->fields('s', ['sid']);
    $query->addExpression('COUNT(s.uid)', 'subscribers');
    $query->groupBy('s.sid');
    $result = $query->execute();
    $subscribers = !empty($result) ? $result->fetchAllKeyed() : [];

    $rows = [];
    foreach ($subscriptions as $sid => $subscription) {
      if ($subscription['type'] === 'scheduled') {
        $preview_title = $this->t('Preview (real)');

        // Subscription that was never sent.
        if (!isset($logs[$sid])) {
          $last = CronExpressionParser::getPreviousRunDate($subscription['frequency']);
          $last = $last->format('Y-m-d H:i:s');
          $next = CronExpressionParser::getNextRunDate($subscription['frequency']);
          $next = $next->format('Y-m-d H:i:s');
        }
        else {
          $last = $this->dateFormatter->format($logs[$sid]['last'], 'custom', 'Y-m-d H:i:s');
          $next = $this->dateFormatter->format($logs[$sid]['next'], 'custom', 'Y-m-d H:i:s');
        }
      }
      else {
        $preview_title = $this->t('Preview (fake)');
        $last = '-';
        $next = 'when triggered';
      }

      $preview_link = Link::fromTextAndUrl($preview_title, Url::fromRoute('reliefweb_subscriptions.subscription_preview', [
        'sid' => $sid,
      ]))->toString();
      $rows[] = [
        $subscription['name'],
        $subscription['frequency'] ?? 'instant',
        $subscribers[$sid] ?? 0,
        $last,
        $next,
        $preview_link,
      ];
    }

    $header = [
      $this->t('Name'),
      $this->t('Frequency'),
      $this->t('Subscribers'),
      $this->t('Last sent'),
      $this->t('Next time'),
      $this->t('Preview'),
    ];

    $build['subscriptions'] = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      "#empty" => $this->t('No record found.'),
    ];

    return $build;
  }

  /**
   * Generate a preview.
   */
  public function adminPreview($sid) {
    $content = $this->mailer->generatePreview($sid);
    $build['preview'] = [
      '#type' => 'inline_template',
      '#template' => '<h2>{{ subject }}</h2><iframe sandbox srcdoc="{{ body }}" width="100%" height="500"></iframe>',
      '#context' => [
        'subject' => $content['subject'],
        'body' => str_replace('"', '\'', $content['body']),
      ],
    ];

    return $build;
  }

}
