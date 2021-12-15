<?php

namespace Drupal\reliefweb_guidelines\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the guidelines.
 */
class GuidelineSinglePageController extends ControllerBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The ReliefWeb API client.
   *
   * @var \Drupal\reliefweb_api\Services\ReliefWebApiClient
   */
  protected $reliefWebApiClient;

  /**
   * The drupal renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\reliefweb_api\Services\ReliefWebApiClient $reliefweb_api_client
   *   The reliefweb api client service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    ReliefWebApiClient $reliefweb_api_client,
    RendererInterface $renderer,
    StateInterface $state
  ) {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->reliefWebApiClient = $reliefweb_api_client;
    $this->renderer = $renderer;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('reliefweb_api.client'),
      $container->get('renderer'),
      $container->get('state')
    );
  }

  /**
   * Redirect based on guideline Id.
   */
  public function redirectToPage($guideline_id) {
    $storage = $this->entityTypeManager->getStorage('guideline');
    /** @var \Drupal\guidelines\Entity\Guideline $guideline */
    $guideline = $storage->load($guideline_id);

    if (!$guideline) {
      return $this->redirect('reliefweb_guidelines.guidelines');
    }

    if ($guideline->hasField('field_short_link')) {
      return $this->redirect('reliefweb_guidelines.guidelines', [], [
        'fragment' => $guideline->field_short_link->value,
      ]);
    }

    return $this->redirect('reliefweb_guidelines.guidelines');
  }

  /**
   * Get the page content.
   *
   * @return array
   *   Render array for the homepage.
   */
  public function getPageContent() {
    $storage = $this->entityTypeManager->getStorage('guideline');

    $ids = $storage
      ->getQuery()
      ->sort('id', 'ASC')
      ->execute();

    /** @var \Drupal\guidelines\Entity\Guideline[] $guidelines */
    $guidelines = $storage->loadMultiple($ids);

    $items = [];

    foreach ($guidelines as $guideline) {
      if ($parents = $guideline->getParentIds()) {
        $items[$parents[0]]['#children'][] = [
          '#theme' => 'reliefweb_guidelines_item',
          '#id' => $guideline->hasField('field_short_link') ? $guideline->field_short_link->value : Html::getUniqueId($guideline->field_title->value),
          '#title' => $guideline->field_title->value,
          '#title_prefix' => $guidelines[$parents[0]]->field_title->value . ' > ',
          '#description' => $this->cleanAtags($guideline->hasField('field_description') ? check_markup($guideline->field_description->value, $guideline->field_description->format) : '', $guidelines),
        ];
      }
      else {
        $items[$guideline->id()] = [
          '#theme' => 'reliefweb_guidelines_item',
          '#id' => $guideline->hasField('field_short_link') ? $guideline->field_short_link->value : Html::getUniqueId($guideline->field_title->value),
          '#title' => $guideline->field_title->value,
          '#title_prefix' => '',
          '#description' => $this->cleanAtags($guideline->hasField('field_description') ? check_markup($guideline->field_description->value, $guideline->field_description->format) : '', $guidelines),
          '#children' => [],
        ];
      }
    }

    $build = [
      '#theme' => 'reliefweb_guidelines_list',
      '#title' => $this->t('ReliefWeb guidelines'),
      '#guidelines' => $items,
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];

    $build['#attached']['library'][] = 'reliefweb_guidelines/reliefweb-guidelines';

    return $build;
  }

  /**
   * Clean A-tags.
   */
  protected function cleanAtags($text, $guidelines) {
    if (empty($text)) {
      return $text;
    }

    $pattern = '/\/admin\/structure\/guideline\/([0-9]+)/i';
    $text = preg_replace_callback($pattern, function ($matches) use ($guidelines) {
      return '#' . $guidelines[$matches[1]]->field_short_link->value;
    }, $text);

    return $text;
  }

}
