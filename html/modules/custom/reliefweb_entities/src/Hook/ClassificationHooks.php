<?php

declare(strict_types=1);

namespace Drupal\reliefweb_entities\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Enum\ClassificationStatus;
use Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Drupal\reliefweb_utility\Helpers\ReliefWebStateHelper;
use Drupal\user\EntityOwnerInterface;
use Psr\Log\LoggerInterface;

/**
 * Classification-related hooks for ReliefWeb entities.
 */
final class ClassificationHooks {

  /**
   * The logger channel.
   */
  protected readonly LoggerInterface $logger;

  /**
   * Constructs ClassificationHooks.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface $userPostingRightsManager
   *   User posting rights manager.
   * @param \Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface|null $contentEntityClassifier
   *   Optional content entity classifier (when ocha_content_classification is
   *   enabled).
   */
  public function __construct(
    protected readonly AccountProxyInterface $currentUser,
    protected readonly StateInterface $state,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly UserPostingRightsManagerInterface $userPostingRightsManager,
    protected readonly ?ContentEntityClassifierInterface $contentEntityClassifier = NULL,
  ) {
    $this->logger = $loggerFactory->get('reliefweb_entities');
  }

  /**
   * Skip classification for draft and refused moderated entities.
   */
  #[Hook('ocha_content_classification_skip_classification_alter')]
  public function skipClassificationAlter(bool &$skip_classification, ClassificationWorkflowInterface $workflow, array $context): void {
    $entity = $context['entity'] ?? NULL;
    if ($entity instanceof EntityModeratedInterface) {
      $status = $entity->getModerationStatus();
      // Draft and refused entities should not be classified.
      if ($status === 'draft' || $status === 'refused') {
        $skip_classification = TRUE;
      }
    }
  }

  /**
   * Requeue entity for classification when attachments change.
   *
   * @todo handle other bundles than report.
   */
  #[Hook('ocha_content_classification_pre_entity_presave')]
  public function preEntityPresave(EntityInterface $entity): void {
    if (!$this->currentUser->hasPermission('requeue report for classification on change')) {
      return;
    }

    // This is restricted to reports for now to handle the "Document drop".
    // @see RW-1188
    if ($entity->bundle() !== 'report') {
      return;
    }

    $original_entity = $entity->getOriginal();
    if (!isset($original_entity)) {
      return;
    }

    // Check if the attachments have changed.
    // @todo we probably need to override the `equals` method in the
    // ReliefWebFileList to only check the UUIDs and hashes.
    if ($entity->field_file->equals($original_entity->field_file)) {
      return;
    }

    if ($this->contentEntityClassifier === NULL) {
      return;
    }

    $workflow = $this->contentEntityClassifier->getWorkflowForEntity($entity);
    if (empty($workflow)) {
      return;
    }

    // Delete any existing classification record so we can requeue the entity.
    $workflow->deleteClassificationProgress($entity);
  }

  /**
   * Update the entity moderation status based on the classification status.
   */
  #[Hook('ocha_content_classification_post_entity_presave')]
  public function postEntityPresave(EntityInterface $entity): void {
    if (!($entity instanceof EntityModeratedInterface)) {
      return;
    }

    $account = match (TRUE) {
      $entity instanceof RevisionLogInterface => $entity->getRevisionUser(),
      $entity instanceof EntityOwnerInterface => $entity->getOwner(),
      default => $this->currentUser,
    };

    if ($account?->id() != 1 && $account?->hasPermission("adjust {$entity->bundle()} moderation status based on classification status")) {
      $this->adjustModerationStatusBasedOnClassificationStatus($entity);
    }
  }

  /**
   * Adjust the entity moderation status based on the classification status.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   */
  public function adjustModerationStatusBasedOnClassificationStatus(EntityInterface $entity): void {
    if (!($entity instanceof EntityModeratedInterface)) {
      return;
    }

    // This flag is set when the classification failed or completed or when the
    // entity is going to be queued (or is already being queued) if the user who
    // triggered the entity saving was allowed to use the classification
    // workflow.
    if (!isset($entity->ocha_content_classification_status)) {
      return;
    }

    // Allow other modules to bypass the status adjustment.
    $bypass = FALSE;
    $this->moduleHandler->alter('reliefweb_entities_moderation_status_adjustment_bypass', $bypass, $entity);
    if ($bypass) {
      return;
    }

    $publication_protection_default_status = ReliefWebStateHelper::getPublicationPreventionDefaultModerationStatus(
      $entity->getEntityTypeId(),
      $entity->bundle(),
    );

    $status = match ($entity->ocha_content_classification_status) {
      // Prevent publication if the document is queued for classification.
      // If the document is already in a non-published state then keep the
      // current status. This allows for example, to quickly mark a document as
      // refused or on-hold.
      ClassificationStatus::Queued => $entity->isPublishedModerationStatus() ? $publication_protection_default_status : $entity->getModerationStatus(),
      // Prevent publication if the document classification failed. A human
      // should review manually.
      ClassificationStatus::Failed => $entity->isPublishedModerationStatus() ? $publication_protection_default_status : $entity->getModerationStatus(),
      // For successful classification, we keep the current status.
      ClassificationStatus::Completed => $entity->getModerationStatus(),
      // This default case should not happen.
      default => $entity->getModerationStatus(),
    };

    if ($status !== $entity->getModerationStatus()) {
      $allowed_statuses = $entity->getAllowedModerationStatuses();
      // Ensure the new status is available otherwise default to draft or the
      // default moderation status for the entity. The point is to try to
      // prevent unsafe publication.
      $status = match (TRUE) {
        isset($allowed_statuses[$status]) => $status,
        isset($allowed_statuses['draft']) => 'draft',
        default => $entity->getDefaultModerationStatus(),
      };

      // Update the moderation status.
      $entity->setModerationStatus($status);
    }
  }

