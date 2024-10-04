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
use RWAPIIndexer\Database\DatabaseConnection;
use RWAPIIndexer\Database\Query as DatabaseQuery;
use RWAPIIndexer\Elasticsearch;
use RWAPIIndexer\Options;
use RWAPIIndexer\Processor;
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
   * Export report data.
   *
   * This export report data for reports created between the start and end
   * dates to the provided tsv file.
   *
   * @param string $start
   *   Starting date for the export. It will export all the reports created
   *   after that date to the end date.
   * @param string $end
   *   End date for the export. It will export all the reports created
   *   between the start date and the end date.
   * @param array $options
   *   Additional options for the command.
   *
   * @command reliefweb_reporting:export-report-data
   *
   * @option output (string)
   *   The export TSV file. Defaults to the standard output.
   * @option batch_size (int)
   *   The number of reports to retrieve at once.
   * @option filter (string)
   *   Filter documents to retrieve. Format:
   *   'field1:value1,value2+field2:value1,value2'.
   * @option properties (string)
   *   JSON-encoded associative array to override default property mappings.
   *   This parameter allows customization of the report fields to be extracted
   *   and their corresponding labels in the output TSV.
   *
   *   The JSON structure should be as follows:
   *   - Keys: Dot-notation paths representing nested fields in the ReliefWeb
   *     API report output (e.g., 'field.subfield.subsubfield').
   *   - Values: Custom labels to be used as column headers in the output TSV.
   *
   *   Example JSON:
   *   {
   *     "id": "Report ID",
   *     "title": "Title",
   *     "date.created": "Creation Date",
   *     "country.name": "Country",
   *     "disaster.type.name": "Disaster Type"
   *   }
   *
   *   If null, the command will use a predefined set of properties.
   *
   * @default $options [
   *   'output' => 'php://stdout',
   *   'batch_size' => 1000,
   *   'filter' => NULL,
   *   'properties' = NULL,
   * ]
   *
   * @usage reliefweb_reporting:export-report-data "2021-01-01T00:00:01+00:00" "now" "/tmp/report-data-export.tsv"
   *   Export the data from 2021 to now into /tmp/report-data-export.tsv.
   *
   * @validate-module-enabled reliefweb_reporting
   */
  public function exportReportData(
    string $start = "-1 month",
    string $end = "now",
    array $options = [
      'output' => 'php://stdout',
      'batch_size' => 1000,
      'filter' => NULL,
      'properties' => NULL,
    ],
  ): bool {
    $output = $options['output'] ?? 'php://stdout';
    $batch_size = $options['batch_size'] ?? 1000;

    $bundle = 'report';
    $entity_type = 'node';
    $index = 'reports';

    $retrieval_options = reliefweb_api_get_indexer_base_options();
    $retrieval_options['filter'] = $options['filter'] ?? NULL;

    // Options to retrieve the report resources.
    $indexer_options = new Options($retrieval_options);

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
    );

    // Retrieve the timestamps from the start and end dates.
    $timezone = new \DateTimeZone('UTC');
    $start_date = new \DateTime($start, $timezone);
    $end_date = new \DateTime($end, $timezone);
    $start_timestamp = $start_date->getTimestamp();
    $end_timestamp = $end_date->getTimestamp();

    // Base query to get the IDs of the reports for the given date range.
    $base_query = new DatabaseQuery('node_field_data', 'node_field_data', $connection);
    $base_query->innerJoin('node', 'node', 'node.nid = node_field_data.nid');
    $base_query->addField('node_field_data', 'nid', 'id');
    $base_query->condition('node.type', 'report', '=');
    $base_query->condition('node_field_data.created', $start_timestamp, '>=');
    $base_query->condition('node_field_data.created', $end_timestamp, '<');

    // Get the total of reports for the date range.
    $count_query = clone $base_query;
    $count_query->count();
    $total = $count_query->execute()?->fetchField() ?? 0;

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

    // Properties to include in the export.
    if (!empty($options['properties'])) {
      $properties = json_decode($options['properties'], TRUE);
    }
    if (empty($properties)) {
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
        'image.url' => 'image.url',
        'headline.title' => 'headline.title',
        'date.created' => 'date.created',
        'date.changed' => 'date.changed',
        'date.original' => 'date.original',
        'user.id' => 'user.id',
        'user.name' => 'user.name',
        'user.role' => 'user.role',
      ];
    }

    // Write the headers to the TSV file.
    fputcsv($file, array_values($properties), "\t");

    try {
      $count = 0;
      $last_id = NULL;

      // Retrieve reports in batch.
      while (TRUE) {
        $query = clone $base_query;
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

    return TRUE;
  }

  /**
   * Flattens an item array based on specified property paths.
   *
   * This function takes a complex array structure and flattens it according to
   * the provided property paths. It handles nested objects and arrays,
   * concatenating multiple values with a pipe character. The resulting array
   * uses property labels as keys.
   *
   * @param array $item
   *   The item array to flatten.
   * @param array $properties
   *   An associative array where keys are property paths and values are labels.
   *
   * @return array
   *   A flattened array where keys are property labels and values are the
   *   retrieved (and potentially flattened) values.
   */
  protected function flattenReportData(array $item, array $properties): array {
    $result = [];

    foreach ($properties as $path => $label) {
      $value = $item;
      $parts = explode('.', $path);

      foreach ($parts as $part) {
        if (is_array($value) && isset($value[0])) {
          // Handle array of objects.
          $value = array_map(
            fn($v) => $v[$part] ?? NULL,
            $value
          );
        }
        elseif (is_array($value)) {
          // Handle object or associative array.
          $value = $value[$part] ?? NULL;
        }
        else {
          $value = NULL;
          break;
        }

        if ($value === NULL) {
          break;
        }
      }

      if (is_array($value)) {
        // Flatten array values.
        $value = implode('|', array_filter($value, fn($v) => !is_array($v)));
      }

      $result[$label] = $value ?? '';
    }

    return $result;
  }

}
