<?php

namespace Drupal\reliefweb_api\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use RWAPIIndexer\Bundles;
use RWAPIIndexer\Manager;

/**
 * ReliefWeb API Drush commandfile.
 */
class ReliefWebApiCommands extends DrushCommands {

  /**
   * ReliefWeb API config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $apiConfig;

  /**
   * Entity Field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Entity Type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfo;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    StateInterface $state,
    ClientInterface $http_client,
    MailManagerInterface $mail_manager,
    EntityTypeBundleInfoInterface $bundle_info,
  ) {
    $this->apiConfig = $config_factory->get('reliefweb_api.settings');
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->state = $state;
    $this->httpClient = $http_client;
    $this->mailManager = $mail_manager;
    $this->bundleInfo = $bundle_info;
  }

  /**
   * Index content in the ReliefWeb API.
   *
   * Wrapper around ReliefWeb API Indexer.
   *
   * @param string $bundle
   *   Entity bundle to index.
   * @param array $options
   *   Additional options for the command.
   *
   * @command reliefweb-api:index
   *
   * @option elasticsearch Elasticsearch URL (http://host:port), defaults to
   *   the 'reliefweb_api.settings:elasticsearch' setting or
   *   'http://elasticsearch:9200'.
   * @option base-index-name Base index name, deaults to the
   *   'reliefweb_api.settings:base_index_name' setting or the database name.
   * @option website Site scheme and hostname to use as base for the URLs,
   *   defaults to the 'reliefweb_api.settings:website' setting or
   *   'https://reliefweb.int'.
   * @option limit Maximum number of entities to index, defaults to 0 (all).
   * @option offset ID of the entity from which to start the indexing, defaults
   *   to the most recent one.
   * @option filter Filter documents to index. Format:
   *   'field1:value1,value2+field2:value1,value2'.
   * @option chunk-size Number of entities to index at one time, defaults to
   *   500.
   * @option tag Tag appended to the index name, defaults to the
   *   reliefweb_api_index_tag variable for this bundle.
   * @option id Id of an entity item to index, defaults to 0 (none).
   * @option remove Removes an entity if id is provided or the index for the
   *   given entity bundle.
   * @option alias Set up the alias for the index after the indexing, ignored if
   *   id is provided.
   * @option alias-only Set up the alias for the index without indexing, ignored
   *   if id is provided.
   * @option replace Replace the old index if tag is provided and different than
   *   the current one, ignored if id is provided.
   * @option count-only Get the number of indexable entities for the
   *   options, defaults to FALSE.
   * @option memory_limit PHP memory limit, defaults to 512M.
   * @option replicas Number of Elasticsearch replicas for the index,
   *   defaults to NULL which means use the reliefweb_api.settings.replicas
   *   default config value.
   * @option shards Number of Elasticsearch shards for the index,
   *   defaults to NULL which means use the reliefweb_api.settings.shards
   *   default config value.
   *
   * @default $options []
   *
   * @usage reliefweb-api:index --id=123 report
   *   Index the report with ID 123.
   * @usagereliefweb-api:index --limit=10 report
   *   Index latest 10 reports.
   * @usage reliefweb-api:index --tag=20140802 --alias report
   *   Index all reports into index tagged with 20140802 and set the index alias
   *   to point at it.
   * @usage reliefweb-api:index --tag=20140915 --replace report
   *   Index all reports into a new index tagged with 20140915 and use it to
   *   replace the old one.
   * @usage reliefweb-api:index --tag=20140802 --remove report
   *   Remove the report index tagged with 20140802.
   *
   * @validate-module-enabled reliefweb_api
   *
   * @aliases rw-api:index, rw-api:i, rwapi-i, rapi-i
   */
  public function index(
    $bundle = '',
    array $options = [
      'elasticsearch' => '',
      'base-index-name' => '',
      'website' => '',
      'limit' => 0,
      'offset' => 0,
      'filter' => '',
      'chunk-size' => 500,
      'tag' => '',
      'id' => 0,
      'remove' => FALSE,
      'replace' => FALSE,
      'alias' => FALSE,
      'alias-only' => FALSE,
      'log' => 'echo',
      'count-only' => FALSE,
      'memory-limit' => '512M',
      'replicas' => NULL,
      'shards' => NULL,
    ],
  ) {
    // Index all the references at once when the special 'references' bundle
    // is passed to the command.
    if ($bundle === 'references') {
      $excluded_bundles = ['country', 'disaster', 'source'];
      foreach (Bundles::$bundles as $bundle => $info) {
        if ($info['type'] === 'taxonomy_term' && !in_array($bundle, $excluded_bundles)) {
          $this->index($bundle, $options);
        }
      }
      return;
    }
    // Index all the resources.
    elseif ($bundle === 'all') {
      foreach (Bundles::$bundles as $bundle => $info) {
        $this->index($bundle, $options);
      }
      return;
    }
    // Index the given bundles.
    elseif (strpos($bundle, ',') > 0) {
      $bundles = explode(',', $bundle);
      foreach ($bundles as $bundle) {
        if ($bundle === 'references' || isset(Bundles::$bundles[$bundle])) {
          $this->index($bundle, $options);
        }
      }
      return;
    }

    $tag = $this->state->get('reliefweb_api_index_tag_' . $bundle, '');
    $replace = !empty($options['replace']);

    // Index indexing options.
    $indexing_options = reliefweb_api_get_indexer_base_options();
    $indexing_options['bundle'] = $bundle;
    $indexing_options['elasticsearch'] = $options['elasticsearch'] ?: $indexing_options['elasticsearch'];
    $indexing_options['base-index-name'] = $options['base-index-name'] ?: $indexing_options['base-index-name'];
    $indexing_options['website'] = $options['website'] ?: $indexing_options['website'];
    $indexing_options['limit'] = (int) ($options['limit'] ?: 0);
    $indexing_options['offset'] = (int) ($options['offset'] ?: 0);
    $indexing_options['filter'] = $options['filter'] ?: '';
    $indexing_options['chunk-size'] = (int) ($options['chunk-size'] ?: 500);
    $indexing_options['tag'] = $options['tag'] ?: $tag;
    $indexing_options['id'] = (int) ($options['id'] ?: 0);
    $indexing_options['remove'] = !empty($options['remove']);
    $indexing_options['alias'] = !empty($options['alias']);
    $indexing_options['alias-only'] = !empty($options['alias-only']);
    // It looks like "simulate" is a reserved drush option so we need another
    // name for the option, thus "count-only"...
    $indexing_options['simulate'] = !empty($options['count-only']);
    $indexing_options['replicas'] = (int) ($options['replicas'] ?? $indexing_options['replicas']);
    $indexing_options['shards'] = (int) ($options['shards'] ?? $indexing_options['shards']);
    $indexing_options['simulate'] = !empty($options['count-only']);
    $indexing_options['log'] = 'echo';

    // Allow some post processing of the items before they are actually added to
    // the index.
    $hook = 'reliefweb_api_post_process_item';
    if (function_exists($hook)) {
      $indexing_options['post-process-item-hook'] = $hook;
    }

    // Make sure there is enough memory.
    ini_set('memory_limit', $options['memory-limit'] ?: '512M');

    // Launch the indexing or index removal.
    try {
      // Create a new Indexing manager.
      $manager = new Manager($indexing_options);
      // Perform indexing or index removal.
      $manager->execute();

      // Print the time and memory usage.
      echo $manager->getMetrics();

      // Reconnect to the Drupal database as connection may have ended.
      Database::closeConnection();
      Database::getConnection('default', 'default');

      // Replace the old index by the new one.
      if (empty($indexing_options['id']) && !empty($replace) && empty($indexing_options['simulate'])) {
        $this->replace($bundle, $indexing_options['tag'], $tag, $options);
      }
    }
    catch (\Exception $exception) {
      if ($exception->getMessage() !== 'No entity to index.') {
        $this->logger->error('(' . $exception->getCode() . ') ' . $exception->getMessage());
      }
      else {
        $this->logger->notice($exception->getMessage());
      }
    }
  }

