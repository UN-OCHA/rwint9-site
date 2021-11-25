<?php

namespace Drupal\reliefweb_moderation\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\ModerationServiceInterface;

/**
 * Content moderation page filter form handler.
 */
class ModerationPageFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_moderation_page_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ModerationServiceInterface $service = NULL) {
    if (empty($service)) {
      return [];
    }
    $form['#service'] = $service;

    $data = [];

    // @todo check if we need to set the class here and the title may be
    // redundant with the section in the template.
    $form['filters'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Filters'),
      '#title_display' => 'invisible',
      '#attributes' => [
        'class' => ['rw-moderation-filters__content'],
      ],
      '#tree' => TRUE,
    ];

    $definitions = $service->getFilterDefinitions();

    // Add the filters to the appropriate form container.
    foreach ($definitions as $name => $filter) {
      if (isset($filter['form'], $filter['label'])) {
        switch ($filter['form']) {
          // Entity status.
          case 'status':
            if (!isset($form['filters']['status'])) {
              $statuses = $service->getFilterStatuses();
              if (!empty($statuses)) {
                $option_attributes = [];
                foreach ($statuses as $status => $label) {
                  $option_attributes[$status]['data-moderation-status'] = $status;
                }
                $form['filters']['status'] = [
                  '#type' => 'checkboxes',
                  '#title' => $this->t('Status'),
                  '#options' => $statuses,
                  '#option_attributes' => $option_attributes,
                  '#default_value' => $service->getFilterDefaultStatuses(),
                  '#attributes' => [
                    'class' => [
                      'rw-moderation-filter-status',
                      'rw-moderation-filter-group',
                    ],
                  ],
                  '#weight' => 1,
                  '#optional' => FALSE,
                ];
              }
            }
            break;

          case 'omnibox':
            if (!isset($form['filters']['omnibox'])) {
              $form['filters']['omnibox'] = [
                '#type' => 'fieldset',
                '#title' => $this->t('Omnibox'),
                '#tree' => TRUE,
                '#parents' => ['omnibox'],
                '#weight' => 2,
                '#optional' => FALSE,
                '#attributes' => [
                  'class' => [
                    'rw-moderation-filter-omnibox',
                  ],
                ],
              ];
              $form['filters']['omnibox']['select'] = [
                '#type' => 'select',
                '#options' => [],
                '#option_attributes' => [],
                '#optional' => FALSE,
              ];
              $form['filters']['omnibox']['input'] = [
                '#type' => 'textfield',
                '#attributes' => [
                  'autocomplete' => 'off',
                  'data-autocomplete-url' => $this->getAutocompleteUrl($form_state, $service),
                ],
                // @todo review if that shouldn't be added by the autocomplete
                // instead or even if that's necessary at all.
                '#wrapper_attributes' => [
                  'data-autocomplete' => '',
                ],
                '#optional' => FALSE,
              ];
            }
            $form['filters']['omnibox']['select']['#options'][$name] = $filter['label'];
            $form['filters']['omnibox']['select']['#option_attributes'][$name] = [
              'data-shortcut' => $filter['shortcut'] ?? $name,
              'data-widget' => $filter['widget'],
            ];
            break;

          case 'other':
            if (!isset($form['filters']['other'])) {
              $form['filters']['other'] = [
                '#type' => 'fieldset',
                '#title' => $this->t('Properties'),
                '#attributes' => [
                  'class' => [
                    'rw-moderation-filter-other',
                    'rw-moderation-filter-group',
                  ],
                ],
                '#weight' => 2,
                '#optional' => FALSE,
              ];
            }
            if (empty($filter['values'])) {
              $form['filters']['other'][$name] = [
                '#type' => 'checkbox',
                '#title' => $filter['label'],
                '#parents' => ['filters', $name],
                '#optional' => FALSE,
              ];
            }
            else {
              $form['filters']['other'][$name] = [
                '#type' => 'checkboxes',
                '#options' => $filter['values'],
                '#parents' => ['filters', $name],
                '#optional' => FALSE,
              ];
            }
            break;
        }
      }
    }

