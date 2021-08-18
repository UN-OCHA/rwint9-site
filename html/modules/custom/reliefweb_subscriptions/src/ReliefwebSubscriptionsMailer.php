<?php

namespace Drupal\reliefweb_subscriptions;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageDefault;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManager;
use Drupal\Core\PrivateKey;
use Drupal\Core\Render\Renderer;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\State;
use Drupal\Core\Theme\ThemeInitialization;
use Drupal\Core\Theme\ThemeManager;
use Drupal\Core\Url;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;

/**
 * Subscription mailer.
 */
class ReliefwebSubscriptionsMailer {

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
   * The state store.
   *
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Reliefweb API client.
   *
   * @var \Drupal\reliefweb_api\Services\ReliefWebApiClient
   */
  protected $reliefwebApiClient;

  /**
   * Private key.
   *
   * @var \Drupal\Core\PrivateKey
   */
  protected $privateKey;

  /**
   * Renderer.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $renderer;

  /**
   * Mail manager.
   *
   * @var \Drupal\Core\Mail\MailManager
   */
  protected $mailManager;

  /**
   * Default language.
   *
   * @var \Drupal\Core\Language\LanguageDefault
   */
  protected $languageDefault;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Theme initializer.
   *
   * @var \Drupal\Core\Theme\ThemeInitialization
   */
  protected $themeInitialization;

  /**
   * Theme manager.
   *
   * @var \Drupal\Core\Theme\ThemeManager
   */
  protected $themeManager;

