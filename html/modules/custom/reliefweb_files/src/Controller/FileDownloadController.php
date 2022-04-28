<?php

namespace Drupal\reliefweb_files\Controller;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Drupal\reliefweb_files\Services\DocstoreClient;
use Drupal\system\FileDownloadController as OriginalFileDownloadController;
use GuzzleHttp\Psr7\StreamWrapper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * System file controller.
 */
class FileDownloadController extends OriginalFileDownloadController {

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
   * The OCHA docstore client service.
   *
   * @var \Drupal\reliefweb_files\Services\DocstoreClient
   */
  protected $docstoreClient;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * FileDownloadController constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\reliefweb_files\Services\DocstoreClient $docstore_client
   *   The OCHA docstore client service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    DocstoreClient $docstore_client,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    StreamWrapperManagerInterface $stream_wrapper_manager
  ) {
    parent::__construct($stream_wrapper_manager);

    $this->config = $config_factory->get('reliefweb_files.settings');
    $this->currentUser = $current_user;
    $this->docstoreClient = $docstore_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('reliefweb_files');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('reliefweb_files.client'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('stream_wrapper_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function download(Request $request, $scheme = 'private') {
    $uri = $scheme . '://' . $request->query->get('file');

    // Retrieve the base directory in which the previews are stored.
    $file_directory = $this->config->get('file_directory') ?? 'attachments';

    // Pattern for the preview files.
    $pattern = '#^(?:private|public)://' .
               preg_quote($file_directory) .
               '/([a-z0-9]{2})/([a-z0-9]{2})/\1\2[a-z0-9-]{32}\.#';

    // Let other modules handle the file if it's not a file matching the pattern
    // used for the reliefweb files.
    if (preg_match($pattern, $uri) !== 1) {
      return parent::download($request, $scheme);
    }

    // Deny access if the user doesn't have the proper permission.
    if ($scheme === 'private' && !$this->currentUser->hasPermission('access reliefweb private files')) {
      throw new AccessDeniedHttpException();
    }

    /** @var \Drupal\file\FileInterface $file */
    $file = $this->loadFileFromUri($uri);
    if (!isset($file)) {
      throw new NotFoundHttpException();
    }

    // Retrieve the file headers and return the file content.
    // @todo review how to deal with the file caching to deal with file
    // replacements.
    $headers = file_get_content_headers($file);
    return new BinaryFileResponse($uri, 200, $headers, FALSE);
  }

  /**
   * Download a public attachment.
   *
   * @param string $uuid
   *   File resource UUID.
   * @param string $filename
   *   File name.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Response to download the file.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   403 if the user is not authorized to download the file.
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   404 if the file was not found.
   */
  public function downloadPublicAttachment($uuid, $filename) {
    return $this->downloadAttachment($uuid, $filename);
  }

  /**
   * Download a private attachment.
   *
   * @param string $uuid
   *   File resource UUID.
   * @param string $filename
   *   File name.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Response to download the file.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   403 if the user is not authorized to download the file.
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   404 if the file was not found.
   */
  public function downloadPrivateAttachment($uuid, $filename) {
    return $this->downloadAttachment($uuid, $filename, TRUE);
  }

  /**
   * Download a file attachment.
   *
   * Note: this tries to download the public file if exists first, then if
   * private is set and the user has access to the private files attempt to
   * download it.
   *
   * @param string $uuid
   *   File resource UUID.
   * @param string $filename
   *   File name.
   * @param bool $private
   *   TRUE to check private files.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Response to download the file.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   403 if the user is not authorized to download the file.
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   404 if the file was not found.
   */
  protected function downloadAttachment($uuid, $filename, $private = FALSE) {
    try {
      // No need to try to fetch the file if the user doesn't have access.
      if ($private && !$this->currentUser->hasPermission('access reliefweb private files')) {
        throw new AccessDeniedHttpException();
      }

      // Try to download the local file matching the given UUID.
      $response = $this->downloadLocalFile($uuid, $filename, $private);
      // Try to download the remote file matching the given UUID.
      if (empty($response)) {
        $response = $this->downloadRemoteFile($uuid, $filename);
      }

      // If the file was found, send its content.
      if (!empty($response)) {
        return $response;
      }
    }
    catch (AccessDeniedHttpException $exception) {
      throw $exception;
    }
    catch (NotFoundHttpException $exception) {
      throw $exception;
    }
    catch (\Exception $exception) {
      // @todo log error.
    }
    throw new NotFoundHttpException('Not Found');
  }

  /**
   * Download a local file.
   *
   * @param string $uuid
   *   File resource UUID.
   * @param string $filename
   *   File name.
   * @param bool $private
   *   TRUE if the file is private.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|null
   *   Response to download the file or NULL if the file was not found. We
   *   don't throw a 404 in that case so that the caller function can perform
   *   additional requests if necessary.
   *
   * @see ::downloadAttachment()
   */
  protected function downloadLocalFile($uuid, $filename, $private = FALSE) {
    $extension = ReliefWebFile::extractFileExtension($filename);
    $uri = ReliefWebFile::getFileUriFromUuid($uuid, $extension, $private);

    // Ensure the latest version of the attachment is always returned.
    $headers = [
      'Cache-Control' => 'private, must-revalidate',
      'Content-Disposition' => 'attachment; filename="' . Unicode::mimeHeaderEncode($filename) . '"',
    ];

    if (file_exists($uri)) {
      return new BinaryFileResponse($uri, 200, $headers, $private, 'attachment', TRUE);
    }
    return NULL;
  }

  /**
   * Download a remote file.
   *
   * @param string $uuid
   *   File resource UUID.
   * @param string $filename
   *   File name.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse|null
   *   Response to download the file or NULL if the file was not found. We
   *   don't throw a 404 in that case so that the caller function can perform
   *   additional requests if necessary.
   *
   * @see ::downloadAttachment()
   *
   * @throws \Exception
   *   Exception if something was wrong with the request.
   */
  protected function downloadRemoteFile($uuid, $filename) {
    $client = $this->docstoreClient;
    $extension = ReliefWebFile::extractFileExtension($filename);

    // Ensure the latest version of the attachment is always returned.
    $headers = [
      'Cache-Control' => 'private, must-revalidate',
      'Content-Disposition' => 'attachment; filename="' . Unicode::mimeHeaderEncode($filename) . '"',
    ];

    // Download the remote file.
    // We're not using the filename to have better compatibility and handle
    // files with encoded space characters for example. The docstore doesn't
    // care about the filename and we send a header with the proper filename.
    $response = $client->request('GET', '/files/' . $uuid . '/' . $uuid . '.' . $extension, [
      'stream' => TRUE,
    ], 1200);

    // Stream the response content.
    if ($response->isSuccessful()) {
      return new StreamedResponse(function () use ($response) {
        $input = StreamWrapper::getResource($response->getBody());
        $output = fopen('php://output', 'wb');
        stream_copy_to_stream($input, $output);
        if (is_resource($input)) {
          @fclose($input);
        }
        if (is_resource($output)) {
          @fclose($output);
        }
      }, 200, $headers + $response->getHeaders());
    }

    // The error is already logged by the docstore client.
    return NULL;
  }

  /**
   * Load a file from its URI.
   *
   * @param string $uri
   *   File URI.
   *
   * @return \Drupal\file\Entity\File|null
   *   File entity or NULL if none was found.
   */
  protected function loadFileFromUri($uri) {
    /** @var \Drupal\file\FileInterface[] $files */
    $files = $this->entityTypeManager
      ->getStorage('file')
      ->loadByProperties(['uri' => $uri]);

    if (!empty($files)) {
      foreach ($files as $file) {
        // Ensure it was a case sensitive match.
        if ($file->getFileUri() === $uri) {
          return $file;
        }
      }
    }

    return NULL;
  }

}
