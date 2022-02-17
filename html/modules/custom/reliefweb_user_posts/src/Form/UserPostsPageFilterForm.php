<?php

namespace Drupal\reliefweb_user_posts\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\reliefweb_moderation\Form\ModerationPageFilterForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\ModerationServiceInterface;
use Drupal\user\UserInterface;

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
  public function buildForm(array $form, FormStateInterface $form_state, ModerationServiceInterface $service = NULL, UserInterface $user = NULL) {
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

    // Add the filter labels to the properties.
    $definitions = $service->getFilterDefinitions();
    foreach ($definitions as $name => $filter) {
      if (isset($form['filters']['other'][$name], $filter['label'])) {
        $form['filters']['other'][$name]['#title'] = $filter['label'];
      }
    }
    // Hide the properties label and move the other filter to the top.
    if (isset($form['filters']['other'])) {
      $form['filters']['other']['#title_display'] = 'invisible';
      $form['filters']['other']['#weight'] = -1;
    }

    // Change the omnibox label.
    if (isset($form['filters']['omnibox'])) {
      $form['filters']['omnibox']['#title'] = $this->t('Filter');
    }

    // Make js work.
    $form['#attributes']['id'] = 'reliefweb-moderation-page-filter-form';
    if (isset($form['filters']['other']['poster']) && !isset($form['filters']['other']['poster']['#default_value'])) {
      $form['filters']['other']['poster']['#default_value'] = ['me'];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function getAutocompleteUrl(FormStateInterface $form_state, ModerationServiceInterface $service) {
    $build_info = $form_state->getBuildInfo();
    if (isset($build_info['args'][1]) && $build_info['args'][1] instanceof UserInterface) {
      return '/user/' . $build_info['args'][1]->id() . '/posts/autocomplete/';
    }
    return '/moderation/content/user_posts/autocomplete/';
  }

}
