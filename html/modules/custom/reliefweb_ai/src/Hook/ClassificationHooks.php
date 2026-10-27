<?php

declare(strict_types=1);

namespace Drupal\reliefweb_ai\Hook;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface;
use Drupal\ocha_content_classification\Plugin\ClassifierPluginInterface;
use Drupal\reliefweb_ai\Dto\ReportLanguageDetectionResult;
use Drupal\reliefweb_ai\Service\ReportLanguageDetectionDoneStore;
use Drupal\reliefweb_ai\Service\ReportLanguageDetector;

/**
 * Classification-related hooks for ReliefWeb AI.
 */
final class ClassificationHooks {

  /**
   * Constructs ClassificationHooks.
   *
   * @param \Drupal\reliefweb_ai\Service\ReportLanguageDetector $reportLanguageDetector
   *   Report language detector.
   */
  public function __construct(
    protected readonly ReportLanguageDetector $reportLanguageDetector,
  ) {}

  /**
   * Determine the language after the usual report classification workflow.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being classified.
   * @param \Drupal\ocha_content_classification\Entity\ClassificationWorkflowInterface $workflow
   *   The classification workflow.
   * @param \Drupal\ocha_content_classification\Plugin\ClassifierPluginInterface $classifier
   *   The classifier plugin.
   * @param array $updated_fields
   *   Fields updated during classification so far.
   * @param array $data
   *   Additional classifier data.
   *
   * @return array|null
   *   Updated field names, or NULL.
   *
   * @todo Replace when the ocha_content_classification module handles
   *   multi-step classification.
   */
  #[Hook('ocha_content_classification_post_classify_entity')]
  public function postClassifyEntity(
    EntityInterface $entity,
    ClassificationWorkflowInterface $workflow,
    ClassifierPluginInterface $classifier,
    array $updated_fields,
    array $data,
  ): ?array {
    // Prevent processing the same entity object again in this request.
    if (ReportLanguageDetectionDoneStore::isDone($entity)) {
      return NULL;
    }

    // This hook currently only handles reports.
    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'report') {
      return NULL;
    }

    $supported_plugins = ['inference', 'structured_inference'];
    if (!in_array($classifier->getPluginId(), $supported_plugins, TRUE)) {
      return NULL;
    }

    if (!($entity instanceof FieldableEntityInterface)) {
      return NULL;
    }

    ReportLanguageDetectionDoneStore::mark($entity);
    return $this->reportLanguageDetector->updateLanguage($entity);
  }

  /**
   * Show language detection outcome next to the simulated language value.
   */
  #[Hook('form_ocha_content_classification_simulate_form_alter')]
  public function alterSimulateForm(array &$form, FormStateInterface $form_state): void {
    $result = $form_state->get('simulation_result');
    if (
      !is_array($result)
      || !($result['language_detection'] ?? NULL) instanceof ReportLanguageDetectionResult
      || empty($form['comparison']['#rows'])
      || !is_array($form['comparison']['#rows'])
    ) {
      return;
    }

    $simulated = $result['simulated'] ?? NULL;
    if (
      !$simulated instanceof ContentEntityInterface
      || !$simulated->hasField('field_language')
    ) {
      return;
    }

    $language_label = (string) $simulated->get('field_language')->getFieldDefinition()->getLabel();
    $note = ReportLanguageDetector::formatSimulationNote($result['language_detection']);
    self::annotateLanguageRow($form['comparison']['#rows'], $language_label, $note);
  }

  /**
   * Append an outcome note to the simulated language cell.
   *
   * @param array $rows
   *   Comparison table rows.
   * @param string $language_label
   *   Field label for field_language.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $note
   *   Outcome note to display.
   */
  public static function annotateLanguageRow(array &$rows, string $language_label, TranslatableMarkup $note): void {
    foreach ($rows as &$row) {
      if (!is_array($row) || (string) ($row[0] ?? '') !== $language_label) {
        continue;
      }

      $cell = $row[2] ?? NULL;
      $field_render = is_array($cell) && array_key_exists('data', $cell)
        ? $cell['data']
        : $cell;

      $row[2] = [
        'data' => [
          'value' => $field_render,
          'note' => [
            '#prefix' => ' ',
            '#type' => 'html_tag',
            '#tag' => 'em',
            '#value' => $note,
          ],
        ],
      ];
      break;
    }
  }

}
