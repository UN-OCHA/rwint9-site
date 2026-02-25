<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_import\Service\ReliefWebImporterModeration;

/**
 * Alters the moderation page filter form for the importer moderation page.
 */
class ModerationPageFilterFormAlter {

  use StringTranslationTrait;

  /**
   * Implements hook_form_alter() for the moderation page filter form.
   *
   * Replaces the source filter checkboxes with an autocomplete select.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($form_id !== 'reliefweb_moderation_page_filter_form') {
      return;
    }

    $service = $form['#service'] ?? NULL;
    if (!$service instanceof ReliefWebImporterModeration) {
      return;
    }

    if (!isset($form['filters']['source']['source'])) {
      return;
    }

    $source_checkboxes = $form['filters']['source']['source'];
    $options = $source_checkboxes['#options'] ?? [];
    $weight = $form['filters']['source']['#weight'] ?? 2;

    $form['filters']['source'] = [
      '#type' => 'select',
      '#title' => $form['filters']['source']['#title'] ?? $this->t('Source'),
      '#options' => $options,
      '#multiple' => TRUE,
      '#parents' => ['filters', 'source'],
      '#weight' => $weight,
      '#optional' => FALSE,
      '#attributes' => [
        'data-with-autocomplete' => '',
      ],
    ];

    $form['#attached']['library'][] = 'reliefweb_form/widget.autocomplete';
  }

}
