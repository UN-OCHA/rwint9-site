<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use Drupal\reliefweb_post_api\Services\ProviderManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the POST API.
 */
class ReliefWebPostApi extends ControllerBase {

  /**
   * Constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface $contentProcessorPluginManager
   *   The ReliefWeb POST API content processor plugin manager.
   * @param \Drupal\reliefweb_post_api\Services\ProviderManager $providerManager
   *   The ReliefWeb POST API provider manager.
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected QueueFactory $queueFactory,
    protected ContentProcessorPluginManagerInterface $contentProcessorPluginManager,
    protected ProviderManager $providerManager
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('queue'),
      $container->get('plugin.manager.reliefweb_post_api.content_processor'),
      $container->get('reliefweb_post_api.provider.manager')
    );
  }

  /**
   * POST endpoint.
   *
   * @param string $bundle
   *   Entity bundle.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response: 200, 4xx or 5xx
   */
  public function postContent(string $bundle): JsonResponse {
    try {
      $request = $this->requestStack->getCurrentRequest();
      $headers = $request->headers;

      // Only POST requests are allowed.
      if ($request->getMethod() !== 'POST') {
        throw new MethodNotAllowedHttpException(['POST'], 'Unsupported method.');
      }

      // Retrieve the provider.
      $provider = $this->providerManager->getProvider($headers->get('X-RW-POST-API-PROVIDER', ''));
      if (!isset($provider)) {
        throw new AccessDeniedHttpException('Invalid provider.');
      }

      // Check access.
      if (!$provider->validateKey($headers->get('X-RW-POST-API-KEY', ''))) {
        throw new AccessDeniedHttpException('Invalid API key.');
      }

      // Check if the bundle is supported.
      try {
        $plugin = $this->contentProcessorPluginManager->getPluginByBundle($bundle);
        if (empty($plugin)) {
          throw new \Exception();
        }
      }
      catch (\Exception $exception) {
        throw new NotFoundHttpException('Invalid endpoint.');
      }

      // Check if we received JSON data.
      if ($request->getContentTypeFormat() !== 'json') {
        throw new BadRequestHttpException('Invalid content format.');
      }

      // Retrieve and decode the body.
      $body = $request->getContent();
      if (empty($body)) {
        throw new BadRequestHttpException('Missing request body.');
      }

      if (!is_string($body)) {
        throw new BadRequestHttpException('Invalid request body.');
      }

      $data = json_decode($body, TRUE);
      if (!is_array($data)) {
        throw new BadRequestHttpException('Invalid JSON body.');
      }

      // Add the bundle to the data so we can know which plugin to use when
      // retrieve and processing it.
      $data['bundle'] = $bundle;

      // Add the provider ID so we can perform additional checks like verifying
      // the URLs of attachments.
      $data['provider'] = $provider->id();

      // Validate the content against the schema for the bundle.
      try {
        $plugin->validate($data);
      }
      catch (\Exception $exception) {
        throw new BadRequestHttpException("Invalid data:\n\n" . $exception->getMessage());
      }

      // Queue the data so it can be processed later (ex: drush command).
      try {
        $queue = $this->queueFactory->get('reliefweb_post_api');
        $queue->createItem($data);
      }
      catch (\Exception $exception) {
        throw new HttpException(500, 'Internal server error.');
      }

      $response = new JsonResponse('Document queued for processing.', 200);
    }
    catch (HttpException $exception) {
      $response = new JsonResponse($exception->getMessage(), $exception->getStatusCode());
    }
    catch (\Exception $exception) {
      $response = new JsonResponse($exception->getMessage(), 500);
    }

    return $response;
  }

}
