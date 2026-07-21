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
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyContext;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchStatus;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchAttentionLevel;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchReason;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchWorkflowSettings;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult;
use Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcherInterface;
use Drupal\reliefweb_files\Services\MissingFileDownloaderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to run report series matching on a report node (read-only).
 */
final class ReportSeriesMatchForm extends FormBase {

  /**
   * Constructs a ReportSeriesMatchForm.
   *
   * @param \Drupal\reliefweb_content_analyzer\Services\ReportSeriesMatcherInterface $reportSeriesMatcher
   *   Series matcher service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager for loading candidates and terms.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Field manager for report field labels and definitions.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Date formatter for candidate created dates.
   * @param \Drupal\reliefweb_files\Services\MissingFileDownloaderInterface $missingFileDownloader
   *   Downloader for missing report attachments (local testing).
   */
  public function __construct(
    protected ReportSeriesMatcherInterface $reportSeriesMatcher,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected DateFormatterInterface $dateFormatter,
    protected MissingFileDownloaderInterface $missingFileDownloader,
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
      $container->get('reliefweb_files.missing_file_downloader'),
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
   *
   * @return \Drupal\node\NodeInterface|null
   *   The report node from the route, or NULL when missing or wrong type.
   */
  protected function getNodeFromRoute(): ?NodeInterface {
    $node = $this->getRouteMatch()->getParameter('node');
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Builds a short line with the entity title for page context.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The report node being matched.
   *
   * @return array
   *   Render array for the entity context line.
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
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\node\NodeInterface $node
   *   The report node being matched.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The match result to display.
   *
   * @return array
   *   Form render array for the results view.
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
    $outcome = SeriesMatchOutcome::resolve(
      $result,
      $workflow,
      $this->buildOutcomePolicyContext($node, $result),
    );

    $form['description'] = $this->buildResultsDescription($result, $outcome);

    $form['proposed_updates'] = $this->buildUpdatedFieldsDetails($result);
    $form['candidates'] = $this->buildCandidatesDetails($result);
    if ($result->debug !== NULL) {
      $form['diagnostics'] = $this->buildDiagnosticsDetails($result, $outcome);
    }

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
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The match result.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome|null $outcome
   *   Resolved outcome when workflow settings allow scoring.
   *
   * @return array
   *   Render array for the results description.
   */
  protected function buildResultsDescription(SeriesMatchResult $result, ?SeriesMatchOutcome $outcome): array {
    $series_summary = $this->formatSeriesMatchSummary($result);

    if ($outcome !== NULL) {
      $policy_messages = $outcome->policyReasonMessages();
      return [
        '#type' => 'inline_template',
        '#template' => <<<TEMPLATE
          <p>
            <strong>{% trans %}Series candidate reports found for this content{% endtrans %}</strong>{% if series_summary %} ({{ series_summary }}){% endif %}.
          </p>
          <p>
            <strong>Series confidence:</strong> {{ series_confidence }}% —
            <strong>Tagging confidence:</strong> {{ tagging_confidence }}% —
            {% if apply_match %}
              <strong>Outcome:</strong> {{ outcome_tier }} —
              <strong>Projected moderation:</strong> {{ status }}
            {% else %}
              <strong>Outcome:</strong> {% trans %}skip{% endtrans %}
            {% endif %}
          </p>
          {% if policy_messages %}
            <div><strong>
              {% if apply_match %}
                {% trans %}Outcome reduced because:{% endtrans %}
              {% else %}
                {% trans %}Match skipped because:{% endtrans %}
              {% endif %}
            </strong>
            <ul>
              {% for message in policy_messages %}
                <li>{{ message }}</li>
              {% endfor %}
            </ul></div>
          {% endif %}
          TEMPLATE,
        '#context' => [
          'series_summary' => $series_summary,
          'series_confidence' => number_format($outcome->seriesConfidence * 100, 1),
          'tagging_confidence' => number_format($outcome->taggingConfidence * 100, 1),
          'outcome_tier' => $outcome->outcomeTier->value,
          'status' => $outcome->targetModerationStatus,
          'apply_match' => $outcome->applyMatch,
          'policy_messages' => array_map('mb_ucfirst', $policy_messages),
        ],
      ];
    }

    $stop_message = $this->formatStoppedMatchSummary($result);
    if ($stop_message !== '') {
      return [
        '#type' => 'inline_template',
        '#template' => <<<TEMPLATE
          <p><strong>{{ stop_message }}</strong></p>
          TEMPLATE,
        '#context' => [
          'stop_message' => $stop_message,
        ],
      ];
    }

    $series_confidence = $result->calculateSeriesConfidence();
    $tagging_confidence = $result->calculateTaggingConfidence();

    return [
      '#type' => 'inline_template',
      '#template' => <<<TEMPLATE
        <p>
          <strong>{% trans %}Series candidate reports found for this content{% endtrans %}</strong>{% if series_summary %} ({{ series_summary }}){% endif %}.
        </p>
        <p>
          <strong>Series confidence:</strong> {{ series_confidence }} —
          <strong>Tagging confidence:</strong> {{ tagging_confidence }}
        </p>
        TEMPLATE,
      '#context' => [
        'series_summary' => $series_summary,
        'series_confidence' => $series_confidence !== NULL ? number_format($series_confidence * 100, 1) . '%' : 'n/a',
        'tagging_confidence' => $tagging_confidence !== NULL ? number_format($tagging_confidence * 100, 1) . '%' : 'n/a',
      ],
    ];
  }

  /**
   * Builds an editor-facing summary when matching stopped without an outcome.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The match result.
   *
   * @return string
   *   Summary text, or empty when the caller should use the scorable fallback.
   */
  protected function formatStoppedMatchSummary(SeriesMatchResult $result): string {
    $reason = $result->status->rejectionReason ?? $result->status->reason;
    if ($reason === NULL) {
      return '';
    }

    return match ($reason) {
      SeriesMatchReason::NotReport => (string) $this->t('Series matching only applies to reports.'),
      SeriesMatchReason::NoSource => (string) $this->t('This report has no source, so series matching cannot run.'),
      SeriesMatchReason::LimitZero => (string) $this->t('Series candidate lookup is disabled (candidate limit is zero).'),
      SeriesMatchReason::NoPatterns => (string) $this->t('No title or URL patterns could be generated for this content.'),
      SeriesMatchReason::NoPatternMatches => (string) $this->t('No matching series candidates were found for this content.'),
      SeriesMatchReason::BelowMinimumCluster => (string) $this->t('Not enough similar series reports were found (@found found, minimum @minimum).', [
        '@found' => $result->evidence->bestClusterSize,
        '@minimum' => $this->resolveMinimumSeriesReportCount($result),
      ]),
    };
  }

  /**
   * Resolves the configured minimum series report count for display.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The match result.
   *
   * @return int
   *   Minimum series size from debug trace or config.
   */
  protected function resolveMinimumSeriesReportCount(SeriesMatchResult $result): int {
    $minimum = $result->debug?->minimumSeriesCount ?? 0;
    if ($minimum > 0) {
      return $minimum;
    }

    return (int) ($this->config('reliefweb_content_analyzer.settings')
      ->get('report_series_matching.matcher.minimum_series_report_count') ?? 3);
  }

  /**
   * Formats the similar-reports summary for the results description.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The match result.
   *
   * @return string
   *   Summary text, or empty when cluster data is unavailable.
   */
  protected function formatSeriesMatchSummary(SeriesMatchResult $result): string {
    $summary = $result->evidence->similarReportsSummary();
    if ($summary === NULL) {
      return '';
    }

    return (string) $this->t('@count similar reports over @months months', [
      '@count' => $summary['count'],
      '@months' => $summary['months'],
    ]);
  }

  /**
   * Renders series candidates as a collapsible details element.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The match result.
   *
   * @return array
   *   Render array for the candidates details element.
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
      '#title' => $this->t('Series candidates (@count)', [
        '@count' => count($candidate_ids),
      ]),
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
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The match result.
   *
   * @return array
   *   Render array for the proposed updates details element.
   */
  protected function buildUpdatedFieldsDetails(SeriesMatchResult $result): array {
    $rows = [];
    foreach ($result->proposal->updatedFields as $field_name => $value) {
      $rows[] = [
        $this->formatFieldUpdateAttention($field_name, $result),
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
        '#header' => [
          $this->t('Status'),
          $this->t('Field'),
          $this->t('Value'),
          $this->t('Source'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No proposed field updates.'),
      ],
      'legend' => [
        '#type' => 'inline_template',
        '#template' => '<p class="reliefweb-series-match-attention-legend"><small>{{ legend }}</small></p>',
        '#context' => [
          'legend' => $this->buildFieldUpdateAttentionLegend(),
        ],
      ],
    ];
  }

  /**
   * Builds the attention-level legend for the proposed updates table.
   *
   * @return string
   *   Legend text with emoji indicators.
   */
  protected function buildFieldUpdateAttentionLegend(): string {
    $parts = [];
    foreach (SeriesMatchAttentionLevel::cases() as $level) {
      $parts[] = $level->indicator() . ' ' . $this->formatAttentionLevelLabel($level);
    }

    return implode(' · ', $parts);
  }

  /**
   * Renders the attention indicator for a proposed field update row.
   *
   * @param string $field_name
   *   Field machine name.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The match result.
   *
   * @return array
   *   Render array for the status table cell.
   */
  protected function formatFieldUpdateAttention(string $field_name, SeriesMatchResult $result): array {
    if ($field_name === 'title') {
      $title_source = $result->proposal->titleSource;
      if ($title_source === NULL) {
        return ['data' => ''];
      }
      $level = $title_source->attentionLevel();
    }
    else {
      $source = $result->proposal->updatedFieldSources[$field_name] ?? NULL;
      if ($source === NULL) {
        return ['data' => ''];
      }
      $level = $source->attentionLevel();
    }

    $label = $this->formatAttentionLevelLabel($level);

    return [
      'data' => [
        '#type' => 'inline_template',
        '#template' => '<span title="{{ label }}" aria-label="{{ label }}">{{ indicator }}</span>',
        '#context' => [
          'indicator' => $level->indicator(),
          'label' => $label,
        ],
      ],
    ];
  }

  /**
   * Human-readable provenance for a proposed field update.
   *
   * @param string $field_name
   *   Field machine name.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The match result.
   *
   * @return string
   *   Human-readable source label.
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
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The match result.
   *
   * @return string
   *   Human-readable title source label.
   */
  protected function formatTitleSource(SeriesMatchResult $result): string {
    $title_source = $result->proposal->titleSource;
    if ($title_source === NULL) {
      return '';
    }

    if ($title_source === SeriesMatchTitleSource::AiGenerated) {
      $label = (string) $this->t('AI generated');
      if ($result->proposal->titleAiDurationSeconds !== NULL) {
        $label .= ' (' . number_format($result->proposal->titleAiDurationSeconds, 2) . 's)';
      }
      return $label;
    }

    return (string) $this->t('Original title kept (@reason)', [
      '@reason' => $this->formatTitleUnchangedReason($title_source),
    ]);
  }

  /**
   * Returns a translatable attention-level label for UI display.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchAttentionLevel $level
   *   The attention level.
   *
   * @return string
   *   Translated label text.
   */
  protected function formatAttentionLevelLabel(SeriesMatchAttentionLevel $level): string {
    return (string) match ($level) {
      SeriesMatchAttentionLevel::Ok => $this->t('High confidence'),
      SeriesMatchAttentionLevel::Info => $this->t('Review suggested'),
      SeriesMatchAttentionLevel::Warning => $this->t('Weaker source'),
      SeriesMatchAttentionLevel::Error => $this->t('Not applied / failed'),
    };
  }

  /**
   * Returns a translatable reason phrase for unchanged title outcomes.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource $source
   *   The title source enum case.
   *
   * @return string
   *   Translated reason text, or empty for AI-generated titles.
   */
  protected function formatTitleUnchangedReason(SeriesMatchTitleSource $source): string {
    return (string) match ($source) {
      SeriesMatchTitleSource::KeptOriginalPatternMatch => $this->t('matches series pattern'),
      SeriesMatchTitleSource::SkippedAiDisabled => $this->t('AI disabled'),
      SeriesMatchTitleSource::SkippedNoAttachmentText => $this->t('no attachment text'),
      SeriesMatchTitleSource::FailedNoCandidateTitles => $this->t('no candidate titles'),
      SeriesMatchTitleSource::FailedUnsupportedAiPlugin => $this->t('unsupported AI plugin'),
      SeriesMatchTitleSource::FailedAiCallError => $this->t('AI call error'),
      SeriesMatchTitleSource::FailedEmptyAiOutput => $this->t('empty AI output'),
      SeriesMatchTitleSource::AiGenerated => '',
    };
  }

  /**
   * Renders match diagnostics as a collapsible details element.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   The match result.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchOutcome|null $outcome
   *   Resolved outcome when available.
   *
   * @return array
   *   Render array for the diagnostics details element, or empty when no debug.
   */
  protected function buildDiagnosticsDetails(
    SeriesMatchResult $result,
    ?SeriesMatchOutcome $outcome = NULL,
  ): array {
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
      $rows[] = [$this->t('Outcome'), $status->reason->label()];
    }

    if ($status->rejectionReason !== NULL) {
      $rows[] = [$this->t('Rejection'), $status->rejectionReason->label()];
    }

    if ($outcome !== NULL) {
      $rows[] = [
        $this->t('Apply match'),
        $outcome->applyMatch ? $this->t('Yes') : $this->t('No'),
      ];
      $messages = $outcome->policyReasonMessages();
      if ($messages !== []) {
        $rows[] = [
          $this->t('Policy reasons'),
          implode('; ', $messages),
        ];
      }
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

    if ($evidence->seriesBodyRatio !== NULL) {
      $rows[] = [
        $this->t('Series body share'),
        number_format(100.0 * $evidence->seriesBodyRatio, 1) . '%',
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
   *
   * @param string $field_name
   *   Field machine name.
   *
   * @return string
   *   Field label or machine name when undefined.
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
   *
   * @return string
   *   Formatted value for display.
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
   *
   * @param string $field_name
   *   Field machine name.
   *
   * @return bool
   *   TRUE when the field is a taxonomy term reference.
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
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function resetMatching(array &$form, FormStateInterface $form_state): void {
    $form_state->set('match_result', NULL);
    $form_state->setRebuild();
  }

  /**
   * Builds outcome policy context from the node and match evidence.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The report node.
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult $result
   *   Match result.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyContext
   *   Policy evaluation context.
   */
  protected function buildOutcomePolicyContext(
    NodeInterface $node,
    SeriesMatchResult $result,
  ): SeriesMatchOutcomePolicyContext {
    $has_body = TRUE;
    if ($node->hasField('body')) {
      $raw = $node->get('body')->value;
      $has_body = is_string($raw) && trim(strip_tags($raw)) !== '';
    }

    return new SeriesMatchOutcomePolicyContext(
      entityHasBody: $has_body,
      seriesBodyRatio: $result->evidence->seriesBodyRatio,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $this->getNodeFromRoute();
    if ($node === NULL) {
      return;
    }

    $this->ensureMissingAttachmentForTesting($node);

    $form_state->set(
      'match_result',
      $this->reportSeriesMatcher->findSeriesCandidates($node, includeDebug: TRUE),
    );
    $form_state->setRebuild();
  }

  /**
   * Downloads the first attachment when missing (series match test only).
   *
   * Controlled by report_series_matching.testing settings. No-ops when disabled
   * or when the source host matches the current request host.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The report being matched.
   */
  protected function ensureMissingAttachmentForTesting(NodeInterface $node): void {
    $settings = $this->config('reliefweb_content_analyzer.settings')
      ->get('report_series_matching.testing') ?? [];
    if (empty($settings['download_missing_attachment'])) {
      return;
    }

    $source_url = $settings['download_missing_attachment_source_url'] ?? '';
    $source_url = is_string($source_url) ? rtrim($source_url, '/') : '';
    $source_url = $source_url ?: 'https://reliefweb.int';

    $result = $this->missingFileDownloader->ensureFirstReportAttachmentOnDisk(
      $node,
      $source_url,
    );

    if ($result === 'downloaded') {
      $this->messenger()->addStatus($this->t('Downloaded missing report attachment for series match testing.'));
    }
    elseif ($result === 'failed') {
      $this->messenger()->addWarning($this->t('Could not download the missing report attachment. AI title generation may be skipped.'));
    }
  }

}
