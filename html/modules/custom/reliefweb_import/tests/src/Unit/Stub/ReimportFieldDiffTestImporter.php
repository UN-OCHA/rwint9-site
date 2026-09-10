<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit\Stub;

use Drupal\Core\Entity\EntityInterface;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginBase;

/**
 * Concrete importer stub for reimport field diff unit tests.
 */
class ReimportFieldDiffTestImporter extends ReliefWebImporterPluginBase {

  /**
   * Optional overrides for normalizeImportFieldValue().
   *
   * @var array<string, mixed>|null
   */
  public ?array $importValueOverrides = NULL;

  /**
   * Optional overrides for normalizeEntityImportValue().
   *
   * @var array<string, mixed>|null
   */
  public ?array $entityValueOverrides = NULL;

  /**
   * {@inheritdoc}
   */
  public function importContent(int $limit = 50): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function processDocumentData(string $uuid, array $document): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function normalizeImportFieldValue(string $field, mixed $value, EntityInterface $entity): mixed {
    if (is_array($this->importValueOverrides) && array_key_exists($field, $this->importValueOverrides)) {
      return $this->importValueOverrides[$field];
    }
    return parent::normalizeImportFieldValue($field, $value, $entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function normalizeEntityImportValue(EntityInterface $entity, string $field): mixed {
    if (is_array($this->entityValueOverrides) && array_key_exists($field, $this->entityValueOverrides)) {
      return $this->entityValueOverrides[$field];
    }
    return parent::normalizeEntityImportValue($entity, $field);
  }

}
