<?php

namespace Drupal\reliefweb_user_posts\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\reliefweb_moderation\Form\ModerationPageFilterForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\ModerationServiceInterface;

/**
 * Content moderation page filter form handler.
 */
class UserPostsPageFilterForm extends ModerationPageFilterForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_user_posts_page_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ModerationServiceInterface $service = NULL) {
    $form = parent::buildForm($form, $form_state, $service);

    // Link to create a new entity.
    $url_options = ['attributes' => ['target' => '_blank']];

    $links = $this->t('Create a new <a href="@job_url">Job vacancy</a> or a new <a href="@training_url">Training program</a>', [
      '@job_url' => Url::fromRoute('node.add', [
        'node_type' => 'job',
      ], $url_options)->toString(),
      '@training_url' => Url::fromRoute('node.add', [
        'node_type' => 'training',
      ], $url_options)->toString(),
    ]);

    // Add intro.
    $form['intro'] = [
      '#weight' => -100,
      '#markup' => new FormattableMarkup('<div class="rw-moderation-intro">' . $links . '</div>', []),
    ];

    // Fix data bundle.
    if (isset($form['filters']['omnibox']['input']['#attributes']['data-bundle'])) {
      $form['filters']['omnibox']['input']['#attributes']['data-bundle'] = 'user_posts';
    }

    // Add the filter labels to the properties.
    $definitions = $service->getFilterDefinitions();
    foreach ($definitions as $name => $filter) {
      if (isset($form['filters']['other'][$name], $filter['label'])) {
        $form['filters']['other'][$name]['#title'] = $filter['label'];
      }
    }
    // Hide the properties label.
    if (isset($form['filters']['other'])) {
      $form['filters']['other']['#title_display'] = 'invisible';
    }

    // Make js work.
    $form['#attributes']['id'] = 'reliefweb-moderation-page-filter-form';

    return $form;
  }

}
