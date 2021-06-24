<?php

namespace Drupal\reliefweb_subscriptions\Command;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
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
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use Drupal\Component\Datetime\TimeInterface;

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
   *   Update locations from HRinfo api.
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
      $sids[$notification['sid']] = $notification['sid'];
    }

    // Get the subscriptions.
    $subscriptions = reliefweb_subscriptions_subscriptions();

    // Send notifications.
    $logs = [];
    $total_sent = 0;
    foreach ($notifications as $notification) {
      $subscription = $subscriptions[$notification['sid']];

      if ($subscription['type'] === 'scheduled') {
        $sent = $this->sendScheduledNotification($notification, $subscription);
      }
      else {
        $sent = $this->sendTriggeredNotification($notification, $subscription);
      }

      if ($sent) {
        $total_sent++;
      }

      $logs[$notification['sid']] = [
        'sid' => $notification['sid'],
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
   * Send scheduled notifications.
   *
   * @param array $notification
   *   Notification information.
   * @param array $subscription
   *   Subscription information.
   *
   * @return bool
   *   TRUE if emails were sent.
   */
  protected function sendScheduledNotification(array $notification, array $subscription) {
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
   * @param array $notification
   *   Notification information.
   * @param array $subscription
   *   Subscription information.
   *
   * @return array
   *   API data with the list of found entities.
   */
  protected function getScheduledNotificationData(array $notification, array $subscription) {
    $payload = $subscription['payload'] ?? [];

    // Add a filter to on the creation date to get only the documents created
    // after the last time the notification was sent.
    $filter = [
      'field' => 'date.created',
      'value' => [
        'from' => gmdate('c', $notification['last']),
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
    $result = $this->query($notification, $subscription, $payload);
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
      $from = variable_get('site_mail', ini_get('sendmail_from'));
      // Format the from to include ReliefWeb if not already.
      if (strpos($from, '<') === FALSE) {
        $from = format_string('!sitename <!sitemail>', [
          '!sitename' => variable_get('site_name', 'ReliefWeb'),
          '!sitemail' => $from,
        ]);
      }
      $language = language_default();
      // Number of emails to send by second.
      $batch_size = variable_get('reliefweb_subscriptions_mail_batch_size', 40);
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
        $unsubscribe = $this->generateUnsubscribeLink($subscriber['uid'], $sid);

        // Update the body with the unique ubsubscribe link.
        $mail_body = strtr($body, ['%unsubscribe%' => $unsubscribe]);

        // Send the email.
        drupal_mail('reliefweb_subscriptions', 'notifications', $subscriber['mail'], $language, [
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
    static $path;
    if (!isset($path)) {
      $path = drupal_get_path('module', 'reliefweb_subscriptions');
    }
    $template = $path . '/templates/' . $subscription['template'] . '.tpl.php';

    // Get the template variables from the API data.
    $preprocess = 'reliefweb_subscriptions_preprocess_' . $subscription['template'] . '_data';
    if (function_exists($preprocess)) {
      $variables = $preprocess($subscription, $data);
    }
    // There should be a preprocessor for all the subscriptions. Abort if none.
    else {
      return '';
    }

    // Generate HTML content.
    $html = theme_render_template($template, $variables);

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
    $query->innerJoin('users', 'u', 'u.uid = s.uid');
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
      $url = url('/', ['absolute' => TRUE]);
      $host = parse_url($url, PHP_URL_HOST);

      $lists[$sid] = format_string('@sid.lists.@host', [
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
    $timestamp = $this->time->getRequestTime();
    return url($path, [
      'absolute' => TRUE,
      'query' => [
        'timestamp' => $timestamp,
        'signature' => $this->getSignature($path, $timestamp),
      ],
    ]);
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
    return drupal_hmac_base64($path, $timestamp . drupal_get_private_key() . drupal_get_hash_salt());
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
    static $api_url;
    static $appname;

    if (!isset($api_url)) {
      $api_url = variable_get('reliefweb_api_url', 'https://api.reliefweb.int/v1');
      $appname = !empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'reliefweb.int';
    }

    $parameters = [
      'appname' => $appname . '-subscriptions',
      'slim' => 1,
      // Bypass any cache.
      'timestamp' => microtime(TRUE),
    ];

    $url = $api_url . '/' . $subscription['resource'] . '?' . http_build_query($parameters, '', '&');

    // Encode the request payload.
    $data = json_encode($payload);
    if ($data === FALSE) {
      $error = 'Unable to encode payload';
      $this->logQueryError($notification, $subscription, $error);
      return [];
    }

    $headers = [
      'Content-Type: application/json',
      'Content-Length: ' . strlen($data),
    ];

    // Create the request handler.
    $curl = curl_init($url);

    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);

    // Enough timeout. This query is not so time sensitive and for subscriptions
    // like jobs, there can be a lot of data to return.
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);

    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    $errno = curl_errno($curl);
    curl_close($curl);

    // Handle timeout and other errors.
    if (!empty($errno)) {
      $error = format_string('[!status, !code, !error]', [
        '!status' => $status,
        '!code' => $errno,
        '!error' => $error,
      ]);
      $this->logQueryError($notification, $subscription, $error);
      return [];
    }

    // No strict equality as status may be a numeric string.
    if ($status != 200) {
      $error = format_string('[!status, !response, !data]', [
        '!status' => $status,
        '!response' => $response,
        '!data' => $data,
      ]);
      $this->logQueryError($notification, $subscription, $error);
      return [];
    }

    // Decode the API response.
    $result = json_decode($response, TRUE);
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

}
