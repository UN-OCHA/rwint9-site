<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Enum;

/**
 * Stable JSON response / exception body strings for the Post API controller.
 */
enum PostApiResponseMessage: string {

  case MissingAppname = 'Missing or invalid appname parameter.';
  case UnsupportedMethod = 'Unsupported method.';
  case InvalidEndpointResource = 'Invalid endpoint resource.';
  case InvalidEndpointUuid = 'Invalid endpoint UUID.';
  case UnknownEndpoint = 'Unknown endpoint.';
  case InvalidProvider = 'Invalid provider.';
  case InvalidApiKey = 'Invalid API key.';
  case ProviderMismatch = 'Not allowed to modify this document.';
  case NotAllowedToPost = 'Not allowed to post content.';
  case RateLimitTooSoon = 'Not enough time elapsed since last request.';
  case DailyQuotaExceeded = 'Daily quota exceeded.';
  case InvalidContentFormat = 'Invalid content format.';
  case MissingRequestBody = 'Missing request body.';
  case InvalidRequestBody = 'Invalid request body.';
  case InvalidJsonBody = 'Invalid JSON body.';
  case DocumentUuidMismatch = 'Document UUID mismatch.';
  case DocumentNotFound = 'Document not found.';
  case InvalidData = "Invalid data:\n\n@message";
  case TerminalCannotUpdate = 'Document is marked as @status (publicly unavailable) and cannot be updated.';
  case TerminalNotPubliclyAvailable = 'Document is already publicly unavailable (marked as @status).';
  case AlreadyWithdrawn = 'Document already withdrawn.';
  case Withdrawn = 'Document withdrawn.';
  case NoChanges = 'No changes.';
  case Processed = 'Document processed.';
  case Queued = 'Document queued for processing.';
  case InvalidSchemaFileName = 'Invalid schema file name.';
  case UnknownSchemaFile = 'Unknown schema file.';
  case InternalServerError = 'Internal server error.';

  /**
   * Format the message, optionally replacing placeholders.
   *
   * @param array<string, string> $args
   *   Placeholder replacements keyed like '@status' or '@message'.
   *
   * @return string
   *   Formatted message string.
   */
  public function format(array $args = []): string {
    return $args === [] ? $this->value : strtr($this->value, $args);
  }

}
