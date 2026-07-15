<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto;

/**
 * Runtime context for global outcome policy evaluation.
 */
final readonly class SeriesMatchOutcomePolicyContext {

  /**
   * Constructs outcome policy context.
   *
   * @param bool $entityHasBody
   *   Whether the current report has non-empty body text.
   * @param float|null $seriesBodyRatio
   *   Fraction (0–1) of winning-cluster candidates with non-empty body, or
   *   NULL when unknown.
   */
  public function __construct(
    public bool $entityHasBody = TRUE,
    public ?float $seriesBodyRatio = NULL,
  ) {}

}
