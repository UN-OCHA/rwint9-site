<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Services;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchMatcherSettings;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchDebugTrace;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchEvidence;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchStatus;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchFieldUpdateSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchReason;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchTitleSource;
use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\SeriesMatchResult;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface;
use Drupal\ocha_ai\Plugin\ocha_ai\Completion\CompletionCapability;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Matches reports to a document series using pattern search and clustering.
 *
 * @phpstan-type CandidateMetadata array<string, string|int|string[]|int[]>
 * @phpstan-type CandidateMetadataSet array<int, CandidateMetadata>
 *
 * @phpstan-type FieldDefinition array{
 *   type: string,
 *   column: string,
 *   multiple: bool,
 * }
 * @phpstan-type FieldDefinitions array<string, FieldDefinition|null>
 *
 * @phpstan-type InferenceSettings array{
 *   plugin_id: string,
 *   temperature: float,
 *   top_p: float,
 *   max_tokens: int,
 *   thinking_mode: string,
 *   system_prompt: string,
 * }
 *
 * @phpstan-type FieldCopyResult array{
 *   values: array<string, null|string|string[]|int[]>,
 *   sources: array<string, SeriesMatchFieldUpdateSource>,
 * }
 *
 * @phpstan-type TitleGenerationResult array{
 *   title: ?string,
 *   source: SeriesMatchTitleSource,
 *   aiDurationSeconds: ?float,
 * }
 */
final class ReportSeriesMatcher implements ReportSeriesMatcherInterface {

  /**
   * Lazily loaded matcher settings from config.
   */
  private ?SeriesMatchMatcherSettings $matcherSettings = NULL;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface $completionPluginManager
   *   The OCHA AI completion plugin manager.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly TimeInterface $time,
    protected readonly Connection $database,
    #[Autowire(service: 'plugin.manager.ocha_ai.completion')]
    protected readonly CompletionPluginManagerInterface $completionPluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function findSeriesCandidates(EntityInterface $entity, bool $includeDebug = FALSE): SeriesMatchResult {
    if (!$this->canApplySeriesMatching($entity)) {
      return SeriesMatchResult::notApplicable();
    }

    $source_ids = $this->getEntitySourceIds($entity);
    if ($source_ids === []) {
      return SeriesMatchResult::stopped(SeriesMatchReason::NoSource);
    }

    $anchor = $this->resolveSeriesAnchorTimestamp($entity);

    $months = $this->getSeriesCandidateDateRangeMonths();
    $window_start = (int) strtotime("-{$months} months", $anchor);

    $limit = $this->getSeriesCandidateLimit();
    if ($limit === 0) {
      return SeriesMatchResult::stopped(SeriesMatchReason::LimitZero);
    }

    // Unsaved nodes have no ID; 0 means nothing to exclude from candidate SQL.
    $entity_id = (int) $entity->id();
    $original_title = $this->resolveOriginalTitle($entity);
    $origin_url = $this->getOriginUrl($entity);
    $title_patterns = $original_title !== '' ? $this->titleToLikePatterns($original_title) : [];
    $url_patterns = $origin_url !== '' ? $this->urlToLikePatterns($origin_url) : [];
    $minimum_series_count = $this->getMinimumSeriesReportCount();

    $debug = $includeDebug ? $this->buildDebugTrace(
      entityId: $entity_id,
      lookbackMonths: $months,
      anchor: $anchor,
      windowStart: $window_start,
      candidateLimit: $limit,
      originalTitle: $original_title,
      originUrl: $origin_url,
      titlePatternCount: count($title_patterns),
      urlPatternCount: count($url_patterns),
      sourceTermIds: $source_ids,
      minimumSeriesCount: $minimum_series_count,
    ) : NULL;

    if ($title_patterns === [] && $url_patterns === []) {
      return SeriesMatchResult::stopped(
        SeriesMatchReason::NoPatterns,
        debug: $debug,
      );
    }

    $title_scored_candidates = [];
    if ($title_patterns !== []) {
      $title_scored_candidates = $this->getSeriesCandidateIdsByPatterns(
        $entity_id,
        'title',
        $title_patterns,
        $window_start,
        $anchor,
        $source_ids,
        $limit,
      );
    }

    $url_scored_candidates = [];
    if ($url_patterns !== []) {
      $url_scored_candidates = $this->getSeriesCandidateIdsByPatterns(
        $entity_id,
        'url',
        $url_patterns,
        $window_start,
        $anchor,
        $source_ids,
        $limit,
      );
    }

    $title_match_count = count($title_scored_candidates);
    $url_match_count = count($url_scored_candidates);
    $both_signals_count = count(array_intersect(
      array_keys($title_scored_candidates),
      array_keys($url_scored_candidates),
    ));

    $merged_scored_candidates = [];
    foreach ($title_scored_candidates as $nid => $score) {
      $merged_scored_candidates[$nid] = ($merged_scored_candidates[$nid] ?? 0) + $score;
    }
    foreach ($url_scored_candidates as $nid => $score) {
      $merged_scored_candidates[$nid] = ($merged_scored_candidates[$nid] ?? 0) + $score;
    }

    $merged_count = count($merged_scored_candidates);
    $evidence = new SeriesMatchEvidence(
      lookbackMonths: $months,
      titleMatchCount: $title_match_count,
      urlMatchCount: $url_match_count,
      bothSignalsCount: $both_signals_count,
      mergedCount: $merged_count,
    );

    if ($merged_scored_candidates === []) {
      return SeriesMatchResult::stopped(
        SeriesMatchReason::NoPatternMatches,
        $evidence,
        $debug,
      );
    }

    arsort($merged_scored_candidates, \SORT_NUMERIC);
    $merged_scored_candidates = array_slice($merged_scored_candidates, 0, $limit, TRUE);
    $merged_after_limit_count = count($merged_scored_candidates);

    $metadata = $this->getCandidateMetadata(array_keys($merged_scored_candidates));
    $clusters = $this->clusterCandidates($merged_scored_candidates, $metadata);
    $selection = $this->selectBestCluster($clusters, $merged_scored_candidates, $metadata);

    $cluster_sizes = array_map('count', $clusters);
    $best_cluster_size = count($selection['cluster']);
    $best_cluster_share = $merged_after_limit_count > 0
      ? $best_cluster_size / $merged_after_limit_count
      : 0.0;

    $evidence = $evidence->with([
      'mergedAfterLimitCount' => $merged_after_limit_count,
      'clusterCount' => count($clusters),
      'clusterSizes' => $cluster_sizes,
      'bestClusterSize' => $best_cluster_size,
      'bestClusterShare' => $best_cluster_share,
      'clusterScore' => $selection['cluster_score'],
      'clusterScoreSize' => $selection['size_score'],
      'clusterScorePattern' => $selection['pattern_score'],
      'clusterScoreTagging' => $selection['tagging_consistency'],
    ]);

    if ($best_cluster_size < $minimum_series_count) {
      return SeriesMatchResult::stopped(
        SeriesMatchReason::BelowMinimumCluster,
        $evidence,
        $debug,
        SeriesMatchReason::BelowMinimumCluster,
      );
    }

    $merged_scored_candidates = array_intersect_key(
      $merged_scored_candidates,
      array_flip($selection['cluster']),
    );
    arsort($merged_scored_candidates, \SORT_NUMERIC);

    $candidate_ids = array_keys($merged_scored_candidates);
    $display_lookback_months = $this->computeBestClusterLookbackMonths(
      $anchor,
      $selection['cluster'],
      $metadata,
    );
    $evidence = $evidence->with([
      'candidateIds' => $candidate_ids,
      'candidatePatternScores' => $merged_scored_candidates,
      'lookbackMonths' => $display_lookback_months,
    ]);

    $proposal = $this->buildSeriesMatchProposal(
      $entity,
      $original_title,
      $candidate_ids,
      $metadata,
    );

    return new SeriesMatchResult(
      new SeriesMatchStatus(passedMinimum: TRUE),
      $proposal,
      $evidence,
      $debug,
    );
  }

