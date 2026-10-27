<?php

declare(strict_types=1);

namespace Drupal\reliefweb_ai\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Enum\ClassificationStatus;
use Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface;

/**
 * Decorates content classification to run language detection in simulations.
 */
final class ContentEntityClassifierDecorator implements ContentEntityClassifierInterface {

  /**
   * Constructs a ContentEntityClassifierDecorator.
   *
   * @param \Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface $inner
   *   The decorated classifier.
   * @param \Drupal\reliefweb_ai\Service\ReportLanguageDetector $reportLanguageDetector
   *   Report language detector.
   */
  public function __construct(
    protected readonly ContentEntityClassifierInterface $inner,
    protected readonly ReportLanguageDetector $reportLanguageDetector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function simulateClassification(ContentEntityInterface $entity): array {
    $result = $this->inner->simulateClassification($entity);
    if (empty($result['success']) || !isset($result['simulated'])) {
      return $result;
    }

    $simulated = $result['simulated'];
    if (
      !$simulated instanceof ContentEntityInterface
      || $simulated->getEntityTypeId() !== 'node'
      || $simulated->bundle() !== 'report'
      || !$simulated->hasField('field_language')
    ) {
      return $result;
    }

    // Clear so the simulated column reflects detection, not the stored value.
    $simulated->set('field_language', NULL);
    $outcome = $this->reportLanguageDetector->resolveLanguage($simulated);
    if ($outcome !== NULL && $outcome->termId !== NULL) {
      $simulated->set('field_language', $outcome->termId);
    }
    $result['language_detection'] = $outcome;

    // Always surface language in the simulation comparison table.
    $result['updated_fields'] = array_values(array_unique(array_merge(
      $result['updated_fields'] ?? [],
      ['field_language'],
    )));
    $result['simulated'] = $simulated;

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function isEntityClassifiable(EntityInterface $entity, bool $check_status = TRUE): bool {
    return $this->inner->isEntityClassifiable($entity, $check_status);
  }

  /**
   * {@inheritdoc}
   */
  public function getWorkflowForEntity(EntityInterface $entity, bool $check_enabled = TRUE): ?ClassificationWorkflowInterface {
    return $this->inner->getWorkflowForEntity($entity, $check_enabled);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityClassificationStatus(EntityInterface $entity): ?ClassificationStatus {
    return $this->inner->getEntityClassificationStatus($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function requeueEntity(ContentEntityInterface $entity): bool {
    return $this->inner->requeueEntity($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function entityBeforeSave(EntityInterface $entity): void {
    $this->inner->entityBeforeSave($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function entityAfterSave(EntityInterface $entity): void {
    $this->inner->entityAfterSave($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function entityDelete(EntityInterface $entity): void {
    $this->inner->entityDelete($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    $this->inner->alterForm($form, $form_state, $form_id);
  }

}
