<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto;

/**
 * Metrics from a series match run used for confidence scoring and display.
 */
final readonly class SeriesMatchEvidence {

  /**
   * Constructs a series match evidence value object.
   *
   * @param int[] $candidateIds
   *   Final candidate node IDs in pattern score order (descending).
   * @param array<int, int> $candidatePatternScores
   *   Merged pattern score per candidate node ID.
   * @param int $titleMatchCount
   *   Candidates matched by title patterns.
   * @param int $urlMatchCount
   *   Candidates matched by URL patterns.
   * @param int $bothSignalsCount
   *   Candidates matched by both title and URL patterns.
   * @param int $mergedCount
   *   Candidate count before applying the configured limit.
   * @param int $mergedAfterLimitCount
   *   Candidate count after applying the configured limit.
   * @param int $clusterCount
   *   Number of similarity clusters computed.
   * @param int[] $clusterSizes
   *   Cluster sizes ordered by descending size.
   * @param int $bestClusterSize
   *   Size of the selected best cluster.
   * @param float $bestClusterShare
   *   Best-cluster share over merged candidates after limit (0–1).
   * @param float $clusterScore
   *   Composite score of the selected best cluster.
   * @param float $clusterScoreSize
   *   Size component of the winning cluster score.
   * @param float $clusterScorePattern
   *   Pattern component of the winning cluster score.
   * @param float $clusterScoreTagging
   *   Tagging-consistency component of the winning cluster score.
   * @param int $lookbackMonths
   *   For successful matches, whole months (ceiling) from the oldest best-
   *   cluster original publication date to the series anchor, for display and
   *   tracking. For incomplete runs, the configured candidate search window.
   * @param float|null $seriesBodyRatio
   *   Fraction (0–1) of winning-cluster candidates with non-empty body text,
   *   or NULL when not computed.
   */
  public function __construct(
    public array $candidateIds = [],
    public array $candidatePatternScores = [],
    public int $titleMatchCount = 0,
    public int $urlMatchCount = 0,
    public int $bothSignalsCount = 0,
    public int $mergedCount = 0,
    public int $mergedAfterLimitCount = 0,
    public int $clusterCount = 0,
    public array $clusterSizes = [],
    public int $bestClusterSize = 0,
    public float $bestClusterShare = 0.0,
    public float $clusterScore = 0.0,
    public float $clusterScoreSize = 0.0,
    public float $clusterScorePattern = 0.0,
    public float $clusterScoreTagging = 0.0,
    public int $lookbackMonths = 0,
    public ?float $seriesBodyRatio = NULL,
  ) {}

  /**
   * Returns a copy of this object with the given properties overridden.
   *
   * @param array<string, mixed> $props
   *   An associative array of property names and their new values.
   *
   * @return static
   *   A new instance with the specified properties replaced.
   */
  public function with(array $props): static {
    return clone($this, $props);
  }

  /**
   * Returns cluster count and lookback months for display summaries.
   *
   * @return array{count: int, months: int}|null
   *   Summary parts when both values are available, otherwise NULL.
   */
  public function similarReportsSummary(): ?array {
    if ($this->bestClusterSize <= 0 || $this->lookbackMonths <= 0) {
      return NULL;
    }

    return [
      'count' => $this->bestClusterSize,
      'months' => $this->lookbackMonths,
    ];
  }

}
