<?php

declare(strict_types=1);

namespace Drupal\reliefweb_ai\Enum;

/**
 * Which text sample stage produced a language detection result.
 */
enum LanguageDetectionSample: string {

  case Attachment = 'attachment';
  case Body = 'body';
  case Title = 'title';
  case None = 'none';

}
