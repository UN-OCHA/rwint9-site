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
  protected $entityTypeId = 'node';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'report';

  /**
   * {@inheritdoc}
   */
  public function getPageTitle() {
    return $this->t('Updates');
  }

  /**
   * {@inheritdoc}
   */
  public function getViews() {
    return [
      'all' => $this->t('All Updates'),
      'headlines' => $this->t('Headlines'),
      'maps' => $this->t('Maps / Infographics'),
      'reports' => $this->t('Reports only'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [
      'PC' => [
        'name' => $this->t('Primary country'),
        'type' => 'reference',
        'vocabulary' => 'country',
        'field' => 'primary_country.id',
        'widget' => [
          'type' => 'autocomplete',
          'label' => $this->t('Search for a primary country'),
          'resource' => 'countries',
        ],
        'operator' => 'AND',
      ],
      'C' => [
        'name' => $this->t('Country'),
        'type' => 'reference',
        'vocabulary' => 'country',
        'field' => 'country.id',
        'widget' => [
          'type' => 'autocomplete',
          'label' => $this->t('Search for a country'),
          'resource' => 'countries',
        ],
        'operator' => 'AND',
      ],
      'S' => [
        'name' => $this->t('Organization'),
        'type' => 'reference',
        'vocabulary' => 'source',
        'field' => 'source.id',
        'widget' => [
          'type' => 'autocomplete',
          'label' => $this->t('Search for an organization'),
          'resource' => 'sources',
          'parameters' => [
            'filter' => [
              'field' => 'content_type',
              'value' => 'report',
            ],
          ],
        ],
        'operator' => 'AND',
      ],
      'OT' => [
        'name' => $this->t('Organization type'),
        'type' => 'reference',
        'vocabulary' => 'organization_type',
        'field' => 'source.type.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select an organization type'),
        ],
        'operator' => 'OR',
      ],
      'D' => [
        'name' => $this->t('Disaster'),
        'type' => 'reference',
        'vocabulary' => 'disaster',
        'field' => 'disaster.id',
        'widget' => [
          'type' => 'autocomplete',
          'label' => $this->t('Search for a disaster'),
          'resource' => 'disasters',
        ],
        'operator' => 'OR',
      ],
      'DT' => [
        'name' => $this->t('Disaster type'),
        'type' => 'reference',
        'vocabulary' => 'disaster_type',
        'exclude' => [
          // Complex Emergency.
          41764,
        ],
        'field' => 'disaster_type.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a disaster type'),
        ],
        'operator' => 'AND',
      ],
      'T' => [
        'name' => $this->t('Theme'),
        'type' => 'reference',
        'vocabulary' => 'theme',
        'field' => 'theme.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a theme'),
        ],
        'operator' => 'AND',
      ],
      'F' => [
        'name' => $this->t('Content format'),
        'type' => 'reference',
        'vocabulary' => 'content_format',
        'field' => 'format.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a content format'),
        ],
        'operator' => 'OR',
      ],
      'L' => [
        'name' => $this->t('Language'),
        'type' => 'reference',
        'vocabulary' => 'language',
        'exclude' => [
          // Other.
          31996,
        ],
        'field' => 'language.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a language'),
        ],
        'operator' => 'OR',
      ],
      'DO' => [
        'name' => $this->t('Original publication date'),
        'type' => 'date',
        'field' => 'date.original',
        'widget' => [
          'type' => 'date',
          'label' => $this->t('Select original publication date'),
        ],
      ],
      'DA' => [
        'name' => $this->t('Posting date on ReliefWeb'),
        'type' => 'date',
        'field' => 'date.created',
        'widget' => [
          'type' => 'date',
          'label' => $this->t('Select posting date on ReliefWeb'),
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getApiPayload($view = '') {
    $payload = [
      'query' => [
        // @todo review the boosts. Maybe the title should have a higher one.
        'fields' => [
          'title^20',
          'body',
          'primary_country.name^100',
          'primary_country.shortname^100',
          'country.name^50',
          'country.shortname^50',
          'source.name^100',
          'source.shortname^100',
          'format.name^100',
          'disaster_type.name^100',
          'disaster.name^100',
          'language.name^100',
          'theme.name^100',
        ],
        'operator' => 'AND',
      ],
      'fields' => [
        'include' => [
          'id',
          'url_alias',
          'date.created',
          'date.original',
          'country.id',
          'country.iso3',
          'country.name',
          'country.shortname',
          'country.primary',
          'source.id',
          'source.name',
          'source.shortname',
          'language.id',
          'language.name',
          'language.code',
          'format.name',
          'file',
        ],
      ],
      'sort' => ['date.created:desc'],
    ];

    switch ($view) {
      // Headlines.
      case 'headlines':
        $payload['filter'] = [
          'field' => 'headline',
        ];
        $payload['fields']['include'][] = 'headline.title';
        $payload['fields']['include'][] = 'headline.summary';
        break;

      // Maps, Infographics and Interactive content.
      case 'maps':
        $payload['filter'] = [
          'field' => 'format.id',
          // Map, Infographic and Interactive term ids.
          // @todo use the term names instead?
          'value' => [12, 12570, 38974],
          'operator' => 'OR',
        ];
        $payload['fields']['include'][] = 'title';
        $payload['fields']['include'][] = 'body-html';
        break;

      // Reports only.
      case 'reports':
        $payload['filter'] = [
          'field' => 'format.id',
          // Map, Infographic and Interactive term ids.
          // @todo use the term names instead?
          'value' => [12, 12570, 38974],
          'operator' => 'OR',
          'negate' => TRUE,
        ];
        $payload['fields']['include'][] = 'title';
        $payload['fields']['include'][] = 'body-html';
        break;

      // All updates.
      default:
        $payload['fields']['include'][] = 'title';
        $payload['fields']['include'][] = 'body-html';

    }

    return $payload;
  }

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
          'url' => UrlHelper::getAliasFromPath('/taxonomy/term/' . $country['id']),
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
          'url' => UrlHelper::getAliasFromPath('/taxonomy/term/' . $source['id']),
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
      $format = '';
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
        $data['url'] = UrlHelper::getAliasFromPath('/node/' . $item['id']);
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
            // @todo once the report attachments have been added back,
            // generate the appropriate preview URL based on the file name.
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

        $data['image'] = [
          'uri' => UrlHelper::getImageUriFromUrl($image['url']),
          'alt' => $image['alt'] ?? '',
          'copyright' => trim($image['copyright'] ?? '', " \n\r\t\v\0@"),
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