  /**
   * Theme handler.
   *
   * @var \Drupal\Core\Etension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * {@inheritdoc}
   */
  public function __construct(
      ConfigFactoryInterface $config_factory,
      Connection $database,
      EntityFieldManagerInterface $entity_field_manager,
      EntityRepositoryInterface $entity_repository,
      EntityTypeManagerInterface $entity_type_manager,
      State $state,
      TimeInterface $time,
      ReliefWebApiClient $reliefwebApiClient,
      PrivateKey $privateKey,
      Renderer $renderer,
      MailManager $mailManager,
      LanguageDefault $languageDefault,
      LoggerChannelFactoryInterface $loggerFactory,
      ThemeInitialization $themeInitialization,
      ThemeManager $themeManager,
      ThemeHandlerInterface $themeHandler
    ) {
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityRepository = $entity_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
    $this->time = $time;
    $this->reliefwebApiClient = $reliefwebApiClient;
    $this->privateKey = $privateKey;
    $this->renderer = $renderer;
    $this->mailManager = $mailManager;
    $this->languageDefault = $languageDefault;
    $this->logger = $loggerFactory->get('reliefweb_subscriptions');
    $this->themeInitialization = $themeInitialization;
    $this->themeManager = $themeManager;
    $this->themeHandler = $themeHandler;
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
   */
  public function send($notifications) {
    if (empty($notifications)) {
      $this->logger->info('No queued notifications.');
      return;
    }

    $this->logger->info('Processing {queued} queued notifications.', [
      'queued' => count($notifications),
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

    $this->logger->info('Sent emails {sent} of {queued} queued notifications.', [
      'sent' => $total_sent,
      'queued' => count($notifications),
    ]);

    // Update the subscription logs.
    $this->updateLogs($logs);
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
    $this->logger->notice('Processing {sid}', [
      'sid' => $sid,
    ]);

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

      // Check if the entity exists.
      $entity = $this->entityTypeManager
        ->getStorage($entity_type)
        ->load($entity_id);
      if (empty($entity)) {
        $this->logger->warning('Entity not found, skipping');
        return;
      }

      $sids = [];
      foreach ($subscriptions as $sid => $subscription) {
        if ($subscription['type'] === 'triggered' && $subscription['bundle'] === $entity_type) {
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
      $sids = $this->getSubscriptionsNotYetQueued($sids, $entity_type, $entity_id);
      if (empty($sids)) {
        return;
      }

      $notifications = [];
      $notifications[] = [
        'sid' => $sid,
        'bundle' => $entity->bundle(),
        'entity_id' => $entity_id,
      ];

      // Queue notifications for all the subscriptions.
      $this->queueNotifications($notifications);
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
          $sid => (object) [
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
        // Only queue the subscription if time is due (next run time is before
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
    $this->logger->notice('Subscription queued');
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
  public function sendScheduledNotification(object $notification, array $subscription) {
    $data = $this->getScheduledNotificationData($notification, $subscription);
    if (empty($data)) {
      return FALSE;
    }

    return $this->generateEmail($subscription, $data);
  }

  /**
   * Send triggered notifications.
   *
   * @param object $notification
   *   Notification information.
   * @param array $subscription
   *   Subscription information.
   *
   * @return bool
   *   TRUE if emails were sent.
   */
  public function sendTriggeredNotification(object $notification, array $subscription) {
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
      $this->logger->info('No content, skipping scheduled notification {name}', [
        'name' => $subscription['name'],
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
      $from = $this->config('system.site')->get('mail') ?? ini_get('sendmail_from');
      // Format the from to include ReliefWeb if not already.
      if (strpos($from, '<') === FALSE) {
        $from = $this->formatString('@sitename <@sitemail>', [
          '@sitename' => $this->config('system.site')->get('name') ?? 'ReliefWeb',
          '@sitemail' => $from,
        ]);
      }
      $language = $this->languageDefault->get()->getId();
      // Number of emails to send by second.
      $batch_size = $this->state->get('reliefweb_subscriptions_mail_batch_size', 40);
    }

    $sid = $subscription['id'];

    // Get the mail subject.
    $subject = $this->generateEmailSubject($subscription, $data);
    if (empty($subject)) {
      $this->logger->error('Unable to generate subject for {name} subscription.', [
        'name' => $subscription['name'],
      ]);
      return FALSE;
    }

    // Generate the HTML and text content.
    $body = $this->generateEmailContent($subscription, $data);
    if (empty($body)) {
      $this->logger->error('Unable to generate body for {name} subscription.', [
        'name' => $subscription['name'],
      ]);
      return FALSE;
    }

    // Get the subscribers.
    $subscribers = $this->getSubscribers($sid);
    if (empty($subscribers)) {
      $this->logger->info('No subscribers found for {name} subscription.', [
        'name' => $subscription['name'],
      ]);
      return FALSE;
    }

    $this->logger->info('Sending {subject} notification to {subscribers} subscribers.', [
      'subject' => $subject,
      'subscribers' => count($subscribers),
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
        $mail_body = new FormattableMarkup($body, [
          '@unsubscribe' => $unsubscribe,
        ]);

        // Send the email.
        $this->mailManager->mail('reliefweb_subscriptions', 'notifications', $subscriber->mail, $language, [
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
    if (isset($subscription['subject callback'])) {
      return call_user_func([$this, $subscription['subject callback']], $data);
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
    $html = '';

    switch ($subscription['id']) {
      case 'headlines':
        $render_array = $this->generateEmailContentHeadlines($subscription, $data);
        break;

      case 'appeals':
        $render_array = $this->generateEmailContentAppeals($subscription, $data);
        break;

      case 'jobs':
        $render_array = $this->generateEmailContentJobs($subscription, $data);
        break;

      case 'training':
        $render_array = $this->generateEmailContentTraining($subscription, $data);
        break;

      case 'disaster':
        $render_array = $this->generateEmailContentDisaster($subscription, $data);
        break;

      case 'ocha_sitrep':
        $render_array = $this->generateEmailContentOchaSitrep($subscription, $data);
        break;

      default:
        // Country updates.
        $render_array = $this->generateEmailContentCountryUpdates($subscription, $data);
    }

    // Render the email using the default frontend theme so that template
    // overrides, if any, can be used.
    $active_theme = $this->themeManager->getActiveTheme();
    $default_theme_name = $this->themeHandler->getDefault();
    $default_theme = $this->themeInitialization->getActiveThemeByName($default_theme_name);

    $this->themeManager->setActiveTheme($default_theme);
    $html = $this->renderer->renderRoot($render_array);
    $this->themeManager->setActiveTheme($active_theme);

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
   * Generate the email content for the headlines notifications.
   *
   * @param array $subscription
   *   Subscription information.
   * @param array $data
   *   API data for the notification.
   *
   * @return string
   *   Render array.
   */
  protected function generateEmailContentHeadlines(array $subscription, array $data) {
    $variables = [
      '#theme' => 'reliefweb_subscriptions_content',
    ];

    $variables['#title'] = $subscription['title'];
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

    return $variables;
  }

  /**
   * Generate the email content for the appeals notifications.
   *
   * @param array $subscription
   *   Subscription information.
   * @param array $data
   *   API data for the notification.
   *
   * @return string
   *   Render array.
   */
  protected function generateEmailContentAppeals(array $subscription, array $data) {
    $variables = [
      '#theme' => 'reliefweb_subscriptions_content',
    ];

    $variables['#title'] = $subscription['title'];
    $variables['#preheader'] = $this->getPreheaderTitles($data);

    // Sort by country.
    $groups = [];
    foreach ($data as $fields) {
      $groups[$fields['primary_country']['name'] ?? 'zzz'][] = $fields;
    }
    ksort($groups);
    $data = array_merge(...array_values($groups));

    $items = [];
    foreach ($data as $fields) {
      $item = [];
      $info = [];
      $item['url'] = $fields['url_alias'];

      // Title.
      $title = $fields['title'];
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

      // Summary.
      $body = !empty($fields['body']) ? check_markup($fields['body'], 'markdown') : '';
      $item['summary'] = $this->summarize($body, 400, FALSE);
      $items[] = $item;
    }
    $variables['#items'] = $items;

    // Prefooter.
    $prefooter_parts = [
      [
        'text' => 'All appeals on ReliefWeb',
        'link' => '/updates',
        'options' => [
          'query' => [
            // Content format facet filter: 'Appeal'.
            'format' => 4,
          ],
        ],
      ],
    ];

    $variables['#prefooter'] = $this->getPrefooterLinks($prefooter_parts);

    return $variables;
  }

  /**
   * Generate the email content for the jobs notifications.
   *
   * @param array $subscription
   *   Subscription information.
   * @param array $data
   *   API data for the notification.
   *
   * @return string
   *   Render array.
   */
  protected function generateEmailContentJobs(array $subscription, array $data) {
    $variables = [
      '#theme' => 'reliefweb_subscriptions_content',
    ];

    $variables['#title'] = $subscription['title'];
    $variables['#preheader'] = $this->getPreheaderTitles($data);

    // Sort by country.
    $groups = [];
    foreach ($data as $fields) {
      $groups[$fields['country'][0]['name'] ?? 'zzz'][] = $fields;
    }
    ksort($groups);
    $data = array_merge(...array_values($groups));

    $items = [];
    foreach ($data as $fields) {
      $item = [];
      $info = [];
      $item['url'] = $fields['url_alias'];

      // Title.
      $title = $fields['title'];
      if (!empty($fields['country'][0]['name'])) {
        $title = $fields['country'][0]['name'] . ': ' . $title;
      }
      else {
        $title = 'Unspecified location: ' . $title;
      }
      $item['title'] = $title;

      // Sources.
      $sources = [];
      foreach (array_slice($fields['source'], 0, 3) as $source) {
        $sources[] = $source['shortname'] ?? $source['name'];
      }
      $info[] = implode(', ', $sources);

      // Dates.
      if (!empty($fields['date']['closing'])) {
        $info[] = 'Closing date: ' . date_create($fields['date']['closing'])->format('j M Y');
      }

      $item['info'] = implode(' &ndash; ', $info);
      $items[] = $item;
    }
    $variables['#items'] = $items;

    // Prefooter.
    $prefooter_parts = [
      [
        'text' => 'All job announcements on ReliefWeb',
        'link' => '/jobs',
      ],
    ];
    $variables['#prefooter'] = $this->prepareFooterLinks($prefooter_parts);

    // Label for the read more links (not translated on purpose as mails are
    // always in English).
    $variables['#read_more_label'] = 'Find out more';

    return $variables;
  }

  /**
   * Generate the email content for the training notifications.
   *
   * @param array $subscription
   *   Subscription information.
   * @param array $data
   *   API data for the notification.
   *
   * @return string
   *   Render array.
   */
  protected function generateEmailContentTraining(array $subscription, array $data) {
    $variables = [
      '#theme' => 'reliefweb_subscriptions_content',
    ];

    $variables['#title'] = $subscription['title'];

    // Sort by country.
    $groups = [];
    foreach ($data as $fields) {
      $groups[$fields['country'][0]['name'] ?? 'zzz'][] = $fields;
    }
    ksort($groups);
    $data = array_merge(...array_values($groups));

    // Generate preheader.
    $variables['#preheader'] = $this->getPreheaderTitles($data);

    $items = [];
    foreach ($data as $fields) {
      $item = [];
      $info = [];
      $item['url'] = $fields['url_alias'];

      // Title.
      $title = $fields['title'];
      if (!empty($fields['country'][0]['name'])) {
        $title = $fields['country'][0]['name'] . ': ' . $title;
      }
      $item['title'] = $title;

      // Sources.
      $sources = [];
      foreach (array_slice($fields['source'], 0, 3) as $source) {
        $sources[] = $source['shortname'] ?? $source['name'];
      }
      $info[] = implode(', ', $sources);

      // Dates.
      if (!empty($fields['date']['start'])) {
        if (empty($fields['date']['end']) || $fields['date']['end'] === $fields['date']['start']) {
          $info[] = 'On ' . date_create($fields['date']['start'])->format('j M Y');
        }
        else {
          $info[] = 'From ' . date_create($fields['date']['start'])->format('j M Y') .
                    ' To ' . date_create($fields['date']['end'])->format('j M Y');
        }
        if (!empty($fields['date']['registration'])) {
          $info[] = 'Registration until ' . date_create($fields['date']['start'])->format('j M Y');
        }
      }
      else {
        $info[] = 'Ongoing';
      }

      $item['info'] = implode(' &ndash; ', $info);
      $items[] = $item;
    }
    $variables['#items'] = $items;

    $prefooter_parts = [
      [
        'text' => 'All training programs on ReliefWeb',
        'link' => '/training',
      ],
    ];
    $variables['#prefooter'] = $this->prepareFooterLinks($prefooter_parts);

    // Label for the read more links (not translated on purpose as mails are
    // always in English).
    $variables['#read_more_label'] = 'Find out more';

    return $variables;
  }

  /**
   * Generate the email content for the disaster notifications.
   *
   * @param array $subscription
   *   Subscription information.
   * @param array $data
   *   API data for the notification.
   *
   * @return array
   *   Render array.
   */
  protected function generateEmailContentDisaster(array $subscription, array $data) {
    $variables = [
      '#theme' => 'reliefweb_subscriptions_content__disaster',
    ];

    $variables['#url'] = $data['url_alias'];

    // Title.
    $variables['#title'] = $data['name'];

    // Event date.
    $variables['#date'] = date_create($data['date']['created'])->format('j M Y');

    // Overview.
    $variables['#overview'] = '';
    if (!empty($data['profile']['overview'])) {
      $variables['#overview'] = check_markup($data['profile']['overview'], 'markdown');
    }

    // Preheader with a maximum of 100 characters.
    $preheader = $this->summarize($variables['#overview'], 100, TRUE);
    $variables['#preheader'] = $preheader;

    // Prefooter.
    $prefooter_parts = [
      [
        'text' => $data['name'] . ' on ReliefWeb',
        'link' => $data['url_alias'],
      ],
      [
        'text' => 'All disasters on ReliefWeb',
        'link' => '/disasters',
      ],
    ];
    $variables['#prefooter'] = $this->prepareFooterLinks($prefooter_parts);

    return $variables;
  }

  /**
   * Generate the email content for the OCHA sitrep notifications.
   *
   * @param array $subscription
   *   Subscription information.
   * @param array $data
   *   API data for the notification.
   *
   * @return string
   *   Render array.
   */
  protected function generateEmailContentOchaSitrep(array $subscription, array $data) {
    $variables = [
      '#theme' => 'reliefweb_subscriptions_content__ocha_sitrep',
    ];

    $info = [];

    $variables['#url'] = $data['url_alias'];

    // Title.
    $title = $data['title'];
    $country = $data['primary_country']['name'];
    // Prepend the primary country if not already in the title.
    if (strpos($title, $country) !== 0) {
      $title = $country . ': ' . $title;
    }
    $variables['#title'] = $title;

    // Sources - OCHA obviously...
    $info[] = 'OCHA';

    // Date.
    $info[] = date_create($data['date']['original'])->format('j M Y');
    $variables['#info'] = implode(' &ndash; ', $info);

    // Summary.
    $body = !empty($data['body']) ? check_markup($data['body'], 'markdown') : '';
    $variables['#summary'] = $this->summarize($body, 400, FALSE);

    // Preheader with a maximum of 100 characters.
    $preheader = $this->summarize($body, 100, TRUE);
    $variables['#preheader'] = $preheader;

    $country_name = $data['primary_country']['name'];
    $country_id = $data['primary_country']['id'];

    // Prefooter.
    $prefooter_parts = [
      [
        'text' => $country_name . ' on ReliefWeb',
        'link' => '/taxonomy/term' . $country_id,
      ],
      [
        'text' => $country_name . ' updates',
        'link' => '/updates',
        'options' => [
          'query' => [
            // Country facet filter.
            'country' => $country_id,
          ],
        ],
      ],
      [
        'text' => 'OCHA Situation Reports',
        'link' => '/updates',
        'options' => [
          'query' => [
            // Source facet filter: 'OCHA'.
            'source' => 1503,
            // Content format facet filter: 'Situation Report'.
            'format' => 10,
          ],
        ],
      ],
    ];
    // Add link to Digital sitrep if there is one.
    $dsr_url = $this->config('reliefweb_dsr')->get('ocha_dsr_url');
    if (!empty($dsr_url) && isset($data['origin']) && strpos($data['origin'], $dsr_url) === 0) {
      array_unshift($prefooter_parts, [
        'text' => 'Digital Situation Report for ' . $country_name,
        'link' => $data['origin'],
      ]);
    }
    $variables['#prefooter'] = $this->prepareFooterLinks($prefooter_parts);

    return $variables;
  }

  /**
   * Generate the email content for the country updates notifications.
   *
   * @param array $subscription
   *   Subscription information.
   * @param array $data
   *   API data for the notification.
   *
   * @return string
   *   Render array.
   */
  protected function generateEmailContentCountryUpdates(array $subscription, array $data) {
    $variables = [
      '#theme' => 'reliefweb_subscriptions_content',
    ];

    $variables['#title'] = $subscription['title'];
    $variables['#preheader'] = $this->getPreheaderTitles($data);

    $items = [];
    foreach ($data as $fields) {
      $item = [];
      $info = [];
      $item['url'] = $fields['url_alias'];

      // Title.
      $title = $fields['title'];
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

      // Summary.
      $body = !empty($fields['body']) ? check_markup($fields['body'], 'markdown') : '';
      $item['summary'] = $this->summarize($body, 400, FALSE);

      // Image.
      if (!empty($fields['file'][0]['preview']['url-thumb'])) {
        $item['image']['url'] = $fields['file'][0]['preview']['url-thumb'];
        $item['image']['description'] = $fields['file'][0]['description'] ?? 'Report preview';
      }
      $items[] = $item;
    }
    $variables['#items'] = $items;

    $country_name = $subscription['country'];
    $country_id = str_replace('country_updates_', '', $subscription['id']);

    $variables['#country_name'] = $country_name;

    // Prefooter.
    $prefooter_parts = [
      [
        'text' => $country_name . ' on ReliefWeb',
        'link' => '/taxonomy/term/' . $country_id,
      ],
      [
        'text' => $country_name . ' updates',
        'link' => '/updates',
        'options' => [
          'query' => [
            'country' => $country_id,
          ],
        ],
      ],
      [
        'text' => 'All updates on ReliefWeb',
        'link' => '/updates',
      ],
    ];
    $variables['#prefooter'] = $this->getPrefooterLinks($prefooter_parts);

    return $variables;
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
      if (strpos($part['link'], 'http') === 0) {
        $links[] = '<a class="prefooter-link" href="' . $part['link'] . '">' . $part['text'] . '</a>';
      }
      else {
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
      ->condition('sid', array_keys($logs), 'IN')
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
    catch (\Exception $exception) {
      $this->logger->error('Unable to compute the next run date for {name} ({frequency})', [
        'name' => $subscription['name'],
        'frequency' => $subscription['frequency'],
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
    catch (\Exception $exception) {
      $this->logger->error('Unable to compute the previous run date for {name} ({frequency})', [
        'name' => $subscription['name'],
        'frequency' => $subscription['frequency'],
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
      $url = $this->getSchemeAndHttpHost();
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
    $path = $this->getSchemeAndHttpHost() . '/notifications/unsubscribe/user/' . $uid;
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
    return Crypt::hmacBase64($path, $timestamp . $this->privateKey->get() . Settings::getHashSalt());
  }

  /**
   * Check unsubscribe links.
   *
   * @param string $uid
   *   Unsubscribe path.
   * @param int $timestamp
   *   Timestamp.
   * @param int $signature
   *   Signature.
   *
   * @return bool
   *   Valid or not.
   */
  public function checkUnsubscribeLink($uid, $timestamp, $signature) {
    if (empty($uid) || empty($timestamp) || empty($signature)) {
      return FALSE;
    }

    $path = $this->getSchemeAndHttpHost() . '/notifications/unsubscribe/user/' . $uid;
    if ($signature !== $this->getSignature($path, $timestamp)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Get the API data for the entity that triggered a notification.
   *
   * @param object $notification
   *   Notification information with the entity bundle and id.
   * @param array $subscription
   *   Subscription information.
   *
   * @return array
   *   API data for the entity (entity fields).
   */
  protected function getTriggeredNotificationData(object $notification, array $subscription) {
    // Skip if there is no entity_id/bundle.
    if (empty($notification->entity_id) || empty($notification->bundle)) {
      return [];
    }

    // Skip if the bundles don't match.
    if ($subscription['bundle'] !== $notification->bundle) {
      return [];
    }
    $payload = $subscription['payload'] ?? [];

    // Add a filter to get only the entity for which to send the notification.
    $filter = [
      'field' => 'id',
      'value' => $notification->entity_id,
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
    $result = $this->query((array) $notification, $subscription, $payload);
    if (empty($result) || empty($result['data'][0]['fields'])) {
      $this->logger->info('No content, skipping triggered notification {name} for {bundle} {entity_id}.', [
        'name' => $subscription['name'],
        'bundle' => $notification->bundle,
        'entity_id' => $notification->entity_id,
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
    $result = $this->reliefwebApiClient->request($subscription['resource'], $payload);

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
      $this->logger->error('Query error for triggered notification {name} for {bundle} {entity_id}: {error}.', [
        'name' => $subscription['name'],
        'bundle' => $notification['bundle'],
        'entity_id' => $notification['entity_id'],
        'error' => $error,
      ]);
    }
    else {
      $this->logger->error('Query error for scheduled notification {name}: {error}', [
        'name' => $subscription['name'],
        'error' => $error,
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
   *
   * @todo update logic to be compatible with Drupal 9 and add trigger
   * callbacks.
   */
  protected function queueTriggered($entity, $entity_type) {
    // Skip if explicitly asked to not send notifications.
    if (!empty($entity->notifications_content_disable)) {
      return;
    }

    $subscriptions = reliefweb_subscriptions_subscriptions();

    // Get triggered type subscriptions for this bundle.
    $sids = [];
    foreach ($subscriptions as $sid => $subscription) {
      if ($subscription['type'] === 'triggered' && $subscription['bundle'] === $entity->entityType()) {
        // Check if the subscription trigger is met for the entity.
        if (!isset($subscription['trigger']) || call_user_func([
          $this,
          $subscription['trigger'],
        ], $entity, $entity_type)) {
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
    $sids = $this->getSubscriptionsNotYetQueued($sids, $entity->entityType(), $entity->id());
    if (empty($sids)) {
      return;
    }

    $notifications = [];
    foreach ($sids as $sid) {
      $notifications[] = [
        'sid' => $sid,
        'bundle' => $entity->entityType(),
        'entity_id' => $entity->id(),
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
          $this->logger->notice('Queueing triggered notification {name} for {bundle} {entity_id}.', [
            'name' => $subscriptions[$notification['sid']]['name'],
            'bundle' => $notification['bundle'],
            'entity_id' => $notification['entity_id'],
          ]);
        }
        else {
          $this->logger->notice('Queueing scheduled notification {name}.', [
            'name' => $subscriptions[$notification['sid']]['name'],
          ]);
        }
      }
    }
  }

  /**
   * Format string wrapper.
   */
  protected function formatString($string, array $args) {
    return new FormattableMarkup($string, $args);
  }

  /**
   * Get scheme and host.
   *
   * @todo use \Symfony\Component\HttpFoundation\Request::getSchemeAndHttpHost()?
   */
  protected function getSchemeAndHttpHost() {
    $url_options = [
      'absolute' => TRUE,
    ];
    return rtrim(Url::fromRoute('<front>', [], $url_options)->toString(), '/');
  }

  /**
   * Summarize and truncate a HTML text to a given length.
   *
   * @param string $html
   *   HTML to summarize.
   * @param int $length
   *   Maximum length of the text.
   * @param bool $plain_text
   *   Return the truncated text as plain text when set to TRUE or as
   *   HTML paragraphs when FALSE.
   *
   * @return string
   *   Truncated text.
   *
   * @todo this is ported from the responsive site and can be removed later
   * to use the RWPageDataWrapper version instead.
   */
  protected function summarize($html, $length = 600, $plain_text = TRUE) {
    static $flags = LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING;
    static $pattern = ['/^\s+|\s+$/u', '/\s{2,}/u'];
    static $replacement = ['', ' '];
    static $end_marks = ";.!?。؟ \t\n\r\0\x0B";

    if (empty($html)) {
      return '';
    }

    // Extract the paragraphs from the html string.
    $paragraphs = [];
    $text_length = 0;
    $meta = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    $dom = new \DomDocument();
    $dom->loadHTML($meta . $html, $flags);
    foreach ($dom->getElementsByTagName('p') as $node) {
      // Sanitize multiple consecutive white spaces and trim the paragraph.
      $paragraph = preg_replace($pattern, $replacement, $node->textContent);
      $paragraphs[] = $paragraph;
      $text_length += mb_strlen($paragraph);
    }

    // Nothing to return if we couldn't extract paragraphs.
    if (empty($paragraphs)) {
      return '';
    }

    if ($plain_text) {
      $prefix = '';
      $suffix = '';
      $separator = ' ';
      $delta = 1;
    }
    else {
      $prefix = '<p>';
      $suffix = '</p>';
      $separator = '</p><p>';
      $delta = 0;
    }

    // Truncate the text to the given length if longer.
    if ($text_length > $length) {
      foreach ($paragraphs as $index => $paragraph) {
        $parts = preg_split('/([\s\n\r]+)/u', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts_count = count($parts);

        for ($i = 0; $i < $parts_count; ++$i) {
          if (($length -= mb_strlen($parts[$i])) <= 0) {
            // Truncate the paragraph and add an ellipsis.
            $paragraphs[$index] = trim(implode(array_slice($parts, 0, $i)), $end_marks) . '...';
            // Truncate the list of paragraphs.
            $paragraphs = array_slice($paragraphs, 0, $index + 1);
            // Break from both loops.
            break 2;
          }
        }

        // Adjust the length to reflect the added space separator when
        // returning the text as plain text.
        $length -= $delta;
      }
    }

    return $prefix . implode($separator, $paragraphs) . $suffix;
  }

  /**
   * Generate a preview.
   */
  public function generatePreview($sid) {
    $subscriptions = reliefweb_subscriptions_subscriptions();
    $subscription = $subscriptions[$sid];

    if ($subscription['type'] === 'scheduled') {
      $last = $this->getPreviousSendingTime($subscription);
      $notification = [
        'sid' => $sid,
        'last' => $last,
      ];
      $data = $this->getScheduledNotificationData((object) $notification, $subscription);
    }
    else {
      $payload = $subscription['payload'];
      $payload['sort'] = ['id:desc'];
      $payload['offset'] = 0;
      $payload['limit'] = 1;

      // Retrieve the data from the API.
      $result = $this->query([], $subscription, $payload);
      if (!empty($result) && !empty($result['data'][0]['fields'])) {
        $data = $result['data'][0]['fields'];
      }
    }

    // Get the mail subject.
    $subject = $this->generateEmailSubject($subscription, $data);
    if (empty($subject)) {
      return '';
    }

    // Generate the HTML and text content.
    $body = $this->generateEmailContent($subscription, $data);
    if (empty($body)) {
      return '';
    }

    // Render the email using the default frontend theme so that template
    // overrides, if any, can be used.
    $active_theme = $this->themeManager->getActiveTheme();
    $default_theme_name = $this->themeHandler->getDefault();
    $default_theme = $this->themeInitialization->getActiveThemeByName($default_theme_name);

    $render_array = [
      '#theme' => 'mimemail_message',
      '#module' => 'reliefweb_subscriptions',
      '#key' => 'notifications',
      '#recipient' => '',
      '#subject' => $subject,
      '#body' => $body,

    ];

    $this->themeManager->setActiveTheme($default_theme);
    $html = $this->renderer->renderRoot($render_array);
    $this->themeManager->setActiveTheme($active_theme);

    return [
      'subject' => $subject,
      'body' => $html,
    ];
  }

  /**
   * Trigger for sitreps.
   */
  protected function triggerOchaSitrepNotification($entity, $entity_type) {
    if (!isset($entity->type) || $entity->type !== 'report') {
      return FALSE;
    }

    $language = $entity->language ?? Language::LANGCODE_NOT_SPECIFIED;

    // Check if the source is OCHA (id: 1503).
    if (!empty($entity->field_source[$language])) {
      $found = FALSE;
      foreach ($entity->field_source[$language] as $item) {
        // No strict equality as tid may be a numeric string.
        if (isset($item['tid']) && $item['tid'] == 1503) {
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        return FALSE;
      }
    }

    // Check if the format is Situation Report (id: 10).
    if (!empty($entity->field_content_format[$language])) {
      $found = FALSE;
      foreach ($entity->field_content_format[$language] as $item) {
        // No strict equality as tid may be a numeric string.
        if (isset($item['tid']) && $item['tid'] == 10) {
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        return FALSE;
      }
    }

    // Check status.
    if (!empty($entity->is_new)) {
      $status = $this->getEntityStatus($entity);
      return in_array($status, ['published', 'to-review']);
    }
    elseif (isset($entity->original)) {
      $status_new = $this->getEntityStatus($entity);
      $status_old = $this->getEntityStatus($entity->original);
      return $status_new !== $status_old &&
        in_array($status_new, ['published', 'to-review']) &&
        !in_array($status_old, ['published', 'to-review']);
    }
    return FALSE;
  }

  /**
   * Trigger for disasters.
   */
  protected function triggerDisasterNotification($entity, $entity_type) {
    if (!isset($entity->vocabulary_machine_name) || $entity->vocabulary_machine_name !== 'disaster') {
      return FALSE;
    }
    if (!empty($entity->is_new)) {
      $status = $this->getEntityStatus($entity);
      return in_array($status, ['alert', 'current']);
    }
    elseif (isset($entity->original)) {
      $status_new = $this->getEntityStatus($entity);
      $status_old = $this->getEntityStatus($entity->original);
      return $status_new !== $status_old && in_array($status_new, [
        'alert',
        'current',
      ]);
    }
    return FALSE;
  }

  /**
   * Generate sitrep subject.
   */
  protected function ochaSitrepSubject($data) {
    if (!empty($data['title'])) {
      return 'New OCHA Situation Report - ' . $data['title'];
    }
    return '';
  }

  /**
   * Generate disaster subject.
   */
  protected function disasterSubject($data) {
    if (!empty($data['name'])) {
      return 'New Alert/Disaster - ' . $data['name'];
    }
    return '';
  }

  /**
   * Get the status of an entity.
   *
   * @param object $entity
   *   Entity.
   *
   * @return string
   *   Entity status or empty string.
   */
  protected function getEntityStatus($entity) {
    $language = $entity->language ?? Language::LANGCODE_NOT_SPECIFIED;
    if (isset($entity->field_status, $entity->field_status[$language][0]['value'])) {
      return $entity->field_status[$language][0]['value'];
    }
    return '';
  }

}
