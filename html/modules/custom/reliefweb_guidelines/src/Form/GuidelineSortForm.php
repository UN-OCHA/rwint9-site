<?php

namespace Drupal\reliefweb_guidelines\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
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

    $form['#title'] = $this->t('Sort guidelines under %list', [
      '%list' => $guideline->label(),
    ]);

    $children = $guideline->getChildren();
    if (empty($children)) {
      return;
    }

    // Build table.
    $group_class = 'group-order-weight';
    $form['items'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Guideline'),
        $this->t('Weight'),
      ],
      '#empty' => $this->t('No items.'),
      '#tableselect' => FALSE,
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => $group_class,
          'hidden' => TRUE,
        ],
      ],
    ];

    // Build rows.
    foreach ($children as $child) {
      $id = $child->id();

      $form['items'][$id]['#attributes']['class'][] = 'draggable';
      $form['items'][$id]['#weight'] = $child->getWeight();

      // Label.
      $form['items'][$id]['label'] = [
        '#markup' => $child->toLink($child->label(), 'edit-form')->toString(),
      ];

      // Weight.
      $form['items'][$id]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @title', [
          '@title' => $child->label(),
        ]),
        '#title_display' => 'invisible',
        '#default_value' => $child->getWeight(),
        '#attributes' => [
          'class' => [
            $group_class,
          ],
        ],
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
