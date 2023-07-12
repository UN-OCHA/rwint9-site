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
  public function trainJobs($field_name = '', $limit = 1000) {
    $field = 'field_' . $field_name;

    // Training data
    $content = $this->buildData($field, 0, $limit);
    $content = $this->jobcategory();
    $fp = fopen('/var/www/training.csv', 'w');
    fputcsv($fp, ['excerpt', 'target_classification']);
    foreach ($content as $fields) {
      fputcsv($fp, $fields);
    }
    fclose($fp);
return;
    // Validation data.
    $size = min(50, round(0.1 * $limit));
    $content = $this->buildData($field, $limit + 1, $size);
    $fp = fopen('/var/www/validation.csv', 'w');
    fputcsv($fp, ['excerpt', 'target_classification']);
    foreach ($content as $fields) {
        fputcsv($fp, $fields);
    }
    fclose($fp);

    // Validation data.
    $content = $this->buildData($field, $limit + $size + 1, $size);
    $fp = fopen('/var/www/testing.csv', 'w');
    fputcsv($fp, ['excerpt', 'target_classification']);
    foreach ($content as $fields) {
        fputcsv($fp, $fields);
    }
    fclose($fp);

return;
    // $validation = $this->uploadFile('validation_' . $field, $content);

    //$response = $this->trainFile($field_name, $training['id'], $validation['id']);
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

  protected function buildData($field, $offset, $limit) : array {
    $job_ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'job')
      ->condition('status', 1)
      ->exists($field)
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

      $prompt = $job->label() . "\n" . $this->cleanPrompt($job->body->value);
      $tags = [];
      foreach ($job->{$field}->referencedEntities() as $tag) {
//        $tags[] = 'lvl1 -> lvl2 -> ' . $tag->label();
        $tags[] = $tag->label();
      }

//      while (count($tags) < 3) {
//        $tags[] = 'test ' . count($tags);
//      }

      $data[] = [
        'label' => implode(' | ', $tags),
        'text' => $prompt,
      ];
    }
print_r(count($data) . "\n");
    return $data;
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
    $prompt = str_replace(["\r\n", "\r", "\n", "\\r", "\\n", "\\r\\n"], " ", $prompt);
    $prompt = preg_replace("/  +/", ' ', $prompt);
    // $prompt= substr($prompt, 0, 5000);

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

  /**
   * Job categories from API.
   *
   * @command reliefweb_openai:job_categories
   * @usage reliefweb_openai:job_categories
   *   job_categories.
   * @validate-module-enabled reliefweb_openai
   */
  public function jobcategory() {
    $result = [];

    $themes = [
      4587 => 'Agriculture',
      49458 => 'Camp Coordination and Camp Management',
      4588 => 'Climate Change and Environment',
      4590 => 'Coordination',
      4591 => 'Disaster Management',
      4592 => 'Education',
      4593 => 'Food and Nutrition',
      4594 => 'Gender',
      4595 => 'Health',
      4596 => 'HIV/Aids',
      12033 => 'Mine Action',
      4599 => 'Peacekeeping and Peacebuilding',
      4600 => 'Protection and Human Rights',
      4601 => 'Recovery and Reconstruction',
      4602 => 'Safety and Security',
      4603 => 'Shelter and Non-Food Items',
      4604 => 'Water Sanitation Hygiene',
    ];

    foreach ($themes as $id => $name) {
      /** @var \Drupal\reliefweb_rivers\Services\JobRiver */
      $river = \Drupal::service('reliefweb_rivers.job.river');

      $payload = $river->getApiPayload();
      $payload['offset'] = 62;
      $payload['limit'] = 1;

      $payload['preset'] = 'latest';
      $payload['fields']['include'] = [];
      $payload['fields']['include'][] = 'theme.id';
      $payload['fields']['include'][] = 'theme.name';
      $payload['fields']['include'][] = 'body';
      $payload['filter'] = [
        'field' => 'theme.id',
        'value' => $id,
      ];

      $data = $river->requestApi($payload);

      foreach ($data['data'] as $row) {
        $result[] = [
          'label' => $name,
          'text' => $row['fields']['body'],
        ];
      }
    }

    return $result;
  }

  /**
   * Job categories from API.
   *
   * @command reliefweb_openai:job_categories_test
   * @usage reliefweb_openai:job_categories_test
   *   job_categories.
   * @validate-module-enabled reliefweb_openai
   */
  public function jobcategory_test() {
    $result = [];

    $themes = [
      4587 => 'Agriculture',
      49458 => 'Camp Coordination and Camp Management',
      4588 => 'Climate Change and Environment',
      4590 => 'Coordination',
      4591 => 'Disaster Management',
      4592 => 'Education',
      4593 => 'Food and Nutrition',
      4594 => 'Gender',
      4595 => 'Health',
      4596 => 'HIV/Aids',
      12033 => 'Mine Action',
      4599 => 'Peacekeeping and Peacebuilding',
      4600 => 'Protection and Human Rights',
      4601 => 'Recovery and Reconstruction',
      4602 => 'Safety and Security',
      4603 => 'Shelter and Non-Food Items',
      4604 => 'Water Sanitation Hygiene',
    ];

    foreach ($themes as $id => $name) {
      /** @var \Drupal\reliefweb_rivers\Services\JobRiver */
      $river = \Drupal::service('reliefweb_rivers.job.river');

      $payload = $river->getApiPayload();
      $payload['offset'] = 62;
      $payload['limit'] = 1;

      $payload['preset'] = 'latest';
      $payload['fields']['include'] = [];
      $payload['fields']['include'][] = 'theme.id';
      $payload['fields']['include'][] = 'theme.name';
      $payload['fields']['include'][] = 'body';
      $payload['filter'] = [
        'field' => 'theme.id',
        'value' => $id,
      ];

      $data = $river->requestApi($payload);

      foreach ($data['data'] as $row) {
        $filename = '/var/www/test_file_' . $id . '.txt';
        file_put_contents($filename, $row['fields']['body']);
      }
    }

    return $result;
  }

  /**
   * Summerize a PDF.
   *
   * @command reliefweb_openai:summarize_pdf
   * @usage reliefweb_openai:summarize_pdf
   *   Summerize a PDF.
   * @validate-module-enabled reliefweb_openai
   */
  function summerizePdf(string $filename = '/var/www/testpdf.txt') {
    $body = file_get_contents($filename);
    $chunks = explode('|||', chunk_split($body, 9000, '|||'));

    $results = [];

    foreach ($chunks as $index => $chunk) {
      $this->logger()->notice('Processing ' . $index . ' of ' . count($chunks));

      if (strlen($chunk) < 100) {
        continue;
      }

      $results[] = reliefweb_openai_http_call_chat(
        [
          'model' => 'gpt-3.5-turbo-16k',
          'messages' => [
            [
              'role' => 'user',
              'content' => "Summerize the following text:\n\n" . $chunk,
            ],
          ],
          'temperature' => .8,
          'max_tokens' => 300,
        ],
      );
    }

    $text = '';
    foreach ($results as $row) {
      $text .= $row['choices'][0]['message']['content'] ?? '';
      $text .= "\n";
    }

    $result = reliefweb_openai_http_call_chat(
      [
        'model' => 'gpt-3.5-turbo-16k',
        'messages' => [
          [
            'role' => 'user',
            'content' => "Summerize the following text:\n\n" . $text,
          ],
        ],
        'temperature' => .8,
        'max_tokens' => 600,
      ],
    );

    return $result['choices'][0]['message']['content'];
  }

}
