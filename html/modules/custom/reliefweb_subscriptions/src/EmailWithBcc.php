<?php

namespace Drupal\reliefweb_subscriptions;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;

/**
 * Extend the Email class to avoid removing the Bcc header.
 */
class EmailWithBcc extends Email {

  /**
   * {@inheritdoc}
   */
  public function getPreparedHeaders() : Headers {
    // Add the BCC header since it's removed by the grandparent class.
    // @see \Symfony\Component\Mime\Message::getPreparedHeaders()
    $headers = parent::getPreparedHeaders();
    if (!$headers->has('Bcc') && $this->getHeaders()->has('Bcc')) {
      $headers->add($this->getHeaders()->get('Bcc'));
    }
    return $headers;
  }

}
