<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Paginated overview of applied report series match records.
 */
final class ReportSeriesMatchLogController extends ControllerBase {

  /**
   * Number of records shown per page.
   */
  private const int PAGE_SIZE = 30;

  /**
   * Constructs a ReportSeriesMatchLogController.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection for match log queries.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Date formatter for recorded timestamps.
   */
  public function __construct(
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the report series match log overview page.
   *
   * @return array
   *   A render array.
   */
  public function overview(): array {
    $records = $this->loadPagedRecords();
    $entities = $this->loadEntitiesForRecords($records);

    $rows = [];
    foreach ($records as $record) {
      $rows[] = $this->buildTableRow($record, $entities);
    }

    return [
      '#type' => 'container',
      'description' => [
        '#type' => 'inline_template',
        '#template' => '<p>{% trans %}Applied series matches recorded when automation tags a new report. Newest first.{% endtrans %}</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $this->getTableHeader(),
        '#rows' => $rows,
        '#empty' => $this->t('No report series match records found.'),
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Table column headers.
   *
   * @return array<int, \Drupal\Core\StringTranslation\TranslatableMarkup|string>
   *   Header labels.
   */
  protected function getTableHeader(): array {
    return [
      $this->t('Recorded'),
      $this->t('Report'),
      $this->t('Series'),
      $this->t('Tagging'),
      $this->t('Outcome'),
      $this->t('Moderation'),
      $this->t('AI title'),
      $this->t('Candidates'),
    ];
  }

  /**
   * Loads one page of match records from the database.
   *
   * @return object[]
   *   StdClass rows from the match table.
   */
  protected function loadPagedRecords(): array {
    $query = $this->database->select('reliefweb_report_series_match', 'm')
      ->fields('m')
      ->orderBy('m.created', 'DESC')
      ->orderBy('m.id', 'DESC');

    $query = $query->extend(PagerSelectExtender::class);
    $query->limit(self::PAGE_SIZE);

    return $query->execute()?->fetchAll() ?? [];
  }

  /**
   * Bulk-loads content entities referenced by the current page of records.
   *
   * @param object[] $records
   *   Match table rows.
   *
   * @return array<string, array<int, \Drupal\Core\Entity\EntityInterface>>
   *   Entities keyed by entity type ID, then entity ID.
   */
  protected function loadEntitiesForRecords(array $records): array {
    $ids_by_type = [];
    foreach ($records as $record) {
      $entity_type_id = (string) ($record->entity_type_id ?? '');
      $entity_id = (int) ($record->entity_id ?? 0);
      if ($entity_type_id === '' || $entity_id <= 0) {
        continue;
      }
      $ids_by_type[$entity_type_id][$entity_id] = $entity_id;
    }

    $entities = [];
    foreach ($ids_by_type as $entity_type_id => $ids) {
      $loaded = $this->entityTypeManager()
        ->getStorage($entity_type_id)
        ->loadMultiple(array_values($ids));
      foreach ($loaded as $entity) {
        $id = $entity->id();
        if ($id !== NULL) {
          $entities[$entity_type_id][(int) $id] = $entity;
        }
      }
    }

    return $entities;
  }

  /**
   * Builds one table row for a match record.
   *
   * @param object $record
   *   A row from reliefweb_report_series_match.
   * @param array<string, array<int, \Drupal\Core\Entity\EntityInterface>> $entities
   *   Preloaded entities keyed by type and ID.
   *
   * @return array<int, array|string>
   *   Table row cells.
   */
  protected function buildTableRow(object $record, array $entities): array {
    $data = $this->decodeRecordData((string) ($record->data ?? ''));
    $entity_type_id = (string) ($record->entity_type_id ?? '');
    $entity_id = (int) ($record->entity_id ?? 0);
    $bundle = (string) ($record->bundle ?? '');
    $entity = $entities[$entity_type_id][$entity_id] ?? NULL;

    return [
      $this->dateFormatter->format((int) ($record->created ?? 0), 'short'),
      ['data' => $this->buildEntityCell($entity, $entity_type_id, $entity_id, $bundle)],
      $this->formatConfidence((float) ($record->series_confidence ?? 0)),
      $this->formatConfidence((float) ($record->tagging_confidence ?? 0)),
      $this->formatOutcome($data),
      $this->formatModeration($data),
      $this->formatAiTitle($data),
      $this->formatCandidateSummary($data),
    ];
  }

  /**
   * Decodes the JSON data column for a match record.
   *
   * @param string $json
   *   Raw JSON from the database.
   *
   * @return array<string, mixed>
   *   Decoded payload or empty array on failure.
   */
  protected function decodeRecordData(string $json): array {
    if ($json === '') {
      return [];
    }

    try {
      $decoded = json_decode($json, TRUE, 512, \JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Renders the report reference cell.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The matched content entity, if it still exists.
   * @param string $entity_type_id
   *   Entity type ID from the record.
   * @param int $entity_id
   *   Entity ID from the record.
   * @param string $bundle
   *   Bundle from the record.
   *
   * @return array
   *   Render array for the table cell.
   */
  protected function buildEntityCell(
    ?EntityInterface $entity,
    string $entity_type_id,
    int $entity_id,
    string $bundle,
  ): array {
    if ($entity !== NULL) {
      $label = $entity->label();
      $title = $label !== NULL && $label !== ''
        ? (string) $label
        : (string) $this->t('Report @id', ['@id' => $entity_id]);

      return Link::fromTextAndUrl($title, $entity->toUrl())->toRenderable();
    }

    return [
      '#type' => 'inline_template',
      '#template' => '{% trans %}{{ entity_type }}:{{ bundle }} #{{ entity_id }} (missing){% endtrans %}',
      '#context' => [
        'entity_type' => $entity_type_id !== '' ? $entity_type_id : 'unknown',
        'bundle' => $bundle !== '' ? $bundle : 'unknown',
        'entity_id' => (string) $entity_id,
      ],
    ];
  }

  /**
   * Formats a confidence score as a percentage string.
   *
   * @param float $confidence
   *   Confidence score between 0.0 and 1.0.
   *
   * @return string
   *   Percentage string with one decimal place.
   */
  protected function formatConfidence(float $confidence): string {
    return number_format($confidence * 100, 1) . '%';
  }

  /**
   * Formats outcome tier from stored payload.
   *
   * @param array<string, mixed> $data
   *   Decoded record payload.
   *
   * @return string
   *   Outcome tier label or "n/a".
   */
  protected function formatOutcome(array $data): string {
    $tier = $data['outcome_tier'] ?? NULL;
    if (!is_string($tier) || $tier === '') {
      return (string) $this->t('n/a');
    }

    return $tier;
  }

  /**
   * Formats applied moderation status from stored payload.
   *
   * @param array<string, mixed> $data
   *   Decoded record payload.
   *
   * @return string
   *   Applied or target moderation status label.
   */
  protected function formatModeration(array $data): string {
    $applied = $data['applied_moderation_status'] ?? NULL;
    if (!is_string($applied) || $applied === '') {
      $target = $data['target_moderation_status'] ?? NULL;
      if (is_string($target) && $target !== '') {
        return (string) $this->t('target: @status', ['@status' => $target]);
      }
      return (string) $this->t('n/a');
    }

    return $applied;
  }

  /**
   * Formats whether AI generated the series title.
   *
   * @param array<string, mixed> $data
   *   Decoded record payload.
   *
   * @return string
   *   Whether AI generated the title, with duration when available.
   */
  protected function formatAiTitle(array $data): string {
    $proposal = $data['proposal'] ?? NULL;
    if (!is_array($proposal)) {
      return (string) $this->t('n/a');
    }

    $title_source = $proposal['title_source'] ?? NULL;
    if ($title_source !== SeriesMatchTitleSource::AiGenerated->value) {
      return (string) $this->t('No');
    }

    $duration = $proposal['title_ai_duration_seconds'] ?? NULL;
    if (is_numeric($duration)) {
      return (string) $this->t('Yes (@duration s)', [
        '@duration' => number_format((float) $duration, 1),
      ]);
    }

    return (string) $this->t('Yes');
  }

  /**
   * Summarizes winning cluster size and lookback window from stored evidence.
   *
   * @param array<string, mixed> $data
   *   Decoded record payload.
   *
   * @return string
   *   Cluster size and lookback summary, or "n/a".
   */
  protected function formatCandidateSummary(array $data): string {
    $evidence = $data['evidence'] ?? NULL;
    if (!is_array($evidence)) {
      return (string) $this->t('n/a');
    }

    $cluster_size = $evidence['best_cluster_size'] ?? NULL;
    $lookback_months = $evidence['lookback_months'] ?? NULL;
    if (!is_int($cluster_size) || $cluster_size <= 0
      || !is_int($lookback_months) || $lookback_months <= 0) {
      return (string) $this->t('n/a');
    }

    return (string) $this->t('@cluster / @months months', [
      '@cluster' => $cluster_size,
      '@months' => $lookback_months,
    ]);
  }

}
