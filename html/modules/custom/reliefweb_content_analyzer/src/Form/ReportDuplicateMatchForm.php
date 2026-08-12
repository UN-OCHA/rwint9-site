<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatch;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchCandidate;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult;
use Drupal\reliefweb_content_analyzer\Services\ReportDuplicateMatcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to run report near-duplicate detection on a report node (read-only).
 */
final class ReportDuplicateMatchForm extends FormBase {

  /**
   * Constructs a ReportDuplicateMatchForm.
   *
   * @param \Drupal\reliefweb_content_analyzer\Services\ReportDuplicateMatcherInterface $reportDuplicateMatcher
   *   Report duplicate matcher service.
   */
  public function __construct(
    protected ReportDuplicateMatcherInterface $reportDuplicateMatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('reliefweb_content_analyzer.report_duplicate_matcher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_content_analyzer_report_duplicate_match_form';
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
   *   The report node being checked.
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
          '#type' => 'inline_template',
          '#template' => '<p>{% trans %}Report not found.{% endtrans %}</p>',
        ],
      ];
    }

    $match_result = $form_state->get('match_result');
    if ($match_result instanceof DuplicateMatchResult) {
      return $this->buildResultsForm($form, $form_state, $node, $match_result);
    }

    $form['entity_context'] = $this->buildEntityReferenceElement($node);

    $form['description'] = [
      '#type' => 'inline_template',
      '#template' => '<p>{% trans %}Runs near-duplicate detection on this report. Nothing is saved.{% endtrans %}</p>',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run detection'),
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
   * Builds the results view after detection has run.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\node\NodeInterface $node
   *   The report node being checked.
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult $result
   *   The detection result to display.
   *
   * @return array
   *   Form render array for the results view.
   */
  protected function buildResultsForm(
    array $form,
    FormStateInterface $form_state,
    NodeInterface $node,
    DuplicateMatchResult $result,
  ): array {
    $form['entity_context'] = $this->buildEntityReferenceElement($node);
    $duration = $form_state->get('match_duration_seconds');
    $form['description'] = $this->buildResultsDescription(
      $result,
      is_float($duration) ? $duration : NULL,
    );

    if ($result->hasMatches()) {
      $form['matches'] = $this->buildMatchesList($result);
    }

    if ($result->hasCandidates()) {
      $form['candidates'] = $this->buildCandidatesTable($result);
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
   * Builds the results page description from detection outcome.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult $result
   *   The detection result.
   * @param float|null $duration_seconds
   *   Wall-clock seconds for the matcher call, when measured.
   *
   * @return array
   *   Render array for the results description.
   */
  protected function buildResultsDescription(
    DuplicateMatchResult $result,
    ?float $duration_seconds = NULL,
  ): array {
    $duration = $duration_seconds !== NULL
      ? number_format($duration_seconds, 2)
      : NULL;

    if ($result->hasCandidates()) {
      return [
        '#type' => 'inline_template',
        '#template' => <<<TEMPLATE
          <p>
            {% trans %}
              Checked 1 candidate.
            {% plural candidate_count %}
              Checked {{ candidate_count }} candidates.
            {% endtrans %}
            <strong>
            {% if duplicate_count > 0 %}
              {% trans %}
                1 near-duplicate found.
              {% plural duplicate_count %}
                {{ duplicate_count }} near-duplicates found.
              {% endtrans %}
            {% else %}
              {% trans %}No near-duplicates found.{% endtrans %}
            {% endif %}
            </strong>
            {% if duration %}
              {% trans %}Completed in {{ duration }}s.{% endtrans %}
            {% endif %}
          </p>
          TEMPLATE,
        '#context' => [
          'candidate_count' => count($result->candidates),
          'duplicate_count' => $result->duplicateCandidateCount(),
          'duration' => $duration,
        ],
      ];
    }

    return [
      '#type' => 'inline_template',
      '#template' => <<<TEMPLATE
        <p>
          <strong>{% trans %}No near-duplicates found{% endtrans %}.</strong> {{ reason }}
          {% if duration %}
            {% trans %}Completed in {{ duration }}s.{% endtrans %}
          {% endif %}
        </p>
        TEMPLATE,
      '#context' => [
        'reason' => $this->formatReason($result->reason),
        'duration' => $duration,
      ],
    ];
  }

  /**
   * Human-readable explanation for a machine skip/stop reason.
   *
   * @param string $reason
   *   Machine reason from DuplicateMatchResult.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   Localized explanation.
   */
  protected function formatReason(string $reason): string|TranslatableMarkup {
    return match ($reason) {
      'not_report' => $this->t('Entity is not a report.'),
      'no_body' => $this->t('This report has no body text.'),
      'has_attachment' => $this->t('This report has an attachment and “Skip reports that have file attachments” is enabled.'),
      'no_source' => $this->t('This report has no source.'),
      'body_too_short' => $this->t('Normalized body text is shorter than the configured minimum length.'),
      'no_candidates' => $this->t('No candidate reports were found in the configured created-date window.'),
      'no_matches' => $this->t('Candidates were found, but none met the similarity threshold.'),
      'matched' => $this->t('Matches were found.'),
      'none' => $this->t('Detection did not run.'),
      default => $this->t('Reason: @reason', ['@reason' => $reason]),
    };
  }

  /**
   * Builds a simple list of linked production near-duplicates.
   *
   * Score details live in the All candidates table; this list is for quick
   * navigation to the reports that would apply.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult $result
   *   Detection result with matches.
   *
   * @return array
   *   Render array for the matches list.
   */
  protected function buildMatchesList(DuplicateMatchResult $result): array {
    $items = [];
    foreach ($result->matches as $match) {
      assert($match instanceof DuplicateMatch);
      $url = Url::fromRoute('entity.node.canonical', ['node' => $match->nid]);
      $items[] = Link::fromTextAndUrl(
        $match->title !== '' ? $match->title : (string) $match->nid,
        $url,
      )->toRenderable();
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#empty' => $this->t('No near-duplicates.'),
    ];
  }

  /**
   * Builds a table of all scored candidates.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult $result
   *   Detection result with candidates.
   *
   * @return array
   *   Render array for the candidates table.
   */
  protected function buildCandidatesTable(DuplicateMatchResult $result): array {
    $rows = [];
    foreach ($result->candidates as $candidate) {
      assert($candidate instanceof DuplicateMatchCandidate);
      $url = Url::fromRoute('entity.node.canonical', ['node' => $candidate->nid]);
      $rows[] = [
        'title' => [
          'data' => Link::fromTextAndUrl(
            $candidate->title !== '' ? $candidate->title : (string) $candidate->nid,
            $url,
          )->toRenderable(),
        ],
        'nid' => $candidate->nid,
        'created' => $candidate->created > 0
          ? gmdate('Y-m-d', $candidate->created)
          : '—',
        'length_ratio' => DuplicateMatchCandidate::formatScore($candidate->lengthRatio),
        'jaccard' => DuplicateMatchCandidate::formatScore($candidate->jaccardScore),
        'tfidf' => DuplicateMatchCandidate::formatScore($candidate->tfidfScore),
        'embedding' => DuplicateMatchCandidate::formatScore($candidate->embeddingScore),
        'source' => $candidate->candidateSource,
        'duplicate' => $candidate->isDuplicate ? $this->t('Yes') : $this->t('No'),
        'disposition' => $this->formatCandidateDisposition($candidate),
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('All candidates'),
      '#open' => FALSE,
      'table' => [
        '#type' => 'table',
        '#header' => [
          'title' => $this->t('Title'),
          'nid' => $this->t('NID'),
          'created' => $this->t('Created'),
          'length_ratio' => $this->t('Length ratio'),
          'jaccard' => $this->t('Jaccard'),
          'tfidf' => $this->t('TF-IDF'),
          'embedding' => $this->t('Embedding'),
          'source' => $this->t('Source'),
          'duplicate' => $this->t('Duplicate'),
          'disposition' => $this->t('Disposition'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No candidates.'),
      ],
    ];
  }

  /**
   * Human-readable gate method for a production match.
   *
   * @param string|null $method
   *   DuplicateMatch::METHOD_* value, or NULL.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   Localized method label.
   */
  protected function formatMatchMethod(?string $method): string|TranslatableMarkup {
    return match ($method) {
      DuplicateMatch::METHOD_JACCARD => $this->t('Hard (Jaccard)'),
      DuplicateMatch::METHOD_EMBEDDING => $this->t('Soft (embedding)'),
      DuplicateMatch::METHOD_TFIDF => $this->t('Soft (TF-IDF)'),
      default => $method ?? '—',
    };
  }

  /**
   * Human-readable disposition for a scored candidate row.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchCandidate $candidate
   *   Candidate.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   Gate method, discard reason, skip reason, or em dash.
   */
  protected function formatCandidateDisposition(DuplicateMatchCandidate $candidate): string|TranslatableMarkup {
    if ($candidate->discardReason !== NULL) {
      return $candidate->discardReason;
    }
    if ($candidate->skipReason !== NULL) {
      return $candidate->skipReason;
    }
    if ($candidate->method !== NULL) {
      return $this->formatMatchMethod($candidate->method);
    }
    return '—';
  }

  /**
   * Clears the stored result so detection can be run again.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function resetMatching(array &$form, FormStateInterface $form_state): void {
    $form_state->set('match_result', NULL);
    $form_state->set('match_duration_seconds', NULL);
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

    $start = hrtime(TRUE);
    $result = $this->reportDuplicateMatcher->findDuplicates($node);
    $form_state->set('match_duration_seconds', (hrtime(TRUE) - $start) / 1e9);
    $form_state->set('match_result', $result);
    $form_state->setRebuild();
  }

}
