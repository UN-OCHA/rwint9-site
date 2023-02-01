<?php

namespace Drupal\reliefweb_files\Controller;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\image\Controller\ImageStyleDownloadController as OriginalImageStyleDownloadController;
use Drupal\image\ImageStyleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Defines a controller to serve image styles.
 */
class ImageStyleDownloadController extends OriginalImageStyleDownloadController {

  /**
   * ReliefWeb Files config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs an ImageStyleDownloadController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Core\Image\ImageFactory $image_factory
   *   The image factory.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    LockBackendInterface $lock,
    ImageFactory $image_factory,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    FileSystemInterface $file_system = NULL,
  ) {
    parent::__construct($lock, $image_factory, $stream_wrapper_manager, $file_system);

    $this->config = $config_factory->get('reliefweb_files.settings');
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('lock'),
      $container->get('image.factory'),
      $container->get('stream_wrapper_manager'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function deliver(Request $request, $scheme, ImageStyleInterface $image_style = NULL) {
    if (empty($image_style)) {
      throw new NotFoundHttpException();
    }

    $file = $request->query->get('file');
    if (!is_string($file)) {
      throw new NotFoundHttpException();
    }

    // This is normally the URI of the source image.
    $uri = $scheme . '://' . trim($file);

    // Retrieve the base directory in which the previews are stored.
    $preview_directory = $this->config->get('preview_directory') ?? 'previews';

    // Pattern for the preview files.
    $pattern = '#^(?:private|public)://' .
               preg_quote($preview_directory) .
               '/([a-z0-9]{2})/([a-z0-9]{2})/\1\2[a-z0-9-]{32}\.#';

    // Let other modules handle the file if it's not a file matching the pattern
    // used for the reliefweb files.
    if (preg_match($pattern, $uri) !== 1) {
      return parent::download($request, $scheme);
    }

    // Check the image token. We return a 404 as it's more likely to be cached
    // than a 403 and the token is just of DDOS protection and chacking helps
    // as well with that.
    if (!$this->validateToken($request, $uri, $image_style)) {
      throw new NotFoundHttpException();
    }

    // Deny access if the user doesn't have the proper permission.
    if ($scheme === 'private' && !$this->currentUser->hasPermission('access reliefweb private files')) {
      throw new AccessDeniedHttpException();
    }

    // Get the deriative image URI.
    $derivative_uri = $image_style->buildUri($uri);

    // Generate the derivative image if doesn't exist.
    if (!file_exists($derivative_uri)) {
      // Retrieve the source image to generate the derivative.
      $uri = $this->getSourceImageUri($uri, $derivative_uri);
      if (empty($uri)) {
        throw new NotFoundHttpException('Error generating image, missing source file.');
      }

      // Generate the derivative image.
      if (!$this->generateDerivative($uri, $derivative_uri, $image_style)) {
        $this->logger->notice('Unable to generate the derived image located at %path.', [
          '%path' => $derivative_uri,
        ]);
        return new Response('Error generating image.', 500);
      }
    }

    // Get the image object for the derivative image so we can generate the
    // response headers.
    $image = $this->imageFactory->get($derivative_uri);
    $headers = [
      'Content-Type' => $image->getMimeType(),
      'Content-Length' => $image->getFileSize(),
      'Cache-Control' => 'private',
    ];

    return new BinaryFileResponse($image->getSource(), 200, $headers, TRUE);
  }

  /**
   * Validate the request token used for DDOS prevention.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request.
   * @param string $uri
   *   Image URI extracted from the request.
   * @param \Drupal\image\ImageStyleInterface $image_style
   *   Image style.
   *
   * @return bool
   *   TRUE if the token was valid.
   */
  protected function validateToken(Request $request, $uri, ImageStyleInterface $image_style) {
    return $this->config('image.settings')->get('allow_insecure_derivatives') ||
      hash_equals($image_style->getPathToken($uri), $request->query->get(IMAGE_DERIVATIVE_TOKEN, ''));
  }

  /**
   * Get the source image URI.
   *
   * @param string $uri
   *   Image URI extracted from the request.
   * @param string $derivative_uri
   *   Derivative image URI.
   *
   * @return string
   *   Image source URI or empty if the source image doesn't exist.
   */
  protected function getSourceImageUri($uri, $derivative_uri) {
    if (file_exists($uri)) {
      return $uri;
    }

    // If the image style converted the extension, it has been added to the
    // original file, resulting in filenames like image.png.jpeg. So to find
    // the actual source image, we remove the extension and check if that
    // image exists.
    $path_info = pathinfo($this->streamWrapperManager->getTarget($uri));
    $scheme = $this->streamWrapperManager->getScheme($derivative_uri);
    $converted_uri = $scheme . '://' . $path_info['dirname'] . '/' . $path_info['filename'];

    if (file_exists($converted_uri)) {
      return $converted_uri;
    }

    $this->logger->notice('Source image at %uri not found while trying to generate derivative image at %derivative_uri.', [
      '%uri' => $uri,
      '%derivative_uri' => $derivative_uri,
    ]);

    return '';
  }

  /**
   * Generate a derivative image.
   *
   * @param string $uri
   *   Source image URI.
   * @param string $derivative_uri
   *   Derivatie image URI.
   * @param \Drupal\image\ImageStyleInterface $image_style
   *   Image style.
   *
   * @return bool
   *   TRUE if the derivative could be generated.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException
   *   503 Service unavailable with a 3 seconds retry-after header.
   */
  protected function generateDerivative($uri, $derivative_uri, ImageStyleInterface $image_style) {
    // Skip if the derivative image exists (for example if it was just created
    // by another thread.
    if (file_exists($derivative_uri)) {
      return TRUE;
    }

    $lock_name = 'image_style_deliver:' . $image_style->id() . ':' . Crypt::hashBase64($uri);
    $lock_acquired = $this->lock->acquire($lock_name);

    // If we cannot acquire the lock, then it means than the derivative image
    // is being generated. We tell the client to retry in 3 seconds.
    if (!$lock_acquired) {
      throw new ServiceUnavailableHttpException(3, 'Image generation in progress. Try again shortly.');
    }

    // Try to generate the image, unless another thread just did it while we
    // were acquiring the lock.
    $success = file_exists($derivative_uri) || $image_style->createDerivative($uri, $derivative_uri);

    if ($lock_acquired) {
      $this->lock->release($lock_name);
    }

    return $success;
  }

}
