<?php

namespace Drupal\reliefweb_guidelines\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for Guideline edit forms.
 *
 * @ingroup guidelines
 */
class GuidelineForm extends ContentEntityForm {

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $account;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    $instance = parent::create($container);
    $instance->account = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
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
  public function save(array $form, FormStateInterface $form_state) {
    $entity = $this->entity;

    // Always create a new revision.
    $entity->setNewRevision(TRUE);
    $entity->setRevisionCreationTime($this->time->getRequestTime());
    $entity->setRevisionUserId($this->account->id());

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
  }

}
