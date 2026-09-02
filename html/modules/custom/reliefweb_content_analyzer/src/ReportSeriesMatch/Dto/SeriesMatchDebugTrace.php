<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto;

/**
 * Optional diagnostics from a series match run for form evaluation.
 */
final readonly class SeriesMatchDebugTrace {

  /**
   * Constructs a series match debug trace value object.
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
   * @param float $similarityThreshold
   *   Pairwise similarity threshold used to build cluster edges.
   * @param float $pairwiseTaggingWeight
   *   Tagging contribution in pairwise similarity.
   * @param float $pairwiseTitleWeight
   *   Title contribution in pairwise similarity.
   * @param float $clusterWeightSize
   *   Cluster-size weight in final cluster scoring.
   * @param float $clusterWeightPattern
   *   Pattern-score weight in final cluster scoring.
   * @param float $clusterWeightTagging
   *   Tagging-consistency weight in final cluster scoring.
   * @param int $minimumSeriesCount
   *   Minimum reports required to consider a valid series.
   */
  public function __construct(
    public int $entityId = 0,
    public int $lookbackMonths = 0,
    public int $anchor = 0,
    public int $windowStart = 0,
    public int $candidateLimit = 0,
    public string $originalTitle = '',
    public string $originUrl = '',
    public int $titlePatternCount = 0,
    public int $urlPatternCount = 0,
    public array $sourceTermIds = [],
    public float $similarityThreshold = 0.0,
    public float $pairwiseTaggingWeight = 0.0,
    public float $pairwiseTitleWeight = 0.0,
    public float $clusterWeightSize = 0.0,
    public float $clusterWeightPattern = 0.0,
    public float $clusterWeightTagging = 0.0,
    public int $minimumSeriesCount = 0,
  ) {}

}