  /**
   * Replace an index.
   *
   * Replace an old index by a new one, setting the index alias to point at the
   * new one. Indexes must exist.
   *
   * @param string $bundle
   *   Entity bundle of the index.
   * @param string $newtag
   *   New index tag.
   * @param string|null $oldtag
   *   Old index tag, defaults to the reliefweb_api_index_tag variable for the
   *   bundle.
   * @param array $options
   *   Additional options for the command.
   *
   * @option elasticsearch Elasticsearch URL (http://host:port), defaults to
   *   the 'reliefweb_api.settings:elasticsearch' setting or
   *   'http://elasticsearch:9200'.
   * @option base-index-name Base index name, deaults to the
   *   'reliefweb_api.settings:base_index_name' setting or the database name.
   *
   * @command reliefweb-api:replace
   *
   * @usage reliefweb-api:replace report 20141015 20140802
   *   Replace the index tagged with 20140802 by the index tagged with 20141015.
   *
   * @validate-module-enabled reliefweb_api
   *
   * @aliases rw-api:replace, rw-api:r, rwapi-r
   */
  public function replace(
    $bundle,
    $newtag,
    $oldtag = NULL,
    array $options = [
      'elasticsearch' => '',
      'base-index-name' => '',
    ],
  ) {
    if (!isset($oldtag)) {
      $oldtag = $this->state->get('reliefweb_api_index_tag_' . $bundle, '');
    }

    if ($newtag === $oldtag) {
      $this->logger->info('Same tags, nothing to update. Skipping');
    }
    else {
      $base_options = reliefweb_api_get_indexer_base_options();
      $base_options['bundle'] = $bundle;
      $base_options['elasticsearch'] = $options['elasticsearch'] ?: $base_options['elasticsearch'];
      $base_options['base-index-name'] = $options['base-index-name'] ?: $base_options['base-index-name'];

      try {
        // Set the alias for the new index.
        $indexing_options = array_merge($base_options, [
          'tag' => $newtag,
          'alias-only' => TRUE,
        ]);
        $manager = new Manager($indexing_options);
        $manager->execute();
      }
      catch (\Exception $exception) {
        $this->logger->error('(' . $exception->getCode() . ') ' . $exception->getMessage());
        return;
      }

      try {
        // Remove the old index.
        $indexing_options = array_merge($base_options, [
          'tag' => $oldtag,
          'remove' => TRUE,
        ]);
        $manager = new Manager($indexing_options);
        $manager->execute();
      }
      catch (\Exception $exception) {
        $message = $exception->getMessage();
        // Ignore index not found message.
        if (strpos($message, 'IndexNotFoundException') !== 0) {
          $this->logger->error('(' . $exception->getCode() . ') ' . $message);
          return;
        }
      }

      // Set the new tag.
      $this->state->set('reliefweb_api_index_tag_' . $bundle, $newtag);
    }

    // Allow other modules to perform actions after the indexing.
    if (isset(Bundles::$bundles[$bundle]['type'])) {
      $entity_type_id = Bundles::$bundles[$bundle]['type'];

      $this->moduleHandler->invokeAll('reliefweb_api_post_indexing', [
        $entity_type_id,
        $bundle,
      ]);

      $this->logger->info('Performed post indexing tasks.');
    }
  }

