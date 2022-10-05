<?php

namespace Drupal\reliefweb_subscriptions;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Language\LanguageDefault;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\PrivateKey;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Theme\ThemeInitialization;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\reliefweb_entities\Entity\Report;
use Drupal\reliefweb_entities\Entity\Disaster;
use Drupal\reliefweb_subscriptions\Services\AwsSesClient;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\reliefweb_utility\Helpers\MailHelper;

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
   * @var \Drupal\Core\State\StateInterface
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
   * AWS SES Client.
   *
   * @var \Drupal\reliefweb_subscriptions\Services\AwsSesClient
   */
  protected $awsSesClient;

  /**
   * Private key.
   *
   * @var \Drupal\Core\PrivateKey
   */
  protected $privateKey;

  /**
   * Renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
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
   * @var \Drupal\Core\Theme\ThemeManagerInterface
   */
  protected $themeManager;

  /**
   * Theme handler.
   *
   * @var \Drupal\Core\Etension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * From address for the emails.
   *
   * @var string
   */
  protected $fromAddress;

  /**
   * Number of emails that can be sent per seconds.
   *
   * @var int
   */
  protected $sendRate;

  /**
   * Number of emails that can be sent in a bulk email.
   *
   * @var int
   */
  protected $batchSize;

  /**
   * {@inheritdoc}
   */
  public function __construct(
      ConfigFactoryInterface $config_factory,
      Connection $database,
      EntityFieldManagerInterface $entity_field_manager,
      EntityRepositoryInterface $entity_repository,
      EntityTypeManagerInterface $entity_type_manager,
      StateInterface $state,
      TimeInterface $time,
      ReliefWebApiClient $reliefweb_api_client,
      PrivateKey $private_key,
      RendererInterface $renderer,
      MailManagerInterface $mail_manager,
      LanguageDefault $language_default,
      LoggerChannelFactoryInterface $logger_factory,
      ThemeInitialization $theme_initialization,
      ThemeManagerInterface $theme_manager,
      ThemeHandlerInterface $theme_handler,
      awsSesClient $aws_ses_client,
    ) {
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityRepository = $entity_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
    $this->time = $time;
    $this->reliefwebApiClient = $reliefweb_api_client;
    $this->privateKey = $private_key;
    $this->renderer = $renderer;
    $this->mailManager = $mail_manager;
    $this->languageDefault = $language_default;
    $this->logger = $logger_factory->get('reliefweb_subscriptions');
    $this->themeInitialization = $theme_initialization;
    $this->themeManager = $theme_manager;
    $this->themeHandler = $theme_handler;
    $this->awsSesClient = $aws_ses_client;
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

    $this->logger->info('Sent emails for {sent} of {queued} queued notifications.', [
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
   * @option entity_id
   *   Entity Id.
   * @option last
   *   Timestamp to use as the last time notifications were sent.
   */
  public function queue($sid, array $options = [
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
      $entity_id = $options['entity_id'];

      if (empty($entity_id)) {
        $this->logger->error('Missing entity id');
        return;
      }
      if (!is_numeric($entity_id)) {
        $this->logger->error('Invalid entity id');
        return;
      }

      // Check if the entity exists.
      $entity = $this->entityTypeManager
        ->getStorage($subscription['entity_type_id'])
        ?->load($entity_id);
      if (empty($entity)) {
        $this->logger->warning('Entity not found, skipping');
        return;
      }
      elseif ($entity->bundle() !== $subscription['bundle']) {
        $this->logger->error('Entity bundle mismatch');
        return;
      }

      $this->queueTriggered($entity);
    }
    // Attempt to queue scheduled notification.
    else {
      $this->queueScheduled([$sid], $options['last'] ?? 0);
    }
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
   * Get the from address.
   *
   * @return string
   *   From address.
   */
  protected function getFromAddress() {
    // Retrieve the From email address.
    if (!isset($this->fromAddress)) {
      $from = $this->config('system.site')->get('mail') ?? ini_get('sendmail_from');
      // Format the from to include ReliefWeb if not already.
      if (strpos($from, '<') === FALSE) {
        $from = $this->formatString('@sitename <@sitemail>', [
          '@sitename' => $this->config('system.site')->get('name') ?? 'ReliefWeb',
          '@sitemail' => $from,
        ]);
      }
      $this->fromAddress = $from;
    }
    return $this->fromAddress;
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
    $sid = $subscription['id'];
    $subscription_name = $subscription['name'];

    // Get the subscribers.
    $subscribers = $this->getSubscribers($sid);
    if (empty($subscribers)) {
      $this->logger->info('No subscribers found for {name} subscription.', [
        'name' => $subscription_name,
      ]);
      return FALSE;
    }

    // Get the mail subject.
    $subject = $this->generateEmailSubject($subscription, $data);
    if (empty($subject)) {
      $this->logger->error('Unable to generate subject for {name} subscription.', [
        'name' => $subscription_name,
      ]);
      return FALSE;
    }

    // Generate the HTML and text content.
    $html = $this->generateEmailContent($subscription, $data);
    if (empty($html)) {
      $this->logger->error('Unable to generate HTML body for {name} subscription.', [
        'name' => $subscription_name,
      ]);
      return FALSE;
    }

    switch ($this->config('reliefweb_subscriptions.settings')->get('method')) {
      case 'smtp':
        return $this->sendViaSmtp($subscription, $subject, $html, $subscribers);

      case 'aws_ses_api_bulk':
        return $this->sendViaAwsSesApiBulk($subscription, $subject, $html, $subscribers);

      default:
        $this->logger->error('Invalid send mode.');
    }

    return FALSE;
  }

  /**
   * Optimize bulk email template.
   *
   * This tries to use placeholders for elements that repeated many times in
   * order to reduce the size of the email template as there is a limit of
   * 500 KB and ~ 250KB for replacements.
   *
   * @param string $html
   *   HTML version of the body of the email.
   * @param array $replacements
   *   Key/value array of placeholder replacements.
   *
   * @return string
   *   The transformed HTML content.
   */
  protected function optimizeEmailTemplate($html, array &$replacements) {
    // Extract the styles. We'll use variable replacements for those so we can
    // reduce a lot the size of the email content.
    $html = preg_replace_callback('/styles="[^"]+"/', function ($match) use ($replacements) {
      $style = $match[0];
      if (!isset($replacements[$style])) {
        $replacement = 'r' . count($replacements);
        $replacements[$style] = $replacement;
      }
      else {
        $replacement = $replacements[$style];
      }
      return '{{' . $replacement . '}}';
    }, $html);

    // Replace the `https://reliefweb.int` start from the URLs with a
    // placeholder to further reduce the email size.
    $html = str_replace('https://reliefweb.int/', '{{rw}}', $html);
    $replacements['rw'] = 'https://reliefweb.int/';

    // Replace the unsubscribe link with a placeholder.
    $html = str_replace('@unsubscribe', '{{unsubscribe}}', $html);

    return $html;
  }

  /**
   * Send the emails via SMTP.
   *
   * @param array $subscription
   *   Subscription.
   * @param string $subject
   *   Email subject.
   * @param string|\Drupal\Component\Render\FormattableMarkup $html
   *   Email content.
   * @param array $subscribers
   *   Subscribers.
   *
   * @return bool
   *   False if the sending failed.
   */
  protected function sendViaSmtp(array $subscription, $subject, $html, array $subscribers) {
    $sid = $subscription['id'];
    $from = $this->getFromAddress();
    $language = $this->languageDefault->get()->getId();

    // Number of emails to send by second.
    $batch_size = $this->state->get('reliefweb_subscriptions_mail_batch_size', 40);

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

        // Update the HTML body with the unique unsubscribe link.
        $mail_body = new FormattableMarkup($html, [
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
   * Send the emails via the AWS SES API.
   *
   * @param array $subscription
   *   Subscription.
   * @param string $subject
   *   Email subject.
   * @param string|\Drupal\Component\Render\FormattableMarkup $html
   *   Email content.
   * @param array $subscribers
   *   Subscribers.
   *
   * @return bool
   *   False if the sending failed.
   */
  protected function sendViaAwsSesApiBulk(array $subscription, $subject, $html, array $subscribers) {
    $subscription_name = $subscription['name'];
    $template_name = $subscription['aws_ses_template_name'];
    $from = $this->getFromAddress();

    // Retrieve the send rate.
    try {
      $send_rate = $this->awsSesClient->getSendRate();
    }
    catch (\Exception $exception) {
      $this->logger->error('Unable to retrieve the max send rate.');
      return FALSE;
    }

    // Retrieve the number of emails to send for each bulk request.
    $bulk_batch_size = $this->config('reliefweb_subscriptions.settings')->get('aws_ses_api_bulk_batch_size') ?? 1;
    $bulk_batch_size = min($send_rate, $bulk_batch_size);

    // Optimize the HTML body of the email.
    $replacements = [];
    $html = $this->optimizeEmailTemplate($html, $replacements);

    // Generate the text version of the email.
    $text = MailHelper::getPlainText($html);

    // Create the template.
    try {
      $this->awsSesClient->createTemplate(
        $template_name,
        $subject,
        $html,
        $text
      );
    }
    catch (\Exception $exception) {
      $this->logger->error('Error create template for {name} subscription: {error}', [
        'name' => $subscription_name,
        'error' => $exception->getMessage(),
      ]);
      return FALSE;
    }

    $this->logger->info('Sending {subject} notification to {subscribers} subscribers.', [
      'subject' => $subject,
      'subscribers' => count($subscribers),
    ]);

    // Batch the subscribe list, so we can throttle if it looks like
    // we will go over our allowed rate limit.
    foreach (array_chunk($subscribers, $send_rate) as $batch) {
      // Record the start of the batch sending so we can throttle if we go
      // too fast for our AWS rate limit.
      $timer_start = microtime(TRUE);

      // Split by the maximum number of recipients per bulk email.
      foreach (array_chunk($batch, $bulk_batch_size) as $bulk_batch) {
        $destinations = [];
        foreach ($bulk_batch as $subscriber) {
          $destinations[] = [
            'recipient' => $subscriber->mail,
            'replacements' => [
              'unsubscribe' => $this->generateUnsubscribeLink($subscriber->uid, $sid),
            ],
          ];
        }

        // AWS SES doesn't allow to customize headers when using the bulk
        // endpoint so we cannot set the List-Id, List-Unsubscribe and
        // X-RW-Category headers.
        try {
          $results = $this->awsSesClient->sendBulkEmail(
            $from,
            $template_name,
            $destinations,
            $replacements
          );
          foreach ($destinations as $index => $destination) {
            if (!isset($results[$index])) {
              $error = 'email not processed';
            }
            elseif (!empty($results[$index]['error'])) {
              $error = $results[$index]['error'];
            }
            else {
              $error = '';
            }
            if (!empty($error)) {
              $this->logger->error('Unable to send {name} subscription email to {recipient}: {error}', [
                'name' => $subscription_name,
                'recipient' => $destination['recipient'],
                'error' => $error,
              ]);
            }
          }
        }
        catch (\Exception $exception) {
          $this->logger->error('Unable to send {name} subscription emails: {error}', [
            'name' => $subscription_name,
            'error' => $exception->getMessage(),
          ]);
        }
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
    $separator = ' / ';
    $separator_length = 3;

    // Extract the titles.
    foreach ($items as $item) {
      $title = $item['title'] ?? $item['headline']['title'] ?? '';
      if (!empty($title)) {
        $titles[] = HtmlSummarizer::sanitizeText($title);
      }
    }

    // Ensure the preheader is no longer than 100 characters (+ ellipsis).
    $titles = HtmlSummarizer::summarizeParagraphs($titles, $length, $separator_length);

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
      $item['summary'] = HtmlSummarizer::summarize($body, 400, FALSE);
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
            'advanced-search' => '(F4)',
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
    $preheader = HtmlSummarizer::summarize($variables['#overview'], 100, TRUE);
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
    $variables['#summary'] = HtmlSummarizer::summarize($body, 400, FALSE);

    // Preheader with a maximum of 100 characters.
    $preheader = HtmlSummarizer::summarize($body, 100, TRUE);
    $variables['#preheader'] = $preheader;

    $country_name = $data['primary_country']['name'];
    $country_id = $data['primary_country']['id'];

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
            // Country facet filter.
            'advanced-search' => '(C' . $country_id . ')',
          ],
        ],
      ],
      [
        'text' => 'OCHA Situation Reports',
        'link' => '/updates',
        'options' => [
          'query' => [
            // Source facet filter: 'OCHA'.
            // Content format facet filter: 'Situation Report'.
            'advanced-search' => '(S1503)_(F10)',
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
      $item['summary'] = HtmlSummarizer::summarize($body, 400, FALSE);

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
            'advanced-search' => '(C' . $country_id . ')',
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
    $query->condition('s.sid', $sid, '=');
    $query->innerJoin('users_field_data', 'u', 'u.uid = s.uid');
    $query->fields('u', ['name', 'mail']);
    $query->innerJoin('user__field_email_confirmed', 'fec', 'fec.entity_id = s.uid');
    $query->condition('fec.field_email_confirmed_value', 1, '=');
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
   * Queue scheduled notifications.
   *
   * @param array $sids
   *   Ids of the subscriptions to queue. If empty, retrieve all the scheduled
   *   subscriptions.
   * @param int $last
   *   Timestamp of the last time the notifications are considered to have
   *   been sent. If empty, get the timestamps from the database.
   */
  public function queueScheduled(array $sids = [], $last = 0) {
    $subscriptions = reliefweb_subscriptions_subscriptions();

    // Retrieve all the scheduled subscriptions if none is provided.
    if (empty($sids)) {
      foreach ($subscriptions as $sid => $subscription) {
        if ($subscription['type'] === 'scheduled') {
          $sids[] = $sid;
        }
      }
    }
    else {
      $sids = array_filter($sids, function ($sid) use ($subscriptions) {
        return isset($subscriptions[$sid]);
      });
    }

    // Skip if there are no subscribers for the subscription.
    $sids = $this->getSubscriptionsWithSubscribers($sids);
    if (empty($sids)) {
      $this->logger->warning('No subscribers for scheduled notifications, skipping');
      return;
    }

    // Skip if the subscriptipon is already queued.
    $sids = $this->getSubscriptionsNotYetQueued($sids);
    if (empty($sids)) {
      $this->logger->warning('No scheduled notifications to queue, skipping');
      return;
    }

    if (empty($last)) {
      // Get the last and next run timestamps for the subscriptions.
      $query = $this->database->select('reliefweb_subscriptions_logs', 'l');
      $query->fields('l', ['sid', 'last', 'next']);
      $query->condition('l.sid', $sids, 'IN');
      $result = $query->execute();
      $timestamps = !empty($result) ? $result->fetchAllAssoc('sid') : [];
    }
    else {
      $timestamps = [];
      foreach ($sids as $sid) {
        $timestamps[$sid] = (object) [
          'last' => $last,
          'next' => $last,
        ];
      }
    }

    $notifications = [];
    $request_time = $this->time->getRequestTime();
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
      if ($next < $request_time) {
        $notifications[] = [
          'sid' => $sid,
          'last' => $last,
        ];
      }
    }

    // Queue notifications for all the subscriptions.
    $this->queueNotifications($notifications);
  }

  /**
   * Queue subscriptions for entity events (insert, update).
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @todo update logic to be compatible with Drupal 9 and add trigger
   * callbacks.
   */
  public function queueTriggered(EntityInterface $entity) {
    // Skip if explicitly asked to not send notifications.
    if (!empty($entity->notifications_content_disable)) {
      $this->logger->notice('Notifications disabled for the @bundle entity @id.', [
        '@bundle' => $entity->bundle(),
        '@id' => $entity->id(),
      ]);
      return;
    }

    $subscriptions = reliefweb_subscriptions_subscriptions();

    // Get triggered type subscriptions for this bundle.
    $sids = [];
    foreach ($subscriptions as $sid => $subscription) {
      if ($subscription['type'] === 'triggered' && $subscription['bundle'] === $entity->bundle()) {
        // Check if the subscription trigger is met for the entity.
        if (!isset($subscription['trigger']) || call_user_func([
          $this,
          $subscription['trigger'],
        ], $entity)) {
          $sids[] = $sid;
        }
        else {
          $this->logger->notice('Triggering criteria not met for the @bundle entity @id.', [
            '@bundle' => $entity->bundle(),
            '@id' => $entity->id(),
          ]);
        }
      }
    }

    // Skip if no subscriptions were found for the entity.
    if (empty($sids)) {
      $this->logger->notice('No Notifications to queue for the @bundle entity @id.', [
        '@bundle' => $entity->bundle(),
        '@id' => $entity->id(),
      ]);
      return;
    }

    // Skip if there are no subscriptions with subscribers.
    $sids = $this->getSubscriptionsWithSubscribers($sids);
    if (empty($sids)) {
      $this->logger->notice('No subscribers for @bundle notifications.', [
        '@bundle' => $entity->bundle(),
      ]);
      return;
    }

    // Skip if there are no new notifications to queue.
    $sids = $this->getSubscriptionsNotYetQueued($sids, $entity->bundle(), $entity->id());
    if (empty($sids)) {
      $this->logger->notice('Notifications for the @bundle entity @id already queued.', [
        '@bundle' => $entity->bundle(),
        '@id' => $entity->id(),
      ]);
      return;
    }

    $notifications = [];
    foreach ($sids as $sid) {
      $notifications[] = [
        'sid' => $sid,
        'bundle' => $entity->bundle(),
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
    $query->condition('s.sid', $sids, 'IN');
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
    $query->condition('q.sid', $sids, 'IN');

    if (!empty($bundle) && !empty($entity_id)) {
      $query->condition('q.bundle', $bundle, '=');
      $query->condition('q.entity_id', $entity_id, '=');
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
      $this->logger->notice('No notifications to queue.');
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
   * Generate a preview.
   */
  public function generatePreview($sid) {
    $subscriptions = reliefweb_subscriptions_subscriptions();
    $subscription = $subscriptions[$sid];

    // For scheduled notifications, we retrieve the content published after
    // the last time the notifications were sent.
    if ($subscription['type'] === 'scheduled') {
      // Get the last time notifications for this subscription were sent.
      $last = $this->database
        ->select('reliefweb_subscriptions_logs', 'l')
        ->fields('l', ['last'])
        ->condition('l.sid', $sid, '=')
        ->range(0, 1)
        ->execute()
        ?->fetchField();

      // If notifications were never sent for this subscription we retrieve
      // what would have been the last sending time based on the subscription's
      // schedule.
      if (empty($last)) {
        $last = $this->getPreviousSendingTime($subscription);
      }
      $notification = [
        'sid' => $sid,
        'last' => $last,
      ];
      $data = $this->getScheduledNotificationData((object) $notification, $subscription);
    }
    // For triggered noticications, we use fake data by retrieving the most
    // recent entity matching the criteria (ex: latest ongoing disaster).
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
   *
   * @param \Drupal\reliefweb_entities\Entity\Report $report
   *   Sitrep report.
   */
  protected function triggerOchaSitrepNotification(Report $report) {
    if ($report->bundle() !== 'report') {
      return FALSE;
    }

    // Check if the source is OCHA (id: 1503).
    if (!$report->field_source->isEmpty()) {
      $found = FALSE;
      foreach ($report->field_source as $item) {
        if ($item->target_id == 1503) {
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        return FALSE;
      }
    }

    // Check if the format is Situation Report (id: 10).
    if (!$report->field_content_format->isEmpty()) {
      $found = FALSE;
      foreach ($report->field_content_format as $item) {
        if ($item->target_id == 10) {
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        return FALSE;
      }
    }

    // Get the status of the entity.
    $status = $report->getModerationStatus();

    // For a newly created entity, check the current status.
    if ($report->isNew()) {
      return in_array($status, ['published', 'to-review']);
    }
    // Otherwise compare with the status of the previous revision.
    else {
      // When this is called in a an hook_entity_update, then the previous
      // revision is stored as the "original" property. Otherwise, for example,
      // when queueing via drush, then we load the previous revision.
      $previous = $report->original ?? $this->loadPreviousEntityRevision($report);

      // If there is no previous revision, check the current status.
      if ($previous === $report) {
        return in_array($status, ['published', 'to-review']);
      }

      // Queue only if the document was not previously already published to
      // avoid sending multiple times notifications for the document.
      $previous_status = $previous->getModerationStatus();
      return $status !== $previous_status &&
        in_array($status, ['published', 'to-review']) &&
        !in_array($previous_status, ['published', 'to-review']);
    }

    return FALSE;
  }

  /**
   * Trigger for disasters.
   *
   * @param \Drupal\reliefweb_entities\Entity\Disaster $disaster
   *   Disaster.
   */
  protected function triggerDisasterNotification(Disaster $disaster) {
    if ($disaster->bundle() !== 'disaster') {
      return FALSE;
    }

    // Get the status of the entity.
    $status = $disaster->getModerationStatus();

    // For a newly created entity, check the current status.
    if ($disaster->isNew()) {
      return in_array($status, ['alert', 'ongoing']);
    }
    else {
      // When this is called in a an hook_entity_update, then the previous
      // revision is stored as the "original" property. Otherwise, for example,
      // when queueing via drush, then we load the previous revision.
      $previous = $report->original ?? $this->loadPreviousEntityRevision($disaster);

      // If there is no previous revision, check the current status.
      if ($previous === $disaster) {
        return in_array($status, ['alert', 'ongoing']);
      }

      // Allow queueing notifications for the disaster from different previous
      // states (ex: draft, alert, past etc.). This allows notably to send
      // a notification when the disaster changes status from draft to alert
      // and from alert to ongoing.
      $previous_status = $previous->getModerationStatus();
      return $status !== $previous_status &&
        in_array($status, ['alert', 'ongoing']);
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
   * Load the previous revision of an entity.
   *
   * @param \Drupal\Core\Entity\RevisionableInterface $entity
   *   Entity.
   *
   * @return \Drupal\Core\Entity\RevisionableInterface
   *   Entity revision.
   */
  protected function loadPreviousEntityRevision(RevisionableInterface $entity) {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $entity_type = $storage->getEntityType();
    $id_key = $entity_type->getKey('id');
    $revision_key = $entity_type->getKey('revision');
    $revision_table = $storage->getRevisionTable();

    // We only retrieve the previous revision id if any.
    $previous_revision_id = $this->database
      ->select($revision_table, 'r')
      ->fields('r', [$revision_key])
      ->condition('r.' . $id_key, $entity->id(), '=')
      ->orderBy($revision_key, 'DESC')
      ->range(1, 1)
      ->execute()
      ?->fetchField();

    // Load the previous revision.
    if (!empty($previous_revision_id)) {
      return $storage->loadRevision($previous_revision_id);
    }
    return $entity;
  }

}
