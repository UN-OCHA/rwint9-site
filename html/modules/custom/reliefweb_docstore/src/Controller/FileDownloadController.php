<?php

namespace Drupal\reliefweb_docstore\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\system\FileDownloadController as OriginalFileDownloadController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * System file controller.
 */
class FileDownloadController extends OriginalFileDownloadController {

  /**
   * ReliefWeb Docstore config.
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
   * FileDownloadController constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    StreamWrapperManagerInterface $stream_wrapper_manager
  ) {
    parent::__construct($stream_wrapper_manager);

    $this->config = $config_factory->get('reliefweb_docstore.settings');
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
