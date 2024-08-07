<?php

namespace Drupal\reliefweb_job_tagger\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\node\NodeInterface;
use Drupal\ocha_ai_tag\Services\OchaAiTagTagger;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extract text from a document file.
 *
 * @QueueWorker(
 *   id = "reliefweb_job_tagger",
 *   title = @Translation("Use AI to tag jobs"),
 *   cron = {"time" = 30}
 * )
 */
class OchaAiJobTagTaggerWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The tagger.
   *
   * @var \Drupal\ocha_ai_tag\Services\OchaAiTagTagger
   */
  protected $jobTagger;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    OchaAiTagTagger $job_tagger,
    LoggerChannelFactoryInterface $logger_factory,
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->jobTagger = $job_tagger;
    $this->logger = $logger_factory->get('reliefweb_job_tagger');
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('ocha_ai_tag.tagger'),
      $container->get('logger.factory'),
      $container->get('http_client'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $nid = $data->nid;

    if (empty($nid)) {
      $this->logger->warning('No nid specified, skipping');
      return;
    }

    /** @var \Drupal\node\Entity\Node $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node || $node->bundle() !== 'job') {
      $this->logger->warning('Unable to load job node @nid, skipping', ['@nid' => $nid]);
      return;
    }

    if (!$node->hasField('reliefweb_job_tagger_status')) {
      $this->logger->warning('Node @nid is missing a mandatory field, skipping', ['@nid' => $nid]);
      return;
    }

    // Skip processed jobs.
    if ($node->reliefweb_job_tagger_status->value == 'processed') {
      $this->logger->warning('Node @nid already processed, skipping', ['@nid' => $nid]);
      return;
    }

    // Skip skipped jobs.
    if ($node->reliefweb_job_tagger_status->value == 'skipped') {
      $this->logger->warning('Node @nid is skipped, skipping', ['@nid' => $nid]);
      return;
    }

    // On initial save item is marked as "queue", change it to "queued".
    if ($node->reliefweb_job_tagger_status->value == 'queue') {
      $node->set('reliefweb_job_tagger_status', 'queued');
      $node->save();
    }

    if ($node->body->isEmpty()) {
      $this->logger->warning('No body text present for node @nid, skipping', ['@nid' => $nid]);
      return $this->setJobStatusPermanent($node, 'No body text present, AI skipped');
    }

    // Only process it when fields are empty.
    if (!$node->field_career_categories->isEmpty()) {
      $this->logger->warning('Category already specified for node @nid, skipping', ['@nid' => $nid]);
      return $this->setJobStatusPermanent($node, 'Category already specified, AI skipped');
    }

    if (!$node->field_theme->isEmpty()) {
      $this->logger->warning('Theme(s) already specified for node @nid, skipping', ['@nid' => $nid]);
      return $this->setJobStatusPermanent($node, 'Theme(s) already specified, AI skipped');
    }

    // Load vocabularies for AI.
    $mapping = [];
    $term_cache_tags = [];
    $vocabularies = [
      'career_category',
      'theme',
    ];
    foreach ($vocabularies as $vocabulary) {
      $mapping[$vocabulary] = [];
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
        'status' => 1,
        'vid' => $vocabulary,
      ]);
      /** @var \Drupal\taxonomy\Entity\Term $term */
      foreach ($terms as $term) {
        if ($term->hasField('field_example_job_posting')) {
          $mapping[$vocabulary][$term->getName()] = $term->get('field_example_job_posting')->value ?? $term->getDescription() ?? $term->getName();
        }
        else {
          $mapping[$vocabulary][$term->getName()] = $term->getDescription() ?? $term->getName();
        }
        $term_cache_tags = array_merge($term_cache_tags, $term->getCacheTags());
      }
    }

    // Arrays to hold all data.
    $ai_data = [
      'career_category' => [],
      'theme' => [],
    ];
    $es_data = [
      'career_category' => [],
      'theme' => [],
    ];
    $vector_data = [
      'career_category' => [],
      'theme' => [],
    ];

    // Ask AI for theme and career category.
    $text = $node->getTitle() . "\n\n" . $node->get('body')->value;
    try {
      $ai_data = $this->jobTagger
        ->setVocabularies($mapping, $term_cache_tags)
        ->tag($text, [OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF], OchaAiTagTagger::AVERAGE_FULL_AVERAGE);
    }
    catch (\Exception $exception) {
      $this->logger->error('Tagging exception for node @nid: @error', [
        '@nid' => $nid,
        '@error' => strtr($exception->getMessage(), "\n", " "),
      ]);

      return $this->setJobStatusTemporary($node, 'AI tagging failed, AI skipped');
    }

    if (empty($ai_data)) {
      $this->logger->error('No data received from AI for node @nid', ['@nid' => $nid]);
      return $this->setJobStatusTemporary($node, 'AI tagging failed, AI skipped');
    }

    if (!isset($ai_data[OchaAiTagTagger::AVERAGE_FULL_AVERAGE][OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF])) {
      $this->logger->error('Data "average mean with cutoff" missing from AI for node @nid', ['@nid' => $nid]);
      return $this->setJobStatusTemporary($node, 'AI tagging failed, AI skipped');
    }

    // Use average mean with cutoff.
    $ai_data = $ai_data[OchaAiTagTagger::AVERAGE_FULL_AVERAGE][OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF];

    // Do a similarity search on Elastic.
    $use_es = $this->configFactory->get('reliefweb_job_tagger.settings')->get('use_es', FALSE);
    if ($use_es) {
      // Get ES feedback.
      $api_fields = [
        'career_categories' => 'career_category',
        'theme' => 'theme',
      ];
      // Doc isn't indexed yet.
      $es_data = $this->getMostRelevantTermsFromEs('jobs', [
        'id' => $node->id(),
        'title' => $node->getTitle(),
        'body' => $node->body->value,
      ], $api_fields, 50);
    }

    // Do a vector search on Elastic.
    $use_vector = $this->configFactory->get('reliefweb_job_tagger.settings')->get('use_vector', FALSE);
    if ($use_vector) {
      $vector_data = [
        'career_category' => $this->getSimilarJobs($node, 'field_career_categories'),
        'theme' => $this->getSimilarJobs($node, 'field_theme'),
      ];
    }

    $message = [];
    $needs_save = FALSE;

    if ($node->field_career_categories->isEmpty()) {
      if (!empty($ai_data['career_category'] ?? [])) {
        $terms = $this->getRelevantTerm('career_category', $ai_data['career_category'], 1);
        $message[] = $this->setAiFeedback('Career category (AI)', $ai_data['career_category'], $terms);
      }

      if (!empty($es_data['career_category'] ?? [])) {
        $terms = $this->getRelevantTerm('career_category', $es_data['career_category'], 1);
        $message[] = $this->setAiFeedback('Career category (ES)', $es_data['career_category'], $terms);
      }

      if (!empty($vector_data['career_category'] ?? [])) {
        $terms = $this->getRelevantTerm('career_category', $vector_data['career_category'], 1);
        $message[] = $this->setAiFeedback('Career category (Vector)', $vector_data['career_category'], $terms);
      }

      $mult = [];

      // Get all array keys.
      $keys = array_merge(
        array_keys($ai_data['career_category'] ?? []),
        array_keys($es_data['career_category'] ?? []),
        array_keys($vector_data['career_category'] ?? []),
      );

      // Multiple confidence levels, if not defined fall back to 20%.
      foreach ($keys as $key) {
        $mult[$key] = ($ai_data[$key] ?? .1);
        if ($use_es) {
          $mult[$key] *= ($es_data[$key] ?? .2);
        }
        if ($use_vector) {
          $mult[$key] *= ($vector_data[$key] ?? .2);
        }
      }

      // Sort reversed and select first.
      arsort($mult);

      $terms = $this->getRelevantTerm('career_category', $mult, 1);
      $message[] = $this->setAiFeedback('Career category', $mult, $terms);

      $node->set('field_career_categories', $terms);
      $needs_save = TRUE;
    }

    if ($node->field_theme->isEmpty()) {
      if (!empty($ai_data['theme'] ?? [])) {
        $terms = $this->getRelevantTerm('theme', $ai_data['theme'], 3);
        $message[] = $this->setAiFeedback('Themes (AI)', $ai_data['theme'], $terms);
      }

      if (!empty($es_data['theme'] ?? [])) {
        $terms = $this->getRelevantTerm('theme', $es_data['theme'], 3);
        $message[] = $this->setAiFeedback('Themes (ES)', $es_data['theme'], $terms);
      }

      if (!empty($vector_data['theme'] ?? [])) {
        $terms = $this->getRelevantTerm('theme', $vector_data['theme'], 3);
        $message[] = $this->setAiFeedback('Themes (Vector)', $vector_data['theme'], $terms);
      }

      $mult = [];

      // Get all array keys.
      $keys = array_merge(
        array_keys($ai_data['theme'] ?? []),
        array_keys($es_data['theme'] ?? []),
        array_keys($vector_data['theme'] ?? []),
      );

      // Multiple confidence levels, if not defined fall back to 20%.
      foreach ($keys as $key) {
        $mult[$key] = ($ai_data[$key] ?? .1);
        if ($use_es) {
          $mult[$key] *= ($es_data[$key] ?? .2);
        }
        if ($use_vector) {
          $mult[$key] *= ($vector_data[$key] ?? .2);
        }
      }

      // Sort reversed and select first.
      arsort($mult);

      $terms = $this->getRelevantTerm('theme', $mult, 3);
      $message[] = $this->setAiFeedback('Themes', $mult, $terms);

      $node->set('field_theme', $terms);
      $needs_save = TRUE;
    }

    if ($needs_save) {
      if ($node->hasField('reliefweb_job_tagger_info')) {
        $node->set('reliefweb_job_tagger_info', [
          'value' => implode("\n\n", $message),
          'format' => 'markdown',
        ]);
      }

      $node->setRevisionCreationTime(time());
      $node->setRevisionLogMessage('Job has been updated by AI.');
      $node->set('reliefweb_job_tagger_status', 'processed');
      $node->setNewRevision(TRUE);
      $node->save();
      $this->logger->info('Node @nid updated with data from AI', ['@nid' => $nid]);
    }
  }

  /**
   * Get top 3 relevant terms.
   */
  protected function getTopNumTerms($terms, $limit) : array {
    $result = [];

    $terms = array_slice($terms, 0, $limit, TRUE);

    foreach ($terms as $term => $score) {
      // Add first one regardless of score.
      if (empty($result)) {
        $result[] = $term;
        continue;
      }

      if ($score > .25) {
        $result[] = $term;
      }

    }

    return $result;
  }

  /**
   * Get relevant terms.
   */
  protected function getRelevantTerm(string $vocabulary, array $data, int $limit = 5) : array {
    if (empty($data)) {
      return $limit === 1 ? NULL : [];
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $items = $this->getTopNumTerms($data, $limit);

    $terms = $storage->loadByProperties([
      'name' => $items,
      'vid' => $vocabulary,
    ]);

    return $terms;
  }

  /**
   * Construct AI feedback message.
   */
  protected function setAiFeedback(string $title, array $data, array $terms, $limit = 5) {
    $message = [];
    $message[] = '**' . $title . '**:' . "\n\n";

    if (empty($data)) {
      $message[] = "*No results.*\n";
      return implode('', $message);
    }

    // Normalize the data. This will result in the most relevant term having
    // a score of 1. The scores otherwise don't mean much.
    // For the AI, it's the similarity between the term description and the job,
    // which will always be fairly low since the job's content contains much
    // more info than just the term description's content. For Elasticsearch,
    // it's not really a  similarity score, more a score for the relevance of a
    // document to the query.
    // So they can not be directly represented as a percentage of confidence.
    // Maybe, instead of showing a numeric score, it would better to use
    // descriptive labels like "best match" or tiered categories like "highly
    // relevant".
    $min = min($data);
    $max = max($data);
    if ($max > $min) {
      foreach ($data as $key => $item) {
        $data[$key] = ($data[$key] - $min) / ($max - $min);
      }
    }

    // Max 5 items.
    $items = array_slice($data, 0, $limit);

    // Selected terms.
    $selected = array_map(
      function ($term) {
        return $term->getName();
      },
      $terms,
    );

    foreach ($items as $key => $confidence) {
      if (in_array($key, $selected)) {
        $message[] = '- **' . $key . '**: ' . floor(100 * $confidence) . '%' . "\n";
      }
      else {
        $message[] = '- ' . $key . ': ' . floor(100 * $confidence) . '%' . "\n";
      }
    }

    return implode('', $message);
  }

  /**
   * Generate a list of terms sorted by relevance to a document.
   *
   * We retrieve a list of the most similar documents using a "more like this"
   * query on the Elasticsearch index, extract the terms from the documents
   * and sort them using the "similarity" score from the "more like this" query.
   *
   * @param string $resource
   *   The API resource.
   * @param int|array $document
   *   Either the document ID if the document is already indexed or an
   *   associative array with the document's title and description.
   * @param array $fields
   *   Associative array with the vocabularies to retrieve keyed by the
   *   corresponding Elasticsearch fields.
   * @param int $limit
   *   Maxium number of similar documents to retrieve. Defaults to 10.
   * @param array $parameters
   *   Parameters for the more like this query.
   *
   * @return array
   *   Associative array keyed by vocabulary with maps of term to relevance as
   *   values.
   */
  public function getMostRelevantTermsFromEs(
    string $resource,
    int|array $document,
    array $fields,
    int $limit = 10,
    // This can be adjusted but seems to give good results.
    array $parameters = [
      'min_term_freq' => 3,
      'min_word_length' => 4,
      'min_doc_freq' => 4,
      'max_query_terms' => 40,
      'boost_terms' => 10,
      'minimum_should_match' => '60%',
    ],
  ): array {
    $index = $this->configFactory->get('reliefweb_api.settings')->get('base_index_name') . '_' . $resource;
    $url = $this->configFactory->get('reliefweb_api.settings')->get('elasticsearch') . '/' . $index . '/_search';

    // If the document is indexed, we can simply use it's ID.
    if (is_int($document)) {
      $entity_id = (int) $document;
      $like = [
        '_id' => $entity_id,
      ];

      // This filter is either the given document or published or expired
      // documents. This ensures the given document is returned so we can
      // normalize the scores.
      $filter = [
        [
          'bool' => [
            'should' => [
              [
                'term' => [
                  'id' => $document,
                ],
              ],
              [
                'terms' => [
                  'status' => ['published', 'expired'],
                ],
              ],
            ],
          ],
        ],
      ];
    }
    // Otherwise we pass the title and body and let Elasticsearch analyze those
    // as if they were to be indexed.
    elseif (isset($document['id'], $document['title'], $document['body'])) {
      $entity_id = (int) $document['id'];
      $like = [
        'doc' => [
          'id' => $entity_id,
          'title' => $document['title'],
          'body' => $document['body'],
          'status' => 'published',
        ],
      ];

      // Here, we can only filter on the published/expired documents.
      $filter = [
        [
          'terms' => [
            'status' => ['published', 'expired'],
          ],
        ],
      ];
    }
    else {
      return [];
    }

    $payload = [
      'query' => [
        'bool' => [
          'must' => [
            [
              'more_like_this' => [
                // Only compare the title and body. We could extend that to
                // include the source etc. but that's more similar to the data
                // used for the embeddings comparison.
                'fields' => ['title', 'body'],
                'like' => [
                  [
                    '_index' => $index,
                  ] + $like,
                ],
                // This is important: Elasticsearch returns scores that relative
                // to the current search query so to have some comparable we
                // need to normalize the scores. With the following parameter,
                // the document is included in the results (= the first item in
                // the results with the higher score). We can then use this max
                // score to normalize the other scores.
                'include' => TRUE,
              ] + $parameters,
            ],
          ],
          // We can only filter on the status. The reviewer user ID is not in
          // the API so we cannot limit to similar documents reviewed by
          // editors. That means the list of similar documents may include
          // documents from trusted users with possibly less correct term
          // selection.
          'filter' => $filter,
        ],
      ],
      '_source' => array_merge(['id'], array_keys($fields)),
      // The document to compare is included so we increase the limit by 1 to
      // account for that.
      'size' => $limit + 1,
    ];

    try {
      $response = $this->httpClient->post($url, [
        'json' => $payload,
      ]);
    }
    catch (\Exception $exception) {
      $this->logger->error(strtr('ES similarity for @id. Exception: @error', [
        '@id' => $entity_id,
        '@error' => $exception->getMessage(),
      ]));
      return [];
    }

    if ($response->getStatusCode() !== 200) {
      $this->logger->error(strtr('ES similarity for @id. Failure: @error', [
        '@id' => $entity_id,
        '@error' => $response->getStatusCode() . ': ' . ($response->getBody()?->getContents() ?? ''),
      ]));
      return [];
    }

    $data = json_decode($response->getBody()->getContents(), TRUE);
    if (empty($data['hits']['hits'])) {
      $this->logger->warning(strtr('ES similarity for @id. No hits found.', [
        '@id' => $entity_id,
      ]));
      return [];
    }

    // Aggregate the scores for each term of each vocabulary.
    $max_score = 0;
    $vocabularies = [];
    foreach ($data['hits']['hits'] as $item) {
      $source = $item['_source'];
      $score = $item['_score'];
      $id = (int) $source['id'];

      if ($score > $max_score) {
        $max_score = $score;
      }

      // Skip the original document because it's only included to normalize the
      // similarity scores of the other documents.
      if ($id === $entity_id) {
        continue;
      }

      // Aggregate the scores for each term of each vocabulary.
      foreach ($fields as $field => $vocabulary) {
        $terms = [];
        if (!empty($source[$field])) {
          foreach ($source[$field] as $term) {
            $vocabularies[$vocabulary][$term['name']][] = $score;
          }
        }
      }
    }

    if (empty($vocabularies)) {
      $this->logger->notice(strtr('ES similarity for @id. No similar documents found.', [
        '@id' => $entity_id,
      ]));
      return [];
    }

    // Calculate the nornmalized mean of the scores for each term.
    foreach ($vocabularies as $vocabulary => $terms) {
      foreach ($terms as $term => $scores) {
        $vocabularies[$vocabulary][$term] = array_sum($scores) / $max_score / count($scores);
      }
      // Sort the terms by relevance.
      arsort($vocabularies[$vocabulary]);
    }

    return $vocabularies;
  }

  /**
   * Get similar jobs.
   */
  protected function getSimilarJobs(NodeInterface $node, string $field) {
    $nid = $node->id();
    $relevant = &drupal_static('reliefweb_job_tagger::' . $nid);
    if (!isset($relevant)) {
      $relevant = $this->jobTagger->getSimilarDocuments($nid, $node->get('body')->value);
    }

    if (empty($relevant)) {
      return [];
    }

    $max = reset($relevant);

    /** @var \Drupal\node\Entity\Node[] $nodes */
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple(array_keys($relevant));

    if (isset($nodes[$nid])) {
      unset($nodes[$nid]);
    }

    $terms = [];
    foreach ($nodes as $node) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        foreach ($node->get($field)->referencedEntities() as $term) {
          if (!isset($terms[$term->label()])) {
            $terms[$term->label()] = ($relevant[$node->id()] ?? .1) / $max;
          }
          else {
            $terms[$term->label()] *= ($relevant[$node->id()] ?? .1) / $max;
          }
        }
      }
    }

    // Sort reversed by count.
    arsort($terms);

    return $terms;
  }

  /**
   * Set job status and revision log for permanent problem.
   */
  protected function setJobStatusPermanent(NodeInterface &$node, string $message) {
    $node->setRevisionCreationTime(time());
    $node->setRevisionLogMessage($message);

    $node->set('reliefweb_job_tagger_status', 'processed');
    $node->setNewRevision(TRUE);
    $node->save();
  }

  /**
   * Set job status and revision log for temporary problem.
   */
  protected function setJobStatusTemporary(NodeInterface &$node, string $message) {
    $node->setRevisionCreationTime(time());
    $node->setRevisionLogMessage($message);

    $queue_count = 0;
    if ($node->hasField('field_job_tagger_queue_count')) {
      $queue_count = $node->get('field_job_tagger_queue_count')->value ?? 1;
      $queue_count++;
    }
    else {
      // Field missing, force skip.
      $queue_count = 99;
    }

    if ($queue_count >= 3) {
      // Mark job as skipped, so editors can manually re-queue.
      $node->set('reliefweb_job_tagger_status', 'skipped');
      $log_message = $node->getRevisionLogMessage();
      $log_message .= (empty($log_message) ? '' : ' ') . 'Job has been queued 3 times, maximum reached.';
      $node->setRevisionLogMessage($log_message);
      $node->set('field_job_tagger_queue_count', $queue_count);
      $node->setNewRevision(TRUE);
      $node->save();

      // Return so item is removed from queue.
      return;
    }

    // Save initial log message.
    $node->set('field_job_tagger_queue_count', $queue_count);
    $node->setNewRevision(TRUE);
    $node->save();

    // Leave item in the queue, but stop queue processing.
    throw new SuspendQueueException($message);
  }

}
