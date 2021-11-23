<?php

namespace Drupal\guidelines\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for Guideline edit forms.
 *
 * @ingroup guidelines
 */
class GuidelineSortForm extends ContentEntityForm {

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
  public function getFormId() {
    return 'guideline_sort_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\guidelines\Entity\Guideline $guideline */
    $guideline = $this->entity;

    $children = $guideline->getChildren();
    if (empty($children)) {
      return;
    }

    $items = [];
    foreach ($children as $child) {
      $data = [];
      $data['id'] = $child->id();
      $data['label'] = Link::createFromRoute(
        $child->label(),
        'entity.guideline.canonical',
        ['guideline' => $child->id()]
      )->toString();

      $parents = [];
      $parent_entities = $child->getParents();
      foreach ($parent_entities as $parent_entity) {
        $parents[] = Link::createFromRoute(
          $parent_entity->label(),
          'entity.guideline.canonical',
          ['guideline' => $parent_entity->id()]
        )->toString();
      }

      $data['parent'] = implode(', ', $parents);
      $data['weight'] = $child->getWeight();

      $items[$child->id()] = $data;
    }

    // Build table.
    $group_class = 'group-order-weight';
    $form['items'] = [
      '#type' => 'table',
      '#caption' => $this->t('Items'),
      '#header' => [
        $this->t('ID'),
        $this->t('Label'),
        $this->t('Parent(s)'),
        $this->t('Weight'),
      ],
      '#empty' => $this->t('No items.'),
      '#tableselect' => FALSE,
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => $group_class,
        ],
      ],
    ];

    // Build rows.
    foreach ($items as $key => $value) {
      $form['items'][$key]['#attributes']['class'][] = 'draggable';
      $form['items'][$key]['#weight'] = $value['weight'];

      // Id.
      $form['items'][$key]['id'] = [
        '#plain_text' => $value['id'],
      ];

      // Label.
      $form['items'][$key]['label'] = [
        '#markup' => $value['label'],
      ];

      // Parent(s).
      $form['items'][$key]['parent'] = [
        '#markup' => $value['parent'],
      ];

      // Weight.
      $form['items'][$key]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @title', ['@title' => $value['label']]),
        '#title_display' => 'invisible',
        '#default_value' => $value['weight'],
        '#attributes' => ['class' => [$group_class]],
      ];
    }

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(FieldableEntityInterface $entity, array &$form, FormStateInterface $form_state) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\guidelines\Entity\Guideline $guideline */
    $guideline = $this->entity;

    $children = $guideline->getChildren();
    $new_weights = $form_state->getValue('items');

    foreach ($children as $child) {
      if (isset($new_weights[$child->id()])) {
        if ($child->getWeight() !== $new_weights[$child->id()]['weight']) {
          $child->setWeight($new_weights[$child->id()]['weight']);
          $child->save();
        }
      }
    }
  }

}
