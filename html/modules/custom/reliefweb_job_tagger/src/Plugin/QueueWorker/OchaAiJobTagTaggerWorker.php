<?php

namespace Drupal\reliefweb_job_tagger\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
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

    if ($node->hasField('reliefweb_job_tagger_status') && $node->reliefweb_job_tagger_status->value == 'processed') {
      $this->logger->warning('Node @nid already processed, skipping', ['@nid' => $nid]);
      return;
    }

    if ($node->body->isEmpty()) {
      $this->logger->warning('No body text present for node @nid, skipping', ['@nid' => $nid]);
      return;
    }

    // Only process it when fields are empty.
    if (!$node->field_career_categories->isEmpty()) {
      $this->logger->warning('Category already specified for node @nid, skipping', ['@nid' => $nid]);
      return;
    }

    if (!$node->field_theme->isEmpty()) {
      $this->logger->warning('Theme(s) already specified for node @nid, skipping', ['@nid' => $nid]);
      return;
    }

    // Load vocabularies.
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

    $text = $node->getTitle() . "\n\n" . $node->get('body')->value;
    try {
      $data = $this->jobTagger
        ->setVocabularies($mapping, $term_cache_tags)
        ->tag($text, [OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF], OchaAiTagTagger::AVERAGE_FULL_AVERAGE);
    }
    catch (\Exception $exception) {
      $this->logger->error('Tagging exception for node @nid: @error', [
        '@nid' => $nid,
        '@error' => strtr($exception->getMessage(), "\n", " "),
      ]);
      return;
    }

    if (empty($data)) {
      $this->logger->error('No data received from AI for node @nid', ['@nid' => $nid]);
      return;
    }

    if (!isset($data[OchaAiTagTagger::AVERAGE_FULL_AVERAGE][OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF])) {
      $this->logger->error('Data "average mean with cutoff" missing from AI for node @nid', ['@nid' => $nid]);
      return;
    }

    // Use average mean with cutoff.
    $data = $data[OchaAiTagTagger::AVERAGE_FULL_AVERAGE][OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF];
    $message = [];
    $needs_save = FALSE;

    if (isset($data['career_category']) && $node->field_career_categories->isEmpty()) {
      $term = $this->getRelevantTerm('career_category', $data['career_category'], 1);
      $message[] = $this->setAiFeedback('Career category (AI)', $data['career_category'], [$term]);

      $node->set('field_career_categories', $term);
      $needs_save = TRUE;

      $use_es = $this->configFactory->get('reliefweb_job_tagger.settings')->get('use_es', FALSE);
      if ($use_es) {
        // Get ES feedback.
        $api_fields = [
          'career_categories' => 'career_category',
        ];
        $es = $this->getMostRelevantTermsFromEs('jobs', $node->id(), $api_fields, 50);
        $es = $es['career_category'];

        $ai = $data['career_category'];
        $intersect = array_intersect_key($es, $ai);
        if (!empty($intersect)) {
          // Multiple confidence levels.
          $mult = [];
          foreach (array_keys($ai) as $key) {
            if (array_key_exists($key, $es)) {
              $mult[$key] = $ai[$key] * $es[$key] * 100;
            }
          }
          arsort($mult);

          $term = $this->getRelevantTerm('career_category', $mult, 1);
          $message[] = $this->setAiFeedback('Career category (ES)', $es, [$term]);

          $node->set('field_career_categories', $term);
          $needs_save = TRUE;
        }
      }
    }

    if (isset($data['theme']) && $node->field_theme->isEmpty()) {
      $terms = $this->getRelevantTerm('theme', $data['theme'], 3);
      $message[] = $this->setAiFeedback('Theme(s)', $data['theme'], $terms);

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
  protected function getTopNumTerms($terms, $limit) {
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
  protected function getRelevantTerm($vocabulary, $data, $limit) {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $items = $this->getTopNumTerms($data, $limit);

    $terms = $storage->loadByProperties([
      'name' => $items,
      'vid' => $vocabulary,
    ]);

    return $limit === 1 ? reset($terms) : $terms;
  }

  /**
   * Construct AI feedback message.
   */
  protected function setAiFeedback($title, $data, $terms, $limit = 5) {
    $message = [];
    $message[] = '**' . $title . '**:' . "\n\n";

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
    $index = 'reliefweb_' . $resource;
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
      return [];
    }

    if ($response->getStatusCode() !== 200) {
      return [];
    }

    $data = json_decode($response->getBody()->getContents(), TRUE);
    if (empty($data['hits']['hits'])) {
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

}