  /**
   * Builds optional form diagnostics from run parameters and config.
   *
   * @param int $entityId
   *   Analyzed entity node ID.
   * @param int $lookbackMonths
   *   Candidate lookup window in months.
   * @param int $anchor
   *   Series anchor timestamp (publication date, created, or request time).
   * @param int $windowStart
   *   Lower bound of candidate created filter (anchor minus lookback months).
   * @param int $candidateLimit
   *   Maximum number of candidate nodes considered.
   * @param string $originalTitle
   *   Entity original title (first revision when saved, else entity title).
   * @param string $originUrl
   *   Parsed origin URL used for pattern extraction.
   * @param int $titlePatternCount
   *   Number of generated title LIKE patterns.
   * @param int $urlPatternCount
   *   Number of generated URL LIKE patterns.
   * @param int[] $sourceTermIds
   *   Source term IDs attached to the analyzed entity.
   * @param int $minimumSeriesCount
   *   Minimum reports required to consider a valid series.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchDebugTrace
   *   Debug trace including config snapshot values from the matcher.
   */
  protected function buildDebugTrace(
    int $entityId = 0,
    int $lookbackMonths = 0,
    int $anchor = 0,
    int $windowStart = 0,
    int $candidateLimit = 0,
    string $originalTitle = '',
    string $originUrl = '',
    int $titlePatternCount = 0,
    int $urlPatternCount = 0,
    array $sourceTermIds = [],
    int $minimumSeriesCount = 0,
  ): SeriesMatchDebugTrace {
    return new SeriesMatchDebugTrace(
      entityId: $entityId,
      lookbackMonths: $lookbackMonths,
      anchor: $anchor,
      windowStart: $windowStart,
      candidateLimit: $candidateLimit,
      originalTitle: $originalTitle,
      originUrl: $originUrl,
      titlePatternCount: $titlePatternCount,
      urlPatternCount: $urlPatternCount,
      sourceTermIds: $sourceTermIds,
      similarityThreshold: $this->getCandidateClusteringSimilarityThreshold(),
      pairwiseTaggingWeight: $this->getCandidateClusteringTaggingWeight(),
      pairwiseTitleWeight: $this->getCandidateClusteringTitleWeight(),
      clusterWeightSize: $this->getClusterScoringSizeWeight(),
      clusterWeightPattern: $this->getClusterScoringPatternScoreWeight(),
      clusterWeightTagging: $this->getClusterScoringTaggingConsistencyWeight(),
      minimumSeriesCount: $minimumSeriesCount,
    );
  }

  /**
   * Builds proposed field updates for a successful match.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity to apply series tagging to.
   * @param string $original_title
   *   Original title of the report (first revision when saved, else entity).
   * @param int[] $candidate_ids
   *   Final candidate node IDs in the winning cluster.
   * @param CandidateMetadataSet $metadata
   *   Candidate field metadata used to copy values and generate the title.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchProposal
   *   Proposed field values and provenance, or empty when there are no
   *   candidates.
   */
  protected function buildSeriesMatchProposal(
    EntityInterface $entity,
    string $original_title,
    array $candidate_ids,
    array $metadata,
  ): SeriesMatchProposal {
    if ($candidate_ids === []) {
      return new SeriesMatchProposal();
    }

    $sorted_candidate_ids = $this->sortCandidateIdsByRecency($candidate_ids, $metadata);
    $most_recent_candidate_id = $sorted_candidate_ids[0] ?? max($candidate_ids);

    $field_copy = $this->getFieldValuesToCopy(
      $candidate_ids,
      $metadata,
      $most_recent_candidate_id,
    );
    $title_result = $this->generateReportTitle(
      $entity,
      $original_title,
      $sorted_candidate_ids,
      $metadata,
    );

    $updated_fields = $field_copy['values'];
    $updated_fields['title'] = $title_result['title'];

    return new SeriesMatchProposal(
      updatedFields: $updated_fields,
      updatedFieldSources: $field_copy['sources'],
      titleSource: $title_result['source'],
      titleAiDurationSeconds: $title_result['aiDurationSeconds'],
      mostRecentCandidateId: $most_recent_candidate_id,
    );
  }

  /**
   * Check if series matching can apply to the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if series matching can apply to the entity, FALSE otherwise.
   */
  protected function canApplySeriesMatching(EntityInterface $entity): bool {
    return $entity->getEntityTypeId() === 'node' && $entity->bundle() === 'report';
  }

  /**
   * Returns typed matcher settings loaded from config.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchMatcherSettings
   *   Matcher settings from reliefweb_content_analyzer.settings.
   */
  protected function matcherSettings(): SeriesMatchMatcherSettings {
    return $this->matcherSettings ??= SeriesMatchMatcherSettings::fromConfigArray(
      $this->configFactory
        ->get('reliefweb_content_analyzer.settings')
        ->get('report_series_matching.matcher'),
    );
  }

  /**
   * Get the minimum number of reports required for a confident series match.
   *
   * Used for confidence tiering, not candidate retrieval.
   *
   * @return int
   *   The minimum number of reports to consider a series.
   */
  protected function getMinimumSeriesReportCount(): int {
    return $this->matcherSettings()->minimumSeriesReportCount;
  }

  /**
   * Get the lookback window in months for series candidate lookup.
   *
   * @return int
   *   How many months before the entity creation date to search (e.g. 3).
   */
  protected function getSeriesCandidateDateRangeMonths(): int {
    return $this->matcherSettings()->seriesCandidateDateRangeMonths;
  }

  /**
   * Get the maximum number of series candidates to return.
   *
   * @return int
   *   The maximum number of series candidates to return.
   */
  protected function getSeriesCandidateLimit(): int {
    return $this->matcherSettings()->seriesCandidateLimit;
  }

  /**
   * Get the maximum source text length for title AI generation.
   *
   * @return int
   *   Maximum character length of extracted file text passed to the AI.
   */
  protected function getAiTitleSourceLengthLimit(): int {
    return $this->matcherSettings()->aiTitleSourceLengthLimit;
  }

  /**
   * Get the number of example titles to include in title AI generation.
   *
   * @return int
   *   Maximum number of candidate titles passed as style examples.
   */
  protected function getAiTitleExampleLineCount(): int {
    return $this->matcherSettings()->aiTitleExampleLineCount;
  }

  /**
   * Get the template for the structured output title field description.
   *
   * @return string
   *   Description template; @examples is replaced with numbered example titles.
   */
  protected function getAiTitleDescriptionTemplate(): string {
    // @todo review the wording of the description if necessary after testing.
    return $this->matcherSettings()->aiTitleDescriptionTemplate;
  }

  /**
   * Get the number of tokens to include in the pattern.
   *
   * @return int[]
   *   The number of tokens to include in the pattern.
   */
  protected function getPatternTokenCounts(): array {
    return $this->matcherSettings()->patternTokenCounts;
  }

  /**
   * Get the weight of tagging similarity in candidate pairwise clustering.
   *
   * @return float
   *   Weight in the range 0–1. Must sum to 1 with the title weight.
   */
  protected function getCandidateClusteringTaggingWeight(): float {
    return $this->matcherSettings()->candidateClusteringTaggingWeight;
  }

  /**
   * Get the weight of edited-title similarity in candidate pairwise clustering.
   *
   * @return float
   *   Weight in the range 0–1. Must sum to 1 with the tagging weight.
   */
  protected function getCandidateClusteringTitleWeight(): float {
    return $this->matcherSettings()->candidateClusteringTitleWeight;
  }

  /**
   * Get the combined similarity threshold for candidate clustering edges.
   *
   * @return float
   *   Minimum combined pairwise similarity (0–1) to connect two candidates.
   */
  protected function getCandidateClusteringSimilarityThreshold(): float {
    return $this->matcherSettings()->candidateClusteringSimilarityThreshold;
  }

  /**
   * Get the weight of cluster size in best-cluster selection.
   *
   * @return float
   *   Weight for the normalised cluster size signal.
   */
  protected function getClusterScoringSizeWeight(): float {
    return $this->matcherSettings()->clusterScoringSizeWeight;
  }

  /**
   * Get the weight of average pattern score in best-cluster selection.
   *
   * @return float
   *   Weight for the normalised average pattern score signal.
   */
  protected function getClusterScoringPatternScoreWeight(): float {
    return $this->matcherSettings()->clusterScoringPatternScoreWeight;
  }

  /**
   * Get the weight of tagging consistency in best-cluster selection.
   *
   * @return float
   *   Weight for the tagging consistency signal.
   */
  protected function getClusterScoringTaggingConsistencyWeight(): float {
    return $this->matcherSettings()->clusterScoringTaggingConsistencyWeight;
  }

  /**
   * Get the field names to get the metadata from.
   *
   * @return string[]
   *   The field names.
   */
  protected function getMetadataFieldNames(): array {
    return array_unique(array_merge(
      [
        'title',
        'created',
        $this->getRecencyFieldName(),
      ],
      $this->getReportEntityFieldNamesToCopy(),
      $this->getClusterComparisonFieldNames(),
    ));
  }

  /**
   * Get the field names to compare for cluster comparison.
   *
   * @return string[]
   *   The field names.
   */
  protected function getClusterComparisonFieldNames(): array {
    return $this->matcherSettings()->clusterComparisonFieldNames;
  }

  /**
   * Get date field to determine recency.
   *
   * @return string
   *   The field name.
   */
  protected function getRecencyFieldName(): string {
    return $this->matcherSettings()->recencyFieldName;
  }

  /**
   * Get the field names to copy to the report entity.
   *
   * @return string[]
   *   The field names.
   */
  protected function getReportEntityFieldNamesToCopy(): array {
    return $this->matcherSettings()->reportEntityFieldNamesToCopy;
  }

