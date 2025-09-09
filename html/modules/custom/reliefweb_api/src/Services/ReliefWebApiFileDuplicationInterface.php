<?php

declare (strict_types=1);

namespace Drupal\reliefweb_api\Services;

/**
 * Interface for ReliefWeb API file duplication detection service.
 */
interface ReliefWebApiFileDuplicationInterface {

  /**
   * Find documents with similar content to the input text.
   *
   * @param string $input_text
   *   The input text to find similar documents for.
   * @param string $bundle
   *   The content type bundle (e.g., 'report', 'job', etc.).
   * @param int $max_documents
   *   Maximum number of documents to return.
   * @param string $minimum_should_match
   *   Minimum should match percentage for Elasticsearch query.
   * @param array $exclude_ids
   *   Array of document IDs to exclude from the search results.
   *
   * @return array
   *   Array of matching documents with similarity scores.
   *   Each document contains:
   *   - id: Document ID
   *   - title: Document title
   *   - url: Document URL
   *   - similarity: Raw similarity score (0.0 to 1.0)
   *   - similarity_percentage: Formatted percentage string
   *   - score: Elasticsearch relevance score
   */
  public function findSimilarDocuments(
    string $input_text,
    string $bundle = 'report',
    int $max_documents = 10,
    string $minimum_should_match = '80%',
    array $exclude_ids = [],
  ): array;

}
