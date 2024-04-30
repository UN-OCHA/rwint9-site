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
   *
   * @command reliefweb_subscriptions:send
   *
   * @usage reliefweb_subscriptions:send
   *   Send emails.
   *
   * @aliases reliefweb-subscriptions-send
   *
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

    // Send the notifications.
    $notifications = $query->execute()?->fetchAllAssoc('eid');
    $this->mailer->send($notifications);

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
  public function queue(
    $sid,
    array $options = [
      'entity_type' => '',
      'entity_id' => 0,
      'last' => 0,
    ],
  ) {
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
  public function unsubscribe(
    $frequency = '1w',
    array $options = [
      'dry-run' => FALSE,
    ],
  ) {
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

  /**
   * Subscribe user accounts to a mailing list.
   *
   * @param string $sids
   *   Subscription IDs separated by a comma.
   * @param string $file
   *   File with a list of email addresses (one per line). Defaults to the
   *   standard input.
   * @param array $options
   *   Drush options.
   *
   * @return bool
   *   TRUE if successful.
   *
   * @command reliefweb_subscriptions:subscribe
   *
   * @option batch_size The number of emails to process at once, (defaul: 200).
   *
   * @default options [
   *   'batch_size' => 500,
   * ]
   *
   * @command reliefweb_subscriptions:subscribe-users
   *
   * @usage reliefweb_subscriptions:subscribe-users headlines,appeals /tmp/emails.txt
   *   Subscribe the users with the emails from the emails.txt file to the
   *   headlines and appeals mailing list.
   *
   * @validate-module-enabled reliefweb_subscriptions
   */
  public function subscribeUsers(
    string $sids,
    string $file = 'php://stdin',
    array $options = [
      'batch_size' => 200,
    ],
  ): bool {
    if ($file !== 'php://stdin' && !file_exists($file)) {
      $this->logger()->error(strtr('Missing file: @file', [
        '@file' => $file,
      ]));
      return FALSE;
    }

    $sids = explode(',', $sids);
    $subscriptions = reliefweb_subscriptions_subscriptions();

    $subscribed = [];
    foreach ($sids as $sid) {
      if (isset($subscriptions[$sid])) {
        $subscribed[$sid] = 0;
      }
      else {
        $this->logger()->warning(strtr('Unknow @sid subscription', [
          '@sid' => $sid,
        ]));
      }
    }

    if (empty($sids)) {
      $this->logger()->error('No valid subscription IDs');
      return FALSE;
    }

    $batch_size = $options['batch_size'];

    $handle = fopen($file, 'r');
    if (is_resource($handle)) {
      while (!feof($handle)) {
        $email = fgets($handle);
        $email = $email !== FALSE ? trim($email) : '';
        if (!empty($email)) {
          $emails[$email] = $email;
        }
        if (count($emails) === $batch_size) {
          foreach ($this->doSubscribeUsers($sids, $emails) as $sid => $count) {
            $subscribed[$sid] += $count;
          }
          $emails = [];
        }
      }
      if (!empty($emails)) {
        foreach ($this->doSubscribeUsers($sids, $emails) as $sid => $count) {
          $subscribed[$sid] += $count;
        }
      }
      fclose($handle);

      foreach ($subscribed as $sid => $count) {
        if ($count > 0) {
          $this->logger()->success(strtr('Subscribed @count accounts to the @sid mailing list.', [
            '@count' => $count,
            '@sid' => $sid,
          ]));
        }
        else {
          $this->logger()->success(strtr('No new subscriptions to create for the @sid mailing list', [
            '@sid' => $sid,
          ]));
        }
      }
    }
    else {
      $this->logger()->error(strtr('Unable to read file: @file', [
        '@file' => $file,
      ]));
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Subscribe a list of email address to subscription lists.
   *
   * @param array<string> $sids
   *   Subscription IDs.
   * @param array<string> $emails
   *   Email list.
   *
   * @return array<string,int>
   *   Number of subscribed accounts per subscription ID.
   */
  protected function doSubscribeUsers(array $sids, array $emails): array {
    $uids = $this->database
      ->select('users_field_data', 'u')
      ->fields('u', ['uid'])
      ->condition('u.mail', $emails, 'IN')
      ->execute()
      ?->fetchCol() ?? [];

    $subscribed = [];
    if (!empty($uids)) {
      foreach ($sids as $sid) {
        $query = $this->database
          ->upsert('reliefweb_subscriptions_subscriptions')
          // Note: not used for MySQL but is needed to avoid an exception.
          ->key('sid_uid')
          ->fields(['sid', 'uid']);

        foreach ($uids as $uid) {
          $query->values(['sid' => $sid, 'uid' => $uid]);
        }

        $subscribed[$sid] = $query->execute();
      }
    }

    return $subscribed;
  }

  /**
   * Enable link tracking for subscriptions.
   *
   * @param string $sids
   *   Comma separated list of subscription ids to track or untrack. Use `all`
   *   to enable link tracking of all the subscriptions. Use `countries` to
   *   enable tracking on all the country based subscriptions. Otherwise use
   *   individual subscription ids.
   *
   * @command reliefweb_subscriptions:enable-link-tracking
   *
   * @usage reliefweb_subscriptions:enable-link-tracking headlines,appeals
   *   Enable link tracking for headlines and appeals.
   *
   * @validate-module-enabled reliefweb_subscriptions
   */
  public function enableLinkTracking($sids = 'all') {
    $this->mailer->toggleLinkTracking(TRUE, explode(',', $sids));
  }

  /**
   * Disable link tracking for subscriptions.
   *
   * @param string $sids
   *   Comma separated list of subscription ids to track or untrack. Use `all`
   *   to disable link tracking of all the subscriptions. Use `countries` to
   *   disable tracking on all the country based subscriptions. Otherwise use
   *   individual subscription ids.
   *
   * @command reliefweb_subscriptions:disable-link-tracking
   *
   * @usage reliefweb_subscriptions:disable-link-tracking headlines,appeals
   *   Disable link tracking for headlines and appeals.
   *
   * @validate-module-enabled reliefweb_subscriptions
   */
  public function disableLinkTracking($sids = 'all') {
    $this->mailer->toggleLinkTracking(FALSE, explode(',', $sids));
  }

}
