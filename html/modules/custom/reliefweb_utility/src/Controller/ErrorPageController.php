<?php

namespace Drupal\reliefweb_utility\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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
   * Include the typed data manager service.
   *
   * @var \Drupal\Core\TypedData\TypedDataManagerInterface
   */
  protected $typedDataManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    AccountInterface $account,
    RequestStack $request_stack,
    MessengerInterface $messenger,
    TypedDataManagerInterface $typedDataManager,
  ) {
    $this->account = $account;
    $this->requestStack = $request_stack;
    $this->messenger = $messenger;
    $this->typedDataManager = $typedDataManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('messenger'),
      $container->get('typed_data_manager'),
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

    // Get the field that caused the problem.
    $field_name = $request->query->get('field_parents');
    $field_config = FieldStorageConfig::loadByName('node', $field_name);

    // Instantiate the field, so we can ask about its max size.
    $field_item_definition = $this->typedDataManager->createDataDefinition('field_item:' . $field_config->getType());
    $field_item = $this->typedDataManager->createInstance('field_item:' . $field_config->getType(), [
      'name' => 'wibble',
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);
    $max_size = $this->ByteSizeMarkup::create($field_item->getMaxFileSize());

    $message = $this->t("The file you are trying to upload exceeds the maximum allowed size of @max_size. Please compress the file or choose a smaller one before trying again. If you need help reducing file size or have questions about upload limits, contact support: submit@reliefweb.int", ['@max_size' => $max_size]);

    if ($request->isXmlHttpRequest()) {
      $response = [
        'selector' => 'input.js-form-file',
        'command'  => 'insert',
        'method'   => 'append',
        'data'     => '<div class="form-item--error-message">' . $message . '</div>',
      ];
      return new JsonResponse($response, 200);
    }

    $this->messenger->addError($this->t("Upload Failed"));

    return [
      '#markup' => $message,
    ];
  }

}
