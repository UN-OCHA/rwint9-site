<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_import\Unit\Stub;

use Drupal\reliefweb_import\Command\ReliefwebImportCommand;

/**
 * Stub class for testing.
 */
class ReliefwebImportCommandStub extends ReliefwebImportCommand {

  /**
   * Set http client.
   */
  public function setHttpClient($http_client) {
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public function validateBody($data) {
    return parent::validateBody($data);
  }

  /**
   * {@inheritdoc}
   */
  public function sanitizeText($field, $text, $format = 'plain_text') {
    return parent::sanitizeText($field, $text, $format);
  }

  /**
   * {@inheritdoc}
   */
  public function validateBaseUrl($base_url) {
    return parent::validateBaseUrl($base_url);
  }

  /**
   * {@inheritdoc}
   */
  public function validateLink($link, $base_url) {
    return parent::validateLink($link, $base_url);
  }

  /**
   * {@inheritdoc}
   */
  public function validateTitle($title) {
    return parent::validateTitle($title);
  }

  /**
   * {@inheritdoc}
   */
  public function validateSource($source, $source_id) {
    return parent::validateSource($source, $source_id);
  }

  /**
   * {@inheritdoc}
   */
  public function validateUser($uid) {
    return parent::validateUser($uid);
  }

  /**
   * {@inheritdoc}
   */
  public function fetchXml($url) {
    return parent::fetchXml($url);
  }

}
