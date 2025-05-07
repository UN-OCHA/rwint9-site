<?php

declare(strict_types=1);

namespace Drupal\reliefweb_files\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\Plugin\Validation\Constraint\BaseFileConstraintValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\FileinfoMimeTypeGuesser;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the ReliefWeb file real mimetype constraint.
 */
class ReliefWebFileRealMimeTypeConstraintValidator extends BaseFileConstraintValidator implements ContainerInjectionInterface {

  /**
   * List of acceptable mime types for mime types guessed from the extension.
   *
   * @var array
   *
   * @see \Drupal\Core\File\MimeType\ExtensionMimeTypeGuesser
   */
  protected array $acceptableMimeTypes = [
    // Extension: csv.
    'text/csv' => [
      'text/csv',
      'text/plain',
      'application/vnd.ms-excel',
      'application/csv',
      'application/excel',
      'application/vnd.msexcel',
    ],
    // Extension: svg.
    'image/svg+xml' => [
      'image/svg+xml',
      'text/plain',
      'application/xml',
      'text/xml',
    ],
    // Extension: tsv.
    'text/tab-separated-values' => [
      'text/tab-separated-values',
      'text/plain',
      'text/tsv',
    ],
    // Extension: xml.
    'application/xml' => [
      'application/xml',
      'text/xml',
      'text/plain',
    ],
    // Extension: zip.
    'application/zip' => [
      'application/zip',
      'application/x-zip-compressed',
      'application/x-zip',
    ],
  ];

  /**
   * Fileinfo mime type guesser.
   *
   * @var \Symfony\Component\Mime\MimeTypeGuesserInterface
   */
  protected MimeTypeGuesserInterface $fileinfoMimeTypeGuesser;

  /**
   * Constructor.
   *
   * @param \Symfony\Component\Mime\MimeTypeGuesserInterface $extensionMimeTypeGuesser
   *   The file validator.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    protected MimeTypeGuesserInterface $extensionMimeTypeGuesser,
    protected StateInterface $state,
  ) {
    $this->fileinfoMimeTypeGuesser = new FileinfoMimeTypeGuesser();

    // Retrieve any overridden list of acceptable mime types.
    $this->acceptableMimeTypes = $state->get('reliefweb_file_real_mime_type_constraint:acceptable_mime_types', $this->acceptableMimeTypes);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('file.mime_type.guesser.extension'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint) {
    $file = $this->assertValueIsFile($value);
    if (!$constraint instanceof ReliefWebFileRealMimeTypeConstraint) {
      throw new UnexpectedTypeException($constraint, ReliefWebFileRealMimeTypeConstraint::class);
    }

    $uri = $file->getFileUri();
    if (!file_exists($uri)) {
      $this->context->addViolation($constraint->fileMissingError, [
        '%uri' => $uri,
      ]);
      return;
    }

    $current_mimetype = $file->getMimeType();
    $content_mimetype = $this->fileinfoMimeTypeGuesser->guessMimeType($uri);

    // Direct match.
    if ($current_mimetype === $content_mimetype) {
      return;
    }

    // Handle acceptable variants (ex: CSV detected as text/plain).
    $acceptable_mimetypes = $this->acceptableMimeTypes[$current_mimetype] ?? [];
    if (in_array($content_mimetype, $acceptable_mimetypes)) {
      return;
    }

    $this->context->addViolation($constraint->mimetypeMismatchError, [
      '%current_mimetype' => $current_mimetype,
      '%content_mimetype' => $content_mimetype,
    ]);
  }

}