  /**
   * Remove ignored fields from the classifier field list.
   *
   * @see RW-1189
   */
  #[Hook('ocha_content_classification_classified_fields_alter')]
  public function classifiedFieldsAlter(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void {
    $key = 'reliefweb_ignore_classified_fields:' . $workflow->id();
    $ignore_fields = $this->state->get($key, []);
    $ignore_fields = array_flip($ignore_fields);
    foreach ($fields as $type => $field_list) {
      $fields[$type] = array_diff_key($field_list, $ignore_fields);
    }
  }

  /**
   * Clear body when report attachments will be analyzed as files.
   */
  #[Hook('ocha_content_classification_prepare_entity_alter')]
  public function prepareEntityAlter(EntityInterface &$prepared_entity, ClassificationWorkflowInterface $workflow, array $context): void {
    if ($prepared_entity->bundle() !== 'report') {
      return;
    }

    $classifier = $context['classifier'] ?? NULL;
    $supported_plugins = ['inference', 'structured_inference'];
    if ($classifier === NULL || !in_array($classifier->getPluginId(), $supported_plugins, TRUE)) {
      return;
    }

    $analyzable_fields = $classifier->getEnabledFields('analyzable');

    // Remove the body field if there are attachments to analyze.
    // The body may already be the result of a previous classification
    // attempt and currently we don't have a good way to know that at that
    // stage. For example if the entity was re-queued then the previous
    // classification record was deleted so we cannot retrieve the list of
    // fields that were classified.
    // @todo review if the classification progress is changed to keep the
    // history in which case we could check if the body was processed or not
    // by the classifier.
    if (!empty($analyzable_fields['field_file']['file']) && $prepared_entity->hasField('field_file') && !$prepared_entity->get('field_file')->isEmpty()) {
      $prepared_entity->set('body', NULL);
    }
  }

  /**
   * Fail classification when any expected attachment is missing on disk.
   */
  #[Hook('ocha_content_classification_validate_entity_data_alter')]
  public function validateEntityDataAlter(bool &$invalid, ClassificationWorkflowInterface $workflow, array $context): void {
    if ($invalid) {
      return;
    }

    $entity = $context['entity'] ?? NULL;
    $classifier = $context['classifier'] ?? NULL;
    if (
      !$entity instanceof EntityInterface
      || $entity->bundle() !== 'report'
      || $classifier === NULL
    ) {
      return;
    }

    $supported_plugins = ['inference', 'structured_inference'];
    if (!in_array($classifier->getPluginId(), $supported_plugins, TRUE)) {
      return;
    }

    $analyzable_fields = $classifier->getEnabledFields('analyzable');
    if (empty($analyzable_fields['field_file']['file'])) {
      return;
    }

    if (!$entity->hasField('field_file') || $entity->get('field_file')->isEmpty()) {
      return;
    }

    $missing = [];
    foreach ($entity->get('field_file') as $item) {
      if (!$item instanceof ReliefWebFile) {
        continue;
      }

      $file = $item->loadFile();
      $uri = $file?->getFileUri();
      if ($file === NULL || empty($uri) || !file_exists($uri)) {
        $missing[] = !empty($uri) ? $uri : (string) ($item->getFileUuid() ?? 'unknown');
      }
    }

    if ($missing === []) {
      return;
    }

    $invalid = TRUE;
    $this->logger->warning('Classification aborted for report @id: missing attachment file(s) on disk: @uris', [
      '@id' => $entity->id() ?? 'unsaved',
      '@uris' => implode(', ', $missing),
    ]);
  }

  /**
   * Keep classifiable fields visible for trusted job posters after completion.
   */
  #[Hook('ocha_content_classification_hide_form_fields_alter')]
  public function hideFormFieldsAlter(array &$fields_to_hide, ClassificationWorkflowInterface $workflow, array $context): void {
    $entity = $context['entity'] ?? NULL;
    if ($entity === NULL) {
      return;
    }

    $bundle = $entity->bundle();

    if ($bundle === 'job' && !$entity->get('field_source')->isEmpty()) {
      // Only show the fields if the classification is completed.
      $status = $context['classifier']->getEntityClassificationStatus($entity);
      if ($status === ClassificationStatus::Completed) {
        // Retrieve the consolidated posting rights for the user to check if
        // the user is trusted for the sources of the entity.
        $user = $this->currentUser->getAccount();
        $sources = array_column($entity->get('field_source')->getValue(), 'target_id');
        $rights = $this->userPostingRightsManager->getUserConsolidatedPostingRight($user, $bundle, $sources);

        // Prevent hiding the fields if the user is trusted.
        if ($rights['right']->isTrusted()) {
          // Effectively unhide the fields by resetting the array.
          $fields_to_hide = [];
        }
      }
    }
  }

}
