<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_api\Unit\Services;

use Drupal\reliefweb_api\Services\ReliefWebElasticsearchClient;

/**
 * Elasticsearch client that records sleep durations for tests.
 */
class TestableReliefWebElasticsearchClient extends ReliefWebElasticsearchClient {

  /**
   * Recorded sleep durations in seconds.
   *
   * @var list<int>
   */
  protected array $sleepCalls = [];

  /**
   * {@inheritdoc}
   */
  protected function sleep(int $seconds): void {
    $this->sleepCalls[] = $seconds;
  }

  /**
   * Get recorded sleep durations.
   *
   * @return list<int>
   *   Sleep durations in seconds.
   */
  public function getSleepCalls(): array {
    return $this->sleepCalls;
  }

}
