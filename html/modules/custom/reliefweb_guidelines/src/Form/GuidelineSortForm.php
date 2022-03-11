<?php

namespace Drupal\reliefweb_guidelines\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\guidelines\Entity\GuidelineInterface;
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
      $form['empty'] = [
        '#markup' => $this->t('No guideline children'),
      ];
      return $form;
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
          $child->setNewRevision(FALSE);
          // @todo see if we can preserve the previous revision user
          // and timestamp.
          $child->save();
        }
      }
    }

    // Show the user a message.
    $this->messenger()->addMessage($this->t('Guidelines successfully re-ordered.'), MessengerInterface::TYPE_STATUS);
  }

  /**
   * Check if the form can be accessed.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   * @param \Drupal\guidelines\Entity\GuidelineInterface $guideline
   *   The guideline entity the form is for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public static function checkAccess(AccountInterface $account, GuidelineInterface $guideline) {
    return AccessResult::allowedIf($guideline->bundle() === 'guideline_list');
  }

}
