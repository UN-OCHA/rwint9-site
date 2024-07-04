<?php

namespace Drupal\reliefweb_reporting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Routing\RouteMatchInterface;
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
    $data = reliefweb_reporting_get_weekly_ai_tagging_stats();

    return new JsonResponse($data, 200);
  }

}
