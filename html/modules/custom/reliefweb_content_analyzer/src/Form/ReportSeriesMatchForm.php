<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchDebugTrace;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchStatus;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult;
use Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to run report series matching on a report node (read-only).
 */
final class ReportSeriesMatchForm extends FormBase {

  /**
   * Constructs a ReportSeriesMatchForm.
   */
  public function __construct(
    protected ReportSeriesMatcherInterface $reportSeriesMatcher,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('reliefweb_content_analyzer.report_series_matcher'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_content_analyzer_report_series_match_form';
  }

  /**
   * Gets the node from the route.
   */
  protected function getNodeFromRoute(): ?NodeInterface {
    $node = $this->getRouteMatch()->getParameter('node');
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Builds a short line with the entity title for page context.
   */
  protected function buildEntityReferenceElement(NodeInterface $node): array {
    $title_text = $node->label();
    $node_id = $node->id();

    if ($title_text === '' || $title_text === NULL) {
      $title_text = match ($node_id) {
        NULL => $this->t('Unsaved report'),
        default => $this->t('Report ID @node_id', ['@node_id' => $node_id]),
      };
    }

    return [
      '#type' => 'inline_template',
      '#template' => '<p><strong>{% trans %}Title{% endtrans %}:</strong> {{ title }}</p>',
      '#context' => [
        'title' => (string) $title_text,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $node = $this->getNodeFromRoute();
    if ($node === NULL) {
      return [
        'error' => [
          '#markup' => '<p>' . $this->t('Report not found.') . '</p>',
        ],
      ];
    }

    $match_result = $form_state->get('match_result');
    if ($match_result instanceof SeriesMatchResult) {
      return $this->buildResultsForm($form, $form_state, $node, $match_result);
    }

    $form['entity_context'] = $this->buildEntityReferenceElement($node);

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Runs series candidate lookup on this report. Nothing is saved.') . '</p>',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run matching'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $node->toUrl(),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * Builds the results view after matching has run.
   */
  protected function buildResultsForm(
    array $form,
    FormStateInterface $form_state,
    NodeInterface $node,
    SeriesMatchResult $result,
  ): array {
    $form['entity_context'] = $this->buildEntityReferenceElement($node);

    $workflow = SeriesMatchWorkflowSettings::fromConfigArray(
      $this->config('reliefweb_content_analyzer.settings')
        ->get('report_series_matching.workflow'),
    );
    $outcome = SeriesMatchOutcome::resolve($result, $workflow);

    $form['description'] = $this->buildResultsDescription($result, $outcome);

    $form['proposed_updates'] = $this->buildUpdatedFieldsDetails($result);
    if ($result->debug !== NULL) {
      $form['diagnostics'] = $this->buildDiagnosticsDetails($result);
    }
    $form['candidates'] = $this->buildCandidatesDetails($result);

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['again'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run again'),
      '#submit' => ['::resetMatching'],
      '#limit_validation_errors' => [],
    ];
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to content'),
      '#url' => $node->toUrl(),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * Builds the results page description from match outcome data.
   */
  protected function buildResultsDescription(SeriesMatchResult $result, ?SeriesMatchOutcome $outcome): array {
    if ($outcome !== NULL) {
      return [
        '#type' => 'inline_template',
        '#template' => '<p>{% trans %}Series candidate reports found for this content.</p><p><strong>Series:</strong> {{ series_confidence }}% - <strong>Tagging:</strong> {{ tagging_confidence }}% - <strong>Outcome:</strong> {{ outcome_tier }} - <strong>Projected moderation:</strong> {{ status }}{% endtrans %}</p>',
        '#context' => [
          'series_confidence' => number_format($outcome->seriesConfidence * 100, 1),
          'tagging_confidence' => number_format($outcome->taggingConfidence * 100, 1),
          'outcome_tier' => $outcome->outcomeTier,
          'status' => $outcome->targetModerationStatus,
        ],
      ];
    }

    $series_confidence = $result->calculateSeriesConfidence();
    $tagging_confidence = $result->calculateTaggingConfidence();
    if ($series_confidence === NULL && $tagging_confidence === NULL) {
      return [
        '#type' => 'inline_template',
        '#template' => '<p>{% trans %}No series candidate reports found for this content.{% endtrans %}</p>',
      ];
    }

    return [
      '#type' => 'inline_template',
      '#template' => '<p>{% trans %}Series candidate reports found for this content.</p><p><strong>Series:</strong> {{ series_confidence }} - <strong>Tagging:</strong> {{ tagging_confidence }}{% endtrans %}</p>',
      '#context' => [
        'series_confidence' => $series_confidence !== NULL ? number_format($series_confidence * 100, 1) . '%' : 'n/a',
        'tagging_confidence' => $tagging_confidence !== NULL ? number_format($tagging_confidence * 100, 1) . '%' : 'n/a',
      ],
    ];
  }

  /**
   * Renders series candidates as a collapsible details element.
   */
  protected function buildCandidatesDetails(SeriesMatchResult $result): array {
    $candidate_ids = $result->evidence->candidateIds;
    $scores = $result->evidence->candidatePatternScores;

    $headers = [
      $this->t('ID'),
      $this->t('Title'),
      $this->t('Created'),
    ];
    if ($scores !== []) {
      $headers[] = $this->t('Pattern score');
    }

    $rows = [];
    if ($candidate_ids !== []) {
      /** @var \Drupal\node\NodeInterface[] $candidates */
      $candidates = $this->entityTypeManager->getStorage('node')->loadMultiple($candidate_ids);
      foreach ($candidate_ids as $nid) {
        $candidate = $candidates[$nid] ?? NULL;
        if ($candidate === NULL) {
          continue;
        }
        $row = [
          ['data' => Link::fromTextAndUrl((string) $nid, $candidate->toUrl())->toRenderable()],
          Html::escape($candidate->label() ?? ''),
          $this->dateFormatter->format((int) $candidate->getCreatedTime(), 'short'),
        ];
        if ($scores !== []) {
          $row[] = isset($scores[$nid]) ? (string) (int) $scores[$nid] : '';
        }
        $rows[] = $row;
      }
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Series candidates'),
      '#open' => FALSE,
      'content' => [
        '#theme' => 'table',
        '#header' => $headers,
        '#rows' => $rows,
        '#empty' => $this->t('No series candidates found.'),
      ],
    ];
  }

  /**
   * Renders proposed updated fields as a collapsible details element.
   */
  protected function buildUpdatedFieldsDetails(SeriesMatchResult $result): array {
    $rows = [];
    foreach ($result->proposal->updatedFields as $field_name => $value) {
      $rows[] = [
        $this->getReportFieldLabel($field_name),
        $this->formatUpdatedFieldValue($field_name, $value),
        $this->formatFieldUpdateSource($field_name, $result),
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Proposed field updates'),
      '#open' => TRUE,
      'content' => [
        '#theme' => 'table',
        '#header' => [$this->t('Field'), $this->t('Value'), $this->t('Source')],
        '#rows' => $rows,
        '#empty' => $this->t('No proposed field updates.'),
      ],
    ];
  }

  /**
   * Human-readable provenance for a proposed field update.
   */
  protected function formatFieldUpdateSource(string $field_name, SeriesMatchResult $result): string {
    if ($field_name === 'title') {
      return $this->formatTitleSource($result);
    }

    $source = $result->proposal->updatedFieldSources[$field_name] ?? NULL;
    if ($source === NULL) {
      return '';
    }

    return match ($source) {
      SeriesMatchFieldUpdateSource::AllCandidates => (string) $this->t('All candidates'),
      SeriesMatchFieldUpdateSource::MostRecent => (string) $this->t('Most recent candidate'),
      SeriesMatchFieldUpdateSource::Merged => (string) $this->t('Merged (all candidates + most recent)'),
      SeriesMatchFieldUpdateSource::Skipped => (string) $this->t('Skipped'),
    };
  }

  /**
   * Human-readable title decision including AI duration when applicable.
   */
  protected function formatTitleSource(SeriesMatchResult $result): string {
    $title_source = $result->proposal->titleSource;
    if ($title_source === NULL) {
      return '';
    }

    $label = match ($title_source) {
      SeriesMatchTitleSource::KeptOriginalPatternMatch => (string) $this->t('Kept original (matches candidate pattern)'),
      SeriesMatchTitleSource::AiGenerated => (string) $this->t('AI generated'),
      SeriesMatchTitleSource::AiDisabled => (string) $this->t('AI title generation disabled'),
      SeriesMatchTitleSource::FailedNoCandidateTitles => (string) $this->t('Failed: no candidate titles'),
      SeriesMatchTitleSource::FailedNoSourceText => (string) $this->t('Failed: no source text'),
      SeriesMatchTitleSource::FailedAi => (string) $this->t('Failed: AI error'),
      SeriesMatchTitleSource::FailedEmptyAiOutput => (string) $this->t('Failed: empty AI output'),
    };

    if ($title_source === SeriesMatchTitleSource::AiGenerated
      && $result->proposal->titleAiDurationSeconds !== NULL) {
      $label .= ' (' . number_format($result->proposal->titleAiDurationSeconds, 2) . 's)';
    }

    return $label;
  }

  /**
   * Renders match diagnostics as a collapsible details element.
   */
  protected function buildDiagnosticsDetails(SeriesMatchResult $result): array {
    $debug = $result->debug;
    if ($debug === NULL) {
      return [];
    }

    $evidence = $result->evidence;
    $status = $result->status;
    $proposal = $result->proposal;
    $rows = [];

    $rows[] = [
      $this->t('Applicable'),
      $status->applicable ? $this->t('Yes') : $this->t('No'),
    ];

    if ($status->reason !== NULL) {
      $rows[] = [$this->t('Outcome'), $status->reason->value];
    }

    if ($status->rejectionReason !== NULL) {
      $rows[] = [$this->t('Rejection'), $status->rejectionReason->value];
    }

    $this->appendDebugRows($rows, $debug, $evidence, $status, $proposal);

    $items = [];
    foreach ($rows as [$label, $value]) {
      $items[] = [
        'label' => (string) $label,
        'value' => (string) $value,
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Match diagnostics'),
      '#open' => FALSE,
      'content' => [
        '#type' => 'inline_template',
        '#template' => '<dl class="reliefweb-series-match-diagnostics">{% for item in items %}<dt>{{ item.label }}</dt><dd>{{ item.value }}</dd>{% endfor %}</dl>',
        '#context' => [
          'items' => $items,
        ],
      ],
    ];
  }

  /**
   * Appends diagnostic rows from debug trace, evidence, and status.
   *
   * @param array<int, array{0: \Drupal\Core\StringTranslation\TranslatableMarkup|string, 1: string}> $rows
   *   The rows to append to.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchDebugTrace $debug
   *   The debug trace.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence $evidence
   *   The evidence.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchStatus $status
   *   The status.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal $proposal
   *   The proposal.
   */
  protected function appendDebugRows(
    array &$rows,
    SeriesMatchDebugTrace $debug,
    SeriesMatchEvidence $evidence,
    SeriesMatchStatus $status,
    SeriesMatchProposal $proposal,
  ): void {
    if ($debug->entityId > 0) {
      $rows[] = [$this->t('Entity ID'), (string) $debug->entityId];
    }

    if ($debug->lookbackMonths > 0) {
      $rows[] = [$this->t('Lookback (months)'), (string) $debug->lookbackMonths];
    }

    if ($debug->anchor > 0 && $debug->windowStart > 0) {
      $rows[] = [
        $this->t('Anchor date window'),
        $this->t('@window_start → @window_end', [
          '@window_start' => $this->dateFormatter->format($debug->windowStart, 'short'),
          '@window_end' => $this->dateFormatter->format($debug->anchor, 'short'),
        ]),
      ];
    }

    if ($debug->originalTitle !== '') {
      $rows[] = [$this->t('Original title'), $debug->originalTitle];
    }

    if ($debug->originUrl !== '') {
      $rows[] = [$this->t('Origin URL'), $debug->originUrl];
    }

    if ($debug->entityId > 0) {
      $rows[] = [
        $this->t('Pattern counts (title / URL)'),
        $debug->titlePatternCount . ' / ' . $debug->urlPatternCount,
      ];
    }

    if ($debug->sourceTermIds !== []) {
      $rows[] = [
        $this->t('Source term IDs'),
        implode(', ', $debug->sourceTermIds),
      ];
    }

    if ($debug->entityId > 0) {
      $rows[] = [
        $this->t('Retrieval (title / URL / both)'),
        $evidence->titleMatchCount . ' / ' . $evidence->urlMatchCount . ' / ' . $evidence->bothSignalsCount,
      ];
    }

    if ($debug->entityId > 0) {
      $rows[] = [
        $this->t('Merged candidates (before limit / after)'),
        $evidence->mergedCount . ' / ' . $evidence->mergedAfterLimitCount,
      ];
    }

    if ($debug->similarityThreshold > 0.0) {
      $rows[] = [
        $this->t('Pairwise similarity threshold'),
        number_format($debug->similarityThreshold, 3),
      ];
    }

    if ($debug->entityId > 0) {
      $rows[] = [
        $this->t('Pairwise weights (tagging / title)'),
        implode(' / ', [
          number_format($debug->pairwiseTaggingWeight, 2),
          number_format($debug->pairwiseTitleWeight, 2),
        ]),
      ];
    }

    if ($debug->entityId > 0) {
      $sizes = array_map('intval', $evidence->clusterSizes);
      $rows[] = [
        $this->t('Clusters (count / sizes)'),
        $evidence->clusterCount . ' — ' . implode(', ', $sizes),
      ];
    }

    if ($debug->entityId > 0) {
      $rows[] = [
        $this->t('Best cluster (size / share / minimum)'),
        $this->t('@cluster_size / @cluster_share% / @minimum_series_count', [
          '@cluster_size' => $evidence->bestClusterSize,
          '@cluster_share' => number_format(100.0 * $evidence->bestClusterShare, 1),
          '@minimum_series_count' => $debug->minimumSeriesCount,
        ]),
      ];
    }

    if ($debug->entityId > 0) {
      $rows[] = [
        $this->t('Cluster score weights (size / pattern / tagging)'),
        implode(' / ', [
          number_format($debug->clusterWeightSize, 2),
          number_format($debug->clusterWeightPattern, 2),
          number_format($debug->clusterWeightTagging, 2),
        ]),
      ];
    }

    if ($debug->entityId > 0 && $evidence->clusterCount > 0) {
      $rows[] = [
        $this->t('Winning cluster score components'),
        implode(' / ', [
          number_format($evidence->clusterScoreSize, 3),
          number_format($evidence->clusterScorePattern, 3),
          number_format($evidence->clusterScoreTagging, 3),
        ]),
      ];
    }

    if ($evidence->clusterScore > 0.0) {
      $rows[] = [
        $this->t('Composite cluster score'),
        number_format($evidence->clusterScore, 4),
      ];
    }

    if ($debug->entityId > 0) {
      $rows[] = [
        $this->t('Passed minimum series size'),
        $status->passedMinimum ? $this->t('Yes') : $this->t('No'),
      ];
    }

    if ($proposal->mostRecentCandidateId > 0) {
      $rows[] = [
        $this->t('Most recent candidate ID'),
        (string) $proposal->mostRecentCandidateId,
      ];
    }
  }

  /**
   * Gets the report field label from field definitions.
   */
  protected function getReportFieldLabel(string $field_name): string {
    if ($field_name === 'title') {
      return (string) $this->t('Title');
    }

    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'report');
    if (isset($definitions[$field_name])) {
      return (string) $definitions[$field_name]->getLabel();
    }

    return $field_name;
  }

  /**
   * Formats an updated field value for display.
   *
   * @param string $field_name
   *   Field machine name.
   * @param null|string|string[]|int[] $value
   *   Suggested value.
   */
  protected function formatUpdatedFieldValue(string $field_name, null|string|array $value): string {
    if ($value === NULL) {
      return (string) $this->t('(no value)');
    }

    if (is_string($value)) {
      return $value !== '' ? $value : (string) $this->t('(empty)');
    }

    if ($value === []) {
      return (string) $this->t('(empty)');
    }

    if (array_all($value, fn($item) => is_int($item)) && $this->isTaxonomyReferenceField($field_name)) {
      return implode(', ', $this->resolveTermLabels($value));
    }

    return implode(', ', array_map('strval', $value));
  }

  /**
   * Checks if the given report field references taxonomy terms.
   */
  protected function isTaxonomyReferenceField(string $field_name): bool {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'report');
    $definition = $definitions[$field_name] ?? NULL;
    if ($definition === NULL) {
      return FALSE;
    }

    return $definition->getType() === 'entity_reference'
      && ($definition->getSetting('target_type') ?? '') === 'taxonomy_term';
  }

  /**
   * Resolves taxonomy term IDs to "Name (id)" labels.
   *
   * @param int[] $ids
   *   Term IDs.
   *
   * @return string[]
   *   Labels in input order.
   */
  protected function resolveTermLabels(array $ids): array {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($ids);
    $labels = [];
    foreach ($ids as $id) {
      $term = $terms[$id] ?? NULL;
      $labels[] = $term ? $term->label() . " ({$id})" : (string) $this->t('Unknown term (@id)', ['@id' => $id]);
    }
    return $labels;
  }

  /**
   * Clears stored results so the user can run matching again.
   */
  public function resetMatching(array &$form, FormStateInterface $form_state): void {
    $form_state->set('match_result', NULL);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $this->getNodeFromRoute();
    if ($node === NULL) {
      return;
    }

    $form_state->set(
      'match_result',
      $this->reportSeriesMatcher->findSeriesCandidates($node, includeDebug: TRUE),
    );
    $form_state->setRebuild();
  }

}
