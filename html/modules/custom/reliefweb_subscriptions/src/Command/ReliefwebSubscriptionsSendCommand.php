<?php

namespace Drupal\reliefweb_subscriptions\Command;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\ProxyClass\File\MimeType\MimeTypeGuesser;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\State;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\reliefweb_subscriptions\CronExpressionParser;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Url;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Site\Settings;
use Drupal\Core\Link;

/**
 * Docstore Drush commandfile.
 *
 * @property \Consolidation\Log\Logger $logger
 */
class ReliefwebSubscriptionsSendCommand extends DrushCommands implements SiteAliasManagerAwareInterface {

  // Drush traits.
  use ProcessManagerAwareTrait;
  use SiteAliasManagerAwareTrait;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The mime type guesser service.
   *
   * @var \Drupal\Core\ProxyClass\File\MimeType\MimeTypeGuesser
   */
  protected $mimeTypeGuesser;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * The file usage.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

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
      AccountInterface $current_user,
      ConfigFactoryInterface $config_factory,
      Connection $database,
      EntityFieldManagerInterface $entity_field_manager,
      EntityRepositoryInterface $entity_repository,
      EntityTypeManagerInterface $entity_type_manager,
      MimeTypeGuesser $mimeTypeGuesser,
      FileSystem $file_system,
      FileUsageInterface $file_usage,
      State $state,
      ClientInterface $httpClient,
      TimeInterface $time
    ) {
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityRepository = $entity_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->mimeTypeGuesser = $mimeTypeGuesser;
    $this->fileSystem = $file_system;
    $this->fileUsage = $file_usage;
    $this->state = $state;
    $this->httpClient = $httpClient;
    $this->time = $time;
  }

  /**
   * Returns the current user.
   *
   * This for compatibility with the ResourceTrait.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The current user.
   */
  protected function currentUser() {
    return $this->currentUser;
  }

  /**
   * Returns the config factory.
   *
   * This for compatibility with the FileTrait.
   *
   * @param string $name
   *   The name of the configuration object to retrieve.
   *
   * @return \Drupal\Core\Config\Config
   *   A configuration object.
   */
  protected function config($name) {
    return $this->configFactory->get($name);
  }

  /**
   * Send notifications.
   *
   * @param int $limit
   *   Max number of items to send.
   *
   * @command reliefweb_subscriptions:send
   * @usage reliefweb_subscriptions:send
   *   Send emails.
   * @validate-module-enabled reliefweb_subscriptions
   */
  public function send($limit = 50) {
    // Get queued notifications, older first.
    // Triggered notifications have priority.
    $query = $this->database->select('reliefweb_subscriptions_queue', 'q');
    $query->fields('q', ['eid', 'sid', 'bundle', 'entity_id', 'last']);
    $query->addExpression('IF(q.entity_id > 0, 1, 0)', 'sortby');
    $query->orderBy('sortby', 'DESC');
    $query->orderBy('q.eid', 'ASC');
    $query->range(0, $limit);
    $result = $query->execute();

    if (empty($result)) {
      return;
    }

    $notifications = $result->fetchAllAssoc('eid');

    if (empty($notifications)) {
      $this->logger->info('No queued notifications.');
      return;
    }

    $this->logger->info('Processing @queued queued notifications.', [
      '@queued' => count($notifications),
    ]);

    // Extract the subsciption ids.
    $sids = [];
    foreach ($notifications as $notification) {
      $sids[$notification->sid] = $notification->sid;
    }

    // Get the subscriptions.
    $subscriptions = reliefweb_subscriptions_subscriptions();

    // Send notifications.
    $logs = [];
    $total_sent = 0;
    foreach ($notifications as $notification) {
      $subscription = $subscriptions[$notification->sid];

      if ($subscription['type'] === 'scheduled') {
        $sent = $this->sendScheduledNotification($notification, $subscription);
      }
      else {
        $sent = $this->sendTriggeredNotification($notification, $subscription);
      }

      if ($sent) {
        $total_sent++;
      }

      $logs[$notification->sid] = [
        'sid' => $notification->sid,
        'last' => $this->time->getRequestTime(),
        'next' => $this->getNextSendingTime($subscription),
      ];
    }

    $this->logger->info('Sent emails for @sent of @queued queued notifications.', [
      '@sent' => $total_sent,
      '@queued' => count($notifications),
    ]);

    // Update the subscription logs.
    $this->updateLogs($logs);

    // Remove the processed notifications from the queue.
    $query = $this->database->delete('reliefweb_subscriptions_queue');
    $query->condition('eid', array_keys($notifications));
    $query->execute();
  }

  /**
   * Queue notification.
   *
   * @param string $sid
   *   Subscription id.
   * @param array $options
   *   Drush options.
   *
   * @command reliefweb_subscriptions:queue
   * @usage reliefweb_subscriptions:queue
   *   Queue emails.
   * @validate-module-enabled reliefweb_subscriptions
   * @option entity_type
   *   Entity type.
   * @option entity_id
   *   Entity Id.
   * @option last
   *   Timestamp to use as the last time notifications were sent.
   */
  public function queue($sid, array $options = [
    'entity_type' => '',
    'entity_id' => 0,
    'last' => 0,
  ]) {

    $this->logger->info('Processing ' . $sid);

    $subscriptions = reliefweb_subscriptions_subscriptions();
    if (!isset($subscriptions[$sid])) {
      $this->logger->error('Invalid subscription id');
      return;
    }

    $subscription = $subscriptions[$sid];

    // Attempt to queue triggered notification.
    if ($subscription['type'] === 'triggered') {
      $entity_type = $options['entity_type'];
      $entity_id = $options['entity_id'];

      if (empty($entity_type) || empty($entity_id)) {
        $this->logger->error('Invalid entity type or id');
        return;
      }

      $entity = entity_load_single($entity_type, $entity_id);
      if (!empty($entity)) {
        $this->queueTriggered($entity, $entity_type);
      }
      else {
        $this->logger->warning('Entity not found, skipping');
        return;
      }
    }
    // Attempt to queue scheduled notification.
    //
    // We use almost the same logic as reliefweb_subscriptions_queue_scheduled()
    // but use the given last timestamp if provided.
    else {
      $sids = [$sid];

      // Skip if there are no subscribers for the subscription.
      $sids = $this->getSubscriptionsWithSubscribers($sids);
      if (empty($sids)) {
        $this->logger->warning('No subscribers, skipping');
        return;
      }

      // Skip if the subscriptipon is already queued.
      $sids = $this->getSubscriptionsNotYetQueued($sids);
      if (empty($sids)) {
        $this->logger->warning('Already queued, skipping');
        return;
      }

      $last = $options['last'];
      if (empty($last)) {
        // Get the last and next run timestamps for the subscriptions.
        $query = $this->database->select('reliefweb_subscriptions_logs', 'l');
        $query->fields('l', ['sid', 'last', 'next']);
        $query->condition('l.sid', $sids);
        $result = $query->execute();
        $timestamps = !empty($result) ? $result->fetchAllAssoc('sid') : [];
      }
      else {
        $timestamps = [
          $sid => [
            'last' => $last,
            'next' => $last,
          ],
        ];
      }

      $notifications = [];
      foreach ($sids as $sid) {
        // If a subscription was never sent then there is no log for it so we
        // compute what would have been the previous sending time.
        if (isset($timestamps[$sid])) {
          $last = $timestamps[$sid]->last;
          $next = $timestamps[$sid]->next;
        }
        else {
          $last = $this->getPreviousSendingTime($subscriptions[$sid]);
          $next = $last;
        }
        // Only queue the subscription is time is due (next run time is before
        // the current time).
        if ($next < $this->time->getRequestTime()) {
          $notifications[] = [
            'sid' => $sid,
            'last' => $last,
          ];
        }
      }

      // Queue notifications for all the subscriptions.
      $this->queueNotifications($notifications);
    }
    $this->logger->success('Subscription queued');
  }

  /**
   * Send scheduled notifications.
   *
   * @param object $notification
   *   Notification information.
   * @param array $subscription
   *   Subscription information.
   *
   * @return bool
   *   TRUE if emails were sent.
   */
  protected function sendScheduledNotification(object $notification, array $subscription) {
    $data = $this->getScheduledNotificationData($notification, $subscription);
    if (empty($data)) {
      return FALSE;
    }

    return $this->generateEmail($subscription, $data);
  }

  /**
   * Send triggered notifications.
   *
   * @param array $notification
   *   Notification information.
   * @param array $subscription
   *   Subscription information.
   *
   * @return bool
   *   TRUE if emails were sent.
   */
  protected function sendTriggeredNotification(array $notification, array $subscription) {
    $data = $this->getTriggeredNotificationData($notification, $subscription);
    if (empty($data)) {
      return FALSE;
    }

    return $this->generateEmail($subscription, $data);
  }

  /**
   * Get the API data for a scheduled notification.
   *
   * @param object $notification
   *   Notification information.
   * @param array $subscription
   *   Subscription information.
   *
   * @return array
   *   API data with the list of found entities.
   */
  protected function getScheduledNotificationData(object $notification, array $subscription) {
    $payload = $subscription['payload'] ?? [];

    // Add a filter to on the creation date to get only the documents created
    // after the last time the notification was sent.
    $filter = [
      'field' => 'date.created',
      'value' => [
        'from' => gmdate('c', $notification->last),
      ],
    ];
    if (!empty($payload['filter'])) {
      $payload['filter'] = [
        'conditions' => [
          $payload['filter'],
          $filter,
        ],
        'operator' => 'AND',
      ];
    }
    else {
      $payload['filter'] = $filter;
    }
    // Retrieve the data from the API.
    $result = $this->query((array) $notification, $subscription, $payload);
    if (empty($result) || empty($result['data'])) {
      $this->logger->info('No content, skipping scheduled notification @name', [
        '@name' => $subscription['name'],
      ]);
      return [];
    }

    // Extract the fields for each resource items.
    $items = [];
    foreach ($result['data'] as $item) {
      if (!empty($item['fields'])) {
        $items[] = $item['fields'];
      }
    }

    return $items;
  }

  /**
   * Generate the notification email content (subject, body, headers).
   *
   * @param array $subscription
   *   Subscription.
   * @param array $data
   *   API data to use in the templates.
   *
   * @return bool
   *   TRUE if emails were sent.
   *
   * @todo use templates for the text version instead of relying on
   * drupal_html_to_text(). That would mean changing the ExtendedMailSystem.
   */
  protected function generateEmail(array $subscription, array $data) {
    static $from;
    static $language;
    static $batch_size;

    if (!isset($from)) {
      $from = \Drupal::state()->get('site_mail', ini_get('sendmail_from'));
      // Format the from to include ReliefWeb if not already.
      if (strpos($from, '<') === FALSE) {
        $from = $this->formatString('!sitename <!sitemail>', [
          '!sitename' => \Drupal::state()->get('site_name', 'ReliefWeb'),
          '!sitemail' => $from,
        ]);
      }
      $language = \Drupal::service('language.default')->get()->getId();
      // Number of emails to send by second.
      $batch_size = \Drupal::state()->get('reliefweb_subscriptions_mail_batch_size', 40);
    }

    $sid = $subscription['id'];

    // Get the mail subject.
    $subject = $this->generateEmailSubject($subscription, $data);
    if (empty($subject)) {
      $this->logger->error('Unable to generate subject for @name subscription.', [
        '@name' => $subscription['name'],
      ]);
      return FALSE;
    }

    // Generate the HTML and text content.
    $body = $this->generateEmailContent($subscription, $data);
    if (empty($body)) {
      $this->logger->error('Unable to generate body for @name subscription.', [
        '@name' => $subscription['name'],
      ]);
      return FALSE;
    }

    // Get the subscribers.
    $subscribers = $this->getSubscribers($sid);
    if (empty($subscribers)) {
      $this->logger->info('No subscribers found for @name subscription.', [
        '@name' => $subscription['name'],
      ]);
      return FALSE;
    }

    $this->logger->info('Sending @subject notification to @subscribers subscribers.', [
      '@subject' => $subject,
      '@subscribers' => count($subscribers),
    ]);

    // Probably only used to categorise emails on SendGrid admin.
    $category = $subscription['category'];

    // Generate a List-Id base on the subscription id (ex: jobs).
    $list_id = $this->generateListId($sid);

    // Batch the subscribe list, so we can throttle if it looks like
    // we will go over our allowed rate limit.
    foreach (array_chunk($subscribers, $batch_size) as $batch) {

      // Record the start of the batch sending so we can throttle if we go
      // too fast for our AWS rate limit.
      $timer_start = microtime(TRUE);

      // Send current batch of emails in a simple loop.
      foreach ($batch as $subscriber) {
        // Generate the individual unsubscribe link.
        $unsubscribe = $this->generateUnsubscribeLink($subscriber->uid, $sid);

        // Update the body with the unique ubsubscribe link.
        $mail_body = strtr($body, ['%unsubscribe%' => $unsubscribe]);

        // Send the email.
        \Drupal::service('plugin.manager.mail')->mail('reliefweb_subscriptions', 'notifications', $subscriber->mail, $language, [
          'headers' => [
            'List-Id'          => $list_id,
            'List-Unsubscribe' => $unsubscribe,
            'X-RW-Category'    => $category,
          ],
          'subject' => $subject,
          'body' => [$mail_body],
        ], $from);
      }

      // If fewer than 1000 milliseconds have elapsed, throttle sending by
      // sleeping for whatever part of a second remains after completing the
      // batch. Probably not strictly necessary, but if we *do* go fast this
      // can help clear a back-log of 38,000 mails just a bit faster.
      $timer_elapsed = microtime(TRUE) - $timer_start;
      if ($timer_elapsed < 1) {
        usleep((1 - $timer_elapsed) * 1e+6);
      }
    }

    return TRUE;
  }

  /**
   * Generate email subject.
   *
   * @param array $subscription
   *   Subscription information.
   * @param array $data
   *   API data for the notification.
   *
   * @return string
   *   Email subject.
   */
  protected function generateEmailSubject(array $subscription, array $data) {
    if (isset($subscription['subject callback']) && is_callable($subscription['subject callback'])) {
      return $subscription['subject callback']($data);
    }
    else {
      return $subscription['subject'] ?? $subscription['name'] ?? '';
    }
  }

  /**
   * Generate the email HTML content.
   *
   * @param array $subscription
   *   Subscription information.
   * @param array $data
   *   API data for the notification.
   *
   * @return string
   *   HTML content.
   */
  protected function generateEmailContent(array $subscription, array $data) {
    switch ($subscription['id']) {
      case 'headlines':
        return $this->generateEmailContentHeadlines($subscription, $data);

      break;

    }

    // @todo build actual content.
    return '<html><body><p>Test mail</p></body></html>';

    // Remove unnecessary whitespaces.
    $html = preg_replace('/(\s)\s+/', '$1', $html);

    // Load styles.
    $styles = $this->getEmailStyles($path . '/css/email-styles.css');
    $replacements = [];
    foreach ($styles as $class => $style) {
      $replacements['class="' . $class . '"'] = 'style="' . $style . '"';
    }

    // Add inline styling.
    $html = strtr($html, $replacements);

    return $html;
  }

  /**
   * Prepare preheader text for titles.
   *
   * The truncation logic is the same as reliefweb_subscriptions_summarize.
   *
   * @param array $items
   *   Array of items to extract titles from.
   *
   * @return string
   *   Item titles for preheader.
   */
  protected function getPreheaderTitles(array $items) {
    $titles = [];
    $length = 100;
    $text_length = 0;
    $end_marks = ";.!?。؟ \t\n\r\0\x0B";
    $separator = ' / ';
    $delta = 3;

    foreach ($items as $item) {
      $title = $item['title'] ?? $item['headline']['title'] ?? '';
      if (!empty($title)) {
        $text_length += strlen($title);
        $titles[] = $title;
      }
    }

    // Ensure the preheader is no longer than 100 characters (+ ellipsis).
    if ($text_length > $length) {
      foreach ($titles as $index => $title) {
        $parts = preg_split('/([\s\n\r]+)/u', $title, -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts_count = count($parts);

        for ($i = 0; $i < $parts_count; ++$i) {
          if (($length -= mb_strlen($parts[$i])) <= 0) {
            // Truncate the title and add an ellipsis.
            $titles[$index] = trim(implode(array_slice($parts, 0, $i)), $end_marks) . '...';
            // Truncate the list of titles.
            $titles = array_slice($titles, 0, $index + 1);
            // Break from both loops.
            break 2;
          }
        }

        // Adjust the length to reflect the added space separator when
        // returning the text as plain text.
        $length -= $delta;
      }
    }

    return implode($separator, $titles);
  }

  /**
   * Prepare prefooter links.
   *
   * @param array $parts
   *   Array of hrefs and link text to format.
   *
   * @return string
   *   Formatted links.
   */
  protected function getPrefooterLinks(array $parts) {
    $links = [];
    foreach ($parts as $part) {
      $options = [
        'absolute' => TRUE,
        'attributes' => ['class' => ['prefooter-link']],
      ];

      if (!empty($part['options'])) {
        $options = $options + $part['options'];
      }

      $url = Url::fromUserInput($part['link'], $options);
      $links[] = Link::fromTextAndUrl($part['text'], $url)->toString();
    }

    return implode(' | ', $links);
  }

  /**
   * Generate the email HTML content.
   *
   * @param array $subscription
   *   Subscription information.
   * @param array $data
   *   API data for the notification.
   *
   * @return string
   *   HTML content.
   */
  protected function generateEmailContentHeadlines(array $subscription, array $data) {
    $variables = [
      '#theme' => 'reliefweb_subscriptions__headlines',
    ];

    $variables['#today'] = date_create('now')->format('j M Y');
    $variables['#preheader'] = $this->getPreheaderTitles($data);

    $items = [];
    foreach ($data as $fields) {
      $item = [];
      $info = [];
      $item['url'] = $fields['url_alias'];

      // Title.
      $title = $fields['headline']['title'];
      $country = $fields['primary_country']['name'];
      // Prepend the primary country if not already in the title.
      if (strpos($title, $country) !== 0) {
        $title = $country . ': ' . $title;
      }
      $item['title'] = $title;

      // Sources.
      $sources = [];
      foreach (array_slice($fields['source'], 0, 3) as $source) {
        $sources[] = $source['shortname'] ?? $source['name'];
      }
      $info[] = implode(', ', $sources);

      // Date.
      $info[] = date_create($fields['date']['original'])->format('j M Y');
      $item['info'] = implode(' &ndash; ', $info);

      $item['summary'] = $fields['headline']['summary'];
      $items[] = $item;
    }
    $variables['#items'] = $items;

    // Prefooter.
    $prefooter_parts = [
      [
        'text' => 'ReliefWeb',
        'link' => '/',
      ],
      [
        'text' => 'All ReliefWeb Headlines',
        'link' => '/headlines',
      ],
    ];
    $variables['#prefooter'] = $this->getPrefooterLinks($prefooter_parts);

    $html = \Drupal::service('renderer')->renderRoot($variables);
    return $html;
  }

  /**
   * Get the email styles to be used inline.
   *
   * @param string $file
   *   CSS file.
   *
   * @return array
   *   Styles as as associative array with class names as keys and css rules
   *   as values.
   */
  protected function getEmailStyles($file) {
    static $cache = [];

    if (!isset($cache[$file])) {
      if (!file_exists($file)) {
        return [];
      }

      $content = file_get_contents($file);
      $styles = [];
      $matches = [];
      preg_match_all('/\.([a-zA-Z0-9_-]+)\s+\{([^}]+)\}/', $content, $matches, PREG_SET_ORDER);
      if (!empty($matches)) {
        foreach ($matches as $match) {
          $styles[$match[1]] = trim(preg_replace('/;\s+/', '; ', $match[2]));
        }
      }
      $cache[$file] = $styles;
    }
    return $cache[$file];
  }

  /**
   * Prepare prefooter links.
   *
   * @param array $parts
   *   Array of hrefs and link text to format.
   *
   * @return string
   *   Formatted links.
   */
  protected function prepareFooterLinks(array $parts) {
    $links = [];
    foreach ($parts as $part) {
      $options = [
        'absolute' => TRUE,
        'attributes' => ['class' => ['prefooter-link']],
      ];
      if (!empty($part['options'])) {
        $options = $options + $part['options'];
      }
      $links[] = l($part['text'], $part['link'], $options);
    }

    return implode(' | ', $links);
  }

  /**
   * Update the subscription logs.
   *
   * @param array $logs
   *   Logs of the sent notifications.
   */
  protected function updateLogs(array $logs) {
    if (empty($logs)) {
      return;
    }

    // Delete the existing log entries.
    $this->database->delete('reliefweb_subscriptions_logs')
      ->condition('sid', array_keys($logs))
      ->execute();

    // Insert the new log entries.
    $query = $this->database->insert('reliefweb_subscriptions_logs');
    $query->fields(['sid', 'last', 'next']);
    foreach ($logs as $log) {
      $query->values($log);
    }
    $query->execute();
  }

  /**
   * Get subscription next sending time.
   *
   * @param array $subscription
   *   Subscription.
   *
   * @return int
   *   Timestamp of the next time the notification should be sent.
   */
  protected function getNextSendingTime(array $subscription) {
    if ($subscription['type'] === 'triggered') {
      // Triggered notifications are supposed to be "instant" so the next run
      // date is irrelevant. We simply use the request time as default.
      return $this->time->getRequestTime();
    }

    // Get the next time the scheduled notification should be sent.
    try {
      $date = CronExpressionParser::getNextRunDate($subscription['frequency'], $this->time->getRequestTime());
      return $date->getTimestamp();
    }
    // If for some reason we cannot compute the next run date, we set up one 1
    // week later so that we are not fully blocked.
    catch (Exception $exception) {
      $this->logger->error('Unable to compute the next run date for @name (@frequency)', [
        '@name' => $subscription['name'],
        '@frequency' => $subscription['frequency'],
      ]);
      return $this->time->getRequestTime() + 7 * 24 * 60 * 60;
    }
  }

  /**
   * Get the time the subscription was supposed to have been sent.
   *
   * @param array $subscription
   *   Subscription.
   *
   * @return int
   *   Timestamp of the previous time the notification should have been sent.
   */
  protected function getPreviousSendingTime(array $subscription) {
    if ($subscription['type'] === 'triggered') {
      // Triggered notifications are supposed to be "instant" so the previous
      // run date is irrelevant. We simply use the request time as default.
      return $this->time->getRequestTime();
    }

    // Get the previous time the scheduled notification should have been sent.
    try {
      $date = CronExpressionParser::getPreviousRunDate($subscription['frequency'], $this->time->getRequestTime());
      return $date->getTimestamp();
    }
    // If for some reason we cannot compute the previous run date, we set up one
    // a week before so that we are not fully blocked.
    catch (Exception $exception) {
      $this->logger->error('Unable to compute the previous run date for @name (@frequency)', [
        '@name' => $subscription['name'],
        '@frequency' => $subscription['frequency'],
      ]);
      return $this->time->getRequestTime() - 7 * 24 * 60 * 60;
    }
  }

  /**
   * Get the list of subscribers for the subscription.
   *
   * @param string $sid
   *   Subscription id.
   *
   * @return array
   *   List of users with their name and mail address.
   */
  protected function getSubscribers($sid) {
    $query = $this->database->select('reliefweb_subscriptions_subscriptions', 's');
    $query->fields('s', ['uid']);
    $query->condition('s.sid', $sid);
    $query->innerJoin('users_field_data', 'u', 'u.uid = s.uid');
    $query->fields('u', ['name', 'mail']);
    $result = $query->execute();
    return !empty($result) ? $result->fetchAllAssoc('uid') : [];
  }

  /**
   * Generate a unique list ID for a given subscription.
   *
   * The ID is unique and based on the subscription ID and site url and emulates
   * list IDs such as the ones created by mailman.
   *
   * @param string $sid
   *   Subscription ID (ex: jobs).
   *
   * @return string
   *   List id.
   */
  protected function generateListId($sid) {
    // Since we get called a lot, use a static cache.
    $lists = &drupal_static(__FUNCTION__);

    // Init as array if not set.
    if (!isset($lists)) {
      $lists = [];
    }

    // If there is no list ID for this subscription, generate one.
    if (!isset($lists[$sid])) {
      $url = \Drupal::request()->getScheme() . '://' . \Drupal::request()->getHost();
      $host = parse_url($url, PHP_URL_HOST);

      $lists[$sid] = $this->formatString('@sid.lists.@host', [
        '@sid' => $sid,
        '@host' => $host,
      ]);
    }

    return $lists[$sid];
  }

  /**
   * Generate an unsubscribe link for the given user and subscription.
   *
   * @param int $uid
   *   User id.
   * @param string $sid
   *   Subscription id.
   *
   * @return string
   *   Unsubscribe link.
   */
  protected function generateUnsubscribeLink($uid, $sid) {
    $path = 'notifications/unsubscribe/user/' . $uid;
    $path = 'http://' . \Drupal::request()->getHost() . '/user/' . $uid;
    $timestamp = $this->time->getRequestTime();
    $url = Url::fromUri($path, [
      'absolute' => TRUE,
      'query' => [
        'timestamp' => $timestamp,
        'signature' => $this->getSignature($path, $timestamp),
      ],
    ]);
    return $url->toString();
  }

  /**
   * Get signature for the unsubscribe links.
   *
   * @param string $path
   *   Unsubscribe path.
   * @param int $timestamp
   *   Timestamp.
   *
   * @return string
   *   Signature.
   */
  protected function getSignature($path, $timestamp) {
    return Crypt::hmacBase64($path, $timestamp . \Drupal::service('private_key')->get() . Settings::getHashSalt());
  }

  /**
   * Get the API data for the entity that triggered a notification.
   *
   * @param array $notification
   *   Notification information with the entity bundle and id.
   * @param array $subscription
   *   Subscription information.
   *
   * @return array
   *   API data for the entity (entity fields).
   */
  protected function getTriggeredNotificationData(array $notification, array $subscription) {
    // Skip if there is no entity_id/bundle.
    if (empty($notification['entity_id']) || empty($notification['bundle'])) {
      return [];
    }

    // Skip if the bundles don't match.
    if ($subscription['bundle'] !== $notification['bundle']) {
      return [];
    }
    $this->logger->info('getTriggeredNotificationData');
    $payload = $subscription['payload'] ?? [];

    // Add a filter to get only the entity for which to send the notification.
    $filter = [
      'field' => 'id',
      'value' => $notification['entity_id'],
    ];
    if (!empty($payload['filter'])) {
      $payload['filter'] = [
        'conditions' => [
          $payload['filter'],
          $filter,
        ],
        'operator' => 'AND',
      ];
    }
    else {
      $payload['filter'] = $filter;
    }

    // Ensure we get only 1 item.
    $payload['offset'] = 0;
    $payload['limit'] = 1;

    // Retrieve the data from the API.
    $result = $this->query($notification, $subscription, $payload);
    if (empty($result) || empty($result['data'][0]['fields'])) {
      $this->logger->info('No content, skipping triggered notification @name for @bundle @entity_id.', [
        '@name' => $subscription['name'],
        '@bundle' => $notification['bundle'],
        '@entity_id' => $notification['entity_id'],
      ]);
      return [];
    }
    return $result['data'][0]['fields'];
  }

  /**
   * Perform a single query against the ReliefWeb API.
   *
   * @param array $notification
   *   Notification information.
   * @param array $subscription
   *   Subscription information.
   * @param array $payload
   *   Request payload.
   *
   * @return array
   *   API response data.
   */
  protected function query(array $notification, array $subscription, array $payload) {
    // Encode the request payload.
    $data = json_encode($payload);
    if ($data === FALSE) {
      $error = 'Unable to encode payload';
      $this->logQueryError($notification, $subscription, $error);
      return [];
    }

    // Create the request handler.
    /** @var \Drupal\reliefweb_api\Services\ReliefWebApiClient $api_client */
    $api_client = \Drupal::service('reliefweb_api.client');
    $result = $api_client->request($subscription['resource'], $payload);

    // Decode the API response.
    if ($result === FALSE) {
      $error = 'Unable to decode response';
      $this->logQueryError($notification, $subscription, $error);
      return [];
    }

    return $result;
  }

  /**
   * Log errors while querying the ReliefWeb API.
   *
   * @param array $notification
   *   Notification information.
   * @param array $subscription
   *   Subscription information.
   * @param string $error
   *   Error message.
   */
  protected function logQueryError(array $notification, array $subscription, $error) {
    if (!empty($notification['bundle']) && !empty($notification['entity_id'])) {
      $this->logger->error('Query error for triggered notification @name for @bundle @entity_id: @error.', [
        '@name' => $subscription['name'],
        '@bundle' => $notification['bundle'],
        '@entity_id' => $notification['entity_id'],
        '@error' => $error,
      ]);
    }
    else {
      $this->logger->error('Query error for scheduled notification @name: @error', [
        '@name' => $subscription['name'],
        '@error' => $error,
      ]);
    }
  }

  /**
   * Queue subscriptions for entity events (insert, update).
   *
   * @param object $entity
   *   Entity.
   * @param string $entity_type
   *   Entity type.
   */
  protected function queueTriggered($entity, $entity_type) {
    // Skip if explicitly asked to not send notifications.
    if (!empty($entity->notifications_content_disable)) {
      return;
    }

    // phpcs:ignore
    list($entity_id, $revision_id, $bundle) = entity_extract_ids($entity_type, $entity);

    $subscriptions = reliefweb_subscriptions_subscriptions();

    // Get triggered type subscriptions for this bundle.
    $sids = [];
    foreach ($subscriptions as $sid => $subscription) {
      if ($subscription['type'] === 'triggered' && $subscription['bundle'] === $bundle) {
        // Check if the subscription trigger is met for the entity.
        if (!isset($subscription['trigger']) || $subscription['trigger']($entity, $entity_type)) {
          $sids[] = $sid;
        }
      }
    }

    // Skip if there are no subscriptions with subscribers.
    $sids = $this->getSubscriptionsWithSubscribers($sids);
    if (empty($sids)) {
      return;
    }

    // Skip if there are no new notifications to queue.
    $sids = $this->getSubscriptionsNotYetQueued($sids, $bundle, $entity_id);
    if (empty($sids)) {
      return;
    }

    $notifications = [];
    foreach ($sids as $sid) {
      $notifications[] = [
        'sid' => $sid,
        'bundle' => $bundle,
        'entity_id' => $entity_id,
      ];
    }
    // Queue notifications for all the subscriptions.
    $this->queueNotifications($notifications);
  }

  /**
   * Get the list of subscriptions with subscribers.
   *
   * @param array $sids
   *   Subscription ids to check against.
   *
   * @return array
   *   Ids of subscriptions with subscribers.
   */
  protected function getSubscriptionsWithSubscribers(array $sids) {
    if (empty($sids)) {
      return [];
    }
    $query = $this->database->select('reliefweb_subscriptions_subscriptions', 's');
    $query->fields('s', ['sid']);
    $query->condition('s.sid', $sids);
    $result = $query->distinct()->execute();

    return !empty($result) ? $result->fetchCol() : [];
  }

  /**
   * Get the subscriptions not yet queued for the given entity bundle and id.
   *
   * Note that a set (subscription id, bundle and entity) represents a unique
   * notification.
   *
   * @param array $sids
   *   Subscription ids.
   * @param string $bundle
   *   Entity bundle (only for triggered notifications).
   * @param int $entity_id
   *   Entity id (only for triggered notifications).
   *
   * @return array
   *   Ids of the subscriptions not yet queued (for this entity bundle and id
   *   in case of triggered type subscriptions).
   */
  public function getSubscriptionsNotYetQueued(array $sids, $bundle = '', $entity_id = 0) {
    if (empty($sids)) {
      return [];
    }
    $query = $this->database->select('reliefweb_subscriptions_queue', 'q');
    $query->fields('q', ['sid']);
    $query->condition('q.sid', $sids);

    if (!empty($bundle) && !empty($entity_id)) {
      $query->condition('q.bundle', $bundle);
      $query->condition('q.entity_id', $entity_id);
    }

    $result = $query->execute();

    if (!empty($result)) {
      $sids = array_diff($sids, $result->fetchCol());
    }

    return $sids;
  }

  /**
   * Queue notifications.
   *
   * @param array $notifications
   *   Notifications to queue.
   */
  protected function queueNotifications(array $notifications) {
    if (empty($notifications)) {
      return;
    }

    $default = [
      'bundle' => '',
      'entity_id' => 0,
      'last' => 0,
    ];

    // Queue notifications for all the subscriptions.
    $query = $this->database->insert('reliefweb_subscriptions_queue');
    $query->fields(['sid', 'bundle', 'entity_id', 'last']);
    foreach ($notifications as $notification) {
      $query->values($notification + $default);
    }
    $query->execute();

    // Log subscription queueing info.
    $subscriptions = reliefweb_subscriptions_subscriptions();
    foreach ($notifications as $notification) {
      if (isset($subscriptions[$notification['sid']])) {
        if (!empty($notification['bundle']) && !empty($notification['entity_id'])) {
          $this->logger->notice('Queueing triggered notification @name for @bundle @entity_id.', [
            '@name' => $subscriptions[$notification['sid']]['name'],
            '@bundle' => $notification['bundle'],
            '@entity_id' => $notification['entity_id'],
          ]);
        }
        else {
          $this->logger->notice('Queueing scheduled notification @name.', [
            '@name' => $subscriptions[$notification['sid']]['name'],
          ]);
        }
      }
    }
  }

  /**
   * Format string wrapper.
   */
  public function formatString($string, array $args) {
    return new FormattableMarkup($string, $args);
  }

}
