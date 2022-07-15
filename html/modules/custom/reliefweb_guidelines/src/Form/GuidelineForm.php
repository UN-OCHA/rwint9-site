<?php

namespace Drupal\reliefweb_guidelines\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Http\RequestStack;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for Guideline edit forms.
 *
 * @ingroup guidelines
 */
class GuidelineForm extends ContentEntityForm {

  /**
   * The tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * The Current User object.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The request stack.
   *
   * @var \Drupal\Core\Http\RequestStack
   */
  protected $requestStack;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    PrivateTempStoreFactory $temp_store_factory,
    AccountProxyInterface $current_user,
    RequestStack $request_stack,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->tempStoreFactory = $temp_store_factory;
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('tempstore.private'),
      $container->get('current_user'),
      $container->get('request_stack'),
    );
  }

  /**
   * Get the current user.
   *
   * @return \Drupal\Core\Session\AccountProxyInterface
   *   The current user.
   */
  protected function currentUser() {
    return $this->currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Try to restore from temp store, this must be done before calling
    // parent::form().
    $store = $this->tempStoreFactory->get('guideline_preview');

    // Attempt to load from preview when the uuid is present unless we are
    // rebuilding the form.
    $request_uuid = $this->requestStack->getCurrentRequest()->query->get('uuid');
    if (!$form_state->isRebuilding() && $request_uuid && $preview = $store->get($request_uuid)) {
      /** @var \Drupal\Core\Form\FormStateInterface $preview */

      $form_state->setStorage($preview->getStorage());
      $form_state->setUserInput($preview->getUserInput());

      // Rebuild the form.
      $form_state->setRebuild();

      // The combination of having user input and rebuilding the form means
      // that it will attempt to cache the form state which will fail if it is
      // a GET request.
      $form_state->setRequestMethod('POST');

      $this->entity = $preview->getFormObject()->getEntity();
      $this->entity->in_preview = NULL;

      $form_state->set('has_been_previewed', TRUE);
    }

    /** @var \Drupal\guidelines\Entity\Guideline $this->entity */
    $form = parent::buildForm($form, $form_state);

    // Only add a field to select the parent the guideline entries.
    if ($this->entity->bundle() === 'field_guideline') {
      $guideline_storage = $this->entityTypeManager->getStorage('guideline');
      $guidelines = $guideline_storage->loadByProperties([
        'type' => 'guideline_list',
      ]);

      $guideline_options = [];
      foreach ($guidelines as $guideline) {
        $guideline_options[$guideline->id()] = $guideline->label();
      }

      // Url to create a new guideline list.
      $new_list_url = Url::fromRoute('entity.guideline.add_form', [
        'guideline_type' => 'guideline_list',
      ], [
        'attributes' => [
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
        ],
      ]);

      $form['relations'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Relations'),
        '#title_display' => 'invisible',
        '#weight' => 10,
      ];

      $form['relations']['parent'] = [
        '#type' => 'select',
        '#title' => $this->t('Guideline list'),
        '#options' => $guideline_options,
        '#default_value' => $this->entity->parent?->target_id ?? NULL,
        '#required' => TRUE,
        '#empty_value' => '_none',
        '#empty_option' => $this->t('- Select -'),
        '#multiple' => FALSE,
        '#description' => $this->t('Select the list this guideline belongs to. @new_list_link (and reload the form after).', [
          '@new_list_link' => Link::fromTextAndUrl($this->t('Create a new list'), $new_list_url)->toString(),
        ]),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $guideline = parent::buildEntity($form, $form_state);

    // Prevent leading and trailing spaces in guideline names.
    $guideline->setName(trim($guideline->getName()));

    // The parent field expects an array.
    $parent = $form_state->getValue('parent');
    if (empty($parent)) {
      $guideline->parent = [];
    }
    elseif (is_scalar($parent)) {
      $guideline->parent = [$parent];
    }

    return $guideline;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditedFieldNames(FormStateInterface $form_state) {
    if ($this->entity->bundle() === 'field_guideline') {
      return array_merge(['parent'], parent::getEditedFieldNames($form_state));
    }
    else {
      return parent::getEditedFieldNames($form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $element = parent::actions($form, $form_state);
    $guideline = $this->entity;
    $preview_mode = $guideline->type->entity->getPreviewMode();

    $element['submit']['#access'] = $preview_mode != DRUPAL_REQUIRED || $form_state->get('has_been_previewed');

    $element['preview'] = [
      '#type' => 'submit',
      '#access' => $preview_mode != DRUPAL_DISABLED && ($guideline->access('create') || $guideline->access('update')),
      '#value' => $this->t('Preview'),
      '#weight' => 20,
      '#submit' => ['::submitForm', '::preview'],
    ];

    if (array_key_exists('delete', $element)) {
      $element['delete']['#weight'] = 100;
    }

    return $element;
  }

  /**
   * Form submission handler for the 'preview' action.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function preview(array $form, FormStateInterface $form_state) {
    $store = $this->tempStoreFactory->get('guideline_preview');
    $this->entity->in_preview = TRUE;
    $store->set($this->entity->uuid(), $form_state);

    $route_parameters = [
      'guideline_preview' => $this->entity->uuid(),
      'view_mode_id' => 'full',
    ];

    $options = [];
    $query = $this->getRequest()->query;
    if ($query->has('destination')) {
      $options['query']['destination'] = $query->get('destination');
      $query->remove('destination');
    }
    $form_state->setRedirect('entity.guideline.preview', $route_parameters, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $entity = $this->entity;

    // Always create a new revision.
    $entity->setNewRevision(TRUE);
    $entity->setRevisionCreationTime($this->time->getRequestTime());
    $entity->setRevisionUserId($this->currentUser()->id());

    $status = parent::save($form, $form_state);

    switch ($status) {
      case SAVED_NEW:
        $this->messenger()->addMessage($this->t('Created the %label Guideline.', [
          '%label' => $entity->label(),
        ]));
        break;

      default:
        $this->messenger()->addMessage($this->t('Saved the %label Guideline.', [
          '%label' => $entity->label(),
        ]));
    }

    $form_state->setRedirect('entity.guideline.canonical', ['guideline' => $entity->id()]);

    // Remove the preview entry from the temp store, if any.
    $store = $this->tempStoreFactory->get('guideline_preview');
    $store->delete($entity->uuid());
  }

}
