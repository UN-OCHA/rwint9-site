<?php

namespace Drupal\reliefweb_migrate\EventSubscriber;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Proxy subscriber to retrieve images from the ReliefWeb Drupal 7 site.
 *
 * Note: this is heavily inspired by the stage_file_proxy module and is only
 * of interest during development.
 */
class ProxySubscriber implements EventSubscriberInterface {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The http client service.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Construct the FetchManager.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The http client service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger interface.
   */
  public function __construct(FileSystemInterface $file_system, ClientInterface $http_client, LoggerInterface $logger) {
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * Fetch the file from it's origin.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The event to process.
   */
  public function checkFileOrigin(GetResponseEvent $event) {
    $request = $event->getRequest();

    // Get the file path.
    $path = $request->getPathInfo();

    // Hotlink to the production site.
    // @todo remove when attachments are added.
    if (strpos($path, '/resources-pdf-previews/') !== FALSE) {
      header('Location: ' . 'https://reliefweb.int' . $path);
      exit;
    }

    // Check if the request is for an image.
    // @todo we may want to allow report attachments as well at some point.
    if (strpos(\GuzzleHttp\Psr7\mimetype_from_filename($path), 'image/') !== 0) {
      return;
    }

    // Skip if the file is not a public file.
    if (strpos($path, '/' . PublicStream::basePath()) !== 0) {
      return;
    }
    else {
      $path = substr($path, strlen(PublicStream::basePath()) + 2);
    }

    // Remove any style info to retrieve the original image.
    if (strpos($path, 'styles/') === 0) {
      $uri = preg_replace('#^styles/[^/]+/(?<scheme>[^/]+)/(?<path>.+)#U', '$1://$2', $path);
    }
    else {
      $uri = 'public://' . $path;
    }

    // Skip if the file already exists.
    if (file_exists($uri)) {
      return;
    }

    // Check if there is mapping between this URI and the old one.
    $old_uri = \Drupal::database()
      ->select('reliefweb_migrate_uri_mapping', 'm')
      ->fields('m', ['old_uri'])
      ->condition('m.new_uri', $uri)
      ->execute()
      // Null safe operator in case the query execution fails.
      ?->fetchField();

    // Skip if there is no old ReliefWeb URI for the current request.
    if (empty($old_uri)) {
      return;
    }

    // Get the old ReliefWeb URL.
    $url = str_replace('public://', 'https://reliefweb.int/sites/reliefweb.int/files/', $old_uri);

    // Check of the remove file exists.
    try {
      $code = $this->httpClient->head($url, [
        'timeout' => 2,
        'connect_timeout' => 2,
      ])->getStatusCode();
    }
    catch (ClientException $exception) {
      $this->logger->warning('HTTP client exception: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return;
    }

    if ($code >= 400) {
      $this->logger->warning('The remote file @url does not exist', [
        '@url' => $url,
      ]);
      return;
    }

    // Get the directory of the new URI so we can create it if necessary.
    $directory = dirname($uri);

    // Prepare the destination directory.
    if (!$this->fileSystem->prepareDirectory($directory, $this->fileSystem::CREATE_DIRECTORY)) {
      $this->logger->warning('Unable to create the destination directory: @directory', [
        '@directory' => $directory,
      ]);
    }

    // Save the original file.
    if (file_put_contents($uri, fopen($url, 'r')) === FALSE) {
      $this->logger->warning('Unable to save the file @uri from @url', [
        '@uri' => $uri,
        '@url' => $url,
      ]);
    }
    else {
      // Avoid redirection caching in upstream proxies.
      header('Cache-Control: must-revalidate, no-cache, post-check=0, pre-check=0, private');
      header('Location: ' . $request->getRequestUri());
      exit;
    }
  }

  /**
   * Registers the methods in this class that should be listeners.
   *
   * @return array
   *   An array of event listener definitions.
   */
  public static function getSubscribedEvents() {
    // Priority 240 is after ban middleware but before page cache.
    $events[KernelEvents::REQUEST][] = ['checkFileOrigin', 240];
    return $events;
  }

}
