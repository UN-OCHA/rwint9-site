<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto;

use Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomePolicyAction;

/**
 * Aggregated result of applying field and global outcome policies.
 */
final readonly class SeriesMatchOutcomePolicyResult {

  /**
   * Constructs a policy evaluation result.
   *
   * @param \Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Enum\SeriesMatchOutcomePolicyAction $action
   *   Strictest policy action from all triggered rules.
   * @param list<\Drupal\reliefweb_content_analyzer\ReportSeriesMatch\Dto\SeriesMatchOutcomePolicyReason> $reasons
   *   Triggered policy reasons (code + editor message).
   */
  public function __construct(
    public SeriesMatchOutcomePolicyAction $action = SeriesMatchOutcomePolicyAction::None,
    public array $reasons = [],
  ) {}

}
