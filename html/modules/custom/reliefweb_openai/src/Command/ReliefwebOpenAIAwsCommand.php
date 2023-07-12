<?php

namespace Drupal\reliefweb_openai\Command;

use Aws\Comprehend\ComprehendClient;
use Consolidation\OutputFormatters\Options\FormatterOptions;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;

/**
 * ReliefWeb Open AI Drush commandfile.
 */
class ReliefwebOpenAIAwsCommand extends DrushCommands implements SiteAliasManagerAwareInterface {

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
   * {@inheritdoc}
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    $account_switcher,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->accountSwitcher = $account_switcher;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->state = $state;
  }

  /**
   * List endpoints.
   *
   * @command reliefweb_openai:aws_endpoints:list
   * @usage reliefweb_openai:aws_endpoints:list
   *   List AWS endpoints.
   * @validate-module-enabled reliefweb_openai
   */
  public function listEndpoints(array $options = [
    'format' => 'table',
  ]) : RowsOfFields {
    $config = \Drupal::config('reliefweb_openai.settings');

    $access_key = $config->get('aws_access_key');
    $secret_key = $config->get('aws_secret_key');
    $region = $config->get('aws_region');

    $client = new ComprehendClient([
      'region' => $region,
      'version' => 'latest',
      'credentials' => [
      'key' => $access_key,
      'secret' => $secret_key,
      ]
    ]);

    $result = $client->listEndpoints();
    $data = [];

    foreach ($result->get('EndpointPropertiesList') as $row) {
      $data[$row['EndpointArn']] = [
        'EndpointArn' => $row['EndpointArn'],
        'Status' => $row['Status'],
        'DesiredInferenceUnits' => $row['DesiredInferenceUnits'],
      ];
    }

    return new RowsOfFields($data);
  }

  /**
   * Create endpoint.
   *
   * @command reliefweb_openai:aws_endpoints:create
   * @usage reliefweb_openai:aws_endpoints:create
   *   Create AWS endpoints.
   * @validate-module-enabled reliefweb_openai
   */
  public function createEndpoint() : string {
    $config = \Drupal::config('reliefweb_openai.settings');

    $access_key = $config->get('aws_access_key');
    $secret_key = $config->get('aws_secret_key');
    $region = $config->get('aws_region');

    $client = new ComprehendClient([
      'region' => $region,
      'version' => 'latest',
      'credentials' => [
      'key' => $access_key,
      'secret' => $secret_key,
      ]
    ]);

    $result = $client->createEndpoint([
      'EndpointName' => 'rw-themes',
      'ModelArn' => 'arn:aws:comprehend:eu-central-1:694216630861:document-classifier/RW-Job-Tagging/version/v0-0-3',
      'DesiredInferenceUnits' => 1,
    ]);

    return 'Endpoint will be created, takes 5-10 minutes to complete';
  }

  /**
   * Delete endpoint.
   *
   * @command reliefweb_openai:aws_endpoints:delete
   * @usage reliefweb_openai:aws_endpoints:delete
   *   Delete AWS endpoints.
   * @validate-module-enabled reliefweb_openai
   */
  public function deleteEndpoints(string $arn) : string {
    $config = \Drupal::config('reliefweb_openai.settings');

    $access_key = $config->get('aws_access_key');
    $secret_key = $config->get('aws_secret_key');
    $region = $config->get('aws_region');

    $client = new ComprehendClient([
      'region' => $region,
      'version' => 'latest',
      'credentials' => [
      'key' => $access_key,
      'secret' => $secret_key,
      ]
    ]);

    $client->deleteEndpoint([
      'EndpointArn' => $arn,
    ]);

    return 'Endpoint will be deleted';
  }

}
