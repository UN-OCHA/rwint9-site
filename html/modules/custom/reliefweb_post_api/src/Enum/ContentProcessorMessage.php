<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Enum;

/**
 * Stable exception message strings for content processors.
 */
enum ContentProcessorMessage: string {

  case MissingBundleJsonSchema = 'Missing @bundle JSON schema.';
  case InvalidProviderUuid = 'Invalid provider UUID.';
  case InvalidProvider = 'Invalid provider.';
  case BlockedProvider = 'Blocked provider.';
  case ExistingEntityWrongBundle = 'Existing entity with the UUID @uuid is not a @bundle.';
  case SkippingTerminalEntity = 'Skipping processing: existing entity with the UUID @uuid is marked as @status.';
  case DocumentNotFound = 'Document not found.';
  case DocumentCannotBeWithdrawn = 'Document cannot be withdrawn.';
  case MissingDocumentUrl = 'Missing document URL.';
  case MissingDocumentUuid = 'Missing document UUID.';
  case InvalidDocumentUuid = 'Invalid document UUID.';
  case UuidUrlMismatch = 'The UUID does not match the one generated from the URL.';
  case UnallowedSources = 'Unallowed source(s)';
  case UnallowedDocumentUrl = 'Unallowed document URL: @url';
  case UnsupportedMimetype = 'Unsupported @mimetype mimetype for @path.';
  case MissingTypeUrl = 'Missing @type URL.';
  case RawBytesNotAllowed = 'Raw bytes not allowed for @type.';
  case MissingTypeUuid = 'Missing @type UUID.';
  case UnallowedTypeUrl = 'Unallowed @type URL: @url.';
  case TypeUuidNotDerived = 'The @type UUID @uuid is not derived from the @type url and document UUID.';
  case MissingTypeChecksum = 'Missing @type checksum.';

  /**
   * Format the message, optionally replacing placeholders.
   *
   * @param array<string, string> $args
   *   Placeholder replacements keyed like '@status' or '@uuid'.
   *
   * @return string
   *   Formatted message string.
   */
  public function format(array $args = []): string {
    return $args === [] ? $this->value : strtr($this->value, $args);
  }

}
