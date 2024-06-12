<?php

namespace Drupal\reliefweb_job_tagger\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_ai_tag\Services\OchaAiTagTagger;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Chat form for the Ocha AI Chat module.
 */
class RwJobTagger extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected OchaAiTagTagger $ochaTagger,
    protected ClientInterface $httpClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ocha_ai_tag.tagger'),
      $container->get('http_client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?bool $popup = NULL): array {
    $intro = [
      'On this page you can test how the AI Job Tagger will classify jobs, based on the key phrases defined for each career category.',
      '',
      '## Steps',
      '1. Select one or more URL\s',
      '2. Adapt the key phrases',
      '3. Analyze the jobs',
      '4. Review you the feedback',
      '5. Adapt the key phrases (if needed)',
      '',
      'Keep in mind that all changes will **NOT** be saved.',
    ];
    $form['intro'] = [
      '#type' => 'processed_text',
      '#text' => implode("\n", $intro),
      '#format' => 'markdown',
    ];

    if ($feedback = $form_state->get('feedback')) {
      $form['feedback'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Url'),
          $this->t('Career category'),
          $this->t('Feedback (AI)'),
          $this->t('Feedback (ES)'),
          $this->t('Info'),
        ],
      ];

      foreach ($feedback as $url => $data) {
        $form['feedback'][$url]['url'] = [
          '#markup' => $url,
        ];

        $form['feedback'][$url]['category'] = [
          '#markup' => $data['category'],
        ];

        $form['feedback'][$url]['feedback'] = [
          '#type' => 'processed_text',
          '#text' => $data['feedback'],
          '#format' => 'markdown',
        ];

        $form['feedback'][$url]['es_feedback'] = [
          '#type' => 'processed_text',
          '#text' => $data['es_feedback'],
          '#format' => 'markdown',
        ];

        $form['feedback'][$url]['info'] = [
          '#type' => 'processed_text',
          '#text' => $data['info'],
          '#format' => 'markdown',
        ];
      }
    }

    $form['urls'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Job Urls'),
      '#description' => $this->t('Enter one or more Urls to job postings.'),
      '#required' => TRUE,
    ];

    $form['definitions'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Career category'),
        $this->t('Key phrases'),
      ],
    ];

    $definitions = $form_state->get('definitions') ?? [];
    if (empty($definitions)) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
        'status' => 1,
        'vid' => 'career_category',
      ]);

      /** @var \Drupal\taxonomy\Entity\Term $term */
      foreach ($terms as $term) {
        $definitions[$term->id()] = [
          'name' => $term->getName(),
          'definition' => $term->get('field_example_job_posting')->value ?? $term->getDescription() ?? $term->getName(),
        ];
      }
    }

    foreach ($definitions as $id => $definition) {
      $form['definitions'][$id]['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#title_display' => 'hidden',
        '#required' => TRUE,
        '#value' => $definition['name'],
        '#disabled' => TRUE,
        '#atttibutes' => [
          'disabled' => 'disabled',
          'readonly' => 'readonly',
        ],
      ];
      $form['definitions'][$id]['definition'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Definition'),
        '#title_display' => 'hidden',
        '#required' => TRUE,
        '#value' => $definition['definition'],
      ];
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Analyze jobs'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $api_fields = [
      'career_categories' => 'career_category',
    ];

    $definitions = $form_state->getValue('definitions', []);
    $form_state->set('definitions', $definitions);
    $this->setTermMapping($definitions);

    $results = [];
    $urls = $form_state->getValue('urls', '');
    $urls = explode("\n", $urls);

    foreach ($urls as $url) {
      $url = trim($url);
      $path = parse_url($url, PHP_URL_PATH);
      $parts = explode('/', $path);

      if (!isset($parts[2]) || !is_numeric($parts[2])) {
        $results[$url] = [
          'category' => '',
          'feedback' => 'Skipped, use URL like https://reliefweb.int/job/4064890/country-director-haiti',
        ];
        continue;
      }

      $nid = $parts[2];
      /** @var \Drupal\node\Entity\Node $node */
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if (!$node) {
        $results[$url] = [
          'category' => '',
          'feedback' => 'Skipped, unable to load',
        ];
        continue;
      }

      $info = [];

      // Get field data.
      $categories = $node->get('field_career_categories')->referencedEntities();
      $category = '';
      if ($categories) {
        $category = $categories[0]->label();
      }

      // Get ES feedback.
      $es = $this->getMostRelevantTermsFromEs('jobs', $node->id(), $api_fields, 50);
      $es = $es['career_category'] ?? [];

      $es_first = '';
      $ai_first = '';
      if (!empty($es) && isset($es)) {
        $es_feedback = $this->setAiFeedback($es);
        $es_first = array_key_first($es);
        $first = reset($es);
        if ($first > .70) {
          $info[] = '- High ES confidence, skip AI';
        }
        elseif ($first > .50) {
          $info[] = '- Average ES confidence';
        }
      }

      // Get AI feedback.
      $text = $node->getTitle() . "\n\n" . $node->get('body')->value;
      $ai = $this->processDoc($text, $definitions);
      $ai_first = array_key_first($ai);

      if ($ai_first == $es_first) {
        $info[] = '- AI and ES agree';
      }

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
        $info[] = '- First in common: ' . array_key_first($mult);
      }

      $results[$url] = [
        'category' => $category,
        'feedback' => $this->setAiFeedback($ai, 10),
        'es_feedback' => $es_feedback,
        'info' => implode("\n", $info),
      ];
    }

    $form_state->set('feedback', $results);
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'rw_job_tagger';
  }

  /**
   * Set term mapping.
   */
  protected function setTermMapping(array $definitions) : void {
    $mapping = [
      'career_category' => [],
    ];

    foreach ($definitions as $definition) {
      $mapping['career_category'][$definition['name']] = $definition['definition'];
    }

    $term_cache_tags = [];

    $this->ochaTagger
      ->setVocabularies($mapping, $term_cache_tags)
      ->clearCache();
  }

  /**
   * Analyze document.
   */
  protected function processDoc(string $text) : array {
    $data = $this->ochaTagger
      ->tag($text, [OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF], OchaAiTagTagger::AVERAGE_FULL_AVERAGE);

    $data = $data[OchaAiTagTagger::AVERAGE_FULL_AVERAGE][OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF];

    return $data['career_category'] ?? [];
  }

  /**
   * Construct AI feedback message.
   */
  protected function setAiFeedback($data, $limit = 5) {
    $message = [];

    // Max n items.
    $items = array_slice($data, 0, $limit);

    foreach ($items as $key => $confidence) {
      $message[] = '- ' . $key . ': ' . floor(100 * $confidence) . '%' . "\n";
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
    $url = $this->config('reliefweb_api.settings')->get('elasticsearch') . '/' . $index . '/_search';

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
