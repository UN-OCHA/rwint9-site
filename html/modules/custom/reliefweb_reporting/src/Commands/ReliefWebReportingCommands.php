<?php

namespace Drupal\reliefweb_reporting\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageDefault;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_reporting\ApiIndexerResource\ReportExtended;
use Drush\Commands\DrushCommands;
use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use RWAPIIndexer\Database\DatabaseConnection;
use RWAPIIndexer\Elasticsearch;
use RWAPIIndexer\Options;
use RWAPIIndexer\Processor;
use RWAPIIndexer\Query as QueryHandler;
use RWAPIIndexer\References;

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
    StateInterface $state,
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
    $message = $this->sendMail($from, $recipients, $subject, $body, $headers);
    if (!empty($message['result'])) {
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
      'Content-Type' => 'text/plain; charset=utf-8',
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

    $attachments = [];

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

      $body = "Attachment: $filename";

      $attachments[] = [
        'filecontent' => $csv,
        'filename' => $filename,
        'filemime' => 'text/csv',
      ];
    }

    // Send the email.
    $message = $this->sendMail($from, $recipients, $subject, $body, $headers, $attachments);
    if (!empty($message['result'])) {
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
   * Send weekly statistics about the AI tagging.
   *
   * @param string $recipients
   *   Comma delimited list of recipients for the report email.
   *
   * @command reliefweb_reporting:send-weekly-ai-tagging-stats
   *
   * @usage reliefweb_reporting:send-weekly-ai-tagging-stats
   *   Send weekly statistics about the AI tagging.
   *
   * @validate-module-enabled reliefweb_reporting
   */
  public function sendWeeklyAiTaggingStats($recipients) {
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

    $filename = "job-ai-tagging-stats-$formatted_last_week_sunday-$formatted_last_sunday.csv";
    $subject = "Weekly job AI tagging stats - $formatted_last_week_sunday to $formatted_last_sunday";
    $headers = [
      'From' => $from,
      'Reply-To' => $from,
      'Content-Type' => 'text/plain; charset=utf-8',
      'Content-Disposition' => 'inline',
      'MIME-Version' => '1.0',
    ];

    $attachments = [];

    // Fetch the stats.
    $data = reliefweb_reporting_get_weekly_ai_tagging_stats();

    // Turn the stats into a CSV attachment if not empty.
    if (empty($data)) {
      $body = 'No jobs tagged by AI this week.';
    }
    else {
      // Convert to CSV.
      $handle = fopen('php://memory', 'r+');
      fputcsv($handle, array_keys($data), "\t");
      fputcsv($handle, array_values($data), "\t");
      rewind($handle);
      $csv = trim(stream_get_contents($handle));
      fclose($handle);

      $body = "Attachment: $filename";

      $attachments[] = [
        'filecontent' => $csv,
        'filename' => $filename,
        'filemime' => 'text/csv',
      ];
    }

    // Send the email.
    $message = $this->sendMail($from, $recipients, $subject, $body, $headers, $attachments);
    if (!empty($message['result'])) {
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
   * Send an email.
   *
   * @param string $from
   *   From address.
   * @param string $recipients
   *   Recipient addresses.
   * @param string $subject
   *   Email subjects.
   * @param string $body
   *   Email body.
   * @param array $headers
   *   Email headers.
   * @param array $attachments
   *   Optional attachments.
   *
   * @return array
   *   The message array with a `result` property indicating success or failture
   *   at the PHP level.
   */
  protected function sendMail(string $from, string $recipients, string $subject, string $body, array $headers, array $attachments = []) {
    $language = $this->languageDefault->get()->getId();
    return $this->mailManager->mail('reliefweb_reporting', 'reporting', $recipients, $language, [
      'headers' => $headers,
      'subject' => $subject,
      'body' => [$body],
      'attachments' => $attachments,
    ], $from, TRUE);
  }

  /**
   * Export report data to TSV format.
   *
   * This command exports report data created between the specified start and
   * end dates to a TSV file or standard output.
   *
   * @param string $start
   *   Starting date for the export (inclusive). Reports created after this date
   *   will be included. Defaults to "-1 month".
   * @param string $end
   *   End date for the export (inclusive). Reports created up to this date
   *   will be included. Defaults to "now".
   * @param array $options
   *   Additional options for the command (see below).
   *
   * @command reliefweb_reporting:export-report-data
   * @aliases rw-export-reports
   *
   * @option output
   *   The export TSV file path. Defaults to standard output (php://stdout).
   * @option batch-size
   *   The number of reports to retrieve in each DB request. Defaults to 1000.
   * @option limit
   *   The maxiumum number of reports to export. Defaults to no limit.
   * @option filter
   *   Filter for documents to retrieve. Format:
   *   'field1:value1,value2+field2:value1,value2'.
   * @option properties
   *   JSON-encoded associative array to override default property mappings.
   *   Keys are dot-notation paths of ReliefWeb API fields, values are custom
   *   TSV column headers. Example: '{"id":"Report ID",
   *   "date.created":"Creation Date"}'
   * @option extra-properties
   *   JSON-encoded associative array with extra properties to include in the
   *   export. See `replace-properties` option.
   * @option exclude-properties
   *   JSON-encoded associative array with extra properties to exclude from the
   *   export. See `replace-properties` option.
   * @option include-body
   *   Include the report body in the export. Defaults to FALSE.
   * @option gdrive-upload-folder
   *   Upload the generated report file to the GDrive folder with this ID.
   *   Expects the folder to exist.
   *   The GOOGLE_APPLICATION_CREDENTIALS environment variable needs to point
   *   at a valid JSON credential file or contain valid JSON credential data.
   *   Requires --output to be a file and not stdout.
   *
   * @default $options [
   *   'output' => 'php://stdout',
   *   'batch-size' => 1000,
   *   'limit' => NULL,
   *   'filter' => NULL,
   *   'properties' => NULL,
   *   'extra-properties' => NULL,
   *   'exclude-properties' => NULL,
   *   'include-body' => NULL,
   *   'gdrive-upload-folder' => NULL,
   * ]
   *
   * @usage reliefweb_reporting:export-report-data "2021-01-01T00:00:01+00:00" "now" --output=/tmp/report-data-export.tsv
   *   Export data from 2021 to now into /tmp/report-data-export.tsv.
   * @usage reliefweb_reporting:export-report-data "2021-01-01T00:00:01+00:00" "now" --output=/tmp/report-data-export.tsv --upload-grive-folder=9frh70y744yyr49
   *   Export data from 2021 to now into /tmp/report-data-export.tsv.
   * @usage reliefweb_reporting:export-report-data --filter="country:syria,yemen" --include-properties=id,title,date.created
   *   Export reports for Syria and Yemen, including only id, title, and
   *   creation date.
   *
   * @validate-module-enabled reliefweb_reporting
   */
  public function exportReportData(
    string $start = "-1 month",
    string $end = "now",
    array $options = [
      'output' => 'php://stdout',
      'batch-size' => 1000,
      'limit' => NULL,
      'filter' => NULL,
      'properties' => NULL,
      'extra-properties' => NULL,
      'exclude-properties' => NULL,
      'include-body' => NULL,
      'gdrive-upload-folder' => NULL,
    ],
  ): bool {
    $output = $options['output'] ?? 'php://stdout';
    $batch_size = (int) ($options['batch-size'] ?? 1000);

    // Are we uploading the result?
    $upload = (!empty($options['gdrive-upload-folder']) && $output != 'php://stdout');
    $credentials = getenv('GOOGLE_APPLICATION_CREDENTIALS');

    // Early exit if upload is requested but credentials are absent.
    if ($upload === TRUE && empty($credentials)) {
      $this->logger->error('Error: Upload requested but no credentials provided.');
      return FALSE;
    }

    $bundle = 'report';
    $entity_type = 'node';
    $index = 'reports';

    // Options to retrieve the report resources.
    $indexer_options = new Options(reliefweb_api_get_indexer_base_options());

    // Create the database connection.
    $dbname = $indexer_options->get('database');
    $host = $indexer_options->get('mysql-host');
    $port = $indexer_options->get('mysql-port');
    $dsn = "mysql:dbname={$dbname};host={$host};port={$port};charset=utf8";
    $user = $indexer_options->get('mysql-user');
    $password = $indexer_options->get('mysql-pass');
    $connection = new DatabaseConnection($dsn, $user, $password);

    // Create a new reference handler.
    $references = new References();

    // Create a new elasticsearch handler.
    $elasticsearch = new Elasticsearch(
      $indexer_options->get('elasticsearch'),
      $indexer_options->get('base-index-name'),
      $indexer_options->get('tag'),
    );

    // Create a new field processor object to prepare items before indexing.
    $processor = new Processor($indexer_options->get('website'), $connection, $references);

    // Create a new resource to get the report data.
    $resource = new ReportExtended(
      $bundle,
      $entity_type,
      $index,
      $elasticsearch,
      $connection,
      $processor,
      $references,
      $indexer_options,
      !empty($options['include-body']),
    );

    // Retrieve the timestamps from the start and end dates.
    $timezone = new \DateTimeZone('UTC');
    $start_date = new \DateTime($start, $timezone);
    $end_date = new \DateTime($end, $timezone);
    $start_timestamp = $start_date->getTimestamp();
    $end_timestamp = $end_date->getTimestamp();

    // We need to build a query handler to be able to apply the filter if any.
    $query_handler = new QueryHandler($connection, $entity_type, $bundle);

    // Base query to get the IDs of the reports for the given date range.
    $base_query = $query_handler->newQuery();
    $base_query->condition('node_field_data.type', $bundle, '=');
    $base_query->condition('node_field_data.created', $start_timestamp, '>=');
    $base_query->condition('node_field_data.created', $end_timestamp, '<=');

    // Add the extra conditions from the provided filter.
    if (!empty($options['filter'])) {
      $conditions = $resource->parseFilters($options['filter']);
      $query_handler->setFilters($base_query, $conditions);
    }

    // Get the total of reports for the date range.
    $count_query = clone $base_query;
    $count_query->count();
    $total = $count_query->execute()?->fetchField() ?? 0;
    $total = min($options['limit'] ?? $total, $total);

    if (empty($total)) {
      $this->logger->info('No reports found for the given date range.');
      return TRUE;
    }
    else {
      $this->logger->info(strtr('Found @total reports to export.', [
        '@total' => $total,
      ]));
    }

    // Open the output file.
    $file = fopen($output, 'w');
    if ($file === FALSE) {
      $this->logger->error(strtr('Unable to write output to @output.', [
        '@output' => $output,
      ]));
      return FALSE;
    }

    // Default properties.
    $properties = [
      'id' => 'id',
      'status' => 'status',
      'title' => 'title',
      'url' => 'url',
      'url_alias' => 'url_alias',
      'origin' => 'origin',
      'origin_type' => 'origin_type',
      'language.name' => 'language.name',
      'language.code' => 'language.code',
      'language.id' => 'language.id',
      'source.name' => 'source.name',
      'source.shortname' => 'source.shortname',
      'source.id' => 'source.id',
      'source.type.name' => 'source.type.name',
      'source.type.id' => 'source.type.id',
      'primary_country.name' => 'primary_country.name',
      'primary_country.id' => 'primary_country.id',
      'primary_country.iso3' => 'primary_country.iso3',
      'country.name' => 'country.name',
      'country.id' => 'country.id',
      'country.iso3' => 'country.iso3',
      'disaster.name' => 'disaster.name',
      'disaster.id' => 'disaster.id',
      'disaster.glide' => 'disaster.glide',
      'disaster.type.name' => 'disaster.type.name',
      'disaster.type.id' => 'disaster.type.id',
      'disaster.type.code' => 'disaster.type.code',
      'disaster_type.name' => 'disaster_type.name',
      'disaster_type.id' => 'disaster_type.id',
      'disaster_type.code' => 'disaster_type.code',
      'format.name' => 'format.name',
      'format.id' => 'format.id',
      'theme.name' => 'theme.name',
      'theme.id' => 'theme.id',
      'ocha_product.name' => 'ocha_product.name',
      'ocha_product.id' => 'ocha_product.id',
      'file.filename' => 'file.filename',
      'file.url' => 'file.url',
      'file.filesize' => 'file.filesize',
      'image.url' => 'image.url',
      'image.copyright' => 'image.copyright',
      'headline.title' => 'headline.title',
      'date.created' => 'date.created',
      'date.changed' => 'date.changed',
      'date.original' => 'date.original',
      'user.id' => 'user.id',
      'user.name' => 'user.name',
      'user.role' => 'user.role',
    ];

    // Replace the default properties.
    if (!empty($options['properties'])) {
      $properties = json_decode($options['properties'], TRUE) ?: $properties;
    }

    // Add extra properties.
    if (!empty($options['extra-properties'])) {
      $extra_properties = json_decode($options['extra-properties'], TRUE);
      if (!empty($extra_properties)) {
        $properties = array_merge($properties, $extra_properties);
      }
    }

    // Remove some properties.
    if (!empty($options['exclude-properties'])) {
      $exclude_properties = json_decode($options['exclude-properties'], TRUE);
      if (!empty($exclude_properties)) {
        $properties = array_diff_key($properties, $exclude_properties);
      }
    }

    if (empty($properties)) {
      $this->logger->error('No properties to export.');
      return FALSE;
    }

    // Write the headers to the TSV file.
    fputcsv($file, array_values($properties), "\t");

    try {
      $count = 0;
      $last_id = NULL;
      $batch_size = min($total, $batch_size);

      // Retrieve reports in batch.
      while (TRUE) {
        $query = clone $base_query;
        $query->addField('node_field_data', 'nid', 'nid');
        if (isset($last_id)) {
          $query->condition('node_field_data.nid', $last_id, '<');
        }
        $query->orderBy('node_field_data.nid', 'DESC');
        $query->range(0, $batch_size);
        $ids = $query->execute()?->fetchCol();

        if (empty($ids)) {
          break;
        }

        // Retrieve the data in format similar to the results of the API.
        $items = $resource->getItems(count($ids), 0, $ids);
        $count += count($items);

        // Flatten and write to the TSV file.
        foreach ($items as $item) {
          $row = $this->flattenReportData($item, $properties);
          if (!fputcsv($file, $row, "\t")) {
            $this->logger->error('Unable to write TSV row');
          }
        }

        $last_id = end($ids);

        $this->logger->info(strtr('Exported @count / @total reports', [
          '@count' => $count,
          '@total' => $total,
        ]));

        if ($count >= $total) {
          break;
        }
      }
    }
    catch (\Exception $exception) {
      $this->logger->error(strtr('Error: @message.', [
        '@message' => $exception->getMessage(),
      ]));
      return FALSE;
    }
    finally {
      fclose($file);
    }

    if ($upload) {
      $client = new Client();

      // Suppress error to avoid echoing the credential in a traceback.
      if (!@file_exists($credentials)) {
        $client->setAuthConfig($credentials);
      }
      else {
        $client->useApplicationDefaultCredentials();
      }

      $client->setApplicationName("Reliefweb Reports Data Uploader");
      $client->setScopes(['https://www.googleapis.com/auth/drive']);
      $client->setDefer(TRUE);
      $service = new Drive($client);

      $file = new DriveFile();
      $file->setName(basename($output));
      $file->setParents([$options['gdrive-upload-folder']]);

      // Upload files in 1 MB chunks.
      $upload_chunk_size = 1 * 1024 * 1024;

      try {
        $request = $service->files->create($file);

        // A MediaFileUpload allows us to chunk the upload.
        $upload = new MediaFileUpload(
          $client,
          $request,
          'text/tab-separated-values',
          NULL,
          TRUE,
          $upload_chunk_size
        );
        $upload->setFileSize(filesize($output));

        $status = FALSE;
        $stream = fopen($output, "rb");
        while (!$status && !feof($stream)) {
          $chunk = $this->readFileChunk($stream, $upload_chunk_size);
          $status = $upload->nextChunk($chunk);
        }

        // The final $status should contain the DriveFile object.
        $result = FALSE;
        if ($status != FALSE) {
          $result = $status;
        }

      }
      catch (\Exception $exception) {
        $this->logger->error(strtr('Error: @message.', [
          '@message' => $exception->getMessage(),
        ]));
        return FALSE;
      }
      finally {
        fclose($stream);
      }

      $this->logger->info(strtr('@file uploaded as ID @id', [
        '@file' => basename($output),
        '@id'   => $result->getId(),
      ]));
    }

    return TRUE;
  }

  /**
   * Flattens an item array based on specified property paths.
   *
   * @param array $item
   *   The item array to flatten.
   * @param array $properties
   *   An associative array where keys are property paths and values are labels.
   *
   * @return array
   *   A flattened array where keys are property labels and values are the
   *   retrieved values.
   */
  protected function flattenReportData(array $item, array $properties): array {
    $result = [];

    foreach ($properties as $path => $label) {
      $values = $this->getNestedValues($item, explode('.', $path));
      $result[$label] = $this->flattenValues($values);
    }

    return $result;
  }

  /**
   * Flattens an array of values into a string.
   *
   * @param array $values
   *   The values to flatten.
   *
   * @return string
   *   The flattened values as a pipe-separated string.
   */
  private function flattenValues(array $values): string {
    $flattened = array_map(function ($value) {
      if (is_array($value)) {
        return implode('|', array_filter($value, function ($item) {
          return $item !== '' && $item !== NULL && !is_array($item);
        }));
      }
      return $value;
    }, $values);

    return implode('|', array_filter($flattened, function ($value) {
      return $value !== '' && $value !== NULL;
    }));
  }

  /**
   * Recursively retrieves nested values from data using a path.
   *
   * @param mixed $data
   *   The data to search in (array or scalar).
   * @param array $path
   *   The path to the desired value.
   *
   * @return array
   *   An array of all values found at the specified path.
   */
  private function getNestedValues(mixed $data, array $path): array {
    if (empty($path)) {
      return [$data];
    }

    $current = array_shift($path);

    if (!is_array($data)) {
      return [];
    }

    if (!isset($data[$current])) {
      return [];
    }

    $value = $data[$current];

    if (empty($path)) {
      return [$value];
    }

    if (is_array($value) && isset($value[0])) {
      // Handle array of arrays/objects.
      $results = [];
      foreach ($value as $item) {
        $results = array_merge($results, $this->getNestedValues($item, $path));
      }
      return array_unique($results);
    }
    else {
      // Handle single object or scalar.
      return $this->getNestedValues($value, $path);
    }
  }

  /**
   * Read and return chunks of a file.
   *
   * @param resource $stream
   *   An open file handle.
   * @param int $size
   *   The maximum size of a file chunk to return.
   *
   * @return string
   *   A data string read from a file.
   *
   * @see https://github.com/googleapis/google-api-php-client/blob/76c312e2696575d315f56aba31f8979d06da06ff/examples/large-file-upload.php#L135
   */
  private function readFileChunk($stream, $size) {
    $byteCount = 0;
    $returnChunk = '';

    while (!feof($stream)) {
      $chunk = fread($stream, 8192);
      $byteCount += strlen($chunk);
      $returnChunk .= $chunk;
      if ($byteCount >= $chunkSize) {
        return $returnChunk;
      }
    }
    return $returnChunk;
  }

}
