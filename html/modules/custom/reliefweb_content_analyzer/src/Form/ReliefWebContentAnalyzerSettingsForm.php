<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_moderation\Services\ReportModeration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for ReliefWeb content analyzer settings.
 */
class ReliefWebContentAnalyzerSettingsForm extends ConfigFormBase {

  private const WEIGHT_SUM_TOLERANCE = 0.001;

  /**
   * Constructs the settings form.
   *
   * @param \Drupal\reliefweb_moderation\Services\ReportModeration $reportModeration
   *   Report moderation service for status select options.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Entity field manager for field name validation.
   */
  public function __construct(
    protected readonly ReportModeration $reportModeration,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('reliefweb_moderation.report.moderation'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['reliefweb_content_analyzer.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_content_analyzer_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('reliefweb_content_analyzer.settings');
    $matching = $config->get('report_series_matching');
    $workflow = $matching['workflow'];
    $matcher = $matching['matcher'];
    $moderation_options = $this->reportModeration->getStatuses();

    $form['report_series_matching'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Report series matching'),
    ];

    $form['automation'] = [
      '#type' => 'details',
      '#title' => $this->t('Automation'),
      '#group' => 'report_series_matching',
      '#tree' => TRUE,
    ];

    $form['automation']['automation_enabled_form_created'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable for reports created via the editorial form'),
      '#description' => $this->t('When enabled, automated series matching runs on new report saves from the editorial form only for users with the “Apply report series matching automation on form create” permission.'),
      '#default_value' => $workflow['automation_enabled_form_created'],
    ];

    $form['automation']['automation_enabled_imported'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable for reports submitted via Post API or import'),
      '#default_value' => $workflow['automation_enabled_imported'],
    ];

    $form['confidence'] = [
      '#type' => 'details',
      '#title' => $this->t('Confidence thresholds'),
      '#group' => 'report_series_matching',
      '#tree' => TRUE,
    ];

    $form['confidence']['minimum_series_confidence'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum series confidence'),
      '#description' => $this->t('Minimum series confidence required to apply a match.'),
      '#default_value' => $workflow['minimum_series_confidence'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['confidence']['minimum_tagging_confidence'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum tagging confidence floor'),
      '#description' => $this->t('Tagging scores below this value are forced to the low tier.'),
      '#default_value' => $workflow['minimum_tagging_confidence'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['confidence']['series_confidence_tiers'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Series confidence tiers'),
      '#tree' => TRUE,
    ];

    $form['confidence']['series_confidence_tiers']['high'] = [
      '#type' => 'number',
      '#title' => $this->t('High tier lower bound'),
      '#default_value' => $workflow['series_confidence_tiers']['high'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['confidence']['series_confidence_tiers']['medium'] = [
      '#type' => 'number',
      '#title' => $this->t('Medium tier lower bound'),
      '#default_value' => $workflow['series_confidence_tiers']['medium'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['confidence']['tagging_confidence_tiers'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Tagging confidence tiers'),
      '#tree' => TRUE,
    ];

    $form['confidence']['tagging_confidence_tiers']['high'] = [
      '#type' => 'number',
      '#title' => $this->t('High tier lower bound'),
      '#default_value' => $workflow['tagging_confidence_tiers']['high'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['confidence']['tagging_confidence_tiers']['medium'] = [
      '#type' => 'number',
      '#title' => $this->t('Medium tier lower bound'),
      '#default_value' => $workflow['tagging_confidence_tiers']['medium'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['moderation'] = [
      '#type' => 'details',
      '#title' => $this->t('Moderation'),
      '#group' => 'report_series_matching',
      '#tree' => TRUE,
    ];

    $form['moderation']['moderation_by_outcome_tier'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Moderation state per outcome tier'),
      '#tree' => TRUE,
    ];

    foreach (['low', 'medium', 'high'] as $tier) {
      $form['moderation']['moderation_by_outcome_tier'][$tier] = [
        '#type' => 'select',
        '#title' => $this->t('@tier outcome tier', ['@tier' => ucfirst($tier)]),
        '#options' => $moderation_options,
        '#default_value' => $workflow['moderation_by_outcome_tier'][$tier],
        '#required' => TRUE,
      ];
    }

    $form['moderation']['skip_series_match_moderation_statuses'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Skip series match moderation statuses'),
      '#description' => $this->t('One moderation state machine name per line. Series matching is skipped at detect presave when the entity has one of these statuses.'),
      '#default_value' => $this->sequenceToLines($workflow['skip_series_match_moderation_statuses']),
      '#rows' => 4,
      '#required' => TRUE,
    ];

    $form['moderation']['restrictiveness_order'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Restrictiveness order'),
      '#description' => $this->t('One moderation state machine name per line, most restrictive first.'),
      '#default_value' => $this->sequenceToLines($workflow['restrictiveness_order']),
      '#rows' => 8,
      '#required' => TRUE,
    ];

    $form['matcher'] = [
      '#type' => 'details',
      '#title' => $this->t('Matcher algorithm'),
      '#group' => 'report_series_matching',
      '#tree' => TRUE,
    ];

    $form['matcher']['minimum_series_report_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum reports per series cluster'),
      '#default_value' => $matcher['minimum_series_report_count'],
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['matcher']['series_candidate_date_range_months'] = [
      '#type' => 'number',
      '#title' => $this->t('Candidate lookback window (months)'),
      '#default_value' => $matcher['series_candidate_date_range_months'],
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['matcher']['series_candidate_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum series candidates'),
      '#default_value' => $matcher['series_candidate_limit'],
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['matcher']['ai_title_generation_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI title generation'),
      '#description' => $this->t('When disabled, import titles that do not already match the series pattern are left unchanged.'),
      '#default_value' => $matcher['ai_title_generation_enabled'] ?? TRUE,
    ];

    $form['matcher']['ai_title_source_length_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('AI title source text length limit'),
      '#default_value' => $matcher['ai_title_source_length_limit'],
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['matcher']['ai_title_example_line_count'] = [
      '#type' => 'number',
      '#title' => $this->t('AI title example count'),
      '#default_value' => $matcher['ai_title_example_line_count'],
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['matcher']['ai_title_description_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('AI title description template'),
      '#description' => $this->t('Use @examples as a placeholder for numbered example titles.'),
      '#default_value' => $matcher['ai_title_description_template'],
      '#rows' => 3,
      '#required' => TRUE,
    ];

    $form['matcher']['pattern_token_counts'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pattern token counts'),
      '#description' => $this->t('Comma-separated positive integers, e.g. 10, 8, 6, 4.'),
      '#default_value' => implode(', ', $matcher['pattern_token_counts']),
      '#required' => TRUE,
    ];

    $form['matcher']['candidate_clustering_tagging_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Candidate clustering tagging weight'),
      '#default_value' => $matcher['candidate_clustering_tagging_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['matcher']['candidate_clustering_title_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Candidate clustering title weight'),
      '#default_value' => $matcher['candidate_clustering_title_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['matcher']['candidate_clustering_similarity_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Candidate clustering similarity threshold'),
      '#default_value' => $matcher['candidate_clustering_similarity_threshold'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['matcher']['cluster_scoring_size_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Cluster scoring size weight'),
      '#default_value' => $matcher['cluster_scoring_size_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['matcher']['cluster_scoring_pattern_score_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Cluster scoring pattern score weight'),
      '#default_value' => $matcher['cluster_scoring_pattern_score_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['matcher']['cluster_scoring_tagging_consistency_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Cluster scoring tagging consistency weight'),
      '#default_value' => $matcher['cluster_scoring_tagging_consistency_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    $form['matcher']['cluster_comparison_field_names'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cluster comparison fields'),
      '#description' => $this->t('One field machine name per line.'),
      '#default_value' => $this->sequenceToLines($matcher['cluster_comparison_field_names']),
      '#rows' => 4,
      '#required' => TRUE,
    ];

    $form['matcher']['recency_field_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recency field'),
      '#default_value' => $matcher['recency_field_name'],
      '#required' => TRUE,
    ];

    $form['matcher']['report_entity_field_names_to_copy'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Fields to copy to the report'),
      '#description' => $this->t('One field machine name per line.'),
      '#default_value' => $this->sequenceToLines($matcher['report_entity_field_names_to_copy']),
      '#rows' => 6,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $this->validateUnitInterval(
      $form_state,
      ['confidence', 'minimum_series_confidence'],
      $this->t('Minimum series confidence must be between 0 and 1.'),
    );
    $this->validateUnitInterval(
      $form_state,
      ['confidence', 'minimum_tagging_confidence'],
      $this->t('Minimum tagging confidence must be between 0 and 1.'),
    );

    $this->validateTierPair(
      $form_state,
      ['confidence', 'series_confidence_tiers'],
      $this->t('Series confidence high tier must be greater than medium tier.'),
    );
    $this->validateTierPair(
      $form_state,
      ['confidence', 'tagging_confidence_tiers'],
      $this->t('Tagging confidence high tier must be greater than medium tier.'),
    );

    $tagging_weight = (float) $form_state->getValue(['matcher', 'candidate_clustering_tagging_weight']);
    $title_weight = (float) $form_state->getValue(['matcher', 'candidate_clustering_title_weight']);
    if (abs(($tagging_weight + $title_weight) - 1.0) > self::WEIGHT_SUM_TOLERANCE) {
      $form_state->setErrorByName(
        'matcher][candidate_clustering_tagging_weight',
        $this->t('Candidate clustering tagging and title weights must sum to 1.'),
      );
    }

    $scoring_sum = (float) $form_state->getValue(['matcher', 'cluster_scoring_size_weight'])
      + (float) $form_state->getValue(['matcher', 'cluster_scoring_pattern_score_weight'])
      + (float) $form_state->getValue(['matcher', 'cluster_scoring_tagging_consistency_weight']);
    if (abs($scoring_sum - 1.0) > self::WEIGHT_SUM_TOLERANCE) {
      $form_state->setErrorByName(
        'matcher][cluster_scoring_size_weight',
        $this->t('Cluster scoring weights must sum to 1.'),
      );
    }

    $pattern_counts = $this->parsePatternTokenCounts(
      (string) $form_state->getValue(['matcher', 'pattern_token_counts']),
    );
    if ($pattern_counts === NULL) {
      $form_state->setErrorByName(
        'matcher][pattern_token_counts',
        $this->t('Pattern token counts must be comma-separated positive integers.'),
      );
    }
    else {
      $form_state->setValue(['matcher', 'pattern_token_counts_parsed'], $pattern_counts);
    }

    $field_lists = [
      'cluster_comparison_field_names' => $this->t('Cluster comparison fields'),
      'report_entity_field_names_to_copy' => $this->t('Fields to copy to the report'),
    ];
    foreach ($field_lists as $key => $label) {
      $fields = $this->linesToSequence((string) $form_state->getValue(['matcher', $key]));
      $unknown = $this->unknownReportFields($fields);
      if ($unknown !== []) {
        $form_state->setErrorByName(
          'matcher][' . $key,
          $this->t('@label: unknown field(s): @fields', [
            '@label' => $label,
            '@fields' => implode(', ', $unknown),
          ]),
        );
      }
    }

    $recency_field = trim((string) $form_state->getValue(['matcher', 'recency_field_name']));
    $unknown_recency = $this->unknownReportFields([$recency_field]);
    if ($unknown_recency !== []) {
      $form_state->setErrorByName(
        'matcher][recency_field_name',
        $this->t('Recency field is not defined on report nodes: @field', [
          '@field' => $recency_field,
        ]),
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('reliefweb_content_analyzer.settings');

    $config->set(
      'report_series_matching.workflow.automation_enabled_form_created',
      (bool) $form_state->getValue(['automation', 'automation_enabled_form_created']),
    );
    $config->set(
      'report_series_matching.workflow.automation_enabled_imported',
      (bool) $form_state->getValue(['automation', 'automation_enabled_imported']),
    );

    $config->set(
      'report_series_matching.workflow.minimum_series_confidence',
      (float) $form_state->getValue(['confidence', 'minimum_series_confidence']),
    );
    $config->set(
      'report_series_matching.workflow.minimum_tagging_confidence',
      (float) $form_state->getValue(['confidence', 'minimum_tagging_confidence']),
    );
    $config->set('report_series_matching.workflow.series_confidence_tiers', [
      'high' => (float) $form_state->getValue(['confidence', 'series_confidence_tiers', 'high']),
      'medium' => (float) $form_state->getValue(['confidence', 'series_confidence_tiers', 'medium']),
    ]);
    $config->set('report_series_matching.workflow.tagging_confidence_tiers', [
      'high' => (float) $form_state->getValue(['confidence', 'tagging_confidence_tiers', 'high']),
      'medium' => (float) $form_state->getValue(['confidence', 'tagging_confidence_tiers', 'medium']),
    ]);

    $config->set(
      'report_series_matching.workflow.moderation_by_outcome_tier',
      $form_state->getValue(['moderation', 'moderation_by_outcome_tier']),
    );
    $config->set(
      'report_series_matching.workflow.skip_series_match_moderation_statuses',
      $this->linesToSequence((string) $form_state->getValue(['moderation', 'skip_series_match_moderation_statuses'])),
    );
    $config->set(
      'report_series_matching.workflow.restrictiveness_order',
      $this->linesToSequence((string) $form_state->getValue(['moderation', 'restrictiveness_order'])),
    );

    $matcher_values = $form_state->getValue('matcher');
    $config->set('report_series_matching.matcher', [
      'minimum_series_report_count' => (int) $matcher_values['minimum_series_report_count'],
      'series_candidate_date_range_months' => (int) $matcher_values['series_candidate_date_range_months'],
      'series_candidate_limit' => (int) $matcher_values['series_candidate_limit'],
      'ai_title_generation_enabled' => (bool) $matcher_values['ai_title_generation_enabled'],
      'ai_title_source_length_limit' => (int) $matcher_values['ai_title_source_length_limit'],
      'ai_title_example_line_count' => (int) $matcher_values['ai_title_example_line_count'],
      'ai_title_description_template' => (string) $matcher_values['ai_title_description_template'],
      'pattern_token_counts' => $form_state->getValue(['matcher', 'pattern_token_counts_parsed']),
      'candidate_clustering_tagging_weight' => (float) $matcher_values['candidate_clustering_tagging_weight'],
      'candidate_clustering_title_weight' => (float) $matcher_values['candidate_clustering_title_weight'],
      'candidate_clustering_similarity_threshold' => (float) $matcher_values['candidate_clustering_similarity_threshold'],
      'cluster_scoring_size_weight' => (float) $matcher_values['cluster_scoring_size_weight'],
      'cluster_scoring_pattern_score_weight' => (float) $matcher_values['cluster_scoring_pattern_score_weight'],
      'cluster_scoring_tagging_consistency_weight' => (float) $matcher_values['cluster_scoring_tagging_consistency_weight'],
      'cluster_comparison_field_names' => $this->linesToSequence((string) $matcher_values['cluster_comparison_field_names']),
      'recency_field_name' => trim((string) $matcher_values['recency_field_name']),
      'report_entity_field_names_to_copy' => $this->linesToSequence((string) $matcher_values['report_entity_field_names_to_copy']),
    ]);

    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Validates a value is within the unit interval.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string[] $parents
   *   Form value parents for the element being validated.
   * @param string $message
   *   Error message when the value is out of range.
   */
  private function validateUnitInterval(
    FormStateInterface $form_state,
    array $parents,
    string $message,
  ): void {
    $value = (float) $form_state->getValue($parents);
    if ($value < 0 || $value > 1) {
      $form_state->setErrorByName(implode('][', $parents), $message);
    }
  }

  /**
   * Validates high tier threshold is greater than medium.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string[] $parents
   *   Form value parents for the tier mapping.
   * @param string $message
   *   Error message when high tier is not greater than medium.
   */
  private function validateTierPair(
    FormStateInterface $form_state,
    array $parents,
    string $message,
  ): void {
    $range_message = $this->t('Tier thresholds must be between 0 and 1.');
    foreach (['high', 'medium'] as $tier) {
      $this->validateUnitInterval(
        $form_state,
        array_merge($parents, [$tier]),
        $range_message,
      );
    }
    $high = (float) $form_state->getValue(array_merge($parents, ['high']));
    $medium = (float) $form_state->getValue(array_merge($parents, ['medium']));
    if ($high <= $medium) {
      $form_state->setErrorByName(implode('][', array_merge($parents, ['high'])), $message);
    }
  }

  /**
   * Parses comma-separated pattern token counts.
   *
   * @param string $raw
   *   Comma-separated token count string from the form.
   *
   * @return int[]|null
   *   Parsed counts, or NULL when invalid.
   */
  private function parsePatternTokenCounts(string $raw): ?array {
    $parts = array_map('trim', explode(',', $raw));
    if ($parts === ['']) {
      return NULL;
    }
    $counts = [];
    foreach ($parts as $part) {
      if ($part === '' || !ctype_digit($part) || (int) $part < 1) {
        return NULL;
      }
      $counts[] = (int) $part;
    }
    return $counts;
  }

  /**
   * Converts a config sequence to newline-separated lines for textareas.
   *
   * @param string[] $sequence
   *   Config sequence values.
   *
   * @return string
   *   Newline-separated lines for textarea display.
   */
  private function sequenceToLines(array $sequence): string {
    return implode("\n", $sequence);
  }

  /**
   * Converts textarea lines to a trimmed config sequence.
   *
   * @param string $raw
   *   Raw textarea value from the form.
   *
   * @return string[]
   *   Non-empty trimmed lines.
   */
  private function linesToSequence(string $raw): array {
    $lines = preg_split('/\R/', $raw) ?: [];
    $sequence = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line !== '') {
        $sequence[] = $line;
      }
    }
    return $sequence;
  }

  /**
   * Returns report field names that are not defined on the report bundle.
   *
   * @param string[] $field_names
   *   Field machine names to check.
   *
   * @return string[]
   *   Unknown field names.
   */
  private function unknownReportFields(array $field_names): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'report');
    $unknown = [];
    foreach ($field_names as $field_name) {
      if (!isset($definitions[$field_name])) {
        $unknown[] = $field_name;
      }
    }
    return $unknown;
  }

}