    // Ensure the fields are ordered properly.
    if (isset($form['filters']['status'])) {
      $form['filters']['status']['#weight'] = 1;
    }
    if (isset($form['filters']['other'])) {
      $form['filters']['other']['#weight'] = 2;
    }
    if (isset($form['filters']['omnibox'])) {
      $form['filters']['omnibox']['#weight'] = 3;
    }

    // Filter selection section.
    $selection = '';
    $input = $form_state->getUserInput();
    // Populate the selection with the input values (query params).
    if (!empty($input['selection'])) {
      $options = $form['filters']['omnibox']['select']['#options'];
      foreach ($input['selection'] as $filter => $items) {
        if (isset($options[$filter], $definitions[$filter]['widget'])) {
          $widget = $definitions[$filter]['widget'];
          foreach ($items as $item) {
            if ($widget === 'search') {
              $value = $item;
              $label = $item;
            }
            else {
              list($value, $label) = explode(':', $item, 2);
            }
            $selection .= new FormattableMarkup(implode('', [
              '<div data-value="@value">',
              '<span class="field">@field: </span>',
              '<span class="label">@label</span>',
              '<input type="hidden" name="selection[@filter][]" value="@item"/>',
              '<button type="button" tabindex="-1">@remove</button>',
              '</div>',
            ]), [
              '@value' => $value,
              '@field' => $options[$filter],
              '@label' => $label,
              '@filter' => $filter,
              '@item' => $item,
              '@remove' => $this->t('Remove'),
            ]);
          }
        }
      }
    }
    $form['selection'] = [
      // Wrap in a FormattableMarkup so that Durpal doesn't strip the
      // buttons...
      // @todo use a template?
      '#markup' => new FormattableMarkup('<div data-selection class="rw-selection">' . $selection . '</div>', []),
      '#weight' => 4,
    ];

    // Add the filter and reset buttons.
    $form['actions'] = [
      '#type' => 'actions',
      '#theme_wrappers' => [
        'fieldset' => [
          '#id' => 'actions',
          '#title' => $this->t('Filter actions'),
          '#title_display' => 'invisible',
        ],
      ],
      '#weight' => 99,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#name' => 'submit',
      '#value' => $this->t('Filter'),
    ];
    $form['actions']['reset'] = [
      '#type' => 'submit',
      '#name' => 'reset',
      '#value' => $this->t('Reset'),
      '#submit' => ['::resetForm'],
    ];

    // Link to create a new entity.
    $bundle = $service->getBundle();

    if (!empty($bundle) && is_string($bundle)) {
      $url_options = ['attributes' => ['target' => '_blank']];
      if ($service->getEntityTypeId() === 'taxonomy_term') {
        $create_url = Url::fromRoute('entity.taxonomy_term.add_form', [
          'taxonomy_vocabulary' => $bundle,
        ], $url_options);
      }
      else {
        $create_url = Url::fromRoute('node.add', [
          'node_type' => $bundle,
        ], $url_options);
      }

      $form['actions']['create'] = [
        '#type' => 'link',
        '#url' => $create_url,
        '#title' => $this->t('Create @bundle', ['@bundle' => $bundle]),
      ];
    }

    // Add the data to be passed to the js scripts (shortcuts etc.).
    $form['content']['#attached'] = [
      'drupalSettings' => ['reliefwebModeration' => $data],
    ];

    // @todo review as it may be conflicting with what
    // reliefweb-moderation-page.html.twig teplate does.
    $form['#attributes']['class'] = ['rw-moderation-content-filters'];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * Reset the form.
   *
   * @param array $form
   *   Form data.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function resetForm(array $form, FormStateInterface $form_state) {
    $form_state->setProgrammed(FALSE);
    $form_state->setRedirect('<current>');
  }

  /**
   * Get the autocomplete URL for the omnibox.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param \Drupal\reliefweb_moderation\ModerationServiceInterface $service
   *   The moderation service associated with this form.
   *
   * @return string
   *   Autocomplete URL.
   */
  protected function getAutocompleteUrl(FormStateInterface $form_state, ModerationServiceInterface $service) {
    $bundle = $service->getBundle();
    if (is_array($bundle)) {
      $bundle = reset($bundle);
    }
    return '/moderation/content/' . $bundle . '/autocomplete/';
  }

}