  /**
   * Re-index queued content.
   *
   * @command reliefweb-api:reindexqueue
   *
   * @option display Display information about terms to be re-indexed before processing.
   * @option email-recipients Comma-separated list of email recipients for notifications.
   *   If empty, no email notifications will be sent.
   * @option email-subject Subject line for email notifications, defaults to
   *   'Automatic re-indexing'.
   *
   * @usage reliefweb-api:reindexqueue
   *   Re-index the queue of terms to update.
   * @usage reliefweb-api:reindexqueue --display
   *   Re-index the queue and display information about terms being processed.
   * @usage reliefweb-api:reindexqueue --display --email-recipients="admin@example.com,user@example.com"
   *   Re-index the queue, display info, and send email notifications.
   *
   * @validate-module-enabled reliefweb_api
   *
   * @aliases rw-api:reindexqueue
   */
  public function reIndexQueue(
    array $options = [
      'display' => FALSE,
      'email-recipients' => '',
      'email-subject' => 'Automatic re-indexing',
    ],
  ) {
    // Retrieve the queue of terms to be re-indexed.
    $reindex_queue = $this->state->get('reliefweb_api.reindex_queue');
    if (empty($reindex_queue)) {
      $this->logger->info('No terms queued for re-indexing');
      return;
    }

    // Gather information about terms to be re-indexed if display is enabled.
    $output = '';
    if (!empty($options['display']) || !empty($options['email-recipients'])) {
      $output = $this->gatherReindexInfo($reindex_queue);

      if (!empty($options['display']) && !empty($output)) {
        $this->displayReindexInfo($output, $reindex_queue);
      }
    }

    // Empty the queue now so it can be repopulated during re-indexing.
    $this->state->set('reliefweb_api.reindex_queue', []);

    // Get default options for indexing.
    $reflection = new \ReflectionMethod(get_called_class(), 'index');
    foreach ($reflection->getParameters() as $parameter) {
      if ($parameter->getName() === 'options') {
        $indexing_options = $parameter->getDefaultValue();
        break;
      }
    }
    if (!isset($indexing_options)) {
      return;
    }

    foreach ($reindex_queue as $bundle => $ids) {
      if (empty($ids)) {
        continue;
      }

      $bundles_to_reindex = $this->getBundlesToReindex($bundle);
      if (empty($bundles_to_reindex)) {
        continue;
      }

      $indexing_options['filter'] = $bundle . ':' . implode(',', array_unique($ids));
      foreach ($bundles_to_reindex as $bundle_to_reindex) {
        $this->logger->info('Re-indexing ' . $bundle_to_reindex . ' resources');
        $this->index($bundle_to_reindex, $indexing_options);
      }
    }

    // Send email notification if recipients are specified and there was output.
    if (!empty($options['email-recipients']) && !empty($output)) {
      $this->sendReindexNotification($output, $options);
    }
  }

