<?php

namespace Drupal\reliefweb_utility\Response;

use GuzzleHttp\Psr7\Response;

/**
 * Wrapper for a response that is supposed to return JSON encoded data.
 */
class JsonResponse {

  /**
   * Wrapped response.
   *
   * @var \Psr\Http\Message\ResponseInterface
   */
  protected $response;

  /**
   * Response status code.
   *
   * @var int
   */
  protected $statusCode = 0;

  /**
   * Construct the response from the guzzle response.
   *
   * @param ?\GuzzleHttp\Psr7\Response $response
   *   Guzzle response.
   */
  public function __construct(?Response $response) {
    $this->response = $response;
    if (!empty($response)) {
      $this->statusCode = $response->getStatusCode();
    }
  }

  /**
   * Get the response content.
   *
   * If the response's content is JSON encoded, this returns the decoded version
   * if instructed so.
   *
   * @param bool $decode
   *   TRUE to try to decode the response's content if it's JSON encoded.
   *
   * @return mixed
   *   Response content or NULL if it couldn't be decoded or the response is
   *   not sucessful.
   */
  public function getContent($decode = TRUE) {
    $content = NULL;
    if ($this->isSuccessful() && !empty($this->response->getBody())) {
      $content = (string) $this->response->getBody();
      if ($this->getContentType() === 'application/json' && $decode) {
        $content = json_decode($content, TRUE);
      }
    }
    return $content;
  }

  /**
   * Get the original response's body.
   *
   * @return \Psr\Http\Message\StreamInterface|null
   *   The origin response's body.
   */
  public function getBody() {
    return !empty($this->response) ? $this->response->getBody() : NULL;
  }

  /**
   * Get the original response's headers.
   *
   * @return array
   *   The origin response's headers.
   */
  public function getHeaders() {
    return !empty($this->response) ? $this->response->getHeaders() : NULL;
  }

  /**
   * Return the type of the response's content.
   *
   * @return string
   *   Content type as indicated in the Content-Type header or empty string if
   *   the header was not found or empty.
   */
  public function getContentType() {
    if (!empty($this->response)) {
      return $this->response->getHeaderLine('Content-Type');
    }
    return '';
  }

  /**
   * Get the status code.
   *
   * @return int
   *   Response status code.
   */
  public function getStatusCode() {
    return $this->statusCode;
  }

  /**
   * Get the reason phrase.
   *
   * @return string
   *   The reason phrase accompanying the response status code.
   */
  public function getReasonPhrase() {
    return !empty($this->response) ? $this->response->getReasonPhrase() : 'Invalid response';
  }

  /**
   * Is response invalid?
   *
   * @return bool
   *   TRUE if invalid.
   */
  public function isInvalid() {
    return $this->statusCode < 100 || $this->statusCode >= 600;
  }

  /**
   * Is response informational?
   *
   * @return bool
   *   TRUE if invalid.
   */
  public function isInformational() {
    return $this->statusCode >= 100 && $this->statusCode < 200;
  }

  /**
   * Is response successful?
   *
   * @return bool
   *   TRUE if successful.
   */
  public function isSuccessful() {
    return $this->statusCode >= 200 && $this->statusCode < 300;
  }

  /**
   * Is the response a redirection?
   *
   * @return bool
   *   TRUE if the response is a redirection.
   */
  public function isRedirection() {
    return $this->statusCode >= 300 && $this->statusCode < 400;
  }

  /**
   * Is there a client error?
   *
   * @return bool
   *   TRUE if the response is a client error.
   */
  public function isClientError() {
    return $this->statusCode >= 400 && $this->statusCode < 500;
  }

  /**
   * Was there a server side error?
   *
   * @return bool
   *   TRUE if the response is a server error.
   */
  public function isServerError() {
    return $this->statusCode >= 500 && $this->statusCode < 600;
  }

  /**
   * Is the response OK?
   *
   * @return bool
   *   TRUE if the response is OK.
   */
  public function isOk() {
    return 200 === $this->statusCode;
  }

  /**
   * Is the response a forbidden error?
   *
   * @return bool
   *   TRUE if the response is a forbidden error.
   */
  public function isForbidden() {
    return 403 === $this->statusCode;
  }

  /**
   * Is the response a not found error?
   *
   * @return bool
   *   TRUE if the response is not found error.
   */
  public function isNotFound() {
    return 404 === $this->statusCode;
  }

  /**
   * Return the original response.
   *
   * @return \GuzzleHttp\Psr7\Response
   *   Original response.
   */
  public function getOriginalResponse() {
    return $this->response;
  }

}
