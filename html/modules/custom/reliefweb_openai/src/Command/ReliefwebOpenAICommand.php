<?php

namespace Drupal\reliefweb_openai\Command;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;

/**
 * ReliefWeb Open AI Drush commandfile.
 */
class ReliefwebOpenAICommand extends DrushCommands implements SiteAliasManagerAwareInterface {

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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The account switcher.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;

  /**
   * An http client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The state store.
   *
   * @var Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The source URL.
   *
   * @var string
   */
  protected $url;

  /**
   * Loaded term IDs.
   *
   * @var array
   */
  protected $loadedTermIds = [];

  /**
   * Separator.
   *
   * @var string
   */
  protected $separator = "\n\nCategory: \n\n###\n\n";

  /**
   * {@inheritdoc}
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    $account_switcher,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->accountSwitcher = $account_switcher;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->state = $state;
  }

  /**
   * Train jobs.
   *
   * @param string $field_name
   *   Field to use.
   * @param int $limit
   *   Max number of items to send.
   *
   * @command reliefweb_openai:train_jobs
   * @usage reliefweb_openai:train_jobs
   *   Send emails.
   * @validate-module-enabled reliefweb_openai
   */
  public function trainJobs($field_name = '', $limit = 50) {
    $field = 'field_' . $field_name;

    // Training data
    $content = $this->buildJson($field, 0, $limit);
    $training = $this->uploadFile('training_' . $field, $content);

    // Validation data.
    $content = $this->buildJson($field, $limit + 1, 50);
    $validation = $this->uploadFile('validation_' . $field, $content);

    $response = $this->trainFile($field_name, $training['id'], $validation['id']);
    return $response['id'];
  }

  /**
   * Jobs status.
   *
   * @param string $id
   *   Id.
   *
   * @command reliefweb_openai:status
   * @field-labels
   *   label: label
   *   value: value
   * @usage reliefweb_openai:status
   *   Status of the job.
   * @validate-module-enabled reliefweb_openai
   */
  public function status($id) : RowsOfFields {
    $config = \Drupal::config('reliefweb_openai.settings');

    $http_client = \Drupal::httpClient();
    $url = 'https://api.openai.com/v1/fine-tunes/' . $id;

    $headers = [
      'Authorization' => 'Bearer ' . $config->get('token'),
    ];

    $response = $http_client->request(
      'GET',
      $url,
      [
        'headers' => $headers,
      ],
    );

    $body = $response->getBody() . '';
    $body = json_decode($body, TRUE);

    $last_event = array_pop($body['events']);

    $data = [
      ['label' => 'id', 'value' => $body['id']],
      ['label' => 'status', 'value' => $body['status']],
      ['label' => 'message', 'value' => $last_event['message'] ?? ''],
      ['label' => 'result_files', 'value' => $body['result_files'][0] ?? ''],
    ];

    return new RowsOfFields($data);
  }

  /**
   * Jobs status.
   *
   * @param string $id
   *   Id.
   *
   * @command reliefweb_openai:results
   * @field-labels
   *   step: step
   *   elapsed_tokens: elapsed tokens
   *   elapsed_examples: elapsed examples
   *   training_loss: training loss
   *   training_sequence_accuracy: training sequence accuracy
   *   training_token_accuracy: training token accuracy
   *   validation_loss: validation loss
   *   validation_sequence_accuracy: validation sequence accuracy
   *   validation_token_accuracy: validation token accuracy
   * @usage reliefweb_openai:results
   *   results of the job.
   * @validate-module-enabled reliefweb_openai
   */
  public function results($id) : RowsOfFields {
    $config = \Drupal::config('reliefweb_openai.settings');

    $http_client = \Drupal::httpClient();
    $url = 'https://api.openai.com/v1/fine-tunes/' . $id;

    $headers = [
      'Authorization' => 'Bearer ' . $config->get('token'),
    ];

    $response = $http_client->request(
      'GET',
      $url,
      [
        'headers' => $headers,
      ],
    );

    $body = $response->getBody() . '';
    $body = json_decode($body, TRUE);

    $url = 'https://api.openai.com/v1/files/' . $body['result_files'][0]['id'] . '/content';

    $headers = [
      'Authorization' => 'Bearer ' . $config->get('token'),
    ];

    $response = $http_client->request(
      'GET',
      $url,
      [
        'headers' => $headers,
      ],
    );

    $body = $response->getBody() . '';
    $body = explode("\n", $body);
    array_pop($body);

    $csv = array_map('str_getcsv', $body);
    array_walk($csv, function(&$a) use ($csv) {
      $a = array_combine($csv[0], $a);
    });
    array_shift($csv);

    $data = $csv;

    return new RowsOfFields($data);
  }

  protected function buildJson($field, $offset, $limit) {
    $job_ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'job')
      ->condition('status', 1)
      ->range($offset, $limit)
      ->sort('nid', 'DESC')
      ->execute();

