<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Service class to retrieve report resource for the report rivers.
 */
class ReportRiver extends RiverServiceBase {

  /**
   * {@inheritdoc}
   */
  protected $river = 'updates';

  /**
   * {@inheritdoc}
   */
  protected $resource = 'reports';

  /**
   * {@inheritdoc}
   */
  protected $entityType = 'node';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'report';

  /**
   * {@inheritdoc}
   */
  public function parseApiData(array $api_data, $view = '') {
    $headlines = $view === 'headlines';

    // Retrieve the API data (with backward compatibility).
    $items = $api_data['items'] ?? $api_data['data'] ?? [];

    // Parse the entities retrieved from the API.
    $entities = [];
    foreach ($items as $item) {
      $fields = $item['fields'];

      // Title.
      if ($headlines && !empty($fields['headline']['title'])) {
        $title = $fields['headline']['title'];
      }
      else {
        $title = $fields['title'];
      }

      // Summary.
      // @todo do the summarization in the template instead?
      $summary = '';
      if ($headlines && !empty($fields['headline']['summary'])) {
        // The headline summary is plain text.
        $summary = $fields['headline']['summary'];
      }
      elseif (!empty($fields['body-html'])) {
        // Summarize the body. The average headline summary length is 182
        // characters so 200 characters sounds reasonable as there is often
        // date or location information at the beginning of the normal body
        // text, so we add a bit of margin to have more useful information in
        // the generated summary.
        $body = HtmlSanitizer::sanitize($fields['body-html']);
        $summary = HtmlSummarizer::summarize($body, 200);
      }

      // Tags (countries, sources etc.).
      $tags = [];

      // Countries.
      $countries = [];
      foreach ($fields['country'] ?? [] as $country) {
        $countries[] = [
          'name' => $country['name'],
          'shortname' => $country['shortname'] ?? $country['name'],
          'code' => $country['iso3'] ?? '',
          'url' => UrlHelper::encodeUrl('taxonomy/term/' . $country['id'], FALSE),
          'main' => !empty($country['primary']),
        ];
      }
      $tags['country'] = $countries;

      // Sources.
      $sources = [];
      foreach ($fields['source'] ?? [] as $source) {
        $sources[] = [
          'name' => $source['name'],
          'shortname' => $source['shortname'] ?? $source['name'],
          'url' => UrlHelper::encodeUrl('taxonomy/term/' . $source['id'], FALSE),
        ];
      }
      $tags['source'] = $sources;

      // Languages.
      $languages = [];
      foreach ($fields['language'] ?? [] as $language) {
        $languages[] = [
          'name' => $language['name'],
          'code' => $language['code'],
        ];
      }
      $tags['language'] = $languages;

      // Determine document type.
      $format = 'Report';
      if (!empty($fields['format'])) {
        if (isset($fields['format']['name'])) {
          $format = $fields['format']['name'];
        }
        elseif (isset($fields['format'][0]['name'])) {
          $format = $fields['format'][0]['name'];
        }
      }

      // Set the summary if it's empty but there are attachments.
      if (empty($summary) && !empty($fields['file'])) {
        $summary = $this->getSummaryFromFormat($format, $fields['file']);
      }

      // Base article data.
      $data = [
        'id' => $item['id'],
        'bundle' => $this->bundle,
        'title' => $title,
        'summary' => $summary,
        'format' => $format,
        'tags' => $tags,
      ];

      // Url to the article.
      if (isset($fields['url_alias'])) {
        $data['url'] = UrlHelper::stripDangerousProtocols($fields['url_alias']);
      }
      else {
        $data['url'] = UrlHelper::encodeUrl('node/' . $item['id'], FALSE);
      }

      // Creation and publication dates.
      if (isset($fields['date']['created'])) {
        $data['posted'] = static::createDate($fields['date']['created']);
      }
      if (isset($fields['date']['original'])) {
        $data['published'] = static::createDate($fields['date']['original']);
      }

      // Attachment preview.
      if (!empty($fields['file'][0]['preview'])) {
        $preview = $fields['file'][0]['preview'];
        $url = $preview['url-thumb'] ?? $preview['url-small'] ?? '';
        if (!empty($url)) {
          $data['preview'] = [
            'url' => UrlHelper::stripDangerousProtocols($url),
            // We don't have any good label/description for the file
            // previews so we use an empty alt to mark them as decorative
            // so that assistive technologies will ignore them.
            'alt' => '',
          ];
        }
      }

      // Headline image.
      if ($headlines && isset($fields['headline']['image']['url'])) {
        $image = $fields['headline']['image'];
        $copyright = trim($image['copyright'] ?? '');
        if (!empty($copyright) && mb_strpos($copyright, '©') === FALSE) {
          $copyright = '© ' . $copyright;
        }

        $data['image'] = [
          'url' => UrlHelper::stripDangerousProtocols($image['url']),
          'alt' => $image['alt'] ?? '',
          'copyright' => $copyright,
          'width' => $image['width'] ?? 0,
          'height' => $image['height'] ?? 0,
        ];
      }

      // Compute the language code for the resource's data.
      $data['langcode'] = static::getLanguageCode($data);

      $entities[$item['id']] = $data;
    }

    return $entities;
  }

  /**
   * Get summary from the document content type.
   *
   * @param string $format
   *   Document format.
   * @param array $files
   *   Document attachments.
   *
   * @return string
   *   Summary for the document.
   */
  protected function getSummaryFromFormat($format, array $files) {
    switch ($format) {
      case 'Map':
        return $this->t('Please refer to the attached Map.');

      case 'Infographic':
        return $this->t('Please refer to the attached Infographic.');

      case 'Interactive':
        return $this->t('Please refer to the linked Interactive Content.');

      default:
        if (count($files) > 1) {
          return $this->t('Please refer to the attached files.');
        }
        else {
          return $this->t('Please refer to the attached file.');
        }
    }

    return '';
  }

}
