<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Queue\QueueFactory;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

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
   * @param \Drupal\Core\Extension\ExtensionPathResolver $pathResolver
   *   The path resolver service.
   * @param \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface $contentProcessorPluginManager
   *   The ReliefWeb POST API content processor plugin manager.
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected QueueFactory $queueFactory,
    protected ExtensionPathResolver $pathResolver,
    protected ContentProcessorPluginManagerInterface $contentProcessorPluginManager
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('queue'),
      $container->get('extension.path.resolver'),
      $container->get('plugin.manager.reliefweb_post_api.content_processor')
    );
  }

  /**
   * Post content endpoint.
   *
   * @param string $resource
   *   Content resource (ex: reports).
   * @param string $uuid
   *   UUID of the resource to create or update.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response: 200, 4xx or 5xx
   */
  public function postContent(string $resource, string $uuid): JsonResponse {
    try {
      $request = $this->requestStack->getCurrentRequest();
      $headers = $request->headers;

      // Only PUT requests are allowed currently.
      // @todo handle PATCH and DELETE.
      if ($request->getMethod() !== 'PUT') {
        throw new MethodNotAllowedHttpException(['PUT'], 'Unsupported method.');
      }

      // Validate the endpoint syntax.
      if (preg_match('/^[a-z_-]+$/', $resource) !== 1) {
        throw new NotFoundHttpException('Invalid endpoint resource.');
      }
      if (!Uuid::isValid($uuid)) {
        throw new NotFoundHttpException('Invalid endpoint UUID.');
      }

      // Check if the resource is supported.
      try {
        $plugin = $this->contentProcessorPluginManager->getPluginByResource($resource);
        if (empty($plugin)) {
          throw new \Exception();
        }
      }
      catch (\Exception $exception) {
        throw new NotFoundHttpException('Unknown endpoint.');
      }

      // Retrieve the provider.
      try {
        $provider = $plugin->getProvider($headers->get('X-RW-POST-API-PROVIDER', ''));
      }
      catch (\Exception $exception) {
        throw new AccessDeniedHttpException('Invalid provider.');
      }

      // Check access.
      if (!$provider->validateKey($headers->get('X-RW-POST-API-KEY', ''))) {
        throw new AccessDeniedHttpException('Invalid API key.');
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

      // Add the UUID if not already in the payload.
      $data['uuid'] = $data['uuid'] ?? $uuid;

      // Add the bundle to the data so we can know which plugin to use when
      // retrieve and processing it.
      $data['bundle'] = $plugin->getBundle();

      // Add the provider ID so we can perform additional checks like verifying
      // the URLs of attachments.
      $data['provider'] = $provider->uuid();

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

  /**
   * Get a JSON schema.
   *
   * @param string $schema
   *   The name of the schema file (ex: report.json).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response: 200, 4xx or 5xx
   */
  public function getJsonSchema(string $schema): JsonResponse {
    if (preg_match('/^[a-z][a-z_-]+[a-z]\.json$/', $schema) !== 1) {
      return new JsonResponse('Invalid schema file name.', 400);
    }

    $path = $this->pathResolver->getPath('module', 'reliefweb_post_api');
    $file = $path . '/schemas/v2/' . $schema;
    if (!file_exists($file)) {
      return new JsonResponse('Unknown schema file.', 404);
    }

    $content = @file_get_contents($file);
    if (empty($content)) {
      return new JsonResponse('Internal server error.', 500);
    }

    return new JsonResponse($content, 200, json: TRUE);
  }

}
