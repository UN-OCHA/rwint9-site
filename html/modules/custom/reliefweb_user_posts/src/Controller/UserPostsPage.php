<?php

namespace Drupal\reliefweb_user_posts\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\Controller\ModerationPage;
use Drupal\reliefweb_user_posts\Services\UserPostsJobService;
use Drupal\reliefweb_user_posts\Services\UserPostsReportService;
use Drupal\reliefweb_user_posts\Services\UserPostsServiceBase;
use Drupal\reliefweb_user_posts\Services\UserPostsTrainingService;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * User posts controller.
 */
class UserPostsPage extends ModerationPage {

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * User posts services keyed by bundle.
   *
   * @var \Drupal\reliefweb_user_posts\Services\UserPostsServiceBase[]
   */
  protected array $bundleServices;

  /**
   * Report service used for bundle availability lookups.
   *
   * @var \Drupal\reliefweb_user_posts\Services\UserPostsReportService
   */
  protected UserPostsReportService $reportService;

  /**
   * Cached available bundles for the current profile user.
   *
   * @var string[]|null
   */
  protected ?array $availableBundlesCache = NULL;

  /**
   * User ID for the cached available bundles.
   *
   * @var int|null
   */
  protected ?int $availableBundlesUserId = NULL;

  /**
   * Constructs a UserPostsPage object.
   *
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   * @param \Drupal\reliefweb_user_posts\Services\UserPostsReportService $report_service
   *   User posts report service.
   * @param \Drupal\reliefweb_user_posts\Services\UserPostsJobService $job_service
   *   User posts job service.
   * @param \Drupal\reliefweb_user_posts\Services\UserPostsTrainingService $training_service
   *   User posts training service.
   */
  public function __construct(
    FormBuilderInterface $form_builder,
    UserPostsReportService $report_service,
    UserPostsJobService $job_service,
    UserPostsTrainingService $training_service,
  ) {
    $this->formBuilder = $form_builder;
    $this->reportService = $report_service;
    $this->bundleServices = [
      'report' => $report_service,
      'job' => $job_service,
      'training' => $training_service,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder'),
      $container->get('reliefweb_moderation.user_posts_report.moderation'),
      $container->get('reliefweb_moderation.user_posts_job.moderation'),
      $container->get('reliefweb_moderation.user_posts_training.moderation'),
    );
  }

  /**
   * Get the page title.
   *
   * @param \Drupal\user\UserInterface $user
   *   User account.
   *
   * @return string|\Drupal\Component\Render\MarkupInterface
   *   Page title.
   */
  public function getTitle(UserInterface $user) {
    if ($user->id() == $this->currentUser()->id()) {
      return $this->t('My posts');
    }
    return $this->t("@name's posts", [
      '@name' => $user->label(),
    ]);
  }

