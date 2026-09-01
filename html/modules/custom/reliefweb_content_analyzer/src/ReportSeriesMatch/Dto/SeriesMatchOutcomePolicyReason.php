<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto;

/**
 * One triggered outcome policy with machine code and editor-facing message.
 */
final readonly class SeriesMatchOutcomePolicyReason {

  /**
   * Constructs a policy reason.
   *
   * @param string $code
   *   Machine-readable reason code for storage/debug.
   * @param string $message
   *   Short editor-facing explanation.
   */
  public function __construct(
    public string $code,
    public string $message,
  ) {}

}
