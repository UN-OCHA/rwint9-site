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
    return 'reliefweb_moderation_page_filter_form';
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

    $form['intro'] = [
      '#weight' => -100,
      '#markup' => new FormattableMarkup('<div class="rw-moderation-intro">' . $links . '</div>', []),
    ];

    return $form;
  }

}
