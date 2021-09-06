<?php

namespace Drupal\reliefweb_topics\Plugin\Filter;

use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Provides a filter to allow iframes.
 *
 * @Filter(
 *   id = "filter_iframe",
 *   title = @Translation("IFrame Filter"),
 *   description = @Translation("Process iframes"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_MARKUP_LANGUAGE,
 * )
 */
class IFrameFilter extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult();
    $result->setProcessedText($this->convertIframeMarkup($text));
    return $result;
  }

  /**
   * Convert the special iframe syntax to html.
   *
   * Syntax is: [iframe:widthxheight title](link).
   */
  protected function convertIframeMarkup($text) {
    $pattern = "/\[iframe(?:[:](?<width>\d+))?(?:[:x](?<height>\d+))?(?:[ ]+\"?(?<title>[^\"\]]+)\"?)?\]\((?<url>[^\)]+)\)/";
    return preg_replace_callback($pattern, [
      IFrameFilter::class,
      'renderIframeToken',
    ], $text);
  }

  /**
   * Generate iframe html markup.
   */
  public static function renderIframeToken($data) {
    if (empty($data['url'])) {
      return '';
    }

    $width = !empty($data['width']) ? intval($data['width'], 10) : 1000;
    $height = !empty($data['height']) ? intval($data['height'], 10) : round($width / 2);
    $title = !empty($data['title']) ?? 'iframe';
    $url = $data['url'];

    $text = '<iframe width="' . $width . '" height="' . $height . '" title="' . $title . '" src="' . $url . '" frameborder="0" allowfullscreen></iframe>';

    return $text;
  }

}
