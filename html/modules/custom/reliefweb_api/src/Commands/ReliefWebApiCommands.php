<?php

namespace Drupal\reliefweb_api\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drush\Commands\DrushCommands;
use RWAPIIndexer\Manager;
use RWAPIIndexer\Bundles;

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
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    StateInterface $state
  ) {
    $this->apiConfig = $config_factory->get('reliefweb_api.settings');
    $this->moduleHandler = $module_handler;
    $this->state = $state;
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
  public function index($bundle = '', array $options = [
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
  ]) {
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
      $this->logger->error('(' . $exception->getCode() . ') ' . $exception->getMessage());
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
  public function replace($bundle, $newtag, $oldtag = NULL, array $options = [
    'elasticsearch' => '',
    'base-index-name' => '',
  ]) {
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
   * @usage reliefweb-api:reindexqueue
   *   Re-index the queue of terms to update.
   *
   * @validate-module-enabled reliefweb_api
   *
   * @aliases rw-api:reindexqueue
   */
  public function reIndexQueue() {

    $reindex_queue = json_decode($this->state->get('reliefweb_api.reindex_queue'));

    if (empty($reindex_queue)) {
      return;
    }

    // Use default values from $this->index().
    $options = [
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
    ];
    foreach (array_keys($reindex_queue) as $bundle) {
      if (empty($reindex_queue[$bundle])) {
        continue;
      }
      // Launch the re-indexing.
      try {
        $options['filter'] = $bundle . ':' . implode(',', $reindex_queue[$bundle]);
        if ($bundle == 'country' || $bundle == 'source') {
          $this->index('training', $options);
          $this->index('job', $options);
        }
        $this->index('report', $options);
      }
      catch (\Exception $exception) {
        $this->logger->error('(' . $exception->getCode() . ') ' . $exception->getMessage());
      }
      $reindex_queue[$bundle] = [];
    }

    $this->state->set('reliefweb_api.reindex_queue', json_encode($reindex_queue));

  }

}
