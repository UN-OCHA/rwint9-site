<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Drupal\reliefweb_post_api\Entity\ProviderInterface;
use Opis\JsonSchema\Validator;
use Psr\Log\LoggerInterface;

/**
 * Interface for the reliefweb_api Content Processor plugins.
 */
interface ContentProcessorPluginInterface {

  /**
   * Get the plugin label.
   *
   * @return string
   *   The plugin label.
   */
  public function getPluginLabel(): MarkupInterface|string;

  /**
   * Get the entity type handled by this plugin.
   *
   * @return string
   *   Entity type.
   */
  public function getEntityType(): string;

  /**
   * Get the entity bundle handled by this plugin.
   *
   * @return string
   *   Entity bundle.
   */
  public function getEntityBundle(): string;

  /**
   * Get the plugin logger.
   *
   * @return Psr\Log\LoggerInterface
   *   Logger.
   */
  public function getLogger(): LoggerInterface;

  /**
   * Get the schema validator.
   *
   * @return \Opis\JsonSchema\Validator
   *   The schema validator.
   */
  public function getSchemaValidator(): Validator;

  /**
   * Get the JSON schema for the entity bundle handled by this plugin.
   *
   * @return string
   *   The JSON schema.
   *
   * @throws \Drupal\ocha_ai_chat\Plugin\ContentProcessorException
   *   An exception if the schema could not be loaded.
   */
  public function getJsonSchema(): string;

  /**
   * Get the POST API provider for the given ID.
   *
   * @return \Drupal\reliefweb_post_api\Entity\ProviderInterface
   *   Provider.
   */
  public function getProvider(string $id): ProviderInterface;

  /**
   * Validate and generate an entity from the given POST API data.
   *
   * @param array $data
   *   POST API data.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The created entity or NULL if it could not be created.
   */
  public function process(array $data): ?ContentEntityInterface;

  /**
   * Validate POST API data.
   *
   * @param array $data
   *   POST API data.
   *
   * @throws \Drupal\reliefweb_post_api\Plugin\ContentProcessorException
   *   Exception if the data is not valid.
   */
  public function validate(array $data): void;

  /**
   * Validate POST API data against the JSON schema for the managed bundle.
   *
   * @param array $data
   *   POST API data.
   *
   * @throws \Drupal\reliefweb_post_api\Plugin\ContentProcessorException
   *   An exception with the schema errors.
   */
  public function validateSchema(array $data): void;

  /**
   * Validate the POST API data sources against the provider allowed sources.
   *
   * @param array $data
   *   POST API data.
   *
   * @throws \Drupal\reliefweb_post_api\Plugin\ContentProcessorException
   *   An exception if the sources are not allowed.
   */
  public function validateSources(array $data): void;

  /**
   * Validate the POST API data URLs against the provider URL pattern.
   *
   * @param array $data
   *   POST API data.
   *
   * @throws \Drupal\reliefweb_post_api\Plugin\ContentProcessorException
   *   An exception if some URLs don't follow the allowed pattern.
   */
  public function validateUrls(array $data): void;

  /**
   * Validate a URL against a pattern.
   *
   * @param string $url
   *   URL to check.
   * @param string $pattern
   *   Pattern the URL must match.
   *
   * @return bool
   *   TRUE if the URL matches the pattern.
   */
  public function validateUrl(string $url, string $pattern): bool;

  /**
   * Return a list of terms that actually exist.
   *
   * @param string $vocabulary
   *   Taxonomy vocabulary.
   * @param array $terms
   *   List of term IDs from the POST data.
   *
   * @return array
   *   Sanitized list of term IDs.
   */
  public function sanitizeTerms(string $vocabulary, array $terms): array;

  /**
   * Return a trimmed string without control characters etc.
   *
   * @param string $string
   *   String to sanitized.
   *
   * @return string
   *   Sanitized string.
   *
   * @see Drupal\reliefweb_utility\Helpers\TextHelper::cleanText()
   */
  public function sanitizeString(string $string): string;

