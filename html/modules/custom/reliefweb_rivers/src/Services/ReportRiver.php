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
  public function getDefaultPageTitle() {
    return $this->t('Updates');
  }

  /**
   * {@inheritdoc}
   */
  public function getExcludedFilterCodesForTitle() {
    // We don't want to use the country (C) field for the updates river, because
    // that leads to duplicate or weird titles with the primary country field,
    // ex: "Afghanistan - Aghanistan Updates"...
    // See RiverServiceBase for the explanation about the exclusion of 'OT'.
    $codes = ['C' => TRUE, 'OT' => TRUE];
    // It doesn't make sense to have Maps + Situation Report for example.
    if ($this->getSelectedView() === 'maps') {
      $codes['F'] = TRUE;
    }
    return $codes;
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
    $filters = [
      'PC' => [
        'name' => $this->t('Primary country'),
        'shortname' => TRUE,
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
        'shortname' => TRUE,
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
        'shortname' => TRUE,
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
          'parameters' => [
            'sort' => 'date:desc',
          ],
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

    // Only include map, infographic and interactive content formats when
    // viewing maps/infographics.
    $view = $this->getSelectedView();
    if ($view === 'maps') {
      $filters['F']['include'] = [12, 12570, 38974];
    }
    // Exclude map, infographic and interactive content formats when viewing
    // reports only.
    elseif ($view === 'reports') {
      $filters['F']['exclude'] = [12, 12570, 38974];
    }

    return $filters;
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
      if (!empty($fields['file'][0]['preview']['url'])) {
        $preview = $fields['file'][0]['preview'];
        $uri = UrlHelper::getImageUriFromUrl($preview['url']);
        $version = $preview['version'] ?? $fields['file'][0]['id'] ?? 0;
        $dimensions = @getimagesize($uri) ?? [];
        $data['preview'] = [
          'uri' => $uri,
          'version' => $version,
          // We don't have any good label/description for the file
          // previews so we use an empty alt to mark them as decorative
          // so that assistive technologies will ignore them.
          'alt' => '',
          'width' => $dimensions[0] ?? NULL,
          'height' => $dimensions[1] ?? NULL,
        ];
      }

      // Headline image.
      if ($headlines && isset($fields['headline']['image']['url'])) {
        $image = $fields['headline']['image'];

        $data['image'] = [
          'uri' => UrlHelper::getImageUriFromUrl($image['url']),
          'alt' => $image['caption'] ?? '',
          'copyright' => trim($image['copyright'] ?? '', " \n\r\t\v\0@"),
          'width' => $image['width'] ?? NULL,
          'height' => $image['height'] ?? NULL,
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

  /**
   * {@inheritdoc}
   */
  public function getApiPayloadForRss($view = '') {
    $payload = $this->getApiPayload($view);
    $payload['fields']['include'][] = 'date.created';
    $payload['fields']['include'][] = 'theme.name';
    $payload['fields']['include'][] = 'disaster_type.name';
    if ($view === 'headlines') {
      $payload['fields']['include'][] = 'headline.image';
    }
    return $payload;
  }

  /**
   * {@inheritdoc}
   */
  public function parseApiDataForRss(array $data, $view = '') {
    $headlines = $view === 'headlines';
    $query = $this->requestStack->getCurrentRequest()->query;
    $country_slug = !$query->has('country_slug') || $query->getInt('country_slug') !== 0;

    $items = $data['items'] ?? $data['data'] ?? [];

    // Parse the entities retrieved from the API.
    $entities = [];
    foreach ($items as $item) {
      $fields = $item['fields'];

      // Base article data.
      $data = [
        'id' => $item['id'],
        'bundle' => $this->bundle,
      ];

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
      $data['format'] = $format;

      // Title.
      if ($headlines && !empty($fields['headline']['title'])) {
        $title = $fields['headline']['title'];
      }
      else {
        $title = $fields['title'];
      }
      // Add the primary country as prefix to the title, unless instructed
      // otherwise.
      if ($country_slug && !empty($fields['country'])) {
        foreach ($fields['country'] as $value) {
          // Ideally, we'd check the language and search for variations of the
          // the country name inside the title but as of February 2022, country
          // names in the API are only in English and alternate names are not
          // indexed and not exposed.
          if (!empty($value['primary'])) {
            // Prepend the country shortname if it exists and the shortname
            // and name are not in the title.
            if (!empty($value['shortname'])) {
              if (mb_stripos($title, $value['shortname']) === FALSE && mb_stripos($title, $value['name']) === FALSE) {
                $title = $value['shortname'] . ': ' . $title;
              }
            }
            // Prepend the country name if not in the title.
            elseif (mb_stripos($title, $value['name']) === FALSE) {
              $title = $value['name'] . ': ' . $title;
            }
            break;
          }
        }
      }
      $data['title'] = $title;

      // Url to the article.
      if (isset($fields['url_alias'])) {
        $data['url'] = UrlHelper::stripDangerousProtocols($fields['url_alias']);
      }
      else {
        $data['url'] = UrlHelper::getAliasFromPath('/node/' . $item['id']);
      }

      // Dates.
      $data['date'] = static::createDate($fields['date']['created']);

      // Body and Summary.
      //
      // The summary is just plain text, so we differenciate it from the body.
      if ($headlines && !empty($fields['headline']['summary'])) {
        $data['summary'] = $fields['headline']['summary'];
      }
      elseif (!empty($fields['body-html'])) {
        $data['body'] = $fields['body-html'];
      }

      // Set the summary if it's empty but there are attachments.
      if (empty($data['summary']) && !empty($fields['file'])) {
        $data['summary'] = $this->getSummaryFromFormat($format, $fields['file']);
      }

      // Media: headline image.
      if ($headlines && isset($fields['headline']['image']['url'])) {
        $image = $fields['headline']['image'];
        $copyright = trim($image['copyright'] ?? '');
        if (!empty($copyright) && mb_strpos($copyright, '©') === FALSE) {
          $copyright = '© ' . $copyright;
        }
        $data['media'][] = [
          'url' => $image['url'],
          'filesize' => $image['filesize'] ?? 0,
          'type' => $image['mimetype'] ?? '',
          'medium' => 'image',
          'expression' => 'full',
          'height' => $image['height'] ?? 0,
          'width' => $image['width'] ?? 0,
          'thumbnail' => $image['url-thumb'] ?? '',
          'title' => $image['caption'] ?? '',
          'copyright' => $copyright,
        ];
      }

      // Enclosure.
      // Only 1 as per rssboard.org/rss-profile#element-channel-item-enclosure.
      if (!empty($fields['file'][0])) {
        $file = $fields['file'][0];
        if (!empty($file['preview']['url-thumb'])) {
          $data['preview'] = $file['preview']['url-thumb'];
        }
        if (isset($file['filesize'], $file['mimetype'], $file['url'])) {
          $data['enclosure'] = [
            'length' => $file['filesize'],
            'type' => $file['mimetype'],
            'url' => $file['url'],
          ];
        }
      }

      // Categories.
      $categories = [
        'country' => [$this->t('Country'), $this->t('Countries')],
        'source' => [$this->t('Source'), $this->t('Sources')],
        'disaster' => [$this->t('Disaster'), $this->t('Disasters')],
        'theme' => [$this->t('Theme'), $this->t('Themes')],
        'format' => [$this->t('Format'), $this->t('Formats')],
        'disaster_type' => [
          $this->t('Disaster type'),
          $this->t('Disaster types'),
        ],
      ];
      $inline = ['country' => TRUE, 'source' => TRUE];
      foreach ($categories as $category => $labels) {
        if (!empty($fields[$category])) {
          $data['categories'][$category] = [
            'label' => $labels[count($fields[$category]) > 1 ? 1 : 0],
            'values' => array_map(function ($term) {
              return $term['name'];
            }, $fields[$category]),
            'inline' => isset($inline[$category]),
          ];
        }
      }

      // Compute the language code for the resource's data.
      $data['langcode'] = static::getLanguageCode($data);

      $entities[$item['id']] = $data;
    }

    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultRiverDescription() {
    return $this->t('Your gateway to all content to date. Search and/or drill down with filters to narrow down the content.');
  }

}
