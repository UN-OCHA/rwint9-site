<?php

namespace Drupal\reliefweb_user_posts\Controller;

use Drupal\reliefweb_moderation\Controller\ModerationPage;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\reliefweb_moderation\ModerationServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\user\UserInterface;

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
   * @param \Drupal\reliefweb_moderation\ModerationServiceInterface $service
   *   Moderation service.
   *
   * @return string|\Drupal\Component\Render\MarkupInterface
   *   Moderation title.
   */
  public function getPageTitle(ModerationServiceInterface $service) {
    return $this->t('My posts');
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
    $form_state->addBuildInfo('args', [$service]);
    $form_state->setMethod('GET');
    $form_state->setProgrammed(TRUE);
    $form_state->setProcessInput(TRUE);
    $form_state->disableCache();

    // Build the filters form.
    // @todo review the URL parameters and eventually remove the unnecessary
    // parameters (list can be retrieved with FormState::getCleanValueKeys()).
    $form = $this->formBuilder
      ->buildForm('\Drupal\reliefweb_moderation\Form\ModerationPageFilterForm', $form_state);

    // Filter the results.
    $filters = [];
    if (!$form_state->getErrors()) {
      $definitions = $service->getFilterDefinitions();

      $values = $form_state->getValues();
      $input = $form_state->getUserInput();

      // Status + Other filters.
      $filters = $values['filters'] ?? [];

      // Filter by current user.
      $filters['author'] = $user->id();

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
                list($value,) = explode(':', $item, 2);
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
      '#theme' => 'reliefweb_moderation_page',
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
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the list of suggestions if any.
   */
  public function autocomplete(ModerationServiceInterface $service, $filter) {
    $suggestions = $service->getAutocompleteSuggestions($filter);
    return new JsonResponse($suggestions);
  }

}
