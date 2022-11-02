<?php

namespace Drupal\reliefweb_subscriptions\Command;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Drupal\reliefweb_subscriptions\ReliefwebSubscriptionsMailer;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;

/**
 * ReliefWeb Subscriptions Drush commandfile.
 */
class ReliefwebSubscriptionsSendCommand extends DrushCommands implements SiteAliasManagerAwareInterface {

  // Drush traits.
  use ProcessManagerAwareTrait;
  use SiteAliasManagerAwareTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The actual mailer.
   *
   * @var \Drupal\reliefweb_subscriptions\ReliefwebSubscriptionsMailer
   */
  protected $mailer;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    Connection $database,
    ClientInterface $http_client,
    ReliefwebSubscriptionsMailer $mailer,
  ) {
    $this->database = $database;
    $this->httpClient = $http_client;
    $this->mailer = $mailer;
  }

  /**
   * Send notifications.
   *
   * @param int $limit
   *   Max number of items to send.
   * @param array $options
   *   Additional options for the command.
   *
   * @command reliefweb_subscriptions:send
   *
   * @option from From email address.
   *
   * @default $options []
   *
   * @usage reliefweb_subscriptions:send
   *   Send emails.
   *
   * @aliases reliefweb-subscriptions-send
   *
   * @validate-module-enabled reliefweb_subscriptions
   */
  public function send($limit = 50, array $options = [
    'from' => '',
  ]) {
    // Get queued notifications, older first.
    // Triggered notifications have priority.
    $query = $this->database->select('reliefweb_subscriptions_queue', 'q');
    $query->fields('q', ['eid', 'sid', 'bundle', 'entity_id', 'last']);
    $query->addExpression('IF(q.entity_id > 0, 1, 0)', 'sortby');
    $query->orderBy('sortby', 'DESC');
    $query->orderBy('q.eid', 'ASC');
    $query->range(0, $limit);

    // Send the notifications.
    $notifications = $query->execute()?->fetchAllAssoc('eid');
    $this->mailer->send($notifications, $options['from'] ?? '');

    // Remove the processed notifications from the queue.
    if (!empty($notifications)) {
      $query = $this->database->delete('reliefweb_subscriptions_queue');
      $query->condition('eid', array_keys($notifications), 'IN');
      $query->execute();
    }
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
   *
   * @usage reliefweb_subscriptions:queue
   *   Queue emails.
   *
   * @option entity_type
   *   Entity type.
   * @option entity_id
   *   Entity Id.
   * @option last
   *   Timestamp to use as the last time notifications were sent.
   *
   * @default options [
   *   'entity_type' => '',
   *   'entity_id' => 0,
   *   'last' => 0,
   * ]
   *
   * @aliases reliefweb-subscriptions-queue
   *
   * @validate-module-enabled reliefweb_subscriptions
   */
  public function queue($sid, array $options = [
    'entity_type' => '',
    'entity_id' => 0,
    'last' => 0,
  ]) {
    $this->mailer->queue($sid, $options);
  }

  /**
   * Unsubscribe bounced emails.
   *
   * @param string $frequency
   *   Timeframe for the data retrieved from ELK.
   * @param array $options
   *   Drush options.
   *
   * @command reliefweb_subscriptions:unsubscribe
   *
   * @option dry-run If set, subscriptions will not be deleted.
   *
   * @default options [
   *   'dry-run' => FALSE,
   * ]
   *
   * @usage reliefweb_subscriptions:unsubscribe
   *   unsubscribe emails.
   *
   * @aliases reliefweb-subscriptions-unsubscribe
   *
   * @validate-module-enabled reliefweb_subscriptions
   */
  public function unsubscribe($frequency = '1w', array $options = [
    'dry-run' => FALSE,
  ]) {
    $settings = Settings::get('ocha_elk_mail', []);
    if (empty($settings['url'])) {
      $this->logger()->error(dt('ELK url missing'));
      return FALSE;
    }

    $url = $settings['url'] . '/mail-*/_search';

    // Payload to retrieve the email addresses from the bounces and complaints.
    $payload = [
      '_source' => [
        'message.mail.destination',
      ],
      'size' => 10000,
      'sort' => [
        [
          '@timestamp' => [
            'order' => 'desc',
          ],
        ],
      ],
      'query' => [
        'bool' => [
          'must' => [
            [
              'match_phrase' => [
                'unocha.environment' => 'prod',
              ],
            ],
            [
              'match_phrase' => [
                'unocha.property' => 'rwint',
              ],
            ],
            [
              'bool' => [
                'should' => [
                  // Bounces.
                  [
                    'bool' => [
                      'must' => [
                        [
                          'match_phrase' => [
                            'message.notificationType' => 'Bounce',
                          ],
                        ],
                        [
                          'match_phrase' => [
                            'message.bounce.bounceType' => 'Permanent',
                          ],
                        ],
                      ],
                    ],
                  ],
                  // Complaints.
                  [
                    'bool' => [
                      'must' => [
                        [
                          'match_phrase' => [
                            'message.notificationType' => 'Complaint',
                          ],
                        ],
                        [
                          'match_phrase' => [
                            'message.complaint.complaintSubType' => 'OnAccountSuppressionList',
                          ],
                        ],
                      ],
                    ],
                  ],
                ],
              ],
            ],
            [
              'range' => [
                '@timestamp' => [
                  'gte' => 'now-' . $frequency,
                  'lt' => 'now',
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $options = [
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      'json' => $payload,
    ];

    // Basic authentication if defined.
    if (!empty($settings['username']) && !empty($settings['password'])) {
      $authorization = 'Basic ' . base64_encode($settings['username'] . ':' . $settings['password']);
      $options['headers']['Authorization'] = $authorization;
    }

    // Get the elasticsearch response.
    try {
      $response = $this->httpClient->post($url, $options);
    }
    catch (\Exception $exception) {
      $this->logger()->error(dt('Unable to query ELK: @message', [
        '@message' => $exception->getMessage(),
      ]));
      return FALSE;
    }

    if ($response->getStatusCode() != 200) {
      $this->logger()->error(dt('Unable to retrieve the email list: @error', [
        '@error' => $response->getReasonPhrase() ?? 'unknow error',
      ]));
      return FALSE;
    }

    // Decode the elasticsearch response.
    $data = Json::decode((string) $response->getBody());
    if (empty($data)) {
      $this->logger()->error(dt('Empty elasticsearch response'));
      return FALSE;
    }

    // Extract the email addresses.
    $emails = [];
    if (!empty($data['hits']['hits'])) {
      foreach ($data['hits']['hits'] as $hit) {
        if (isset($hit['_source']['message']['mail']['destination'][0])) {
          $email = $hit['_source']['message']['mail']['destination'][0];
          $emails[$email] = $email;
        }
      }
    }
    if (empty($emails)) {
      $this->logger()->info('No emails to unsubscribe');
      return TRUE;
    }

    // Get the list of user ids.
    $query = $this->database
      ->select('users_field_data', 'u')
      ->fields('u', ['uid'])
      ->distinct()
      ->condition('u.mail', $emails, 'IN');

    // No need to process users without subscriptions.
    $query->innerJoin('reliefweb_subscriptions_subscriptions', 's', 's.uid = u.uid');

    $ids = $query->execute()?->fetchCol();
    if (empty($ids)) {
      $this->logger()->info(dt('No matching accounts to unsubscribe'));
      return TRUE;
    }

    // Delete all the subscriptions for those email addresses.
    if (empty($options['dry-run'])) {
      $this->database->delete('reliefweb_subscriptions_subscriptions')
        ->condition('uid', $ids, 'IN')
        ->execute();
    }

    $this->logger()->info(dt('Unsubscribed @unsubscribed accounts from @emails emails', [
      '@unsubscribed' => count($ids),
      '@emails' => count($emails),
    ]));
    return TRUE;
  }

}
