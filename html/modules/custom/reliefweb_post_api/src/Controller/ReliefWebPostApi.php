<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\reliefweb_post_api\Entity\ProviderInterface;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use Drupal\reliefweb_post_api\Queue\ReliefWebPostApiDatabaseQueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
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
   * @param \Drupal\reliefweb_post_api\Queue\ReliefWebPostApiDatabaseQueueFactory $queueFactory
   *   The ReliefWeb POST API queue factory.
   * @param \Drupal\Core\Extension\ExtensionPathResolver $pathResolver
   *   The path resolver service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface $contentProcessorPluginManager
   *   The ReliefWeb POST API content processor plugin manager.
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected ReliefWebPostApiDatabaseQueueFactory $queueFactory,
    protected ExtensionPathResolver $pathResolver,
    protected Connection $database,
    protected TimeInterface $time,
    protected ContentProcessorPluginManagerInterface $contentProcessorPluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      // We use our own queue that stores unique submissions based on the
      // resource UUID.
      $container->get('reliefweb_post_api.queue.database'),
      $container->get('extension.path.resolver'),
      $container->get('database'),
      $container->get('datetime.time'),
      $container->get('plugin.manager.reliefweb_post_api.content_processor'),
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

      // Check that the appname parameter is present.
      if (empty($request->query->get('appname'))) {
        throw new BadRequestHttpException('Missing or invalid appname parameter.');
      }

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

      // Check the rate limits.
      $this->checkRateLimits($provider);

      // Check if we received JSON data.
      if ($request->getContentTypeFormat() !== 'json') {
        throw new BadRequestHttpException('Invalid content format.');
      }

      // Check if the submission can be processed.
      if (!$plugin->isProcessable($uuid)) {
        throw new UnprocessableEntityHttpException('Unprocessable submission.');
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

      if (isset($data['uuid']) && $data['uuid'] !== $uuid) {
        throw new BadRequestHttpException('Document UUID mistmatch.');
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

      // Process the document directly.
      if ($provider->skipQueue()) {
        try {
          $plugin->process($data);
        }
        catch (ContentProcessorException $exception) {
          throw new BadRequestHttpException("Invalid data:\n\n" . $exception->getMessage());
        }
        catch (\Exception $exception) {
          throw new HttpException(500, 'Internal server error.');
        }

        $response = new JsonResponse('Document processed.', 200);
      }
      // Queue the data so it can be processed later (ex: drush command).
      else {
        try {
          $queue = $this->queueFactory->get('reliefweb_post_api');
          $queue->createItem($data);
        }
        catch (\Exception $exception) {
          throw new HttpException(500, 'Internal server error.');
        }

        $response = new JsonResponse('Document queued for processing.', 202);
      }
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
   * Check the request rate limit for a provider.
   *
   * @param \Drupal\reliefweb_post_api\Entity\ProviderInterface $provider
   *   Provider.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   An HTTP exception (ex: 500 or 429) if there is an issue like missing
   *   field or the rate limit/quota is exceeded.
   */
  public function checkRateLimits(ProviderInterface $provider) {
    $quota = $provider->getQuota();
    $rate_limit = $provider->getRateLimit();

    if (empty($quota)) {
      throw new AccessDeniedHttpException('Not allowed to post content.');
    }

    $info = $this->database
      ->select('reliefweb_post_api_rate_limit', 't')
      ->fields('t', ['provider_id', 'request_count', 'last_request_time'])
      ->condition('t.provider_id', $provider->id())
      ->execute()
      ?->fetchAssoc() ?? [];

    $request_time = $this->time->getRequestTime();
    $last_request_time = min($info['last_request_time'] ?? $request_time - $rate_limit, $request_time);

    try {
      $request_date = new \DateTime('@' . $request_time, new \DateTimeZone('UTC'));
      $last_request_date = new \DateTime('@' . $last_request_time, new \DateTimeZone('UTC'));
      $diff = $request_date->diff($last_request_date);
    }
    catch (\Exception $exception) {
      throw new HttpException(500, 'Internal server error.');
    }

    $seconds_since_last_request = $diff->days * 24 * 60 * 60;
    $seconds_since_last_request += $diff->h * 60 * 60;
    $seconds_since_last_request += $diff->i * 60;
    $seconds_since_last_request += $diff->s;

    // Not enough time since last request.
    if ($seconds_since_last_request < $rate_limit) {
      throw new TooManyRequestsHttpException($rate_limit - $seconds_since_last_request, 'Not enough time ellapsed since last request.');
    }

    $same_day = $diff->d === 0;

    // Already performed the maximum daily number of requests.
    $request_count = $info['request_count'] ?? 0;
    if ($same_day && $request_count >= $quota) {
      // Next valid time to retry is the next day (UTC).
      $date = $request_date
        ->add(new \DateInterval('P1D'))
        ->setTime(0, 0, 1)
        ->format(\DateTimeInterface::RFC7231);
      throw new TooManyRequestsHttpException($date, 'Daily quota exceeded.');
    }

    // If the request is valid, update the database.
    $this->database
      ->upsert('reliefweb_post_api_rate_limit')
      ->fields(['request_count', 'last_request_time'])
      ->key('provider_id')
      ->values([
        'provider_id' => $provider->id(),
        'request_count' => $same_day ? $request_count + 1 : 1,
        'last_request_time' => $request_time,
      ])
      ->execute();
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
