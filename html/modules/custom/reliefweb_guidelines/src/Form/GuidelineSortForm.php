<?php

namespace Drupal\reliefweb_guidelines\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Sort guideline nodes under a guideline list.
 */
class GuidelineSortForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The guideline list term.
   *
   * @var \Drupal\taxonomy\TermInterface
   */
  protected TermInterface $term;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
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
  public function buildForm(array $form, FormStateInterface $form_state, ?GuidelineList $taxonomy_term = NULL) {
    $this->term = $taxonomy_term;
    $form['#title'] = $this->t('Sort guidelines under %list', [
      '%list' => $taxonomy_term->label(),
    ]);

    /** @var \Drupal\reliefweb_guidelines\Entity\Node\Guideline[] $children */
    $children = $taxonomy_term->getChildren();
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
      $form['items'][$id]['#weight'] = (int) $child->field_weight->value;

      // Label.
      $form['items'][$id]['label'] = $child
        ?->toLink($child->label(), 'edit-form')
        ?->toRenderable();

      // Weight.
      $form['items'][$id]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @title', [
          '@title' => $child->label(),
        ]),
        '#title_display' => 'invisible',
        '#default_value' => (int) $child->field_weight->value,
        '#attributes' => [
          'class' => [$group_class],
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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\reliefweb_guidelines\Entity\Node\Guideline[] $children */
    $children = $this->term->getChildren();
    $new_weights = $form_state->getValue('items');

    foreach ($children as $child) {
      if (!isset($new_weights[$child->id()])) {
        continue;
      }
      $weight = (int) $new_weights[$child->id()]['weight'];
      if ((int) $child->field_weight->value !== $weight) {
        $child->set('field_weight', $weight);
        $child->setNewRevision(FALSE);
        // @todo see if we can preserve the previous revision user
        // and timestamp.
        $child->save();
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
   * @param \Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList $taxonomy_term
   *   The guideline list term the form is for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public static function checkAccess(AccountInterface $account, GuidelineList $taxonomy_term) {
    return AccessResult::allowedIf($taxonomy_term->bundle() === 'guideline_list');
  }

}
