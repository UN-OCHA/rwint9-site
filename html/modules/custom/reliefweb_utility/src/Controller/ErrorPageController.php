<?php

namespace Drupal\reliefweb_utility\Controller;

use Drupal\Component\Utility\Environment;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * ErrorPage controller.
 */
class ErrorPageController extends ControllerBase {
  use StringTranslationTrait;

  /**
   * Account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * Request.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Include the messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    AccountInterface $account,
    RequestStack $request_stack,
    MessengerInterface $messenger,
  ) {
    $this->account = $account;
    $this->requestStack = $request_stack;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('messenger'),
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

  /**
   * Handle the error passed by nginx.
   */
  public function handleError() {
    $request = $this->getRequest();

    // What size was the file the user tried?
    $size = ByteSizeMarkup::create($request->query->get('size') ?? 0);

    // What is the max size the site allows?
    $max_size = ByteSizeMarkup::create(Environment::getUploadMaxSize());

    // WHat do we tell the user?
    $message = $this->t("The file you are trying to upload is %size, which exceeds the maximum allowed size of %max_size. Please compress the file or choose a smaller one before trying again. If you need help reducing file size or have questions about upload limits, contact support: submit@reliefweb.int",
      [
        '%size' => $size,
        '%max_size' => $max_size,
      ]
    );

    // If this is an ajax callback, return an ajax response.
    if ($request->isXmlHttpRequest()) {
      $response = new AjaxResponse();
      $response->addCommand(new MessageCommand($message, NULL, ['type' => 'error']));
      return $response;
    }

    // Show an error box, for consistency.
    $this->messenger->addError($this->t("File Too Large"));

    return [
      '#markup' => $message,
    ];
  }

}
