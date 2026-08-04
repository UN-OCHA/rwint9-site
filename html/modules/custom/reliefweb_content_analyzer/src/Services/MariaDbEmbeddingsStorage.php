<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Services;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingNearestHit;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingNearestQuery;

/**
 * MariaDB VECTOR implementation of embeddings storage.
 *
 * The VECTOR column is not declared in hook_schema() because Drupal Schema API
 * cannot model it and non-MariaDB environments would break. Use ensureReady().
 */
final class MariaDbEmbeddingsStorage implements EmbeddingsStorageInterface {

  /**
   * Table name.
   */
  public const TABLE = 'reliefweb_content_analyzer_embeddings';

  /**
   * Constructs MariaDbEmbeddingsStorage.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   */
  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    try {
      $version = (string) $this->database->query('SELECT VERSION()')->fetchField();
    }
    catch (\Throwable) {
      return FALSE;
    }

    if (!str_contains(strtolower($version), 'mariadb')) {
      return FALSE;
    }

    if (!preg_match('/(\d+)\.(\d+)/', $version, $matches)) {
      return FALSE;
    }

    $major = (int) $matches[1];
    $minor = (int) $matches[2];
    return $major > 11 || ($major === 11 && $minor >= 8);
  }

  /**
   * Whether the embeddings table exists.
   *
   * @return bool
   *   TRUE when the table exists.
   */
  private function tableExists(): bool {
    return $this->database->schema()->tableExists(self::TABLE);
  }

  /**
   * {@inheritdoc}
   */
  public function ensureReady(int $dimensions = self::DEFAULT_DIMENSIONS): void {
    if ($this->tableExists()) {
      return;
    }

    if (!$this->isAvailable()) {
      throw new \RuntimeException('Embeddings storage is not available (MariaDB 11.8+ VECTOR required to create ' . self::TABLE . ').');
    }

    $dimensions = max(1, $dimensions);
    $table = self::TABLE;
    $this->database->query("
      CREATE TABLE IF NOT EXISTS {{$table}} (
        entity_type_id VARCHAR(32) NOT NULL,
        entity_id INT UNSIGNED NOT NULL,
        bundle VARCHAR(32) NOT NULL,
        embedding VECTOR({$dimensions}) NOT NULL,
        text_hash CHAR(64) NOT NULL,
        language VARCHAR(12) NOT NULL,
        created INT UNSIGNED NOT NULL,
        PRIMARY KEY (entity_type_id, entity_id),
        VECTOR INDEX embedding_idx (embedding) M=8 DISTANCE=cosine
      )
    ");
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $entity_type_id, int $entity_id): void {
    if (!$this->tableExists()) {
      return;
    }

    $this->database->delete(self::TABLE)
      ->condition('entity_type_id', $entity_type_id)
      ->condition('entity_id', $entity_id)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function loadHashes(string $entity_type_id, array $entity_ids): array {
    $entity_ids = array_values(array_unique(array_filter(array_map('intval', $entity_ids))));
    if ($entity_ids === [] || !$this->tableExists()) {
      return [];
    }

    $query = $this->database->select(self::TABLE, 'e');
    $query->fields('e', ['entity_id', 'text_hash']);
    $query->condition('e.entity_type_id', $entity_type_id);
    $query->condition('e.entity_id', $entity_ids, 'IN');

    $hashes = [];
    foreach ($query->execute() as $row) {
      $hashes[(int) $row->entity_id] = (string) $row->text_hash;
    }
    return $hashes;
  }

  /**
   * {@inheritdoc}
   */
  public function existingIds(string $entity_type_id, array $entity_ids): array {
    return array_fill_keys(array_keys($this->loadHashes($entity_type_id, $entity_ids)), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function loadVector(string $entity_type_id, int $entity_id): ?array {
    if (!$this->tableExists()) {
      return NULL;
    }

    $table = self::TABLE;
    $row = $this->database->query(
      "SELECT VEC_ToText(embedding) AS embedding_text
       FROM {{$table}}
       WHERE entity_type_id = :entity_type_id AND entity_id = :entity_id",
      [
        ':entity_type_id' => $entity_type_id,
        ':entity_id' => $entity_id,
      ],
    )->fetchAssoc();

    if (!$row || empty($row['embedding_text'])) {
      return NULL;
    }

    return $this->parseVectorText((string) $row['embedding_text']);
  }

  /**
   * {@inheritdoc}
   */
  public function findNearest(EmbeddingNearestQuery $query): array {
    if (!$this->tableExists()) {
      return [];
    }

    $limit = max(1, $query->limit);
    $query_vec = $this->formatVectorText($query->query);
    $table = self::TABLE;
    $where_extra = '';
    $args = [
      ':query_vec' => $query_vec,
      ':entity_type_id' => $query->entityTypeId,
    ];

    if ($query->bundle !== NULL && $query->bundle !== '') {
      $where_extra .= '
        AND e.bundle = :bundle';
      $args[':bundle'] = $query->bundle;
    }
    if ($query->excludeEntityId !== NULL) {
      $where_extra .= '
        AND e.entity_id <> :exclude_entity_id';
      $args[':exclude_entity_id'] = $query->excludeEntityId;
    }
    if ($query->entityIdMin !== NULL && $query->entityIdMax !== NULL) {
      $where_extra .= '
        AND e.entity_id >= :id_min
        AND e.entity_id <= :id_max';
      $args[':id_min'] = $query->entityIdMin;
      $args[':id_max'] = $query->entityIdMax;
    }

    // Join-free ORDER BY VEC_DISTANCE_COSINE + LIMIT so the VECTOR INDEX can be
    // used; optional filters are PK/column predicates only.
    $sql = "
      SELECT e.entity_id AS entity_id,
             VEC_DISTANCE_COSINE(e.embedding, VEC_FromText(:query_vec)) AS distance
      FROM {{$table}} e
      WHERE e.entity_type_id = :entity_type_id
        {$where_extra}
      ORDER BY distance ASC
      LIMIT {$limit}
    ";

    try {
      $rows = $this->database->query($sql, $args)->fetchAll();
    }
    catch (DatabaseExceptionWrapper $exception) {
      throw new \RuntimeException('Failed to query nearest embeddings: ' . $exception->getMessage(), 0, $exception);
    }

    $hits = [];
    foreach ($rows as $row) {
      $distance = (float) $row->distance;
      $similarity = 1.0 - $distance;
      if ($query->minSimilarity !== NULL && $similarity < $query->minSimilarity) {
        continue;
      }
      $hits[] = new EmbeddingNearestHit((int) $row->entity_id, $similarity);
    }
    return $hits;
  }

  /**
   * {@inheritdoc}
   */
  public function upsert(
    string $entity_type_id,
    int $entity_id,
    string $bundle,
    array $vector,
    string $text_hash,
    string $language,
    int $dimensions = self::DEFAULT_DIMENSIONS,
    ?int $created = NULL,
  ): void {
    if (!$this->tableExists()) {
      throw new \RuntimeException('Embeddings storage is not ready. Call ensureReady() first.');
    }

    if (count($vector) !== $dimensions) {
      throw new \InvalidArgumentException(sprintf(
        'Expected %d embedding dimensions, got %d.',
        $dimensions,
        count($vector),
      ));
    }

    $vec_text = $this->formatVectorText($vector);
    $created ??= time();
    $table = self::TABLE;

    try {
      $this->database->query(
        "INSERT INTO {{$table}}
          (entity_type_id, entity_id, bundle, embedding, text_hash, language, created)
         VALUES
          (:entity_type_id, :entity_id, :bundle, VEC_FromText(:embedding), :text_hash, :language, :created)
         ON DUPLICATE KEY UPDATE
          bundle = VALUES(bundle),
          embedding = VALUES(embedding),
          text_hash = VALUES(text_hash),
          language = VALUES(language),
          created = VALUES(created)",
        [
          ':entity_type_id' => $entity_type_id,
          ':entity_id' => $entity_id,
          ':bundle' => $bundle,
          ':embedding' => $vec_text,
          ':text_hash' => $text_hash,
          ':language' => $language !== '' ? $language : 'en',
          ':created' => $created,
        ],
      );
    }
    catch (DatabaseExceptionWrapper $exception) {
      throw new \RuntimeException('Failed to upsert embedding: ' . $exception->getMessage(), 0, $exception);
    }
  }

  /**
   * Serialize a float vector for VEC_FromText().
   *
   * @param float[] $vector
   *   Embedding floats.
   *
   * @return string
   *   Bracketed comma-separated floats.
   *
   * @throws \InvalidArgumentException
   *   When the vector is empty or contains non-numeric values.
   */
  private function formatVectorText(array $vector): string {
    if ($vector === []) {
      throw new \InvalidArgumentException('Embedding vector must not be empty.');
    }

    $floats = [];
    foreach ($vector as $value) {
      if (!is_int($value) && !is_float($value)) {
        throw new \InvalidArgumentException('Embedding vector contains a non-numeric value.');
      }
      $floats[] = sprintf('%.8g', (float) $value);
    }
    return '[' . implode(',', $floats) . ']';
  }

  /**
   * Parse VEC_ToText JSON array into floats.
   *
   * @param string $text
   *   VEC_ToText output.
   *
   * @return float[]|null
   *   Embedding vector, or NULL when invalid.
   */
  private function parseVectorText(string $text): ?array {
    $decoded = json_decode($text, TRUE);
    if (!is_array($decoded) || $decoded === []) {
      return NULL;
    }

    $out = [];
    foreach ($decoded as $value) {
      if (!is_int($value) && !is_float($value)) {
        return NULL;
      }
      $out[] = (float) $value;
    }
    return $out;
  }

}
