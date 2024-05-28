<?php

namespace Drupal\reliefweb_job_tagger\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_ai_tag\Services\OchaAiTagTagger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Chat form for the Ocha AI Chat module.
 */
class RwJobTagger extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The OCHA AI chat service.
   *
   * @var \Drupal\ocha_ai_tag\Services\OchaAiTagTagger
   */
  protected OchaAiTagTagger $ochaTagger;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ocha_ai_tag\Services\OchaAiTagTagger $ocha_tagger
   *   The OCHA AI tagger service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    OchaAiTagTagger $ocha_tagger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->ochaTagger = $ocha_tagger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ocha_ai_tag.tagger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?bool $popup = NULL): array {
    if ($feedback = $form_state->get('feedback')) {
      $form['feedback'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Url'),
          $this->t('Career category'),
          $this->t('Feedback'),
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
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if (!$node) {
        $results[$url] = [
          'category' => '',
          'feedback' => 'Skipped, unable to load',
        ];
        continue;
      }

      $data = $this->processDoc($node->get('body')->value, $definitions);
      $categories = $node->get('field_career_categories')->referencedEntities();
      $category = '';
      if ($categories) {
        $category = $categories[0]->label();
      }

      $results[$url] = [
        'category' => $category,
        'feedback' => $this->setAiFeedback($data),
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

}
