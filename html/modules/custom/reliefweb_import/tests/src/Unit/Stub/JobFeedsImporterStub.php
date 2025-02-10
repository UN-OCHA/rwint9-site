<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit\Stub;

use Drupal\reliefweb_import\Service\JobFeedsImporter;
use GuzzleHttp\ClientInterface;

/**
 * Stub class for testing.
 */
class JobFeedsImporterStub extends JobFeedsImporter {

  /**
   * Set http client.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   HTTP client.
   */
  public function setHttpClient(ClientInterface $http_client): void {
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  // @codingStandardsIgnoreLine Generic.CodeAnalysis.UselessOverridingMethod
  public function validateBody(string $data): string {
    return parent::validateBody($data);
  }

  /**
   * {@inheritdoc}
   */
  // @codingStandardsIgnoreLine Generic.CodeAnalysis.UselessOverridingMethod
  public function sanitizeText(string $field, string $text, int $max_heading_level = 2): string {
    return parent::sanitizeText($field, $text, $max_heading_level);
  }

  /**
   * {@inheritdoc}
   */
  // @codingStandardsIgnoreLine Generic.CodeAnalysis.UselessOverridingMethod
  public function validateBaseUrl(string $base_url): void {
    parent::validateBaseUrl($base_url);
  }

  /**
   * {@inheritdoc}
   */
  // @codingStandardsIgnoreLine Generic.CodeAnalysis.UselessOverridingMethod
  public function validateLink(string $link, string $base_url): void {
    parent::validateLink($link, $base_url);
  }

  /**
   * {@inheritdoc}
   */
  // @codingStandardsIgnoreLine Generic.CodeAnalysis.UselessOverridingMethod
  public function validateTitle(string $title): string {
    return parent::validateTitle($title);
  }

  /**
   * {@inheritdoc}
   */
  // @codingStandardsIgnoreLine Generic.CodeAnalysis.UselessOverridingMethod
  public function validateSource(string $source, int $source_id): int {
    return parent::validateSource($source, $source_id);
  }

  /**
   * {@inheritdoc}
   */
  // @codingStandardsIgnoreLine Generic.CodeAnalysis.UselessOverridingMethod
  public function validateUser(mixed $uid): void {
    parent::validateUser($uid);
  }

  /**
   * {@inheritdoc}
   */
  // @codingStandardsIgnoreLine Generic.CodeAnalysis.UselessOverridingMethod
  public function fetchXml(string $url): string {
    return parent::fetchXml($url);
  }

  /**
   * {@inheritdoc}
   */
  // @codingStandardsIgnoreLine Generic.CodeAnalysis.UselessOverridingMethod
  public function validateCity(string $data): string {
    return parent::validateCity($data);
  }

  /**
   * {@inheritdoc}
   */
  // @codingStandardsIgnoreLine Generic.CodeAnalysis.UselessOverridingMethod
  public function validateJobClosingDate(string $data): string {
    return parent::validateJobClosingDate($data);
  }

  /**
   * {@inheritdoc}
   */
  // @codingStandardsIgnoreLine Generic.CodeAnalysis.UselessOverridingMethod
  public function validateHowToApply(string $data): string {
    return parent::validateHowToApply($data);
  }

}
