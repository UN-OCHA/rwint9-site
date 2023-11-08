<?php

namespace Drupal\reliefweb_entities\Services;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;

/**
 * Topic form alteration service.
 */
class TopicFormAlter extends EntityFormAlterServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function addBundleFormAlterations(array &$form, FormStateInterface $form_state) {
    // Add the disaster map token help to the overview field.
    $form['field_overview']['token-help'] = static::getDisasterMapTokenHelp();

    // Add an autocomplete widget to the tags.
    $form['field_disaster_type']['#attributes']['data-with-autocomplete'] = '';
    $form['field_theme']['#attributes']['data-with-autocomplete'] = '';
  }

  /**
   * Get the help to use the disaster map tokens.
   *
   * @return array
   *   Render array with the token help.
   */
  protected static function getDisasterMapTokenHelp() {
    $info = reliefweb_disaster_map_token_info();
    if (!isset($info['types']['disaster-map']) || !isset($info['tokens']['disaster-map'])) {
      return [];
    }

    $rows = [];
    foreach ($info['tokens']['disaster-map'] as $token => $data) {
      $rows[] = [
        $data['name'] ?? $token,
        '[disaster-map:' . $token . ']',
        $data['description'] ?? '',
      ];
    }

    $table = [
      '#theme' => 'table',
      '#header' => [
        t('Name'),
        t('Token'),
        t('Description'),
      ],
      '#rows' => $rows,
      '#attributes' => [
        'class' => [
          'rw-token-help__table',
        ],
      ],
    ];

    return [
      '#type' => 'details',
      '#title' => new FormattableMarkup('<strong>@label</strong><p>@description</p>', [
        '@label' => t('Disaster map tokens'),
        '@description' => $info['types']['disaster-map']['description'] ?? '',
      ]),
      '#attributes' => [
        'class' => [
          'rw-token-help',
        ],
      ],
      '#not_required' => TRUE,
      'table' => $table,
    ];
  }

}