  /**
   * Get list of bundles to re-index for given taxonomy vocabulary.
   *
   * @param string $vocabulary
   *   Vocabulary.
   *
   * @return array
   *   List of bundles to re-index.
   */
  protected function getBundlesToReindex(string $vocabulary): array {
    // Gather a list of entity reference fields.
    $entity_reference_fields = $this->entityFieldManager
      ->getFieldMapByFieldType('entity_reference');

    // Generate list of field config ids for the reference fields.
    $ids = [];
    foreach ($entity_reference_fields as $entity_type_id => $fields) {
      foreach ($fields as $field_name => $info) {
        foreach ($info['bundles'] as $entity_bundle) {
          $ids[] = "$entity_type_id.$entity_bundle.$field_name";
        }
      }
    }

    // Load the configuration of the entity reference fields.
    $field_configs = $this->entityTypeManager
      ->getStorage('field_config')
      ->loadMultiple($ids);

    // Check if the fields reference the vocabulary. If so, get list of bundles
    // that use those fields.
    $bundles = [];
    foreach ($field_configs as $field_config) {
      $field_name = $field_config->getName();
      $entity_type_id = $field_config->getTargetEntityTypeId();

      if ($field_config->getSetting('target_type') === 'taxonomy_term') {
        $handler_settings = $field_config->getSetting('handler_settings');
        if (isset($handler_settings['target_bundles'][$vocabulary])) {
          $bundles += $entity_reference_fields[$entity_type_id][$field_name]['bundles'];
        }
      }
    }

    // Exclude bundles that are not indexed in the API.
    return array_keys(array_intersect_key(Bundles::$bundles, $bundles));
  }