  /**
   * Sanitize a plain, markdown or html text, converting it to markdown.
   *
   * @param string $text
   *   Text to sanitized.
   * @param int $max_heading_level
   *   Maximum heading level, defaults to 2.
   *
   * @return string
   *   Sanitized text.
   *
   * @see Drupal\reliefweb_utility\Helpers\TextHelper::cleanText()
   * @see Drupal\reliefweb_utility\Helpers\HtmlSanitizer::sanitizeFromMarkdown()
   * @see Drupal\reliefweb_utility\Helpers\HtmlConverter::convert()
   */
  public function sanitizeText(string $text, int $max_heading_level = 2): string;

  /**
   * Sanitize a date, chinging it to the UTC timezone.
   *
   * @param string $date
   *   Date to sanitized.
   * @param bool $strip_time
   *   If TRUE, only preserve the date part (year month day).
   *
   * @return string
   *   Sanitized date (changed to UTC timezone).
   */
  public function sanitizeDate(string $date, bool $strip_time = TRUE): string;

  /**
   * Sanitize a URL.
   *
   * @param string $url
   *   URL to sanitized.
   * @param string $pattern
   *   Pattern the URL must match.
   *
   * @return string
   *   Sanitized URL.
   */
  public function sanitizeUrl(string $url, string $pattern): string;

  /**
   * Set the value of an entity's field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to which set the field.
   * @param string $field_name
   *   Field name.
   * @param mixed $value
   *   The value for the field. It can be a single value or a list of values.
   */
  public function setField(ContentEntityInterface $entity, string $field_name, mixed $value): void;

  /**
   * Set the value of an entity's string field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to which set the field.
   * @param string $field_name
   *   Field name.
   * @param string $string
   *   The text for the field.
   */
  public function setStringField(ContentEntityInterface $entity, string $field_name, string $string): void;

  /**
   * Set the value of an entity's text field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to which set the field.
   * @param string $field_name
   *   Field name.
   * @param string $text
   *   The text for the field.
   * @param int $max_heading_level
   *   Maximum heading level, defaults to 2.
   * @param string $format
   *   Optional text format.
   */
  public function setTextField(ContentEntityInterface $entity, string $field_name, string $text, int $max_heading_level = 2, string $format = ''): void;

  /**
   * Set the value of an entity's date field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to which set the field.
   * @param string $field_name
   *   Field name.
   * @param string $date
   *   The date.
   * @param bool $strip_time
   *   If TRUE, only preserve the date part (year month day).
   */
  public function setDateField(ContentEntityInterface $entity, string $field_name, string $date, bool $strip_time = TRUE): void;

  /**
   * Set the value of an entity's taxonomy reference field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to which set the field.
   * @param string $field_name
   *   Field name.
   * @param string $vocabulary
   *   Taxonomy vocabulary.
   * @param array $terms
   *   List of term IDs from the POST data.
   */
  public function setTermField(ContentEntityInterface $entity, string $field_name, string $vocabulary, array $terms): void;

  /**
   * Set the value of an entity's URL field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to which set the field.
   * @param string $field_name
   *   Field name.
   * @param string $url
   *   URL to sanitized.
   * @param string $pattern
   *   Pattern the URL must match.
   */
  public function setUrlField(ContentEntityInterface $entity, string $field_name, string $url, string $pattern): void;

  /**
   * Set the value of an entity's ReliefWeb file field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to which set the field.
   * @param string $field_name
   *   Field name.
   * @param array $files
   *   List of file data from the POST API with URL, description, language and
   *   checksum.
   */
  public function setReliefWebFileField(ContentEntityInterface $entity, string $field_name, array $files): void;

  /**
   * Set the value of an entity's ReliefWeb file field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to which set the field.
   * @param string $field_name
   *   Field name.
   * @param array $image
   *   Image data from the POST API with URL, caption, copyright and
   *   checksum.
   */
  public function setImageField(ContentEntityInterface $entity, string $field_name, array $image): void;

