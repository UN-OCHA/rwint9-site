<?php

namespace Drupal\reliefweb_guidelines\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Sort guideline lists.
 */
class GuidelineListSortForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager
  ) {
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
    return 'guideline_list_sort_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\reliefweb_guidelines\Entity\GuidelineList[] $entities */
    $entities = $this->entityTypeManager
      ->getStorage('guideline')
      ->loadByProperties([
        'type' => 'guideline_list',
      ]);

    $form['#title'] = $this->t('Sort guideline lists');

    if (empty($entities)) {
      $form['empty'] = [
        '#markup' => $this->t('No guideline lists'),
      ];
      return $form;
    }

    // Sort the guideline lists by weight.
    uasort($entities, function ($a, $b) {
      return $a->getWeight() <=> $b->getWeight();
    });

    // Build table.
    $group_class = 'group-order-weight';
    $form['order'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Guideline list'),
        $this->t('Weight'),
      ],
      '#empty' => $this->t('No guideline lists.'),
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
    foreach ($entities as $entity) {
      $id = $entity->id();

      $form['order'][$id]['#attributes']['class'][] = 'draggable';
      $form['order'][$id]['#weight'] = $entity->getWeight();

      // Label.
      $form['order'][$id]['label'] = [
        '#markup' => $entity->toLink($entity->label(), 'edit-form')->toString(),
      ];

      // Weight.
      $form['order'][$id]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @title', [
          '@title' => $entity->label(),
        ]),
        '#title_display' => 'invisible',
        '#default_value' => $entity->getWeight(),
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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\reliefweb_guidelines\Entity\GuidelineList[] $entities */
    $entities = $this->entityTypeManager
      ->getStorage('guideline')
      ->loadByProperties([
        'type' => 'guideline_list',
      ]);

    $order = $form_state->getValue('order');

    foreach ($entities as $entity) {
      $id = $entity->id();
      if (isset($order[$id]['weight'])) {
        if ($entity->getWeight() !== $order[$id]['weight']) {
          $entity->setWeight($order[$id]['weight']);
          $entity->setNewRevision(FALSE);
          // @todo see if we can preserve the previous revision user
          // and timestamp.
          $entity->save();
        }
      }
    }

    // Show the user a message.
    $this->messenger()->addMessage($this->t('Guideline lists successfully re-ordered.'), MessengerInterface::TYPE_STATUS);

    // Redirect to the moderation backend.
    $form_state->setRedirectUrl(Url::fromRoute('reliefweb_moderation.content', [
      'service' => 'guideline_list',
    ]));
  }

}
