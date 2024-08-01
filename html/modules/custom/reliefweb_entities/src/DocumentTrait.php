<?php

namespace Drupal\reliefweb_entities;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\reliefweb_entities\Entity\Source;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\reliefweb_utility\Helpers\MediaHelper;

/**
 * Trait implementing most methods of the DocumentInterface.
 *
 * @see Drupal\reliefweb_entities\DocumentInterface
 */
trait DocumentTrait {

  use StringTranslationTrait;

  /**
   * Get the meta information for the entity.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::getEntityMeta()
   */
  public function getEntityMeta() {
    return [];
  }

  /**
   * Get the entity image.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::getEntityImage()
   */
  public function getEntityImage() {
    if ($this->field_image->isEmpty()) {
      return [];
    }
    return MediaHelper::getImage($this->field_image);
  }

  /**
   * Get the list of social media links to share the content.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::getShareLinks()
   */
  public function getShareLinks() {
    $entity_id = $this->id();

    // Skip if the entity doesn't have an id, for example when previewing
    // an entity being created.
    if (empty($entity_id)) {
      return [];
    }

    $source = 'ReliefWeb';

    // Title.
    $title = $this->label();

    // Summary (use the headline summary if available).
    $summary = '';
    if ($this->hasField('field_headline_summary') && !$this->field_headline_summary->isEmpty()) {
      $summary = $this->field_headline_summary->value;
    }
    elseif ($this->hasField('body') && !$this->body->isEmpty()) {
      $body = check_markup($this->body->value, $this->body->format);
      $summary = HtmlSummarizer::summarize($body, 200, TRUE);
    }

    // Url with the tracking parameters.
    $url = Url::fromUri('entity:node/' . $entity_id, [
      'absolute' => TRUE,
      'query' => [
        'utm_medium' => 'social',
        'utm_campaign' => 'shared',
      ],
    ])->toString();

    // Social media platforms we support.
    $links['facebook'] = [
      'title' => $this->t('Share this on Facebook'),
      'url' => Url::fromUri('https://www.facebook.com/sharer.php', [
        'query' => [
          'u' => $url . '&utm_source=facebook.com',
          't' => $title,
        ],
      ]),
    ];
    $links['x'] = [
      'title' => $this->t('Share this on X'),
      'url' => Url::fromUri('https://x.com/share', [
        'query' => [
          'url' => $url . '&utm_source=x.com',
          // Truncate the title as text for X to stay within the allowed
          // number of characters.
          'text' => Unicode::truncate($title, 90, FALSE, TRUE),
          'via' => 'reliefweb',
        ],
      ]),
    ];
    $links['linkedin'] = [
      'title' => $this->t('Post this on LinkedIn'),
      'url' => Url::fromUri('https://www.linkedin.com/shareArticle', [
        'query' => [
          'mini' => 'true',
          'url' => $url . '&utm_source=linkedin.com',
          'title' => $title,
          'summary' => $summary,
          'source' => $source,
        ],
      ]),
    ];

    return [
      '#theme' => 'reliefweb_entities_entity_social_media_links',
      '#title' => $this->t('Share'),
      '#links' => $links,
      '#icons_only' => TRUE,
    ];
  }

  /**
   * Get the reports related to the entity.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::getRelatedContent()
   */
  public function getRelatedContent($limit = 4) {
    $client = \Drupal::service('reliefweb_api.client');
    $title = $this->t('Related Content');
    $query = [];

    // Get the standard report payload but exclude the attachments and body.
    $payload = RiverServiceBase::getRiverApiPayload('report');
    $payload['fields']['exclude'][] = 'body-html';
    $payload['fields']['exclude'][] = 'file';
    $payload['limit'] = $limit;

    // Disasters.
    $disaster_ids = $this->getReferencedEntityIds('field_disaster');
    if (!empty($disaster_ids)) {
      $query[] = 'disaster.id:' . implode(' OR disaster.id:', $disaster_ids);
    }

    // Themes.
    $theme_ids = $this->getReferencedEntityIds('field_theme');
    if (!empty($theme_ids)) {
      $query[] = 'theme.id:' . implode(' OR theme.id:', $theme_ids);
    }

    // Countries.
    // For non report resources (ex: jobs, training), limit to countries with
    // an ongoing humanitarian situation.
    if ($this->bundle() !== 'report' && $this->hasField('field_country')) {
      $country_ids = [];
      foreach ($this->field_country->referencedEntities() as $country) {
        if ($country->getModerationStatus() === 'ongoing') {
          $country_ids[] = $country->id();
        }
      }
    }
    else {
      $country_ids = $this->getReferencedEntityIds('field_country');
    }
    if (!empty($country_ids)) {
      $query[] = 'primary_country.id:' . implode(' OR primary_country.id:', $country_ids);
    }

    // Get the data.
    if (!empty($query)) {
      if ($this->bundle() === 'report') {
        // Exclude current report.
        $payload['filter'] = [
          'field' => 'id',
          'value' => $this->id(),
          'negate' => TRUE,
        ];
        // Add a sub-query to get the same report in other languages with a high
        // boost to give it precedence over the other related content.
        $query[] = $this->getReportInOtherLanguages() . '^100';
      }

      // Construct query string with boost.
      $payload['query']['value'] = implode(' OR ', $query);

      // Sort by score to get the most relevant documents first.
      $payload['sort'] = ['score:desc', 'date.created:desc'];

      // Get the API data.
      $data = $client->request('reports', $payload);

      // Get the list of entities from the API data.
      $entities = RiverServiceBase::getRiverData('report', $data);
    }

    // If there is no related content, we show the latest updates.
    if (empty($entities)) {
      $title = $this->t('Latest Updates');

      // Get the API data.
      $data = $client->request('reports', $payload);

      // Get the list of entities from the API data.
      $entities = RiverServiceBase::getRiverData('report', $data);
    }

    return [
      '#theme' => 'reliefweb_rivers_river',
      '#id' => 'related',
      '#title' => $title,
      '#resource' => 'reports',
      '#entities' => $entities,
      '#cache' => [
        'tags' => [
          'node_list:report',
          'taxonomy_term_list:country',
          'taxonomy_term_list:source',
        ],
      ],
    ];
  }