  /**
   * Overview page with per-type stats.
   *
   * @param \Drupal\user\UserInterface $user
   *   User account.
   *
   * @return array
   *   Render array.
   */
  public function getOverview(UserInterface $user) {
    $bundles = $this->getAvailableBundles($user);
    $node_list_tags = [
      'node_list:report',
      'node_list:job',
      'node_list:training',
    ];

    if (empty($bundles)) {
      return [
        '#theme' => 'reliefweb_user_posts_overview',
        '#tabs' => [],
        '#sections' => [],
        '#empty' => $this->t('No post types are available for this account.'),
        '#cache' => [
          'contexts' => ['user'],
          'tags' => array_merge(['user:' . $user->id()], $node_list_tags),
        ],
      ];
    }

    $sections = [];
    foreach ($bundles as $bundle) {
      $service = $this->getBundleService($bundle);
      $stats = $service->getOverviewStats();
      $sections[$bundle] = [
        'bundle' => $bundle,
        'label' => UserPostsServiceBase::getBundleLabel($bundle),
        'mine' => $stats['mine'],
        'colleagues' => $stats['colleagues'],
        'organizations' => $stats['organizations'],
        'url' => Url::fromRoute('reliefweb_user_posts.content.bundle', [
          'user' => $user->id(),
          'bundle' => $bundle,
        ])->toString(),
        'view_label' => match ($bundle) {
          'job' => $this->t('View jobs'),
          'training' => $this->t('View training'),
          default => $this->t('View reports'),
        },
      ];
    }

    return [
      '#theme' => 'reliefweb_user_posts_overview',
      '#intro' => $this->buildCreateIntro($user),
      '#tabs' => $this->getNavigationTabs($user),
      '#sections' => $sections,
      '#empty' => NULL,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => array_merge(['user:' . $user->id()], $node_list_tags),
      ],
    ];
  }

  /**
   * Get the posts list page for a content type.
   *
   * @param \Drupal\user\UserInterface $user
   *   User account.
   * @param string $bundle
   *   Content bundle.
   *
   * @return array
   *   Render array.
   */
  public function getContent(UserInterface $user, string $bundle) {
    $this->assertBundleAvailable($user, $bundle);
    $service = $this->getBundleService($bundle);

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$service, $user, $bundle]);
    $form_state->setMethod('GET');
    $form_state->setProgrammed(TRUE);
    $form_state->setProcessInput(TRUE);
    $form_state->disableCache();

    $form = $this->formBuilder
      ->buildForm('\Drupal\reliefweb_user_posts\Form\UserPostsPageFilterForm', $form_state);

    $filters = [];
    if (!$form_state->getErrors()) {
      $definitions = $service->getFilterDefinitions();

      $values = $form_state->getValues();
      $input = $form_state->getUserInput();

      $filters = $values['filters'] ?? [];

      // Select multiple submits a list of values; filter parsing expects
      // value => selected flag maps like checkboxes.
      if (!empty($filters['source']) && is_array($filters['source']) && array_is_list($filters['source'])) {
        $filters['source'] = array_fill_keys(array_filter($filters['source'], static function ($value) {
          return $value !== '' && $value !== NULL;
        }), 1);
      }

      // Drop empty text filters so they do not become LIKE '%%'.
      foreach (['title', 'nid'] as $text_filter) {
        if (isset($filters[$text_filter]) && !is_array($filters[$text_filter]) && trim((string) $filters[$text_filter]) === '') {
          unset($filters[$text_filter]);
        }
      }

      // Convert from/to date fields to the unix keys filterQuery expects.
      foreach (['created', 'deadline'] as $date_filter) {
        if (!isset($filters[$date_filter]) || !is_array($filters[$date_filter])) {
          continue;
        }
        if (!array_key_exists('from', $filters[$date_filter]) && !array_key_exists('to', $filters[$date_filter])) {
          continue;
        }
        $normalized = $this->normalizeDateRangeFilter($filters[$date_filter]);
        if ($normalized === NULL) {
          unset($filters[$date_filter]);
        }
        else {
          $filters[$date_filter] = $normalized;
        }
      }

      // Legacy omnibox selection query params from bookmarked URLs.
      if (!empty($input['selection'])) {
        foreach ($input['selection'] as $filter => $items) {
          if (isset($definitions[$filter]['widget'])) {
            $widget = $definitions[$filter]['widget'];
            foreach ($items as $item) {
              if ($widget === 'search') {
                $value = $item;
              }
              else {
                [$value] = explode(':', $item, 2);
              }
              $filters[$filter][$value] = 1;
            }
          }
        }
      }
    }

    $tabs = $this->getNavigationTabs($user, $bundle);

    return [
      '#theme' => 'reliefweb_user_posts_page',
      '#tabs' => $tabs,
      '#intro' => $this->buildCreateIntro($user),
      '#filters' => $form,
      '#list' => $service->getTable($filters, 30),
    ];
  }

  /**
   * Build the create-new content intro for a My posts page.
   *
   * @param \Drupal\user\UserInterface $user
   *   Profile user.
   *
   * @return array|null
   *   Render array, or NULL when the user cannot create any post type.
   */
  protected function buildCreateIntro(UserInterface $user): ?array {
    $labels = [
      'job' => $this->t('Job vacancy'),
      'training' => $this->t('Training program'),
      'report' => $this->t('Report'),
    ];

    $links = [];
    foreach ($labels as $bundle => $label) {
      $url = Url::fromRoute('node.add', ['node_type' => $bundle], [
        'attributes' => ['target' => '_blank'],
      ]);
      if ($url->access($user)) {
        $links[] = Link::fromTextAndUrl($label, $url)->toString();
      }
    }

    if (empty($links)) {
      return NULL;
    }

    $replacements = [];
    foreach ($links as $index => $link) {
      $replacements['@link' . ($index + 1)] = $link;
    }

    $markup = match (count($links)) {
      3 => $this->t('Create a new @link1, a new @link2 or a new @link3', $replacements),
      2 => $this->t('Create a new @link1 or a new @link2', $replacements),
      default => $this->t('Create a new @link1', $replacements),
    };

    return [
      '#prefix' => '<div class="rw-user-posts-intro__content">',
      '#markup' => $markup,
      '#suffix' => '</div>',
    ];
  }

  /**
   * Return suggestions for the autocomplete widget.
   *
   * @param string $bundle
   *   Content bundle.
   * @param string $filter
   *   Filter name.
   * @param \Drupal\user\UserInterface|null $user
   *   User account for the user posts page.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the list of suggestions if any.
   */
  public function autocompleteFilter(string $bundle, string $filter, ?UserInterface $user = NULL) {
    if ($user === NULL) {
      throw new NotFoundHttpException();
    }
    $this->assertBundleAvailable($user, $bundle);
    $service = $this->getBundleService($bundle);
    $suggestions = $service->getAutocompleteSuggestions($filter);
    return new JsonResponse($suggestions);
  }

  /**
   * Check the access to the page.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account to check access for.
   * @param \Drupal\user\UserInterface $user
   *   User account for the user posts page.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkUserPostsPageAccess(AccountInterface $account, UserInterface $user) {
    if ($account->id() == $user->id()) {
      return AccessResult::allowedIf($account->hasPermission('view own posts'));
    }
    return AccessResult::allowedIf($account->hasPermission('view other user posts'));
  }

  /**
   * Check the access to the page for the current user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkCurrentUserPostsPageAccess(AccountInterface $account) {
    return AccessResult::allowedIf($account->hasPermission('view own posts'));
  }

  /**
   * Redirect the current user to their posts page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirection response.
   */
  public function currentUserPostsPage() {
    return $this->redirect('reliefweb_user_posts.content', [
      'user' => $this->currentUser()->id(),
    ], [], 301);
  }

  /**
   * Redirect the current user to a bundle posts page.
   *
   * @param string $bundle
   *   Content bundle.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirection response.
   */
  public function currentUserPostsBundlePage(string $bundle) {
    return $this->redirect('reliefweb_user_posts.content.bundle', [
      'user' => $this->currentUser()->id(),
      'bundle' => $bundle,
    ], [], 301);
  }

  /**
   * Normalize a from/to date filter into datepicker filterQuery format.
   *
   * @param array $range
   *   Values with optional 'from' and 'to' keys as YYYY/MM/DD strings.
   *
   * @return array|null
   *   Map of "{start_unix_date}-{end_unix_date}" => 1, or NULL when both ends
   *   empty/invalid.
   */
  protected function normalizeDateRangeFilter(array $range): ?array {
    $from = is_string($range['from'] ?? NULL) ? trim($range['from']) : '';
    $to = is_string($range['to'] ?? NULL) ? trim($range['to']) : '';

    $start = $this->parseFilterDateToUnix($from);
    $end = $this->parseFilterDateToUnix($to);

    if ($start === NULL && $end === NULL) {
      return NULL;
    }

    // Match omnibox behavior: allow open-ended ranges; swap if inverted.
    if ($start !== NULL && $end !== NULL && $start > $end) {
      [$start, $end] = [$end, $start];
    }

    $key = ($start ?? '') . '-' . ($end ?? '');
    return [$key => 1];
  }

  /**
   * Parse a YYYY/MM/DD (or YYYY-MM-DD) date string to a UTC unix timestamp.
   *
   * @param string $date
   *   Date string.
   *
   * @return int|null
   *   Unix timestamp or NULL if invalid/empty.
   */
  protected function parseFilterDateToUnix(string $date): ?int {
    if ($date === '') {
      return NULL;
    }
    if (!preg_match('#^(\d{4})[/-](\d{2})[/-](\d{2})$#', $date, $matches)) {
      return NULL;
    }
    $timestamp = gmmktime(0, 0, 0, (int) $matches[2], (int) $matches[3], (int) $matches[1]);
    return $timestamp === FALSE ? NULL : $timestamp;
  }

  /**
   * Get the navigation tabs for the user posts pages.
   *
   * @param \Drupal\user\UserInterface $user
   *   User.
   * @param string|null $selected_bundle
   *   Currently selected content type, or NULL for overview.
   *
   * @return array
   *   Render array for the navigation tabs.
   */
  protected function getNavigationTabs(UserInterface $user, ?string $selected_bundle = NULL) {
    $bundles = $this->getAvailableBundles($user);
    if (empty($bundles)) {
      return [];
    }

    $tabs = [];
    $tabs['overview'] = [
      'url' => Url::fromRoute('reliefweb_user_posts.content', [
        'user' => $user->id(),
      ])->toString(),
      'title' => $this->t('Overview'),
      'selected' => $selected_bundle === NULL,
    ];

    foreach ($bundles as $bundle) {
      $tabs[$bundle] = [
        'url' => Url::fromRoute('reliefweb_user_posts.content.bundle', [
          'user' => $user->id(),
          'bundle' => $bundle,
        ])->toString(),
        'title' => UserPostsServiceBase::getBundleLabel($bundle),
        'selected' => $bundle === $selected_bundle,
      ];
    }

    return [
      '#theme' => 'reliefweb_rivers_views',
      '#title' => $this->t('Post types'),
      '#views' => $tabs,
    ];
  }

  /**
   * Ensure the bundle is available for the profile user.
   *
   * @param \Drupal\user\UserInterface $user
   *   Profile user.
   * @param string $bundle
   *   Bundle machine name.
   */
  protected function assertBundleAvailable(UserInterface $user, string $bundle): void {
    $available = $this->getAvailableBundles($user);
    if (!in_array($bundle, $available, TRUE)) {
      throw new NotFoundHttpException();
    }
  }

  /**
   * Get available bundles for a profile user, memoized per request.
   *
   * @param \Drupal\user\UserInterface $user
   *   Profile user.
   *
   * @return string[]
   *   Available bundle machine names.
   */
  protected function getAvailableBundles(UserInterface $user): array {
    $user_id = (int) $user->id();
    if ($this->availableBundlesUserId !== $user_id) {
      $this->availableBundlesCache = $this->reportService->getAvailableBundles($user);
      $this->availableBundlesUserId = $user_id;
    }
    return $this->availableBundlesCache;
  }

  /**
   * Get the moderation service for a user posts bundle.
   *
   * @param string $bundle
   *   Bundle machine name.
   *
   * @return \Drupal\reliefweb_user_posts\Services\UserPostsServiceBase
   *   Bundle service.
   */
  protected function getBundleService(string $bundle): UserPostsServiceBase {
    if (!isset($this->bundleServices[$bundle])) {
      throw new NotFoundHttpException();
    }
    return $this->bundleServices[$bundle];
  }

}
