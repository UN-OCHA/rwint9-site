<?php

namespace Drupal\reliefweb_job_tagger\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\ocha_ai_tag\Services\OchaAiTagTagger;
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
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, OchaAiTagTagger $job_tagger, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->jobTagger = $job_tagger;
    $this->logger = $logger_factory->get('reliefweb_job_tagger');
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
        $mapping[$vocabulary][$term->getName()] = $term->getDescription() ?? $term->getName();
        $term_cache_tags = array_merge($term_cache_tags, $term->getCacheTags());
      }
    }

    $text = $node->getTitle() . "\n\n" . $node->get('body')->value;
    $data = $this->jobTagger
      ->setVocabularies($mapping, $term_cache_tags)
      ->tag($text, [OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF], OchaAiTagTagger::AVERAGE_FULL_AVERAGE);

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
      $message[] = $this->setAiFeedback('Career category', $data['career_category'], [$term]);

      $node->set('field_career_categories', $term);
      $needs_save = TRUE;
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

}
