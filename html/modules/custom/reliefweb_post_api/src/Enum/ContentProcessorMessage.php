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
  case CannotClearMandatoryField = 'Cannot clear mandatory field: @field.';
  case SchemaInvalidType = 'Invalid type: expected @expected, got @actual.';
  case SchemaMissingRequired = 'Missing required properties: @missing.';
  case SchemaInvalidUrl = 'Must be a valid URL.';
  case SchemaInvalidUuid = 'Must be a valid UUID.';
  case SchemaInvalidFileMapKey = 'Attachment map keys must be UUIDs.';
  case SchemaInvalidDateTime = 'Must be an ISO 8601 date-time (e.g. 2024-06-06T01:00:00+00:00).';
  case SchemaInvalidEmail = 'Must be a valid email address.';
  case SchemaInvalidFormat = "Must match the '@format' format.";
  case SchemaInvalidEnum = 'Must be one of: @values.';
  case SchemaUnknownProperties = 'Unknown properties not allowed: @properties.';
  case SchemaMinItems = 'Array must have at least @min item(s), @count found.';
  case SchemaMaxItems = 'Array must have at most @max item(s), @count found.';
  case SchemaMinLength = 'String must be at least @min character(s), @length found.';
  case SchemaMaxLength = 'String must be at most @max character(s), @length found.';
  case SchemaMinimum = 'Number must be greater than or equal to @min.';
  case SchemaUniqueItems = 'Array must have unique items.';
  case SchemaMaxProperties = 'Object must have at most @max properties, @count found.';
  case CountryMandatoryForOnSite = 'The country field is mandatory.';
  case CountryNotAllowed = 'The country field is not allowed.';
  case FeeInformationMandatory = 'The fee information field is mandatory.';
  case FeeInformationNotAllowed = 'The fee information field is not allowed.';
  case UnallowedSources = 'Unallowed source(s)';
  case UnallowedDocumentUrl = 'Unallowed document URL: @url';
  case UnsupportedMimetype = 'Unsupported @mimetype mimetype for @path.';
  case MissingTypeUrl = 'Missing @type URL.';
  case MissingTypeDownloadUrl = 'Missing @type download URL.';
  case RawBytesNotAllowed = 'Raw bytes not allowed for @type.';
  case MissingTypeUuid = 'Missing @type UUID.';
  case FileUuidKeyMismatch = 'The file UUID @uuid does not match the map key @key.';
  case UnallowedTypeUrl = 'Unallowed @type URL: @url.';
  case TypeUuidNotDerived = 'The @type UUID @uuid is not derived from the @type url and document UUID.';
  case MissingTypeChecksum = 'Missing @type checksum.';
  case UnknownFileUuid = 'Unknown file UUID @uuid; provide url to create a new attachment.';
  case TooManyFiles = 'Too many file attachments (maximum @max).';

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
