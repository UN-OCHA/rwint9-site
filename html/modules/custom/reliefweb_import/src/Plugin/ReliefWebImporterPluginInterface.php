<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Psr\Log\LoggerInterface;

/**
 * Interface for the importer plugins.
 */
interface ReliefWebImporterPluginInterface {

  /**
   * Get the plugin label.
   *
   * @return string
   *   The plugin label.
   */
  public function getPluginLabel(): string;

  /**
   * Get the plugin type.
   *
   * @return string
   *   The plugin type.
   */
  public function getPluginType(): string;

  /**
   * Check if the plugin is enabled.
   *
   * @return bool
   *   TRUE if enabled.
   */
  public function enabled(): bool;

  /**
   * Get the plugin logger.
   *
   * @return \Psr\Log\LoggerInterface
   *   Logger.
   */
  public function getLogger(): LoggerInterface;

  /**
   * Get a plugin setting.
   *
   * @param string $key
   *   The setting name. It can be nested in the form "a.b.c" to retrieve "c".
   * @param mixed $default
   *   Default value if the setting is missing.
   * @param bool $throw_if_null
   *   If TRUE and both the setting and default are NULL then an exception
   *   is thrown. Use this for example for mandatory settings.
   *
   * @return mixed
   *   The plugin setting for the key or the provided default.
   *
   * @throws \Drupal\reliefweb_import\Exception\InvalidConfigurationException
   *   Throws an exception if no setting could be found (= NULL).
   */
  public function getPluginSetting(string $key, mixed $default = NULL, bool $throw_if_null = TRUE): mixed;

  /**
   * Load the plugin configuration.
   *
   * @return array
   *   The plugin configuration.
   */
  public function loadConfiguration(): array;

  /**
   * Save the plugin configuration.
   *
   * @param array $configuration
   *   The plugin configuration to save.
   */
  public function saveConfiguration(array $configuration): void;

  /**
   * Get the name of the configuration for this plugin.
   *
   * @return string
   *   Configuration name.
   */
  public function getConfigurationKey(): string;

  /**
   * Get the entity type ID the importer works with.
   *
   * @return string
   *   Entity type ID.
   */
  public function getEntityTypeId(): string;

  /**
   * Get the entity bundle the importer works with.
   *
   * @return string
   *   Entity bundle.
   */
  public function getEntityBundle(): string;

  /**
   * Import newest and update content.
   *
   * @param int $limit
   *   Number of documents to import at once (batch).
   *
   * @return bool
   *   TRUE if the batch import was successful.
   */
  public function importContent(int $limit = 50): bool;

  /**
   * Get the list of allowed extensions for the report attachments.
   *
   * @return array
   *   List of allowed extensions.
   */
  public function getReportAttachmentAllowedExtensions(): array;

  /**
   * Get the allowed max size of the report attachments.
   *
   * @return int
   *   Allowed max size in bytes.
   */
  public function getReportAttachmentAllowedMaxSize(): int;

  /**
   * Retrieve a Post API schema.
   *
   * @param string $bundle
   *   Resource bundle.
   *
   * @return string
   *   Schema.
   */
  public function getJsonSchema(string $bundle): string;

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
   * Alter the skip classification flag.
   *
   * This allows to bypass the check on the emptiness of a classifiable or
   * fillable field when determining if the classification should proceed.
   *
   * @param bool $skip
   *   Flag to indicate whether the classification should be skipped or not.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The workflow being used for classification.
   * @param array $context
   *   An array containing contextual information:
   *   - entity: The entity being classified.
   */
  public function alterContentClassificationSkipClassification(bool &$skip, ClassificationWorkflowInterface $workflow, array $context): void;

  /**
   * Alter whether user permissions should be checked before classification.
   *
   * @param bool $check
   *   Whether to check user permissions. Set to FALSE to bypass permission
   *   checks.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account for which to check permissions.
   * @param array $context
   *   An array containing contextual information:
   *   - workflow: The classification workflow.
   *   - entity: The entity being classified.
   */
  public function alterContentClassificationUserPermissionCheck(bool &$check, AccountInterface $account, array $context): void;

  /**
   * Alter the fields to check to proceed with the classification.
   *
   * This allows to bypass the check on the emptiness of a classifiable or
   * fillable field when determining if the classification should proceed.
   *
   * @param array $fields
   *   Associative array with the field names as keys and TRUE or FALSE as
   *   values. The check on a field is performed only if the value for the field
   *   is TRUE.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The workflow being used for classification.
   * @param array $context
   *   An array containing contextual information:
   *   - entity: The entity being classified.
   */
  public function alterContentClassificationSpecifiedFieldCheck(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void;

  /**
   * Alter the fields that should be forcibly updated during classification.
   *
   * @param array $fields
   *   Associative array of fields to always update with field names as keys and
   *   TRUE or FALSE as values. Set to TRUE to force the update of the field and
   *   set to FALSE to skip if the field already has a value.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The workflow being used for classification.
   * @param array $context
   *   An array containing contextual information:
   *   - entity: The entity being classified.
   */
  public function alterContentClassificationForceFieldUpdate(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void;

  /**
   * Alter the fields to update on the entity after the classification.
   *
   * @param array $fields
   *   The list of fields that the classifier handled, keyed by type:
   *   classifiable or fillable with the list of fields keyed by field names
   *   as values.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The workflow used for classification.
   * @param array $context
   *   An array containing contextual information:
   *   - entity: the entity being classified
   *   - classifier: the classifier plugin
   *   - data: the raw data used by the classifier (depends on the classifier).
   */
  public function alterContentClassificationClassifiedFields(array &$fields, ClassificationWorkflowInterface $workflow, array $context): void;

  /**
   * Alter the bypass flag for ReliefWeb entities moderation status adjustment.
   *
   * This method can be used to bypass the automatic moderation status
   * adjustment based on classification status for specific entities or
   * conditions.
   *
   * @param bool $bypass
   *   The bypass flag passed by reference. Set to TRUE to bypass the status
   *   adjustment, FALSE to allow normal processing.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being processed for moderation status adjustment.
   *
   * @see reliefweb_import_reliefweb_entities_moderation_status_adjustment_bypass_alter()
   */
  public function alterReliefWebEntitiesModerationStatusAdjustment(bool &$bypass, EntityInterface $entity): void;

}
