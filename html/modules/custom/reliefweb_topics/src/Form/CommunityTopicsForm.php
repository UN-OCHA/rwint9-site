<?php

namespace Drupal\reliefweb_topics\Form;

use Drupal\Core\State\State;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manage subscription for user.
 */
class CommunityTopicsForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;


  /**
   * The state store.
   *
   * @var Drupal\Core\State\State
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database, State $state) {
    $this->database = $database;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'community_topics_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, AccountInterface $user = NULL) {
    $links = $this->getCommunityTopics();

    // Data to pass to the javascript.
    $data = [
      'placeholders' => [
        'url' => $this->t('External URL (must start with http or https)'),
        'title' => $this->t('Link title'),
        'description' => $this->t('Description'),
      ],
      'links' => $links,
      'settings' => [],
    ];

    $form['container'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Community topics'),
      '#attributes' => [
        'data-settings-validate-url' => '/admin/community-topics/validate',
      ],
      '#optional' => FALSE,
    ];

    // We wrap the submit button into a fieldset so that it can easily be
    // disabled using HTML5 <fieldset disabled> feature but we don't use the
    // Drupal Form API to create a fieldset to avoid useless markup and classes.
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Save',
      '#title' => $this->t('Save changes'),
      '#name' => 'save',
      '#prefix' => '<fieldset id="actions" disabled>',
      '#suffix' => '</fieldset>',
    ];

    // Placeholder for the topics data. This will be filled with a json encoded
    // list of users for each field by the javascript script when submitting.
    $form['data'] = [
      '#type' => 'hidden',
      '#value' => json_encode($data),
      '#attributes' => [],
    ];

    $form['#attached']['library'][] = 'reliefweb_topics/reliefweb-topics-admin';

    // Redirect to the topics landing page after submission.
    $form['#redirect'] = '/topics';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $links = $form_state->getValue('data');
    $links = json_decode($links);
    $this->setCommunityTopics($links);
  }

  /**
   * Get all community topics.
   */
  protected function getCommunityTopics() {
    return $this->state->get('reliefweb_topics', []);
  }

  /**
   * Set all community topics.
   */
  protected function setCommunityTopics($topics) {
    return $this->state->set('reliefweb_topics', $topics);
  }

}
