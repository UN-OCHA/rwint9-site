<?php

namespace Drupal\reliefweb_reporting\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class ReliefWebReporting Controller.
 *
 * @package Drupal\reliefweb_reporting\Controller
 */
class ReliefWebReportingController extends ControllerBase {
  use LoggerChannelTrait;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs controller.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The RequestStack object.
   */
  public function __construct(RequestStack $request_stack) {
    $this->requestStack = $request_stack;
  }

  /**
   * Generate AI Job Tagging statistics.
   *
   * Call the helper to fetch the stas, the output them as JSON.
   */
  public function weeklyAiTaggingStats(RouteMatchInterface $route_match, Request $request) {

    if ($this->access($this->currentUser())->isForbidden()) {
      return new JsonResponse(['error' => 'Access denied!'], 403);
    }

    $data = reliefweb_reporting_get_weekly_ai_tagging_stats();

    return new JsonResponse($data, 200);
  }

  /**
   * Access result callback.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Determines the access to controller.
   */
  public function access(AccountInterface $account) {
    $header_secret = $this->requestStack->getCurrentRequest()->headers->get('reliefweb-reporting') ?? NULL;
    $config_secret = $this->config('reliefweb_reporting')->get('statistics.key');
    if ((!empty($header_secret) && $header_secret === $config_secret)
      || $account->hasPermission('view site reports')) {
      $access_result = AccessResult::allowed();
    }
    else {
      $access_result = AccessResult::forbidden();
      $logger = $this->getLogger('reliefweb_reporting');
      $logger->warning('Unauthorized access to reporrs denied');
    }
    $access_result
      ->setCacheMaxAge(0)
      ->addCacheContexts([
        'headers:reliefweb-reporting',
        'user.roles',
      ])
      ->addCacheTags(['reliefweb_reporting']);
    return $access_result;
  }

}
