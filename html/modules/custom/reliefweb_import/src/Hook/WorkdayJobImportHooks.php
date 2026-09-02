<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Session\AccountInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\reliefweb_entities\Entity\Job;
use Drupal\reliefweb_import\JobImport\JobImportStateStore;

/**
 * Automated content classification integration for Workday job feed imports.
 */
final class WorkdayJobImportHooks {

  /**
   * Bypasses classification permission checks for Workday imports.
   *
   * @param bool $check
   *   Whether to check user permissions.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account being checked.
   * @param array $context
   *   Classification context.
   */
  #[Hook(
    'ocha_content_classification_user_permission_check_alter',
    order: new OrderAfter(modules: ['reliefweb_import']),
  )]
  public function userPermissionCheckAlter(bool &$check, AccountInterface $account, array $context): void {
    if (!$this->isWorkdayClassificationImport($context['entity'] ?? NULL)) {
      return;
    }
    $check = FALSE;
  }

  /**
   * Defers career/theme field checks until after classification runs.
   *
   * @param array $fields
   *   Field check map.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   Classification workflow.
   * @param array $context
   *   Classification context.
   */
  #[Hook(
    'ocha_content_classification_specified_field_check_alter',
    order: new OrderAfter(modules: ['reliefweb_import']),
  )]
  public function specifiedFieldCheckAlter(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void {
    if (!$this->isWorkdayClassificationImport($context['entity'] ?? NULL)) {
      return;
    }

    foreach (['field_career_categories', 'field_theme'] as $field_name) {
      if (isset($fields[$field_name])) {
        $fields[$field_name] = FALSE;
      }
    }
  }

  /**
   * Ensures pending Workday imports are not skipped for classification.
   *
   * @param bool $skip
   *   Whether to skip classification.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   Classification workflow.
   * @param array $context
   *   Classification context.
   */
  #[Hook(
    'ocha_content_classification_skip_classification_alter',
    order: new OrderAfter(modules: ['reliefweb_import', 'reliefweb_entities']),
  )]
  public function skipClassificationAlter(bool &$skip, ClassificationWorkflowInterface $workflow, array $context): void {
    if (!$this->isWorkdayClassificationImport($context['entity'] ?? NULL)) {
      return;
    }

    $entity = $context['entity'];
    if (!$entity instanceof Job) {
      return;
    }

    if ($entity->getModerationStatus() === 'pending' && !JobImportStateStore::hasBlockingErrors($entity)) {
      $skip = FALSE;
    }
  }

  /**
   * Whether the entity is a Workday import with classification enabled.
   *
   * @param mixed $entity
   *   Entity being classified.
   *
   * @return bool
   *   TRUE for Workday classification imports.
   */
  private function isWorkdayClassificationImport(mixed $entity): bool {
    if (!$entity instanceof Job) {
      return FALSE;
    }

    $context = JobImportStateStore::getContext($entity);
    return $context !== NULL
      && $context->source === 'workday'
      && $context->classificationEnabled;
  }

}
