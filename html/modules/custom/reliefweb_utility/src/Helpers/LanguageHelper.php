<?php

namespace Drupal\reliefweb_utility\Helpers;

use Nitotm\Eld\LanguageDetector;
use Nitotm\Eld\EldDataFile;
use Nitotm\Eld\EldFormat;

/**
 * Helper class to help with language tasks like language detection.
 */
class LanguageHelper {

  /**
   * Store the language detector instance.
   *
   * @var \Nitotm\Eld\LanguageDetector
   */
  protected static LanguageDetector $languageDetector;

  /**
   * Get the language detector.
   *
   * @return \Nitotm\Eld\LanguageDetector
   *   The language detector.
   */
  protected static function getLanguageDetector(): LanguageDetector {
    if (!isset(static::$languageDetector)) {
      static::$languageDetector = new LanguageDetector(EldDataFile::SMALL, EldFormat::ISO639_1);
    }
    return static::$languageDetector;
  }

  /**
   * Detect the language of a text.
   *
   * @param string $text
   *   Text to analyze.
   *
   * @return string
   *   ISO 639-1 language code in lower case or 'und' if the language
   *   couldn't be detected reliably.
   */
  public static function detectTextLanguage(string $text): string {
    $language_detector = static::getLanguageDetector();
    $detection = $language_detector->detect($text);
    return $detection->isReliable() ? $detection->language : 'und';
  }

}
