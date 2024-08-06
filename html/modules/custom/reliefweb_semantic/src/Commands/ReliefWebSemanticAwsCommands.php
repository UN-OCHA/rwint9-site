<?php

namespace Drupal\reliefweb_semantic\Commands;

use Aws\BedrockAgent\BedrockAgentClient;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drush\Commands\DrushCommands;

/**
 * ReliefWeb API Drush commandfile.
 */
class ReliefWebSemanticAwsCommands extends DrushCommands {

  /**
   * ReliefWeb API config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
  ) {
    $this->config = $config_factory->get('reliefweb_semantic.settings');
    $this->state = $state;
  }

  /**
   * List kbs.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @command reliefweb-semantic:list-kbs
   *
   * @option reset.
   *
   * @default $options []
   *
   * @usage reliefweb-semantic:list-kbs
   *   List kbs.
   */
  public function listKbs(
    array $options = [
      'reset' => 0,
      'format' => 'table',
    ],
  ) : RowsOfFields {
    $data = $this->getKbs($options['reset']);
    return new RowsOfFields($data);
  }

  /**
   * List datasources.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @command reliefweb-semantic:list-datasources
   *
   * @option reset.
   *
   * @default $options []
   *
   * @usage reliefweb-semantic:list-datasources
   *   List datasources.
   */
  public function listDatasources(
    array $options = [
      'reset' => 0,
      'format' => 'table',
    ],
  ) : RowsOfFields {
    $data = $this->getDatasources($options['reset']);
    return new RowsOfFields($data);
  }

  /**
   * List j=ingestion jobs.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @command reliefweb-semantic:list-jobs
   *
   * @option reset.
   *
   * @default $options []
   *
   * @usage reliefweb-semantic:list-jobs
   *   List jobs.
   */
  public function listJobs(
    array $options = [
      'id' => 0,
      'format' => 'table',
    ],
  ) : null|RowsOfFields {
    $aws_options = reliefweb_semantic_get_aws_client_options();
    $bedrock = new BedrockAgentClient($aws_options);

    if (empty($options['id'])) {
      return NULL;
    }

    $datasources = $this->getDatasources();
    $result = $bedrock->listIngestionJobs([
      'dataSourceId' => $datasources[$options['id']]['id'],
      'knowledgeBaseId' => $datasources[$options['id']]['kb_id'],
    ]);

    $jobs = $result->toArray()['ingestionJobSummaries'];
    $data = [];

    foreach ($jobs as $job) {
      $data[$job['ingestionJobId']] = [
        'id' => $job['ingestionJobId'],
        'status' => $job['status'],
        'updated' => $job['updatedAt'],
        'numberOfDocumentsScanned' => $job['statistics']['numberOfDocumentsScanned'],
        'numberOfDocumentsFailed' => $job['statistics']['numberOfDocumentsFailed'],
        'numberOfNewDocumentsIndexed' => $job['statistics']['numberOfNewDocumentsIndexed'],
      ];
    }

    return new RowsOfFields($data);
  }

  /**
   * Trigger sync.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @command reliefweb-semantic:trigger-sync
   *
   * @option reset.
   *
   * @default $options []
   *
   * @usage reliefweb-semantic:trigger-sync --id=xxx
   *   List datasources.
   */
  public function triggerSync(
    array $options = [
      'id' => 0,
    ],
  ) {
    $datasources = $this->getDatasources();

    $aws_options = reliefweb_semantic_get_aws_client_options();
    $bedrock = new BedrockAgentClient($aws_options);

    if (!empty($options['id'])) {
      $bedrock->startIngestionJob([
        'dataSourceId' => $datasources[$options['id']]['id'],
        'knowledgeBaseId' => $datasources[$options['id']]['kb_id'],
      ]);

      return;
    }

    foreach ($datasources as $id => $datasource) {
      $bedrock->startIngestionJob([
        'dataSourceId' => $id,
        'knowledgeBaseId' => $datasource['kb_id'],
      ]);

      // Rate limit is 0.1 / sec.
      sleep(15);
    }
  }

  /**
   * Get kbs.
   */
  protected function getKbs($reset = 0) : array {
    $data = $this->state->get('reliefweb_semantic_kbs', []);
    if (empty($data) || !empty($reset)) {
      $data = [];
      $aws_options = reliefweb_semantic_get_aws_client_options();
      $bedrock = new BedrockAgentClient($aws_options);
      $result = $bedrock->listKnowledgeBases();

      $kbs = $result->toArray()['knowledgeBaseSummaries'];
      foreach ($kbs as $kb) {
        $data[$kb['knowledgeBaseId']] = [
          'id' => $kb['knowledgeBaseId'],
          'name' => $kb['name'],
          'status' => $kb['status'],
        ];
      }

      $this->state->set('reliefweb_semantic_kbs', $data);
    }

    return $data;
  }

  /**
   * Get data sources.
   */
  protected function getDatasources($reset = 0) {
    $data = $this->state->get('reliefweb_semantic_datasources', []);
    if (empty($data) || !empty($reset)) {
      $kbs = $this->getKbs();
      $aws_options = reliefweb_semantic_get_aws_client_options();
      $bedrock = new BedrockAgentClient($aws_options);

      foreach ($kbs as $id => $kb) {
        $result = $bedrock->listDataSources([
          'knowledgeBaseId' => $id,
        ]);

        $datasources = $result->toArray()['dataSourceSummaries'];
        foreach ($datasources as $datasource) {
          $data[$datasource['dataSourceId']] = [
            'id' => $datasource['dataSourceId'],
            'name' => $datasource['name'],
            'status' => $datasource['status'],
            'kb_id' => $kb['id'],
            'kb_name' => $kb['name'],
            'kb_status' => $kb['status'],
          ];
        }
      }

      $this->state->set('reliefweb_semantic_datasources', $data);
    }

    return $data;
  }

}
