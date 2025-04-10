<?php

namespace Drupal\reliefweb_user_posts\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\Form\ModerationPageFilterForm;
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
  public function buildForm(array $form, FormStateInterface $form_state, ?ModerationServiceInterface $service = NULL, ?UserInterface $user = NULL) {
    $form = parent::buildForm($form, $form_state, $service);

    // Link to create a new entity.
    $url_options = ['attributes' => ['target' => '_blank']];

    // URLs to the creation forms.
    $types = [
      'job' => $this->t('Job vacancy'),
      'training' => $this->t('Training program'),
      'report' => $this->t('Report'),
    ];

    $links = [];
    foreach ($types as $type => $label) {
      $url = Url::fromRoute('node.add', ['node_type' => $type], $url_options);
      if ($url->access($user)) {
        $links[$type] = Link::fromTextAndUrl($label, $url);
      }
    }

    // No filters if the user is not allowed to post anything.
    if (empty($links)) {
      return [];
    }

    $pattern = match(count($links)) {
      3 => 'Create a new @link1, a new @link2 or a new @link3',
      2 => 'Create a new @link1 or a new @link2',
      1 => 'Create a new @link1',
    };

    $replacements = [];
    foreach (array_values($links) as $index => $link) {
      $replacements['@link' . ($index + 1)] = $link->toString();
    }

    // Add intro.
    $form['intro'] = [
      '#weight' => -100,
      '#markup' => new FormattableMarkup('<div class="rw-moderation-intro">' . $pattern . '</div>', $replacements),
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

    if (!empty($form['filters']['other']['bundle']['#options'])) {
      $form['filters']['other']['bundle']['#options'] = array_intersect_key($form['filters']['other']['bundle']['#options'], $links);
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
