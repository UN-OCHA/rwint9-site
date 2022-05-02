<?php

namespace Drupal\reliefweb_topics\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\State\StateInterface;
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
   * @var Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database, StateInterface $state) {
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
        'description' => $this->t('Short description of topic page (optional)'),
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

    $form['actions'] = [
      '#type' => 'actions',
      '#theme_wrappers' => [
        'fieldset' => [
          '#id' => 'actions',
          '#title' => $this->t('Form actions'),
          '#title_display' => 'invisible',
        ],
      ],
      '#weight' => 99,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save changes'),
      '#name' => 'save',
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
    $links = $form_state->getUserInput()['data'];
    $links = json_decode($links);
    $this->setCommunityTopics($links);
  }

  /**
   * Get all community topics.
   */
  protected function getCommunityTopics() {
    return $this->state->get('reliefweb_topics_community_topics', []);
  }

  /**
   * Set all community topics.
   */
  protected function setCommunityTopics($topics) {
    $this->state->set('reliefweb_topics_community_topics', $topics);
    Cache::invalidateTags(['reliefweb_community_topics']);
  }

}
