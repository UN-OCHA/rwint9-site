<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_import\Unit\Stub;

use Drupal\reliefweb_import\Command\ReliefwebImportCommand;

/**
 * Stub class for testing.
 */
class ReliefwebImportCommandStub extends ReliefwebImportCommand {

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

}
