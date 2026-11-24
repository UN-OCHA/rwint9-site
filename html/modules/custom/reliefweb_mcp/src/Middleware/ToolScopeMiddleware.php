<?php

declare(strict_types=1);

namespace Drupal\reliefweb_mcp\Middleware;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Mcp\Server\Transport\ReadsBoundedBody;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Check if an AppName is passed.
 */
final class ToolScopeMiddleware implements MiddlewareInterface {


  use ReadsBoundedBody;

  /**
   * Constructs a ToolScopeMiddleware.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for loading mcp_tool_config entities.
   * @param \Psr\Http\Message\ResponseFactoryInterface $responseFactory
   *   The PSR-17 response factory.
   * @param \Psr\Http\Message\StreamFactoryInterface $streamFactory
   *   The PSR-17 stream factory.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ResponseFactoryInterface $responseFactory,
    private readonly StreamFactoryInterface $streamFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    $payload = $this->readPayload($request);

    // Only intercept tool calls.
    if (($payload['method'] ?? NULL) !== 'tools/call') {
      return $handler->handle($request);
    }

    $is_batch = array_is_list($payload);
    $messages = array_values(array_filter($is_batch ? $payload : [$payload], 'is_array'));

    // Extract AppName from HTTP headers.
    $app_name = $request->getHeaderLine('app-name');
    if (empty($app_name)) {
      return $this->deny($messages, $is_batch, 400, -32003, 'APP-NAME header required');
    }

    return $handler->handle($request);
  }

  /**
   * Decodes the JSON-RPC payload without consuming it for the SDK.
   *
   * The SDK reads the body stream once and rewinds it only when it is
   * seekable, so prefer the parsed body the PSR-7 bridge already decoded.
   * The bridge decodes the body only for a JSON Content-Type. For any other
   * type the parsed body comes from PHP's form parsing, while the SDK still
   * decodes the raw body, so read the stream to decide on the same bytes.
   *
   * @return array<mixed>
   *   A single message, a batch of messages, or an empty array when the body
   *   is not JSON or is over the SDK's size limit.
   */
  private function readPayload(ServerRequestInterface $request): array {
    if ($this->hasJsonContentType($request)) {
      $payload = $request->getParsedBody();
      if (is_array($payload) && $payload !== []) {
        return $payload;
      }
    }

    $body = $request->getBody();
    // A body over the limit passes through for the SDK to refuse with 413.
    $contents = $this->readBoundedBody($body, StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES);
    if ($body->isSeekable()) {
      $body->rewind();
    }
    $payload = $contents === NULL ? NULL : json_decode($contents, TRUE);
    return is_array($payload) ? $payload : [];
  }

  /**
   * Checks whether the request declares a JSON body.
   */
  private function hasJsonContentType(ServerRequestInterface $request): bool {
    $media_type = explode(';', $request->getHeaderLine('Content-Type'), 2)[0];
    return strtolower(trim($media_type)) === 'application/json';
  }

  /**
   * Builds the denial response.
   *
   * @param list<array<mixed>> $messages
   *   The JSON-RPC messages of the request.
   * @param bool $is_batch
   *   Whether the request was a batch, which gets one error per message.
   * @param int $status
   *   The HTTP status, 401 or 403.
   * @param int $code
   *   The JSON-RPC error code.
   * @param string $message
   *   The JSON-RPC error message.
   */
  private function deny(array $messages, bool $is_batch, int $status, int $code, string $message): ResponseInterface {
    $errors = array_map(static fn (array $request_message): array => [
      'jsonrpc' => '2.0',
      'error' => ['code' => $code, 'message' => $message],
      'id' => $request_message['id'] ?? NULL,
    ], $messages);
    $body = json_encode($is_batch ? $errors : $errors[0], JSON_THROW_ON_ERROR);

    return $this->responseFactory->createResponse($status)
      ->withHeader('Content-Type', 'application/json')
      ->withBody($this->streamFactory->createStream($body));
  }

}