  /**
   * Hardcoded inference settings for report title AI generation.
   *
   * @return InferenceSettings
   *   Inference settings.
   *
   * @todo Read from config or the node_report classification workflow.
   */
  protected function getReportTitleAiInferenceSettings(): array {
    return [
      'plugin_id' => 'aws_bedrock_nova_lite_v1',
      'temperature' => 0.0,
      'top_p' => 0.9,
      'max_tokens' => 512,
      'thinking_mode' => 'none',
      'system_prompt' => 'Generate a title for this humanitarian report that matches the series naming style shown in the structured output schema. Use the `structured_output` tool.',
    ];
  }

  /**
   * Get the field definitions for the metadata fields.
   *
   * @param string[] $field_names
   *   The field names.
   *
   * @return FieldDefinitions
   *   The field definitions keyed by field name.
   */
  protected function getMetadataFieldDefinitions(array $field_names): array {
    $entity_type_id = 'node';
    $bundle = 'report';
    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);

    $definitions = [];
    foreach ($field_names as $field_name) {
      $field_storage_definition = $field_definitions[$field_name]?->getFieldStorageDefinition();
      if ($field_storage_definition === NULL) {
        $definitions[$field_name] = NULL;
        continue;
      }

      $type = match (TRUE) {
        $field_storage_definition->isBaseField() => 'property',
        $field_storage_definition->getType() === 'entity_reference' => 'reference',
        default => 'value',
      };

      $definitions[$field_name] = [
        'type' => $type,
        'column' => $field_storage_definition->getMainPropertyName(),
        'multiple' => $field_storage_definition->getCardinality() !== 1,
      ];
    }
    return $definitions;
  }

  /**
   * Get source term IDs from a report entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity.
   *
   * @return int[]
   *   Source taxonomy term IDs.
   */
  protected function getEntitySourceIds(EntityInterface $entity): array {
    if (!$entity->hasField('field_source') || $entity->get('field_source')->isEmpty()) {
      return [];
    }

    return array_map(
      'intval',
      array_column($entity->get('field_source')->getValue(), 'target_id'),
    );
  }

  /**
   * Fetch lightweight metadata for candidate report nodes.
   *
   * @param int[] $nids
   *   Candidate node IDs.
   * @param string[] $field_names
   *   The field names to get the metadata from.
   *
   * @return CandidateMetadataSet
   *   Metadata keyed by node ID.
   */
  protected function getCandidateMetadata(
    array $nids,
    array $field_names = [],
  ): array {
    if (empty($nids)) {
      return [];
    }

    $field_names = $field_names ?: $this->getMetadataFieldNames();
    if (empty($field_names)) {
      return [];
    }

    $field_definitions = $this->getMetadataFieldDefinitions($field_names);

    $values = [];
    $defaults = [];
    foreach ($field_names as $field_name) {
      $field_definition = $field_definitions[$field_name];
      if ($field_definition === NULL) {
        $defaults[$field_name] = [];
        $values[$field_name] = [];
        continue;
      }

      $type = $field_definition['type'];
      $column = $field_definition['column'];

      $values[$field_name] = match ($type) {
        'property' => $this->fetchPropertyValuesGrouped($field_name, $nids),
        'value' => $this->fetchFieldValuesGrouped($field_name, $nids, $column),
        'reference' => $this->fetchReferenceTargetIdsGrouped($field_name, $column, $nids),
      };

      $defaults[$field_name] = match ($type) {
        'property' => '',
        default =>  [],
      };

      foreach ($nids as $nid) {
        $metadata[$nid][$field_name] = $values[$field_name][$nid] ?? $defaults[$field_name];
      }
    }
    return $metadata;
  }

  /**
   * Fetch string entity property values grouped by entity ID.
   *
   * @param string $property
   *   Property name.
   * @param int[] $entity_ids
   *   Entity IDs.
   *
   * @return array<int, string>
   *   The property values keyed by entity ID.
   */
  protected function fetchPropertyValuesGrouped(string $property, array $entity_ids): array {
    $query = $this->database->select('node_field_data', 'nfd');
    $query->fields('nfd', ['nid', $property]);
    $query->condition('nfd.nid', $entity_ids, 'IN');
    $results = $query->execute()->fetchAllKeyed();

    $values = [];
    foreach ($entity_ids as $entity_id) {
      $values[$entity_id] = $results[$entity_id] ?? '';
    }
    return $values;
  }

  /**
   * Fetch field values grouped by entity ID.
   *
   * @param string $field_name
   *   Field name.
   * @param int[] $entity_ids
   *   Entity IDs.
   * @param string $column
   *   The column to fetch.
   *
   * @return array<int, string[]>
   *   The field values keyed by entity ID.
   */
  protected function fetchFieldValuesGrouped(
    string $field_name,
    array $entity_ids,
    string $column = 'value',
  ): array {
    if ($entity_ids === []) {
      return [];
    }

    $query = $this->database->select('node__' . $field_name, 'f');
    $query->fields('f', ['entity_id', $field_name . '_' . $column]);
    $query->condition('f.entity_id', $entity_ids, 'IN');
    $query->condition('f.deleted', 0);
    $query->orderBy('f.entity_id', 'ASC');
    $query->orderBy('f.delta', 'ASC');

    $results = [];
    foreach ($query->execute() as $row) {
      $results[(int) $row->entity_id][] = $row->{$field_name . '_' . $column};
    }

    $values = [];
    foreach ($entity_ids as $entity_id) {
      $values[$entity_id] = $results[$entity_id] ?? [];
    }
    return $values;
  }

  /**
   * Load sorted unique reference target IDs per entity from a field table.
   *
   * @param string $table
   *   Field data table (e.g. field_primary_country).
   * @param string $target_column
   *   Target ID column on that table.
   * @param int[] $entity_ids
   *   Entity IDs to load.
   *
   * @return array<int, int[]>
   *   Entity ID keyed map of sorted unique target IDs.
   */
  protected function fetchReferenceTargetIdsGrouped(
    string $table,
    string $target_column,
    array $entity_ids,
  ): array {
    if ($entity_ids === []) {
      return [];
    }

    $values = $this->fetchFieldValuesGrouped($table, $entity_ids, $target_column);
    foreach ($values as $entity_id => $field_values) {
      $target_ids = array_unique(array_map('intval', $field_values));
      sort($target_ids, \SORT_NUMERIC);
      $values[$entity_id] = $target_ids;
    }
    return $values;
  }

  /**
   * Cluster candidates by tagging overlap and edited-title similarity.
   *
   * @param array<int, int> $scored_candidates
   *   Merged pattern scores keyed by node ID.
   * @param CandidateMetadataSet $metadata
   *   Candidate metadata from getCandidateMetadata().
   *
   * @return array<int, int[]>
   *   Connected components (clusters) of node IDs, largest first.
   */
  protected function clusterCandidates(array $scored_candidates, array $metadata): array {
    $nids = array_keys($scored_candidates);
    if (count($nids) <= 1) {
      return $nids === [] ? [] : [$nids];
    }

    $threshold = $this->getCandidateClusteringSimilarityThreshold();
    $adjacency = array_fill_keys($nids, []);

    $count = count($nids);
    for ($i = 0; $i < $count; $i++) {
      for ($j = $i + 1; $j < $count; $j++) {
        $nid_i = $nids[$i];
        $nid_j = $nids[$j];
        if ($this->computePairwiseCandidateSimilarity(
          $metadata[$nid_i] ?? [],
          $metadata[$nid_j] ?? [],
        ) >= $threshold) {
          $adjacency[$nid_i][] = $nid_j;
          $adjacency[$nid_j][] = $nid_i;
        }
      }
    }

    $clusters = $this->findConnectedClusters($adjacency);
    usort($clusters, static fn(array $a, array $b): int => count($b) <=> count($a));

    return $clusters;
  }

  /**
   * Select the most likely series cluster from a list of candidate clusters.
   *
   * @param array<int, int[]> $clusters
   *   Clusters of node IDs from clusterCandidates().
   * @param array<int, int> $scored_candidates
   *   Merged pattern scores keyed by node ID.
   * @param CandidateMetadataSet $metadata
   *   Candidate metadata from getCandidateMetadata().
   *
   * @return array{cluster: int[], cluster_score: float, size_score: float, pattern_score: float, tagging_consistency: float}
   *   Best cluster and its score breakdown.
   */
  protected function selectBestCluster(
    array $clusters,
    array $scored_candidates,
    array $metadata,
  ): array {
    $empty = [
      'cluster' => [],
      'cluster_score' => 0.0,
      'size_score' => 0.0,
      'pattern_score' => 0.0,
      'tagging_consistency' => 0.0,
    ];

    if ($clusters === []) {
      return $empty;
    }

    $total_candidates = count($scored_candidates);
    $max_pattern_score = max($scored_candidates) ?: 1;

    $size_weight = $this->getClusterScoringSizeWeight();
    $pattern_weight = $this->getClusterScoringPatternScoreWeight();
    $tagging_weight = $this->getClusterScoringTaggingConsistencyWeight();

    $best_cluster = [];
    $best_score = -1.0;
    $best_size_score = 0.0;
    $best_pattern_score = 0.0;
    $best_tagging_score = 0.0;

    foreach ($clusters as $cluster) {
      $cluster_size = count($cluster);
      $size_score = $cluster_size / $total_candidates;

      $pattern_sum = 0;
      foreach ($cluster as $nid) {
        $pattern_sum += $scored_candidates[$nid] ?? 0;
      }
      $pattern_score = ($pattern_sum / $cluster_size) / $max_pattern_score;

      $tagging_score = $this->computeClusterTaggingConsistency($cluster, $metadata);

      $cluster_score = ($size_weight * $size_score)
        + ($pattern_weight * $pattern_score)
        + ($tagging_weight * $tagging_score);

      if ($cluster_score > $best_score) {
        $best_score = $cluster_score;
        $best_cluster = $cluster;
        $best_size_score = $size_score;
        $best_pattern_score = $pattern_score;
        $best_tagging_score = $tagging_score;
      }
    }

    return [
      'cluster' => $best_cluster,
      'cluster_score' => $best_score,
      'size_score' => $best_size_score,
      'pattern_score' => $best_pattern_score,
      'tagging_consistency' => $best_tagging_score,
    ];
  }

  /**
   * Compute combined pairwise similarity between two candidates.
   *
   * @param CandidateMetadata $a
   *   First candidate metadata.
   * @param CandidateMetadata $b
   *   Second candidate metadata.
   *
   * @return float
   *   Combined similarity in the range 0–1.
   */
  protected function computePairwiseCandidateSimilarity(array $a, array $b): float {
    $tagging_fields = $this->getClusterComparisonFieldNames();

    $field_scores = [];
    foreach ($tagging_fields as $field) {
      $field_scores[] = $this->computePairwiseTaggingFieldSimilarity(
        $a[$field] ?? [],
        $b[$field] ?? [],
      );
    }
    $tagging_score = array_sum($field_scores) / count($tagging_fields);

    $title_a = $a['title'] ?? '';
    $title_b = $b['title'] ?? '';
    $title_score = 0.0;
    if ($title_a !== '' && $title_b !== '') {
      similar_text($title_a, $title_b, $percent);
      $title_score = $percent / 100.0;
    }

    return ($this->getCandidateClusteringTaggingWeight() * $tagging_score)
      + ($this->getCandidateClusteringTitleWeight() * $title_score);
  }

  /**
   * Compute pairwise similarity for one multi-valued tagging dimension.
   *
   * Empty-empty counts as agreement. Empty vs non-empty counts as mismatch.
   * Otherwise uses Jaccard similarity on sorted unique term ID sets.
   *
   * @param int[] $set_a
   *   Sorted unique term IDs for the field on candidate A.
   * @param int[] $set_b
   *   Sorted unique term IDs for the field on candidate B.
   *
   * @return float
   *   Score in the range 0–1.
   */
  protected function computePairwiseTaggingFieldSimilarity(array $set_a, array $set_b): float {
    if ($set_a === [] && $set_b === []) {
      return 1.0;
    }
    if ($set_a === [] || $set_b === []) {
      return 0.0;
    }

    $intersection = count(array_intersect($set_a, $set_b));
    if ($intersection === 0) {
      return 0.0;
    }

    $union = count(array_unique(array_merge($set_a, $set_b)));

    return $union > 0 ? $intersection / $union : 0.0;
  }

  /**
   * Compute how consistently a cluster shares tagging field values.
   *
   * @param int[] $cluster
   *   Node IDs in the cluster.
   * @param CandidateMetadataSet $metadata
   *   Candidate metadata keyed by node ID.
   *
   * @return float
   *   Average fraction of members sharing the most common value per field.
   */
  protected function computeClusterTaggingConsistency(array $cluster, array $metadata): float {
    if ($cluster === []) {
      return 0.0;
    }

    $tagging_fields = $this->getClusterComparisonFieldNames();

    $field_scores = [];
    foreach ($tagging_fields as $field) {
      // Count members that include each term ID; mode = term appearing on most
      // members.
      $members_per_term = [];
      foreach ($cluster as $nid) {
        $ids = $metadata[$nid][$field] ?? [];
        foreach ($ids as $tid) {
          $members_per_term[$tid] = ($members_per_term[$tid] ?? 0) + 1;
        }
      }
      if ($members_per_term === []) {
        // Everyone empty on this field — treat as fully aligned for
        // consistency.
        $field_scores[] = 1.0;
      }
      else {
        $field_scores[] = max($members_per_term) / count($cluster);
      }
    }

    return array_sum($field_scores) / count($field_scores);
  }

  /**
   * Find connected components from an adjacency list using BFS flood-fill.
   *
   * @param array<int|string, int[]|string[]> $adjacency
   *   Adjacency list mapping each node to its connected neighbours.
   *
   * @return array<int, int[]|string[]>
   *   Connected components, each an array of node keys.
   */
  protected function findConnectedClusters(array $adjacency): array {
    $keys = array_keys($adjacency);
    $visited = [];
    $clusters = [];

    foreach ($keys as $key) {
      if (isset($visited[$key])) {
        continue;
      }
      $cluster = [];
      $queue = [$key];
      while ($queue !== []) {
        $current = array_shift($queue);
        if (isset($visited[$current])) {
          continue;
        }
        $visited[$current] = TRUE;
        $cluster[] = $current;
        foreach ($adjacency[$current] ?? [] as $neighbour) {
          if (!isset($visited[$neighbour])) {
            $queue[] = $neighbour;
          }
        }
      }
      $clusters[] = $cluster;
    }

    return $clusters;
  }

  /**
   * Run a multi-pattern candidate query and return scored entity IDs.
   *
   * Score tiers are processed cumulatively from highest to lowest. Candidates
   * from lower tiers are added to the accumulated set without discarding higher
   * tier matches. Each candidate keeps the score of the highest tier it
   * matched.
   *
   * @param int $entity_id
   *   The entity ID to exclude from the results.
   * @param string $field
   *   The field to search in (title or url).
   * @param array<int, string> $patterns
   *   Scored LIKE patterns keyed by specificity score.
   * @param int $window_start
   *   Lower bound for candidate node_field_data.created.
   * @param int $window_end
   *   Upper bound for candidate node_field_data.created.
   * @param int[] $source_ids
   *   The source IDs to search for.
   * @param int|null $limit
   *   The maximum number of results to return.
   *
   * @return array<int, int>
   *   Returns an array of candidate report IDs as keys, each mapped to their
   *   matching score tier. Higher scores mean more specific matches.
   */
  protected function getSeriesCandidateIdsByPatterns(
    int $entity_id,
    string $field,
    array $patterns,
    int $window_start,
    int $window_end,
    array $source_ids,
    ?int $limit = NULL,
  ): array {
    if ($patterns === []) {
      return [];
    }

    $limit = $limit ?? $this->getSeriesCandidateLimit();
    $query = $this->getSeriesCandidateQueryMultiPattern(
      $entity_id,
      $field,
      $patterns,
      $window_start,
      $window_end,
      $source_ids,
      $limit,
    );

    $rows = $query->execute()->fetchAll();

    if ($rows === []) {
      return [];
    }

    $tiers = [];
    foreach ($rows as $row) {
      $score = (int) $row->pattern_score;
      if ($score === 0) {
        continue;
      }
      $tiers[$score][] = $row;
    }

    if ($tiers === []) {
      return [];
    }

    krsort($tiers, \SORT_NUMERIC);

    $candidates = [];
    foreach ($tiers as $score => $tier_rows) {
      foreach ($tier_rows as $row) {
        $nid = (int) $row->nid;
        if (!isset($candidates[$nid])) {
          $candidates[$nid] = $score;
        }
      }
    }

    if ($candidates === []) {
      return [];
    }

    return array_slice($candidates, 0, $limit, TRUE);
  }

  /**
   * Get the SQL query to find the series candidates using multiple patterns.
   *
   * @param int $entity_id
   *   The entity ID to exclude from the results.
   * @param string $field
   *   The field to search in (title or url).
   * @param array<int, string> $patterns
   *   Scored LIKE patterns keyed by specificity score.
   * @param int $window_start
   *   Lower bound for candidate node_field_data.created.
   * @param int $window_end
   *   Upper bound for candidate node_field_data.created.
   * @param int[] $source_ids
   *   The source IDs to search for.
   * @param int $limit
   *   The maximum number of results to return.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The SQL query to find the series candidates.
   */
  protected function getSeriesCandidateQueryMultiPattern(
    int $entity_id,
    string $field,
    array $patterns,
    int $window_start,
    int $window_end,
    array $source_ids,
    int $limit = 100,
  ): SelectInterface {
    // Subquery to limit to the first revisions.
    $first_revision_subquery = $this->database->select('node_field_revision', 'nfr_inner');
    $first_revision_subquery->fields('nfr_inner', ['nid']);
    $first_revision_subquery->addExpression('MIN(nfr_inner.vid)', 'first_vid');
    $first_revision_subquery->groupBy('nfr_inner.nid');

    // Main query.
    $query = $this->database->select('node_field_data', 'nfd');
    $query->fields('nfd', ['nid', 'created']);

    // Join to only the first revision.
    $query->innerJoin(
      $first_revision_subquery,
      'first_revision',
      'first_revision.nid = nfd.nid',
    );

    // Limit to report nodes.
    $query->condition('nfd.type', 'report');

    // Exclude the entity itself.
    $query->condition('nfd.nid', $entity_id, '<>');

    // Filter by candidate created date range.
    $query->condition('nfd.created', $window_start, '>=');
    $query->condition('nfd.created', $window_end, '<=');

    // Filter by source IDs.
    // Candidates must have, at least, every source on the entity.
    foreach ($source_ids as $index => $source_id) {
      $alias = 'fs' . $index;
      $query->innerJoin(
        'node__field_source',
        $alias,
        "{$alias}.entity_id = nfd.nid AND {$alias}.deleted = 0 AND {$alias}.field_source_target_id = :source_{$index}",
        [":source_{$index}" => $source_id],
      );
    }

    // Get the field to match on.
    if ($field === 'title') {
      $match_field = 'nfr.title';
      $query->innerJoin(
        'node_field_revision',
        'nfr',
        'nfr.nid = first_revision.nid AND nfr.vid = first_revision.first_vid',
      );
    }
    elseif ($field === 'url') {
      $match_field = 'fon.field_origin_notes_value';
      $query->innerJoin(
        'node__field_origin_notes',
        'fon',
        'fon.entity_id = nfd.nid AND fon.deleted = 0',
      );
    }
    else {
      throw new \InvalidArgumentException("Unsupported pattern field: {$field}");
    }

    // Build the condition group for the patterns and the case expression.
    $or_group = $query->orConditionGroup();
    $case_parts = ['CASE'];
    $case_arguments = [];
    $score = count($patterns);

    foreach ($patterns as $index => $pattern) {
      $or_group->condition($match_field, $pattern, 'LIKE');
      $case_parts[] = "WHEN {$match_field} LIKE :pattern_{$index} THEN :score_{$index}";
      $case_arguments[":pattern_{$index}"] = $pattern;
      $case_arguments[":score_{$index}"] = $score--;
    }
    $case_parts[] = 'ELSE 0 END';

    $query->condition($or_group);
    $query->addExpression(implode(' ', $case_parts), 'pattern_score', $case_arguments);

    // Order by score and created date.
    // @todo evaluate if we should order by created date first.
    $query->orderBy('pattern_score', 'DESC');
    $query->orderBy('nfd.created', 'DESC');

    // Limit the number of results.
    $query->range(0, $limit);

    return $query;
  }

  /**
   * Convert a title to scored SQL LIKE prefix patterns.
   *
   * @param string $title
   *   The title to convert.
   *
   * @return array<int, string>
   *   Scored LIKE patterns keyed by specificity score (higher = more specific).
   */
  protected function titleToLikePatterns(string $title): array {
    return $this->generatePatternList(
      $title,
      $this->getPatternTokenCounts(),
    );
  }

  /**
   * Convert an origin URL to scored SQL LIKE prefix patterns.
   *
   * @param string $url
   *   The origin URL to convert.
   *
   * @return array<int, string>
   *   Scored LIKE patterns keyed by specificity score (higher = more specific).
   */
  protected function urlToLikePatterns(string $url): array {
    // Split the URL into protocol/domain and path.
    $parts = parse_url($url);
    if ($parts === FALSE || empty($parts['host']) || empty($parts['path'])) {
      return [];
    }
    $prefix = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . '/';
    $path = trim($parts['path'] ?? '', '/');

    // Skip if the URL ends with a numeric ID. It's likely a generic documnent
    // URL like https://example.test/document/123456 that is not specific
    // enough to be used as a series identifier.
    $last_path_component = array_last(explode('/', $path));
    if (is_numeric($last_path_component)) {
      return [];
    }

    // Generate the patterns.
    return $this->generatePatternList(
      $path,
      $this->getPatternTokenCounts(),
      $prefix,
    );
  }

  /**
   * Generate pattern list.
   *
   * @param string $string
   *   The string to generate patterns for. It's already a SQL LIKE pattern.
   * @param int[] $counts
   *   The number of tokens to include in the pattern.
   * @param string $prefix
   *   Prefix to prepend to the patterns. Defaults to ''.
   * @param string $wildcard
   *   The wildcard to use. Defaults to '%'.
   *
   * @return string[]
   *   The patterns ordered by length in descending order.
   */
  protected function generatePatternList(
    string $string,
    array $counts = [10, 8, 6, 4],
    string $prefix = '',
    string $wildcard = '%',
  ): array {
    // Convert the string to a SQL LIKE pattern.
    $string = $this->stringToLikePattern($string, $wildcard);

    // Tokenize the string.
    $tokens = $this->tokenizeString($string);
    if ($tokens === []) {
      return [];
    }

    // Escape the prefix for SQL LIKE.
    if ($prefix !== '') {
      $prefix = $this->database->escapeLike($prefix);
    }

    // Start with the full string.
    $patterns = [$prefix . $string => TRUE];

    // Sort the token counts in descending order.
    rsort($counts, \SORT_NUMERIC);

    // Get the smallest count.
    $minimum = min($counts);

    // Generate patterns for the specified counts.
    foreach ($counts as $count) {
      $pattern_tokens = array_slice($tokens, 0, $count);
      if (count($pattern_tokens) < $minimum) {
        break;
      }

      $last_token = end($pattern_tokens);
      $byte_offset = $last_token['offset'] + strlen($last_token['token']);
      $pattern_string = substr($string, 0, $byte_offset);

      // Append a wildcard to the pattern if none is present.
      if (!str_ends_with($pattern_string, $wildcard)) {
        $pattern_string .= $wildcard;
      }

      // Use the pattern as the key to avoid duplicates.
      $patterns[$prefix . $pattern_string] = TRUE;
    }

    // Return the patterns as an array of strings.
    return array_keys($patterns);
  }

  /**
   * Returns a regex-safe alternation of month names.
   *
   * Includes full and abbreviated names in English, French, Spanish, Russian,
   * Chinese, and Arabic. Built once per request.
   *
   * @return string
   *   A regex-safe alternation of month names.
   */
  private static function getDateLikePatternMonthAlternation(): string {
    static $alternation = NULL;
    if ($alternation !== NULL) {
      return $alternation;
    }

    $names = [
      // English full.
      'January', 'February', 'March', 'April', 'May', 'June',
      'July', 'August', 'September', 'October', 'November', 'December',
      // English abbreviated.
      'Jan', 'Feb', 'Mar', 'Apr', 'Jun', 'Jul', 'Aug',
      'Sep', 'Sept', 'Oct', 'Nov', 'Dec',
      // French full.
      'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
      'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
      // French abbreviated.
      'janv.', 'févr.', 'avr.', 'juil.', 'sept.', 'oct.', 'nov.', 'déc.',
      // Spanish full.
      'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
      'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
      // Spanish abbreviated.
      'ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.',
      'jul.', 'ago.', 'dic.',
      // Russian nominative.
      'январь', 'февраль', 'март', 'апрель', 'май', 'июнь',
      'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь',
      // Russian genitive (e.g. "27 апреля 2026").
      'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
      'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
      // Chinese full and numeric month.
      '一月', '二月', '三月', '四月', '五月', '六月',
      '七月', '八月', '九月', '十月', '十一月', '十二月',
      '1月', '2月', '3月', '4月', '5月', '6月',
      '7月', '8月', '9月', '10月', '11月', '12月',
      // Arabic.
      'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
      'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر',
    ];

    $names = array_values(array_unique($names));
    usort($names, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    $alternation = implode('|', array_map(
      static fn(string $name): string => preg_quote($name, '/'),
      $names,
    ));

    return $alternation;
  }

  /**
   * Convert a string to a SQL LIKE pattern.
   *
   * Escapes the string for SQL LIKE and replaces variable date and number parts
   * with SQL LIKE wildcards.
   *
   * @param string $string
   *   The string to convert.
   * @param string $wildcard
   *   The wildcard to use. Defaults to '%'.
   *
   * @return string
   *   The SQL LIKE pattern.
   */
  protected function stringToLikePattern(string $string, string $wildcard = '%'): string {
    // Escape the string for SQL LIKE.
    $string = $this->database->escapeLike($string);

    $months = self::getDateLikePatternMonthAlternation();
    $optional_de = '(?:de\s+)?';
    $optional_le = '(?:le\s+)?';
    $optional_fi = '(?:في\s+)?';
    $day = '(?:1er|1ère|1e|\d{1,2})';

    // Date and number replacements.
    $replacements = [
      // Date patterns (most specific first).
      // Chinese: 2026年4月27日 (no leading \b — CJK text may appear directly
      // before the year).
      '/(?<![0-9])\d{4}年\d{1,2}月\d{1,2}日/u' => $wildcard,

      // Chinese: 2026年4月 or 2026年十二月.
      '/(?<![0-9])\d{4}年(?:' . $months . '|\d{1,2}月)/u' => $wildcard,

      // Numeric day range + month + year: "02 - 06 May 2026".
      '/\b\d{1,2}\s*[-–—]\s*\d{1,2}\s+' . $optional_de . '(?:' . $months . ')\s+' . $optional_de . '\d{4}\b/iu' => $wildcard,

      // Day + month name + year: "27 April 2026", "le 1er avril 2026".
      '/\b' . $optional_le . $optional_fi . $day . '\s+' . $optional_de . '(?:' . $months . ')\s+' . $optional_de . '\d{4}\b/iu' => $wildcard,

      // Month name + year: "December 2025", "diciembre de 2025".
      '/\b(?:' . $months . ')\s+' . $optional_de . '\d{4}\b/iu' => $wildcard,

      // Numeric dates: 2026-04-27, 27/04/2026, 27.04.2026.
      '/\b\d{1,4}[-\/\.]\d{1,2}[-\/\.]\d{2,4}\b/u' => $wildcard,

      // Number patterns.
      // Numeric ranges (not dates): "12-13", "12 - 13", "2024-2025".
      '/\b\d{1,4}\s*[-–—]\s*\d{1,4}\b/u' => $wildcard,

      // Hash-prefixed: #193, #60.
      '/#\d+\w*/u' => $wildcard,

      // Decimal numbers: 93.1, 4.0.
      '/\b\d+\.\d+\b/u' => $wildcard,

      // Label + number: "Week 60", "Update 12", "Tool 5".
      '/\b([A-Za-z]+)\s+\d+\b/u' => '$1 ' . $wildcard,

      // Number + label: "5 Districts", "3 Regions", "2nd Phase".
      '/\b\d+(?:st|nd|rd|th)?\s+([A-Za-z]+)\b/u' => $wildcard . ' $1',

      // Standalone remaining integers.
      '/\b\d+\b/u' => $wildcard,
    ];

    // @todo we should replace common mistaken characters like dashes, mdashes,
    // etc. with a `?` wildcard to be more lenient.
    $result = preg_replace(
      array_keys($replacements),
      array_values($replacements),
      $string
    );

    // Collapse multiple consecutive wildcards into one.
    $escaped = preg_quote($wildcard, '/');
    $result = preg_replace('/(?:' . $escaped . '\s*){2,}/u', $wildcard . ' ', $result);

    return trim($result ?? '');
  }

  /**
   * Tokenize a string into an array of tokens with their byte offsets.
   *
   * Extracts sequences of Unicode word characters (letters and digits),
   * skipping everything else (spaces, punctuation, slashes, hyphens, etc.).
   *
   * @param string $string
   *   The UTF-8 string to tokenize.
   *
   * @return array<int, array{token: string, offset: int}>
   *   Ordered list of tokens. Each entry contains:
   *   - 'token': the token text.
   *   - 'offset': the byte offset at which the token starts in $string.
   *   To find where a token ends (in bytes): offset + strlen(token).
   *   Use substr(), not mb_substr(), when slicing $string with these offsets.
   */
  protected function tokenizeString(string $string): array {
    if ($string === '') {
      return [];
    }

    // Match sequences of Unicode "word" characters (letters, digits).
    // \p{L} = any Unicode letter, \p{N} = any Unicode number.
    if (preg_match_all('/[\p{L}\p{N}]+/u', $string, $matches, PREG_OFFSET_CAPTURE) === FALSE) {
      return [];
    }

    $tokens = [];
    foreach ($matches[0] as [$token, $offset]) {
      $tokens[] = ['token' => $token, 'offset' => $offset];
    }

    return $tokens;
  }

  /**
   * Get the validated origin URL for a report entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity.
   *
   * @return string
   *   The origin URL, or an empty string if missing or invalid.
   */
  protected function getOriginUrl(EntityInterface $entity): string {
    if (!$entity->hasField('field_origin_notes') || $entity->get('field_origin_notes')->isEmpty()) {
      return '';
    }

    $url = (string) $entity->get('field_origin_notes')->value;
    if (!UrlHelper::isValid($url, TRUE)) {
      return '';
    }

    return $url;
  }

  /**
   * Resolve the original title used for pattern matching on the subject report.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity being analyzed.
   *
   * @return string
   *   First-revision title when the entity is saved, otherwise the entity
   *   label.
   */
  protected function resolveOriginalTitle(EntityInterface $entity): string {
    $entity_id = $entity->id();
    if ($entity_id !== NULL) {
      $from_revision = $this->getOriginalTitles([(int) $entity_id])[(int) $entity_id] ?? '';
      if ($from_revision !== '') {
        return $from_revision;
      }
    }

    return trim((string) $entity->label());
  }

  /**
   * Gets the original publication date as a Unix timestamp from the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity.
   *
   * @return int|null
   *   Start-of-day UTC timestamp, or NULL when the field is empty.
   */
  protected function getEntityOriginalPublicationTimestamp(EntityInterface $entity): ?int {
    if (!$entity->hasField('field_original_publication_date')
      || $entity->get('field_original_publication_date')->isEmpty()) {
      return NULL;
    }

    return $this->parseRecencyValueToTimestamp(
      $entity->get('field_original_publication_date')->value,
    );
  }

  /**
   * Parses a recency field value from metadata or an entity to a UTC timestamp.
   *
   * Supports Unix seconds (e.g. node created) and ISO/datetime strings (e.g.
   * original publication date). Format is inferred from the value.
   *
   * @param string|int|array<int, string>|null $value
   *   Recency value from candidate metadata or an entity field.
   *
   * @return int|null
   *   Unix timestamp, or NULL when empty or invalid.
   */
  protected function parseRecencyValueToTimestamp(string|int|array|null $value): ?int {
    if (is_array($value)) {
      $value = array_first($value);
    }

    if (empty($value)) {
      return NULL;
    }

    if (is_int($value)) {
      return $value > 0 ? $value : NULL;
    }

    if (!is_string($value)) {
      return NULL;
    }

    if (is_numeric($value)) {
      $value = (int) $value;
      return $value > 0 ? $value : NULL;
    }

    try {
      return new \DateTimeImmutable($value, new \DateTimeZone('UTC'))->getTimestamp();
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Months spanned by the best cluster for revision-log display.
   *
   * Uses the oldest cluster member's recency date (publication or created)
   * through the series anchor. Falls back to the configured candidate search
   * window when dates are missing.
   *
   * @param int $anchor
   *   Series anchor timestamp (publication date, created, or request time).
   * @param int[] $cluster_ids
   *   Node IDs in the winning cluster.
   * @param CandidateMetadataSet $metadata
   *   Candidate metadata including the recency field.
   *
   * @return int
   *   Whole months (ceiling) for "over N months" messaging, at least 1.
   */
  protected function computeBestClusterLookbackMonths(
    int $anchor,
    array $cluster_ids,
    array $metadata,
  ): int {
    $config_months = $this->getSeriesCandidateDateRangeMonths();
    if ($cluster_ids === []) {
      return $config_months;
    }

    $recency_field = $this->getRecencyFieldName();
    $oldest_timestamp = NULL;
    foreach ($cluster_ids as $candidate_id) {
      if (!isset($metadata[$candidate_id][$recency_field])) {
        return $config_months;
      }
      $recency_value = $metadata[$candidate_id][$recency_field];
      $recency_timestamp = $this->parseRecencyValueToTimestamp($recency_value);
      if ($recency_timestamp === NULL) {
        return $config_months;
      }
      if ($oldest_timestamp === NULL || $recency_timestamp < $oldest_timestamp) {
        $oldest_timestamp = $recency_timestamp;
      }
    }

    if ($oldest_timestamp === NULL) {
      return $config_months;
    }

    try {
      $timezone = new \DateTimeZone('UTC');
      $anchor_datetime = new \DateTimeImmutable('@' . $anchor, $timezone);
      $oldest_datetime = new \DateTimeImmutable('@' . $oldest_timestamp, $timezone);
    }
    catch (\Exception) {
      return $config_months;
    }

    if ($oldest_datetime > $anchor_datetime) {
      return $config_months;
    }

    $interval = $oldest_datetime->diff($anchor_datetime);
    $months = $interval->y * 12 + $interval->m;
    if ($interval->d > 0 || $interval->h > 0 || $interval->i > 0 || $interval->s > 0) {
      $months++;
    }

    return max(1, $months);
  }

  /**
   * Resolves the timestamp that anchors the candidate lookback window.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity being analyzed.
   *
   * @return int
   *   Publication date when set, else created when set, else request time.
   */
  protected function resolveSeriesAnchorTimestamp(EntityInterface $entity): int {
    $publication = $this->getEntityOriginalPublicationTimestamp($entity);
    if ($publication !== NULL) {
      return $publication;
    }

    $created = (int) $entity->get('created')->value;
    if ($created !== 0) {
      return $created;
    }

    return $this->time->getRequestTime();
  }

  /**
   * Get the original titles of entities from their first revision.
   *
   * @param int[] $ids
   *   The entity IDs to get the original titles from.
   *
   * @return array<int, string>
   *   The original titles keyed by entity ID.
   */
  protected function getOriginalTitles(array $ids): array {
    if (empty($ids)) {
      return [];
    }

    // Get the minimum revision ID for each entity.
    $subquery = $this->database->select('node_field_revision', 'nfr2');
    $subquery->addExpression('MIN(vid)', 'min_vid');
    $subquery->fields('nfr2', ['nid']);
    $subquery->condition('nid', $ids, 'IN');
    $subquery->groupBy('nfr2.nid');

    // Get the title for each entity.
    $query = $this->database->select('node_field_revision', 'nfr');
    $query->fields('nfr', ['nid', 'title']);
    $query->join($subquery, 'min_vids', 'nfr.nid = min_vids.nid AND nfr.vid = min_vids.min_vid');

    $titles = [];
    foreach ($query->execute() as $record) {
      $titles[(int) $record->nid] = $record->title;
    }
    return $titles;
  }

  /**
   * Retrieve the first file of the entity if it exists.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to retrieve the file from.
   *
   * @return \Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile|null
   *   The first file field item or NULL if no file exists.
   */
  protected function getFirstFile(EntityInterface $entity): ?ReliefWebFile {
    if (!$entity->hasField('field_file')) {
      return NULL;
    }
    $field = $entity->get('field_file');
    if ($field->isEmpty()) {
      return NULL;
    }
    $item = $field->first();
    return $item instanceof ReliefWebFile ? $item : NULL;
  }

  /**
   * Get the values to copy to the report entity.
   *
   * @param int[] $candidate_ids
   *   The candidate IDs to get the values to copy from.
   * @param CandidateMetadataSet $metadata
   *   The metadata to get the values to copy from.
   * @param int $most_recent_candidate_id
   *   The most recent candidate node ID in the cluster.
   *
   * @return FieldCopyResult
   *   Values and per-field provenance.
   */
  protected function getFieldValuesToCopy(
    array $candidate_ids,
    array $metadata,
    int $most_recent_candidate_id,
  ): array {
    // Get the report fields to copy.
    $fields = $this->getReportEntityFieldNamesToCopy();

    // Get the values to copy that are present in all the candidates.
    $all_present_values = $this->getFieldValuesToCopyPresentInAllCandidates($candidate_ids, $metadata, $fields);

    // Get the values to copy from the most recent candidate.
    $most_recent_values = $this->getFieldValuesToCopyFromMostRecentCandidate(
      $most_recent_candidate_id,
      $metadata,
      $fields,
    );

    // Merge the values.
    $values = [];
    $sources = [];
    foreach ($fields as $field_name) {
      /** @var null|string|string[]|int[] $value */
      $value = NULL;
      $source = SeriesMatchFieldUpdateSource::Skipped;
      $all_present_value = $all_present_values[$field_name] ?? NULL;
      $most_recent_value = $most_recent_values[$field_name] ?? NULL;

      // If both values are present, merge them.
      if ($all_present_value !== NULL && $most_recent_value !== NULL) {
        // Guard against mismatching types. This should never happen.
        if (gettype($all_present_value) !== gettype($most_recent_value)) {
          $value = NULL;
          $source = SeriesMatchFieldUpdateSource::Skipped;
        }
        // For arrays, we merge the values and sort them.
        elseif (is_array($all_present_value)) {
          $value = array_values(array_unique(array_merge($all_present_value, $most_recent_value)));
          $source = match (TRUE) {
            $this->arraysHaveSameValues($all_present_value, $most_recent_value) => SeriesMatchFieldUpdateSource::AllCandidates,
            $all_present_value === [] => SeriesMatchFieldUpdateSource::MostRecent,
            $most_recent_value === [] => SeriesMatchFieldUpdateSource::AllCandidates,
            default => SeriesMatchFieldUpdateSource::Merged,
          };

          // Sort the value's elements if not empty.
          if ($value !== []) {
            if (is_int(array_first($value))) {
              sort($value, \SORT_NUMERIC);
            }
            else {
              sort($value, \SORT_STRING);
            }
          }
        }
        // Otherwise, the most recent value takes precedence (normally they
        // are the same values for single values).
        else {
          $value = $most_recent_value;
          $source = $all_present_value === $most_recent_value
            ? SeriesMatchFieldUpdateSource::AllCandidates
            : SeriesMatchFieldUpdateSource::MostRecent;
        }
      }
      elseif ($most_recent_value !== NULL) {
        $value = $most_recent_value;
        $source = SeriesMatchFieldUpdateSource::MostRecent;
      }
      elseif ($all_present_value !== NULL) {
        $value = $all_present_value;
        $source = SeriesMatchFieldUpdateSource::AllCandidates;
      }

      $values[$field_name] = $value;
      $sources[$field_name] = $source;
    }

    return [
      'values' => $values,
      'sources' => $sources,
    ];
  }

  /**
   * Get the values to copy that are present in all the candidates.
   *
   * @param int[] $candidate_ids
   *   The candidate IDs to get the values to copy from.
   * @param CandidateMetadataSet $metadata
   *   The metadata to get the values to copy from.
   * @param string[] $fields
   *   The fields to get the values to copy from.
   *
   * @return array<string, null|string|string[]|int[]>
   *   The values to copy that are present in all the candidates.
   */
  protected function getFieldValuesToCopyPresentInAllCandidates(
    array $candidate_ids,
    array $metadata,
    array $fields,
  ): array {
    if (empty($fields) || empty($candidate_ids) || empty($metadata)) {
      return [];
    }

    $values = [];
    foreach ($fields as $field_name) {
      // Get the values for the field keyed by candidate ID.
      $field_values = [];
      foreach ($candidate_ids as $candidate_id) {
        if (!isset($metadata[$candidate_id][$field_name])) {
          break;
        }
        $field_values[] = $metadata[$candidate_id][$field_name];
      }

      // Skip if we don't have values for all the candidates.
      if (count($field_values) !== count($candidate_ids)) {
        $values[$field_name] = NULL;
        continue;
      }

      // List of arrays.
      if (array_all($field_values, fn(mixed $value): bool => is_array($value))) {
        // All values are empty arrays, that means the field is consistently
        // empty for all the candidates, we store an empty array as value for
        // the field to override any existing value on the entity to update.
        if (array_all($field_values, fn(array $value): bool => $value === [])) {
          $values[$field_name] = [];
        }
        else {
          // We take the intersection of the values to get the most common ones.
          $intersection = array_values(array_unique(array_intersect(...$field_values)));
          // If any candidate has no values, then the intersection is empty.
          // That means we don't have a common value for the field, we store
          // NULL to ignore that field.
          $values[$field_name] = $intersection !== [] ? $intersection : NULL;
        }
      }
      elseif (array_all($field_values, 'is_string')) {
        if (array_all($field_values, fn(string $value): bool => $value === '')) {
          $values[$field_name] = '';
        }
        else {
          // Here, we have only strings, so a common value means they are all
          // the same, we store the first value if there is only one, otherwise
          // we store NULL to ignore that field.
          $unique = array_unique($field_values);
          $values[$field_name] = count($unique) === 1 ? array_first($unique) : NULL;
        }
      }
    }
    return $values;
  }

  /**
   * Sort candidate IDs from most recent to oldest.
   *
   * @param int[] $candidate_ids
   *   The candidate IDs to sort.
   * @param CandidateMetadataSet $metadata
   *   The candidate metadata.
   *
   * @return int[]
   *   Candidate IDs ordered from most recent to oldest.
   */
  protected function sortCandidateIdsByRecency(array $candidate_ids, array $metadata): array {
    if ($candidate_ids === []) {
      return [];
    }

    $recency_field = $this->getRecencyFieldName();

    $recency_timestamps = [];
    foreach ($candidate_ids as $candidate_id) {
      if (!isset($metadata[$candidate_id][$recency_field])) {
        break;
      }
      $recency_timestamp = $this->parseRecencyValueToTimestamp(
        $metadata[$candidate_id][$recency_field],
      );
      if ($recency_timestamp === NULL) {
        break;
      }
      $recency_timestamps[$candidate_id] = $recency_timestamp;
    }

    if (count($recency_timestamps) === count($candidate_ids)) {
      arsort($recency_timestamps, \SORT_NUMERIC);
      return array_keys($recency_timestamps);
    }

    rsort($candidate_ids, \SORT_NUMERIC);
    return $candidate_ids;
  }

  /**
   * Get the field values to copy from the most recent candidate.
   *
   * @param int $most_recent_candidate_id
   *   The most recent candidate node ID in the cluster.
   * @param CandidateMetadataSet $metadata
   *   The metadata to get the values to copy from.
   * @param string[] $fields
   *   The fields to get the values to copy from.
   *
   * @return array<string, null|string|string[]|int[]>
   *   The values to copy from the most recent candidate, keyed by field name.
   */
  protected function getFieldValuesToCopyFromMostRecentCandidate(
    int $most_recent_candidate_id,
    array $metadata,
    array $fields,
  ): array {
    if ($fields === [] || $most_recent_candidate_id === 0 || $metadata === []) {
      return [];
    }

    $values = [];
    foreach ($fields as $field_name) {
      if (!isset($metadata[$most_recent_candidate_id][$field_name])) {
        $values[$field_name] = NULL;
      }
      else {
        $values[$field_name] = $metadata[$most_recent_candidate_id][$field_name];
      }
    }
    return $values;
  }

  /**
   * Generate a title for a report entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity to generate the title for.
   * @param string $original_title
   *   The original title of the report.
   * @param int[] $candidate_ids
   *   Candidate node IDs ordered from most recent to oldest.
   * @param CandidateMetadataSet $metadata
   *   The metadata to use as examples.
   *
   * @return TitleGenerationResult
   *   Generated title, provenance, and optional AI duration.
   */
  protected function generateReportTitle(
    EntityInterface $entity,
    string $original_title,
    array $candidate_ids,
    array $metadata,
  ): array {
    $candidate_titles = $this->getCandidateTitles($candidate_ids, $metadata);
    if (empty($candidate_titles)) {
      $this->getLogger()->warning('Report title unchanged: no candidate titles.');
      return [
        'title' => $original_title,
        'source' => SeriesMatchTitleSource::FailedNoCandidateTitles,
        'aiDurationSeconds' => NULL,
      ];
    }

    if ($this->checkReportTitleSimilarity($original_title, $candidate_titles)) {
      return [
        'title' => $original_title,
        'source' => SeriesMatchTitleSource::KeptOriginalPatternMatch,
        'aiDurationSeconds' => NULL,
      ];
    }

    if (!$this->matcherSettings()->aiTitleGenerationEnabled) {
      return [
        'title' => $original_title,
        'source' => SeriesMatchTitleSource::SkippedAiDisabled,
        'aiDurationSeconds' => NULL,
      ];
    }

    return $this->generateReportTitleWithAi($entity, $original_title, $candidate_titles);
  }

  /**
   * Gets non-empty candidate titles from metadata.
   *
   * @param int[] $candidate_ids
   *   Candidate node IDs in the order titles should be returned (e.g. most
   *   recent first).
   * @param CandidateMetadataSet $metadata
   *   The metadata to get the titles from.
   *
   * @return string[]
   *   Candidate titles in the same order as $candidate_ids.
   */
  protected function getCandidateTitles(
    array $candidate_ids,
    array $metadata,
  ): array {
    $titles = [];
    foreach ($candidate_ids as $candidate_id) {
      $title = $metadata[$candidate_id]['title'] ?? '';
      if (is_string($title) && $title !== '') {
        $titles[] = $title;
      }
    }
    return $titles;
  }

  /**
   * Check if the title is similar to the candidate titles.
   *
   * @param string $title
   *   The title to check.
   * @param string[] $candidate_titles
   *   The candidate titles to check.
   *
   * @return bool
   *   TRUE if the title is similar to the candidate titles, FALSE otherwise.
   */
  protected function checkReportTitleSimilarity(
    string $title,
    array $candidate_titles,
  ): bool {
    // Generate SQL pattern for all the titles. If they are all the same, then
    // we keep the document title as is. Otherwise we generate a new title
    // using the AI.
    $patterns = [$this->stringToLikePattern($title)];
    foreach ($candidate_titles as $candidate_title) {
      $patterns[] = $this->stringToLikePattern($candidate_title);
    }
    // @todo we could be a bit lenient to allow for slight varations like
    // dashes, mdashes, etc.
    $patterns = array_unique($patterns);
    return count($patterns) === 1;
  }

  /**
   * Generate a title for a report entity using AI.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The report entity to generate the title for.
   * @param string $original_title
   *   The original title of the report.
   * @param string[] $candidate_titles
   *   The candidate titles to use as examples.
   *
   * @return TitleGenerationResult
   *   Generated title, provenance, and optional AI duration.
   */
  protected function generateReportTitleWithAi(
    EntityInterface $entity,
    string $original_title,
    array $candidate_titles,
  ): array {
    if ($candidate_titles === []) {
      $this->getLogger()->warning('Report title unchanged: no candidate titles.');
      return [
        'title' => $original_title,
        'source' => SeriesMatchTitleSource::FailedNoCandidateTitles,
        'aiDurationSeconds' => NULL,
      ];
    }

    $file = $this->getFirstFile($entity);
    $source = $file !== NULL ? trim($file->extractText(1)) : '';
    if ($source === '') {
      $this->getLogger()->warning('Report title unchanged: no attachment text.');
      return [
        'title' => $original_title,
        'source' => SeriesMatchTitleSource::SkippedNoAttachmentText,
        'aiDurationSeconds' => NULL,
      ];
    }

    // Truncate the source to reduce the number of tokens passed to the AI.
    $max_source_length = $this->getAiTitleSourceLengthLimit();
    if (mb_strlen($source) > $max_source_length) {
      $source = mb_substr($source, 0, $max_source_length);
    }

    $example_lines = [];
    foreach (array_slice($candidate_titles, 0, $this->getAiTitleExampleLineCount()) as $index => $title) {
      $example_lines[] = ($index + 1) . '. ' . $title;
    }

    $title_description = strtr($this->getAiTitleDescriptionTemplate(), [
      '@examples' => implode("\n", $example_lines),
    ]);

    $json_schema = [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => [
        'title' => [
          'type' => 'string',
          'description' => $title_description,
        ],
      ],
      'required' => ['title'],
    ];

    $settings = $this->getReportTitleAiInferenceSettings();

    $prompt = $source;
    $system_prompt = $settings['system_prompt'];
    $parameters = [
      'temperature' => $settings['temperature'],
      'top_p' => $settings['top_p'],
      'max_tokens' => $settings['max_tokens'],
      'thinking_mode' => $settings['thinking_mode'],
    ];

    try {
      $plugin = $this->completionPluginManager->getPlugin($settings['plugin_id']);
      if (!$plugin->hasCapability(CompletionCapability::StructuredOutput)) {
        $this->getLogger()->error('Report title unchanged: unsupported AI plugin (@plugin).', [
          '@plugin' => $settings['plugin_id'],
        ]);
        return [
          'title' => $original_title,
          'source' => SeriesMatchTitleSource::FailedUnsupportedAiPlugin,
          'aiDurationSeconds' => NULL,
        ];
      }

      $start = microtime(TRUE);
      $output = $plugin->queryStructured(
        $prompt,
        $json_schema,
        $system_prompt,
        $parameters,
      );
      $ai_duration = microtime(TRUE) - $start;
    }
    catch (\Exception $exception) {
      $this->getLogger()->error('Report title unchanged: AI call error (@message).', [
        '@message' => $exception->getMessage(),
      ]);
      return [
        'title' => $original_title,
        'source' => SeriesMatchTitleSource::FailedAiCallError,
        'aiDurationSeconds' => NULL,
      ];
    }

    if ($output === NULL || empty($output['title']) || !is_string($output['title'])) {
      $this->getLogger()->warning('Report title unchanged: empty AI output.');
      return [
        'title' => $original_title,
        'source' => SeriesMatchTitleSource::FailedEmptyAiOutput,
        'aiDurationSeconds' => $ai_duration ?? NULL,
      ];
    }

    $title = trim($output['title']);
    if ($title === '') {
      return [
        'title' => $original_title,
        'source' => SeriesMatchTitleSource::FailedEmptyAiOutput,
        'aiDurationSeconds' => $ai_duration ?? NULL,
      ];
    }

    return [
      'title' => $title,
      'source' => SeriesMatchTitleSource::AiGenerated,
      'aiDurationSeconds' => $ai_duration ?? NULL,
    ];
  }

  /**
   * Check if two arrays have the same values, ignoring order and duplicates.
   *
   * @param array $a
   *   The first array to check.
   * @param array $b
   *   The second array to check.
   *
   * @return bool
   *   TRUE if the arrays have the same values.
   */
  protected function arraysHaveSameValues(array $a, array $b): bool {
    return !array_diff($a, $b) && !array_diff($b, $a);
  }

  /**
   * Get the logger.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger.
   */
  protected function getLogger(): LoggerInterface {
    return $this->loggerFactory->get('reliefweb_report_series_matcher');
  }

}
