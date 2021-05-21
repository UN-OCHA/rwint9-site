<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\HtmlSummerizer;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Service class to retrieve report resource for the report rivers.
 */
class Reports extends River {

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

    // Labels for the item dates and tags.
    $labels = [
      'posted' => $this->t('Posted'),
      'published' => $this->t('Originally published'),
      'tags' => [
        'country' => [$this->t('Country'), $this->t('Countries')],
        'source' => [$this->t('Source'), $this->t('Sources')],
      ],
    ];

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
        $summary = HtmlSummerizer::summarize($body, 200);
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

      $data = [
        'id' => $item['id'],
        'title' => $title,
        'summary' => $summary,
        'url' => $fields['url_alias'] ?? UrlHelper::encodeUrl('node/' . $item['id'], FALSE),
        'format' => $format,
        'tags' => $tags,
      ];

      if (isset($fields['date']['created'])) {
        $data['posted'] = $fields['date']['created'];
      }
      if (isset($fields['date']['original'])) {
        $data['published'] = $fields['date']['original'];
      }

      // Attachment preview.
      if (!empty($fields['file'][0]['preview'])) {
        $preview = $fields['file'][0]['preview'];
        $url = $preview['url-thumb'] ?? $preview['url-small'] ?? '';
        if (!empty($url)) {
          $data['preview'] = [
            'url' => $url,
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
          'url' => $image['url'],
          'alt' => $image['alt'] ?? '',
          'copyright' => $copyright,
          'width' => $image['width'] ?? 0,
          'height' => $image['height'] ?? 0,
        ];
      }

      // Wrap the item data for easy access/sanitation in the template.
      // @todo review if we should re-introduce a wrapper.
      $entities[$item['id']] = [
        'bundle' => $this->bundle,
        'data' => $data,
        'labels' => $labels,
      ];
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
