<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Exception;

use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;

/**
 * Error for duplication.
 */
class DuplicateException extends ContentProcessorException implements ExceptionInterface {}