    $jobs = $this->entityTypeManager->getStorage('node')->loadMultiple($job_ids);

    $data = [];
    foreach ($jobs as $job) {
      $tags = [];
      if (!$job->hasField($field) || $job->{$field}->isEmpty()) {
        continue;
      }

      foreach ($job->{$field}->referencedEntities() as $tag) {
        if ($field == 'field_country') {
          $tags[] = ' ' . $tag->field_iso3->value;
        }
        else {
          $tags[] = ' ' . $tag->id();
        }
      }

      $prompt = "Extract the category of this text\n\n";
      $prompt .= $job->label() . "\n" . $this->cleanPrompt($job->body->value);

      $data[] = [
        'prompt' => $prompt . $this->separator,
        'completion' => implode("\n", $tags),
      ];
    }

    $content = '';
    foreach ($data as $row) {
      $content .= json_encode($row) . "\n";
    }

    return $content;
  }

  /**
   * Test it
   *
   * @param string $model
   *   Model.
   * @param string $field_name
   *   Model.
   * @param int $limit
   *   Max number of items to send.
   *
   * @command reliefweb_openai:test_jobs
   * @field-labels
   *   nid: nid
   *   title: title
   *   expected: expected
   *   result: result
   * @usage reliefweb_openai:test_jobs
   *   Send emails.
   * @validate-module-enabled reliefweb_openai
   */
  public function testJobs($model, $field_name, $limit = 50) : RowsOfFields {
    $field = 'field_' . $field_name;

    $job_ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'job')
      ->condition('status', 1)
      ->range(0, $limit)
      ->sort('nid', 'DESC')
      ->execute();

    $jobs = $this->entityTypeManager->getStorage('node')->loadMultiple($job_ids);

    $data = [];
    foreach ($jobs as $job) {
      $themes = [];
      if (!$job->hasField($field) || $job->{$field}->isEmpty()) {
        continue;
      }

      foreach ($job->{$field}->referencedEntities() as $theme) {
        $themes[] = $theme->label();
      }

      $prompt = "Extract the category of this text\n\n";
      $prompt .= $job->label() . "\n" . $this->cleanPrompt($job->body->value);

      $result = reliefweb_openai_http_call(
        [
          'model' => $model,
          'prompt' => $prompt . $this->separator,
          'temperature' => 0,
          'max_tokens' => 3,
          'logprobs' => 1,
        ],
      );

      $answer = trim($result["choices"][0]["text"]);

      $data['job' . $job->id()] = [
        'nid' => $job->id(),
        'title' => $job->label(),
        'expected' => implode(', ', $themes),
        'result' => $answer,
      ];

    }

    return new RowsOfFields($data);
  }

  /**
   * Clean prompt.
   */
  protected function cleanPrompt($prompt) {
    $prompt = Unicode::truncate(strip_tags(trim($prompt)), 3900, TRUE);
    $prompt = str_replace(["\r\n", "\r", "\n", "\\r", "\\n", "\\r\\n"], "\n ", $prompt);
    $prompt = preg_replace("/  +/", ' ', $prompt);
    $prompt= substr($prompt, 0, 5000);

    return $prompt;
  }

  /**
   * Post file.
   */
  protected function uploadFile($file_name, $content) {
    $config = \Drupal::config('reliefweb_openai.settings');

    $http_client = \Drupal::httpClient();
    $url = 'https://api.openai.com/v1/files';

    $headers = [
      'Authorization' => 'Bearer ' . $config->get('token'),
    ];

    $response = $http_client->request(
      'POST',
      $url,
      [
        'headers' => $headers,
        'multipart' => [
          [
            'name' => 'purpose',
            'contents' => 'fine-tune',
          ],
          [
            'content-type' => 'multipart/form-data',
            'name' => 'file',
            'contents' => $content,
            'filename' => $file_name . '.jsonl',
          ],
        ],
      ],
    );

    $body = $response->getBody() . '';
    return json_decode($body, TRUE);
  }

  /**
   * Train file.
   */
  protected function trainFile($suffix, $file_id, $validation_id) {
    $config = \Drupal::config('reliefweb_openai.settings');

    $http_client = \Drupal::httpClient();
    $url = 'https://api.openai.com/v1/fine-tunes';

    $headers = [
      'Content-Type' => 'application/json',
      'Authorization' => 'Bearer ' . $config->get('token'),
    ];

    $response = $http_client->request(
      'POST',
      $url,
      [
        'headers' => $headers,
        'json' => [
          'model' => 'ada',
          'training_file' => $file_id,
          'validation_file' => $validation_id,
          'suffix' => 'rw-jobs-' . $suffix,
          'n_epochs' => 2,
        ],
      ],
    );

    $body = $response->getBody() . '';
    return json_decode($body, TRUE);
  }

}
