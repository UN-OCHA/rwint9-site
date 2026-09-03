<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Services;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingTextResult;
use Drupal\reliefweb_content_analyzer\Helpers\PlainTextNormalizer;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;

/**
 * Builds normalized embeddable text from configured entity fields.
 */
final class EmbeddingTextBuilder {

  /**
   * Field separator when concatenating multiple fields.
   */
  public const FIELD_SEPARATOR = "\n\n";

  /**
   * Build embeddable text for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   * @param string[] $fields
   *   Field machine names in order (title, body, field_file, …).
   * @param int $min_text_length
   *   Minimum Unicode length.
   *
   * @return \Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingTextResult
   *   Result with text/hash or a skip reason.
   */
  public function build(EntityInterface $entity, array $fields, int $min_text_length): EmbeddingTextResult {
    $language = $this->resolveLanguage($entity);
    $parts = [];

    foreach ($fields as $field) {
      $part = match ($field) {
        'title' => $this->extractTitle($entity),
        'body' => $this->extractBody($entity),
        'field_file' => $this->extractFiles($entity),
        default => '',
      };
      if ($part !== '') {
        $parts[] = $part;
      }
    }

    $text = implode(self::FIELD_SEPARATOR, $parts);
    if (trim($text) === '') {
      return new EmbeddingTextResult(NULL, NULL, $language, EmbeddingTextResult::SKIP_EMPTY);
    }

    if (mb_strlen($text, 'UTF-8') < $min_text_length) {
      return new EmbeddingTextResult(NULL, NULL, $language, EmbeddingTextResult::SKIP_SHORT);
    }

    return new EmbeddingTextResult(
      text: $text,
      hash: $this->hash($fields, $text),
      language: $language,
    );
  }

  /**
   * SHA-256 of field profile + text (profile changes bust skip=hash).
   *
   * @param string[] $fields
   *   Field list.
   * @param string $text
   *   Concatenated text.
   *
   * @return string
   *   Hex hash.
   */
  public function hash(array $fields, string $text): string {
    return hash('sha256', implode(',', $fields) . "\0" . $text);
  }

  /**
   * Validate field names against allowed set and entity definitions.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string $bundle
   *   Bundle.
   * @param string[] $fields
   *   Requested fields.
   * @param string[] $allowed
   *   Allowed field names for this command/UI.
   * @param callable(string, string, string): bool $has_field
   *   Callback ($entity_type_id, $bundle, $field_name) => whether the field
   *   exists on the entity type/bundle.
   *
   * @return string[]
   *   Invalid field names (empty when all valid).
   */
  public function invalidFields(
    string $entity_type_id,
    string $bundle,
    array $fields,
    array $allowed,
    callable $has_field,
  ): array {
    $invalid = [];
    foreach ($fields as $field) {
      if (!in_array($field, $allowed, TRUE)) {
        $invalid[] = $field;
        continue;
      }
      if ($field === 'title') {
        // Base field / label — always allowed when listed.
        continue;
      }
      if (!$has_field($entity_type_id, $bundle, $field)) {
        $invalid[] = $field;
      }
    }
    return $invalid;
  }

  /**
   * Extract title / label.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return string
   *   Trimmed label, or empty string.
   */
  private function extractTitle(EntityInterface $entity): string {
    return trim((string) $entity->label());
  }

  /**
   * Extract and normalize body.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return string
   *   Normalized body text, or empty string when missing.
   */
  private function extractBody(EntityInterface $entity): string {
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField('body') || $entity->get('body')->isEmpty()) {
      return '';
    }
    $item = $entity->get('body')->first();
    if ($item === NULL) {
      return '';
    }
    $property = $item->get('value');
    $raw = $property !== NULL ? (string) $property->getValue() : '';
    if (trim(strip_tags($raw)) === '') {
      return '';
    }
    return PlainTextNormalizer::normalize($raw);
  }

  /**
   * Extract text from field_file items; ignore failures.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return string
   *   Concatenated extracted file text, or empty string.
   */
  private function extractFiles(EntityInterface $entity): string {
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField('field_file') || $entity->get('field_file')->isEmpty()) {
      return '';
    }

    $parts = [];
    $field = $entity->get('field_file');
    $count = $field->count();
    for ($delta = 0; $delta < $count; $delta++) {
      $item = $field->get($delta);
      if (!$item instanceof ReliefWebFile) {
        continue;
      }
      try {
        $text = trim($item->extractText());
      }
      catch (\Throwable) {
        continue;
      }
      if ($text === '') {
        continue;
      }
      $parts[] = PlainTextNormalizer::normalize($text);
    }

    return implode(self::FIELD_SEPARATOR, array_filter($parts, static fn(string $p): bool => $p !== ''));
  }

  /**
   * Language code for storage (helper embed ignores it).
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return string
   *   ISO language code, or "en".
   */
  private function resolveLanguage(EntityInterface $entity): string {
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField('field_language') || $entity->get('field_language')->isEmpty()) {
      return 'en';
    }
    foreach ($entity->get('field_language')->referencedEntities() as $language) {
      if (!$language->hasField('field_language_code') || $language->get('field_language_code')->isEmpty()) {
        continue;
      }
      $code = strtolower(trim((string) $language->get('field_language_code')->value));
      if ($code !== '' && $code !== 'ot') {
        return $code;
      }
    }
    return 'en';
  }

}
