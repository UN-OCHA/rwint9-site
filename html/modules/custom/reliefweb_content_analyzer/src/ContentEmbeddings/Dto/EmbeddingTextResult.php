<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto;

/**
 * Result of building embeddable text from an entity.
 */
final class EmbeddingTextResult {

  /**
   * Skip reason when no embeddable text was produced.
   */
  public const SKIP_EMPTY = 'empty';

  /**
   * Skip reason when concatenated text is below the minimum length.
   */
  public const SKIP_SHORT = 'short';

  /**
   * Constructs EmbeddingTextResult.
   *
   * @param string|null $text
   *   Concatenated text, or NULL when skipped.
   * @param string|null $hash
   *   SHA-256 of field profile + text, or NULL when skipped.
   * @param string $language
   *   Language code for storage / helper schema.
   * @param string|null $skipReason
   *   SKIP_* constant when not embeddable.
   */
  public function __construct(
    public readonly ?string $text,
    public readonly ?string $hash,
    public readonly string $language,
    public readonly ?string $skipReason = NULL,
  ) {}

  /**
   * Whether the result can be sent to the embed endpoint.
   *
   * @return bool
   *   TRUE when the result can be sent to the embed endpoint.
   */
  public function isEmbeddable(): bool {
    return $this->skipReason === NULL && $this->text !== NULL && $this->hash !== NULL;
  }

}
