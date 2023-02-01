<?php

namespace Drupal\reliefweb_reporting\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageDefault;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drush\Commands\DrushCommands;

/**
 * ReliefWeb Reporting Drush commands.
 */
class ReliefWebReportingCommands extends DrushCommands {

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
   * The mail manager.
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
   * The state manager.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    Connection $database,
    MailManagerInterface $mail_manager,
    LanguageDefault $language_default,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state
  ) {
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->mailManager = $mail_manager;
    $this->languageDefault = $language_default;
    $this->logger = $logger_factory->get('reliefweb_reporting');
    $this->state = $state;
  }

  /**
   * Send weekly statistics about the job posting.
   *
   * @param string $recipients
   *   Comma delimited list of recipients for the report email.
   *
   * @command reliefweb_reporting:send-weekly-job-stats
   *
   * @usage reliefweb_reporting:send-weekly-job-stats
   *   Send weekly statistics about the job posting.
   *
   * @validate-module-enabled reliefweb_reporting
   */
  public function sendWeeklyJobStats($recipients) {
    if (!empty($this->state->get('system.maintenance_mode', 0))) {
      $this->logger()->warning(dt('Maintenance mode, aborting.'));
      return TRUE;
    }

    if (empty($recipients)) {
      $this->logger()->error(dt('Missing recipients.'));
      return FALSE;
    }

    $from = $this->configFactory->get('system.site')->get('mail') ?? ini_get('sendmail_from');
    if (empty($from)) {
      $this->logger()->error(dt('Missing from address.'));
      return FALSE;
    }

    // Format the from to include ReliefWeb if not already.
    if (strpos($from, '<') === FALSE) {
      $from = strtr('@sitename <@sitemail>', [
        '@sitename' => $this->configFactory->get('system.site')->get('name') ?? 'ReliefWeb',
        '@sitemail' => $from,
      ]);
    }

    $recipients = implode(', ', preg_split('/,\s*/', trim($recipients)));

    $last_sunday = strtotime('last sunday', time());
    $last_week_sunday = strtotime('last sunday', $last_sunday);
    $formatted_last_sunday = gmdate('Y-m-d', $last_sunday);
    $formatted_last_week_sunday = gmdate('Y-m-d', $last_week_sunday);

    $subject = "Weekly job stats - $formatted_last_week_sunday to $formatted_last_sunday";
    $headers = [
      'From' => $from,
      'Reply-To' => $from,
      'Content-Type' => 'text/html; charset=utf-8',
      'MIME-Version' => '1.0',
    ];

    $body = '<!DOCTYPE html>';
    $body .= '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    $body .= '<title>' . $subject . '</title></head><body>';
    $body .= '<h1 style="font-size: 18px;">' . $subject . '</h1>';

    $records = $this->database->query("
      SELECT
        q.name AS name,
        COUNT(q.nid) AS published
      FROM (
        SELECT
          n.nid AS nid,
          IF(ur.roles_target_id = 'editor', u.name, 'Trusted users') AS name
        FROM {node} AS n
        INNER JOIN {node_revision} AS nr
          ON nr.nid = n.nid
        INNER JOIN {node_field_revision} AS nfr
          ON nfr.vid = nr.vid AND nfr.moderation_status = 'published'
        INNER JOIN {users_field_data} AS u
          ON u.uid = nr.revision_uid
        LEFT JOIN {user__roles} AS ur
          ON ur.entity_id = u.uid
        WHERE n.type = 'job'
          AND nr.revision_timestamp >= UNIX_TIMESTAMP(DATE_SUB(DATE(NOW()), INTERVAL DAYOFWEEK(NOW()) + 6 DAY))
          AND nr.revision_timestamp < UNIX_TIMESTAMP(DATE_SUB(DATE(NOW()), INTERVAL DAYOFWEEK(NOW()) - 1 DAY))
          GROUP BY n.nid
        UNION
        SELECT n.nid AS nid,
        'Imported jobs' AS name
        FROM {node} AS n
        INNER JOIN {node_revision} AS nr
          ON nr.nid = n.nid
        WHERE n.type = 'job'
          AND nr.revision_log LIKE '% imported from %'
          AND nr.revision_timestamp >= UNIX_TIMESTAMP(DATE_SUB(DATE(NOW()), INTERVAL DAYOFWEEK(NOW()) + 6 DAY))
          AND nr.revision_timestamp < UNIX_TIMESTAMP(DATE_SUB(DATE(NOW()), INTERVAL DAYOFWEEK(NOW()) - 1 DAY))
        GROUP BY n.nid
      ) AS q
      GROUP BY q.name
      ORDER BY FIELD(q.name, 'Trusted users', 'Imported jobs') ASC, name ASC
    ");

    if (empty($records)) {
      $body .= '<p>No jobs posted this week.</p>';
    }
    else {
      $body .= '<table border="1"><thead><tr><th>name</th><th>published</th></tr><thead><tbody>';
      foreach ($records as $record) {
        $body .= '<tr><td>' . $record->name . '</td><td>' . $record->published . '</td></tr>';
      }
      $body .= '</tbody></table>';
    }
    $body .= '</body></html>';

    // Send the email.
    if (mail($recipients, $subject, $body, $headers)) {
      $this->logger->info(dt('"@subject" sent to @recipients', [
        '@subject' => $subject,
        '@recipients' => $recipients,
      ]));
    }
    else {
      $this->logger->error(dt('Unable to send "@subject" to @recipients', [
        '@subject' => $subject,
        '@recipients' => $recipients,
      ]));
    }

    return TRUE;
  }

  /**
   * Send weekly statistics about the report posting.
   *
   * @param string $recipients
   *   Comma delimited list of recipients for the report email.
   *
   * @command reliefweb_reporting:send-weekly-report-stats
   *
   * @usage reliefweb_reporting:send-weekly-report-stats
   *   Send weekly statistics about the report posting.
   *
   * @validate-module-enabled reliefweb_reporting
   */
  public function sendWeeklyReportStats($recipients) {
    if (!empty($this->state->get('system.maintenance_mode', 0))) {
      $this->logger()->warning(dt('Maintenance mode, aborting.'));
      return TRUE;
    }

    if (empty($recipients)) {
      $this->logger()->error(dt('Missing recipients.'));
      return FALSE;
    }

    $from = $this->configFactory->get('system.site')->get('mail') ?? ini_get('sendmail_from');
    if (empty($from)) {
      $this->logger()->error(dt('Missing from address.'));
      return FALSE;
    }

    // Format the from to include ReliefWeb if not already.
    if (strpos($from, '<') === FALSE) {
      $from = strtr('@sitename <@sitemail>', [
        '@sitename' => $this->configFactory->get('system.site')->get('name') ?? 'ReliefWeb',
        '@sitemail' => $from,
      ]);
    }

    $recipients = implode(', ', preg_split('/,\s*/', trim($recipients)));

    $last_sunday = strtotime('last sunday', time());
    $last_week_sunday = strtotime('last sunday', $last_sunday);
    $formatted_last_sunday = gmdate('Y-m-d', $last_sunday);
    $formatted_last_week_sunday = gmdate('Y-m-d', $last_week_sunday);

    $filename = "report-stats-$formatted_last_week_sunday-$formatted_last_sunday.csv";
    $subject = "Weekly report stats - $formatted_last_week_sunday to $formatted_last_sunday";
    $headers = [
      'From' => $from,
      'Reply-To' => $from,
      'Content-Type' => 'multipart/mixed; boundary=GvXjxJ+pjyke8COw',
      'Content-Disposition' => 'inline',
      'MIME-Version' => '1.0',
    ];

    $records = $this->database->query("
      SELECT
        n.nid AS id,
        n.title AS title,
        CONCAT('https://reliefweb.int/node/', n.nid) AS url,
        n.moderation_status AS status,
        CASE
          WHEN fo.field_origin_value = 0 THEN 'URL'
          WHEN fo.field_origin_value = 1 THEN 'Submit'
          ELSE 'ReliefWeb'
        END AS origin,
        IFNULL(fon.field_origin_notes_value, '-') AS origin_url,
        FROM_UNIXTIME(n.created, '%Y-%m-%dT%TZ') AS created,
        u.name AS editor,
        u.uid AS editor_id
      FROM {node_field_data} AS n
      INNER JOIN {users_field_data} AS u
        ON u.uid = n.uid
      INNER JOIN {node__field_origin} AS fo
        ON fo.entity_id = n.nid
      LEFT JOIN {node__field_origin_notes} AS fon
        ON fon.entity_id = n.nid
      WHERE n.type = 'report'
        AND n.created >= UNIX_TIMESTAMP(DATE_SUB(DATE(NOW()), INTERVAL DAYOFWEEK(NOW()) + 6 DAY))
        AND n.created < UNIX_TIMESTAMP(DATE_SUB(DATE(NOW()), INTERVAL DAYOFWEEK(NOW()) - 1 DAY))
      ORDER BY n.nid DESC
    ")->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($records)) {
      $body = 'No reports posted this week.';
    }
    else {
      // Convert to CSV.
      $handle = fopen('php://memory', 'r+');
      foreach ($records as $index => $record) {
        if ($index === 0) {
          fputcsv($handle, array_keys($record), "\t");
        }
        fputcsv($handle, array_values($record), "\t");
      }
      rewind($handle);
      $csv = trim(stream_get_contents($handle));
      fclose($handle);

      $body = implode("\n", [
        '--GvXjxJ+pjyke8COw',
        'Content-Type: text/html',
        'Content-Disposition: inline',
        '',
        "Attachment: $filename",
        '',
        '--GvXjxJ+pjyke8COw',
        'Content-Type: text/csv',
        "Content-Disposition: attachment; filename=$filename",
        '',
        $csv,
      ]);
    }

    // Send the email.
    if (mail($recipients, $subject, $body, $headers)) {
      $this->logger->info(dt('"@subject" sent to @recipients', [
        '@subject' => $subject,
        '@recipients' => $recipients,
      ]));
    }
    else {
      $this->logger->error(dt('Unable to send "@subject" to @recipients', [
        '@subject' => $subject,
        '@recipients' => $recipients,
      ]));
    }

    return TRUE;
  }

}