  /**
   * Display re-indexing information with enhanced formatting.
   *
   * @param string $content
   *   The content to display.
   * @param array $reindex_queue
   *   The reindex queue from state.
   */
  protected function displayReindexInfo(string $content, array $reindex_queue): void {
    // Calculate summary statistics.
    $total_terms = 0;
    $bundle_counts = [];
    foreach ($reindex_queue as $bundle => $ids) {
      if (!empty($ids)) {
        $count = count($ids);
        $total_terms += $count;
        $bundle_counts[$bundle] = $count;
      }
    }

    // Display header.
    $this->output()->writeln('');
    $this->output()->writeln('================================================================================');
    $this->output()->writeln('                    RELIEFWEB API RE-INDEXING');
    $this->output()->writeln('================================================================================');
    $this->output()->writeln('');

    // Display summary.
    $this->output()->writeln('SUMMARY:');
    $this->output()->writeln("   Total terms to re-index: {$total_terms}");
    foreach ($bundle_counts as $bundle => $count) {
      $bundle_label = ucfirst($bundle);
      $term_text = $count === 1 ? 'term' : 'terms';
      $this->output()->writeln("   {$bundle_label}: {$count} {$term_text}");
    }
    $this->output()->writeln('');

    // Display detailed information.
    if (!empty($content)) {
      $this->output()->writeln('DETAILED INFORMATION:');
      $this->output()->writeln('');

      // Convert markdown links to clickable URLs for console display.
      $content = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $content);
      $content = preg_replace('/\*\*([^*]+)\*\*/', '$1', $content);

      $lines = explode("\n", $content);
      foreach ($lines as $line) {
        if (strpos($line, '- ') === 0) {
          $this->output()->writeln("   " . $line);
        }
        else {
          $this->output()->writeln($line);
        }
      }
    }

    $this->output()->writeln('');
    $this->output()->writeln('================================================================================');
    $this->output()->writeln('');
  }

  /**
   * Gather information about terms to be re-indexed.
   *
   * @param array $reindex_queue
   *   The reindex queue from state.
   *
   * @return string
   *   Formatted markdown output with information about terms.
   */
  protected function gatherReindexInfo(array $reindex_queue): string {
    $result = [];
    $storage = $this->entityTypeManager->getStorage("taxonomy_term");
    $bundle_info = $this->bundleInfo->getBundleInfo("taxonomy_term");

    foreach ($reindex_queue as $bundle => $ids) {
      if (!empty($ids)) {
        $bundle_label = $bundle_info[$bundle]["label"] ?? ucfirst($bundle);
        $result[] = strtr("**@bundle:**\n- @terms", [
          "@bundle" => $bundle_label,
          "@terms" => implode("\n- ", array_map(function ($term) {
            return "[" . $term->label() . "](" . $term->toUrl("canonical", ["absolute" => TRUE])->toString() . ")";
          }, $storage->loadMultiple($ids))),
        ]);
      }
    }

    return !empty($result) ? implode("\n\n", $result) : '';
  }

  /**
   * Send email notification about re-indexing.
   *
   * @param string $content
   *   The content to include in the email.
   * @param array $options
   *   Command options including email settings.
   */
  protected function sendReindexNotification(string $content, array $options): void {
    $recipients = array_filter(array_map('trim', explode(',', $options['email-recipients'] ?? '')));
    if (empty($recipients)) {
      $this->logger->info('No valid email recipients specified, skipping email notification');
      return;
    }

    $subject = $options['email-subject'] ?? 'Automatic re-indexing';

    // Use Drupal's mail system to send the email.
    $this->mailManager->mail('reliefweb_api', 'reindex_notification', implode(', ', $recipients), 'en', [
      'subject' => $subject,
      'body' => $content,
    ]);

    $this->logger->info('Sent re-indexing notification email to: ' . implode(', ', $recipients));
  }

}