  /**
   * Get a query to find the same document in other languages.
   *
   * Search for documents witht the same tagging but a different language
   * published within 2 days of the document. This is not 100% accurate but
   * in the worst case it will surface documents that really similar to the
   * given one which are good candidates as related content.
   *
   * @return array
   *   Query requesting possible translations.
   */
  protected function getReportInOtherLanguages() {
    $query = [];

    // Same report in different languages should have the same tagging.
    $fields = [
      'primary_country' => 'primary_country',
      'country' => 'country',
      'source' => 'source',
      'content_format' => 'format',
      'theme' => 'theme',
      'disaster' => 'disaster',
      'disaster_type' => 'disaster_type',
    ];
    foreach ($fields as $field => $name) {
      $field = 'field_' . $field;
      $ids = $this->getReferencedEntityIds($field);
      if (!empty($ids)) {
        $query[] = $name . '.id:(' . implode(' AND ', $ids) . ')';
      }
    }

    // We want the documents with the same tagging in a different language.
    $languages = $this->getReferencedEntityIds('field_language');
    if (!empty($languages)) {
      $query[] = 'NOT language.id:(' . implode(' AND ', $languages) . ')';
    }

    // Search for documents 2 days around the publication date.
    if ($this->hasField('field_original_publication_date') && !$this->field_original_publication_date->isEmpty()) {
      $date = (int) $this->field_original_publication_date->first()->getValue();
      $query[] = 'date.original:[' . date(DATE_ATOM, $date - 2 * 24 * 60 * 60) .
                 ' TO ' . date(DATE_ATOM, $date + 2 * 24 * 60 * 60) . ']';
    }

    return '(' . implode(' AND ', $query) . ')';
  }

  /**
   * Get the entity meta information for the given entity reference field.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::getEntityMetaFromField()
   */
  public function getEntityMetaFromField($field, $code = NULL, array $extra_fields = ['shortname' => 'shortname']) {
    $field = 'field_' . $field;

    if (!$this->hasField($field) || !$this->{$field} instanceof EntityReferenceFieldItemList) {
      return [];
    }

    // Country and disaster have aprimary fields, retrieve the corresponding id.
    $main_field = str_replace('field_', 'field_primary_', $field);
    $main_id = NULL;
    if ($this->hasField($main_field) && $this->{$main_field} instanceof EntityReferenceFieldItemList) {
      $main_id = $this->{$main_field}->target_id;
    }

    $items = [];
    foreach ($this->{$field}->referencedEntities() as $entity) {
      if ($entity instanceof EntityModeratedInterface && !$entity->access('view')) {
        continue;
      }

      if ($code !== NULL) {
        $url = RiverServiceBase::getRiverUrl($this->bundle(), [
          'advanced-search' => '(' . $code . $entity->id() . ')',
        ], $entity->label(), TRUE);
      }
      else {
        $url = $entity->toUrl();
      }

      $item = [
        'name' => $entity->label(),
        'url' => $url,
      ];

      if (!empty($main_id) && $entity->id() === $main_id) {
        $item['main'] = TRUE;
      }

      // Add any extra field.
      foreach ($extra_fields as $name => $property) {
        $extra_field = 'field_' . $name;
        if ($entity->hasField($extra_field)) {
          $item[$property] = $entity->{$extra_field}->value ?? $entity->label();
        }
      }

      $items[] = $item;
    }

    return $items;
  }

  /**
   * Get the entity ids from a entity reference field.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::getReferencedEntityIds()
   */
  public function getReferencedEntityIds($field) {
    $ids = [];
    if ($this->hasField($field) && !$this->{$field}->isEmpty()) {
      foreach ($this->{$field} as $item) {
        $target_id = $item->target_id;
        if (!empty($target_id)) {
          $ids[] = $target_id;
        }
      }
    }
    return $ids;
  }

  /**
   * Convert a date to a \DateTime object.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::createDate()
   */
  public function createDate($date) {
    if (is_string($date) || is_int($date)) {
      $date = is_numeric($date) ? '@' . $date : $date;
      return new \DateTime((string) $date, new \DateTimeZone('UTC'));
    }
    elseif (is_a($date, \DateTime::class)) {
      return $date;
    }
    return NULL;
  }

  /**
   * Update the status of the sources when publishing an opportunity.
   */
  protected function updateSourceModerationStatus() {
    if (!$this->hasField('field_source') || $this->field_source->isEmpty()) {
      return;
    }

    if (!($this instanceof EntityPublishedInterface) || !$this->isPublished()) {
      return;
    }

    // Make the inactive or archive sources active when the node is published.
    foreach ($this->field_source as $item) {
      $source = $item->entity;
      if (empty($source) || !($source instanceof Source)) {
        continue;
      }

      if (in_array($source->getModerationStatus(), ['inactive', 'archive'])) {
        $source->notifications_content_disable = TRUE;
        $source->setModerationStatus('active');
        $source->setNewRevision(TRUE);
        $source->setRevisionLogMessage('Automatic status update due to publication of node ' . $this->id());
        $source->setRevisionUserId(2);
        $source->setRevisionCreationTime(time());
        $source->save();
      }
    }
  }

}
