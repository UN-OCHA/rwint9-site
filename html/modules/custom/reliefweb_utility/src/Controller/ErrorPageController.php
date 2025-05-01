<?php

namespace Drupal\reliefweb_utility\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * ErrorPage controller.
 */
class ErrorPageController extends ControllerBase {

  /**
   * Request.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    RequestStack $request_stack,
  ) {
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
    );
  }

  /**
   * Get the current request.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   Current request.
   */
  public function getRequest(): Request {
    return $this->requestStack->getCurrentRequest();
  }

  public function handleError() {
    $request = $this->getRequest();

      if (empty($timestamp) || empty($signature)) {
        throw new AccessDeniedHttpException();
      }


    return [
      '#type' => 'inline_template',
      '#template' => '<p><strong>You have successfully unsubscribed.</strong></p><p>You will no longer receive <em>{{ subscription_label }}</em> notifications.</p><p>{{ message }}</p>',
      '#context' => [
        'subscription_label' => $subscriptions[$sid]['name'],
        'message' => $message,
      ],
    ];
  }

}