  /**
   * Create an image media entity.
   *
   * @param string $bundle
   *   Media bundle.
   * @param string $uuid
   *   Media UUID.
   * @param string $url
   *   URL of the image file.
   * @param string $checksum
   *   Checksum of the image.
   * @param string $mimetype
   *   The image mimetype.
   * @param string $max_size
   *   Maximum file size (ex: 2MB). Defaults to the environment upload max size.
   * @param string $alt
   *   Alternative text for the image.
   *
   * @return \Drupal\media\MediaInterface|null
   *   The image media.
   *
   * @throws \Exception
   *   Exception if the image could not be retrieved or the media created.
   */
  public function createImageMedia(string $bundle, string $uuid, string $url, string $checksum, string $mimetype, string $max_size, string $alt): ?MediaInterface;

  /**
   * Create a ReliefWeb file field item from a remote file.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   Field item definition.
   * @param string $uuid
   *   File UUID.
   * @param string $url
   *   Remote file URL.
   * @param string $checksum
   *   Checksum of the file.
   * @param string $mimetype
   *   Accepted mimetype.
   * @param string $max_size
   *   Maximum file size (ex: 2MB). Defaults to the environment upload max size.
   *
   * @return \Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile|null
   *   ReliefWeb file field item.
   */
  public function createReliefWebFileFieldItem(DataDefinitionInterface $definition, string $uuid, string $url, string $checksum, string $mimetype, string $max_size = ''): ?ReliefWebFile;

  /**
   * Create and validate a file.
   *
   * @param string $uuid
   *   The file UUID.
   * @param string $uri
   *   The destination URI where the file will be saved.
   * @param string $name
   *   The file name.
   * @param string $mimetype
   *   The file mimetype.
   * @param string $url
   *   The remote file URL.
   * @param string $checksum
   *   The file checksum.
   * @param string $max_size
   *   Maximum file size (ex: 2MB). Defaults to the environment upload max size.
   * @param array $validators
   *   The file validators.
   *
   * @return \Drupal\file\FileInterface|null
   *   The created file entity or NULL if there was no retrievable content.
   *
   * @throws \Exception
   *   An exception of the file could not be saved.
   */
  public function createFile(string $uuid, string $uri, string $name, string $mimetype, string $url, string $checksum, string $max_size, array $validators = []): ?FileInterface;

  /**
   * Get the content of remote file.
   *
   * @param string $url
   *   Remote file URL.
   * @param string $checksum
   *   The checksum of the file.
   * @param string $mimetype
   *   Accepted mimetype.
   * @param string $max_size
   *   Maximum file size (ex: 2MB). Defaults to the environment upload max size.
   *
   * @return string
   *   Downloaded content.
   */
  public function getRemoteFileContent(string $url, string $checksum, string $mimetype, string $max_size = ''): string;

  /**
   * Validate a file against a list of validators.
   *
   * @param \Drupal\file\Entity\File $file
   *   File to validate.
   * @param array $validators
   *   Associative array of upload validators with their ID as key and
   *   expected parameters as values.
   *
   * @return array
   *   List of validation error messages if any.
   */
  public function validateFile(File $file, array $validators = []): array;

  /**
   * Generate a UUID for a string (ex: URL).
   *
   * @param string $string
   *   String for which to generate a UUID.
   * @param string|null $namespace
   *   Optional namespace. Defaults to `Uuid::NAMESPACE_URL`.
   *
   * @return string
   *   UUID.
   */
  public function generateUuid(string $string, ?string $namespace = NULL): string;

  /**
   * Get the mime of the file.
   *
   * @param string $uri
   *   File uri.
   * @param array $allowed_mimetypes
   *   List of allowed mimetypes. An empty list means any mime type is accepted.
   *
   * @return string
   *   File mime type.
   *
   * @throws \Drupal\reliefweb_post_api\Plugin\ContentProcessorException
   *   Exception if the mimetype could be guessed or is not allowed.
   */
  public function guessFileMimeType(string $uri, array $allowed_mimetypes = []): string;

  /**
   * Get the default language code.
   *
   * @return string
   *   The default language ID.
   */
  public function getDefaultLangcode(): string;

}
