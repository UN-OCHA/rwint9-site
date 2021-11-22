<?php

namespace Drupal\guidelines\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
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

    if (!$this->entity->isNew()) {
      $form['new_revision'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Create new revision'),
        '#default_value' => FALSE,
        '#weight' => 10,
      ];
    }

    $guideline_storage = $this->entityTypeManager->getStorage('guideline');
    $guidelines = $guideline_storage->loadMultiple();
    $guideline_options = [
      '<' . $this->t('root') . '>',
    ];

    foreach ($guidelines as $guideline) {
      if ($guideline->id() != $this->entity->id()) {
        $guideline_options[$guideline->id()] = $guideline->label();
      }
    }

    $form['relations'] = [
      '#type' => 'details',
      '#title' => $this->t('Relations'),
      '#open' => count($guideline_options) > 1,
      '#weight' => 10,
    ];

    $form['relations']['parent'] = [
      '#type' => 'select',
      '#title' => $this->t('Parent guideline(s)'),
      '#options' => $guideline_options,
      '#default_value' => $this->entity->getParents(),
      '#multiple' => TRUE,
    ];

    $form['relations']['weight'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Weight'),
      '#size' => 6,
      '#default_value' => $this->entity->getWeight(),
      '#description' => $this->t('Guilines are displayed in ascending order by weight.'),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Ensure numeric values.
    if ($form_state->hasValue('weight') && !is_numeric($form_state->getValue('weight'))) {
      $form_state->setErrorByName('weight', $this->t('Weight value must be numeric.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $guideline = parent::buildEntity($form, $form_state);

    // Prevent leading and trailing spaces in guideline names.
    $guideline->setName(trim($guideline->getName()));

    // Assign parents with proper delta values starting from 0.
    $guideline->parent = array_values($form_state->getValue('parent'));

    return $guideline;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditedFieldNames(FormStateInterface $form_state) {
    return array_merge(['parent', 'weight'], parent::getEditedFieldNames($form_state));
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $entity = $this->entity;

    // Save as a new revision if requested to do so.
    if (!$form_state->isValueEmpty('new_revision') && $form_state->getValue('new_revision') != FALSE) {
      $entity->setNewRevision();

      // If a new revision is created, save the current user as revision author.
      $entity->setRevisionCreationTime($this->time->getRequestTime());
      $entity->setRevisionUserId($this->account->id());
    }
    else {
      $entity->setNewRevision(FALSE);
    }

    $current_parent_count = count($form_state->getValue('parent'));
    // Root doesn't count if it's the only parent.
    if ($current_parent_count == 1 && $form_state->hasValue(['parent', 0])) {
      $form_state->setValue('parent', []);
    }

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
