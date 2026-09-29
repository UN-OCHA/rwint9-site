<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Exception;

use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;

/**
 * Error when a document UUID does not match an existing entity.
 */
class DocumentNotFoundException extends ContentProcessorException implements ExceptionInterface {}
