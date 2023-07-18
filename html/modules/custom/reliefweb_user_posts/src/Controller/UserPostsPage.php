<?php

namespace Drupal\reliefweb_user_posts\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\reliefweb_moderation\Controller\ModerationPage;
use Drupal\reliefweb_moderation\ModerationServiceInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

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
   * {@inheritdoc}
   */
  public function __construct(FormBuilderInterface $form_builder) {
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder')
    );
  }

  /**
   * Get the page title.
   *
   * @param \Drupal\user\UserInterface $user
   *   User accoutn.
   * @param \Drupal\reliefweb_moderation\ModerationServiceInterface $service
   *   Moderation service.
   *
   * @return string|\Drupal\Component\Render\MarkupInterface
   *   Moderation title.
   */
  public function getTitle(UserInterface $user, ModerationServiceInterface $service) {
    if ($user->id() === $this->currentUser()->id()) {
      return $this->t('My posts');
    }
    else {
      return $this->t("@name's posts", [
        '@name' => $user->label(),
      ]);
    }
  }

  /**
   * Get the moderation page content.
   *
   * @param \Drupal\user\UserInterface $user
   *   User accoutn.
   * @param \Drupal\reliefweb_moderation\ModerationServiceInterface $service
   *   Moderation service.
   *
   * @return array
   *   Render array.
   */
  public function getContent(UserInterface $user, ModerationServiceInterface $service) {
    // We want the editors to be able to bookmark a moderation page with
    // a selection of filters so we set the method as GET.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$service, $user]);
    $form_state->setMethod('GET');
    $form_state->setProgrammed(TRUE);
    $form_state->setProcessInput(TRUE);
    $form_state->disableCache();

    // Build the filters form.
    // @todo review the URL parameters and eventually remove the unnecessary
    // parameters (list can be retrieved with FormState::getCleanValueKeys()).
    $form = $this->formBuilder
      ->buildForm('\Drupal\reliefweb_user_posts\Form\UserPostsPageFilterForm', $form_state);

    // Filter the results.
    $filters = [];
    if (!$form_state->getErrors()) {
      $definitions = $service->getFilterDefinitions();

      $values = $form_state->getValues();
      $input = $form_state->getUserInput();

      // Status + Other filters.
      $filters = $values['filters'] ?? [];

      // Omnibox selections.
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
              // For compatibility with the other type of filters we set the
              // selected value as key with 1 value to flag it as selected.
              $filters[$filter][$value] = 1;
            }
          }
        }
      }
    }

    return [
      '#theme' => 'reliefweb_moderation_page__user_posts',
      '#filters' => $form,
      // List of results as a table with a pager.
      '#list' => $service->getTable($filters, 30),
    ];
  }

  /**
   * Return suggestions for the autocomplete widget on the moderation pages.
   *
   * @param \Drupal\reliefweb_moderation\ModerationServiceInterface $service
   *   Moderation service.
   * @param string $filter
   *   Filter name.
   * @param \Drupal\user\UserInterface|null $user
   *   User account for the user posts page.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the list of suggestions if any.
   */
  public function autocomplete(ModerationServiceInterface $service, $filter, UserInterface $user = NULL) {
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
   * Redirect the current user to the its posts page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirection response.
   */
  public function currentUserPostsPage() {
    return $this->redirect('reliefweb_user_posts.content', [
      'user' => $this->currentUser()->id(),
    ], [], 301);
  }

}
