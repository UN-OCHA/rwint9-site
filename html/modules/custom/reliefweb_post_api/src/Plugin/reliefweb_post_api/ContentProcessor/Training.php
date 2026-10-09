<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin\reliefweb_post_api\ContentProcessor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_post_api\Attribute\ContentProcessor;
use Drupal\reliefweb_post_api\Enum\ContentProcessorMessage;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginBase;

/**
 * Training content handler.
 */
#[ContentProcessor(
  id: 'reliefweb_post_api.content_processor.training',
  label: new TranslatableMarkup('Training'),
  entityType: 'node',
  bundle: 'training',
  resource: 'training'
)]
class Training extends ContentProcessorPluginBase {

  /**
   * Taxonomy term ID for the on-site training format.
   */
  protected const int ON_SITE_FORMAT_ID = 4606;

  /**
   * API fields involved in training JSON Schema conditionals.
   */
  protected const array CONDITIONAL_FIELDS = [
    'format',
    'country',
    'cost',
    'fee_information',
  ];

  /**
   * {@inheritdoc}
   */
  public function process(array $data): ?ContentEntityInterface {
    // Ensure the data is valid.
    $this->validate($data);

    $provider = $this->getProvider($data['provider'] ?? '');
    $user_id = $data['user'] ?? $provider->getUserId();

    $node = $this->loadEntityForProcessing($data, (int) $user_id);

    // Verify the bundle if the entity already exists.
    $this->validateEntityBundle($node);

    // Verify the entity is processable (not in a terminal status).
    $this->validateEntityProcessable($node);

    // Identical payload: no field writes, revision, or status change.
    if ($this->isUnchanged($node, $data)) {
      return $node;
    }

    $partial = !$node->isNew() && !empty($data['partial']);

    // Set the mandatory fields.
    if (!$partial || array_key_exists('title', $data)) {
      $node->title = $this->sanitizeString((string) ($data['title'] ?? ''));
    }

    if (!$partial || array_key_exists('source', $data)) {
      $this->setTermField($node, 'field_source', 'source', $data['source'] ?? []);
    }
    if (!$partial || array_key_exists('format', $data)) {
      $this->setTermField($node, 'field_training_format', 'training_format', $data['format'] ?? []);
    }

    if (!$partial || array_key_exists('event_url', $data)) {
      $this->setUrlField($node, 'field_link', (string) ($data['event_url'] ?? ''), $provider->getUrlPattern());
    }
    if (!$partial || array_key_exists('cost', $data)) {
      $this->setStringField($node, 'field_cost', (string) ($data['cost'] ?? ''));
    }

    if (!$partial || array_key_exists('category', $data)) {
      $this->setTermField($node, 'field_training_type', 'training_type', $data['category'] ?? []);
    }
    if (!$partial || array_key_exists('training_language', $data)) {
      $this->setTermField($node, 'field_training_language', 'training_language', $data['training_language'] ?? []);
    }

    if (!$partial || array_key_exists('language', $data)) {
      $this->setTermField($node, 'field_language', 'language', $data['language'] ?? []);
    }
    if (!$partial || array_key_exists('body', $data)) {
      $this->setTextField($node, 'body', (string) ($data['body'] ?? ''), format: 'markdown');
    }
    if (!$partial || array_key_exists('how_to_register', $data)) {
      $this->setTextField($node, 'field_how_to_register', (string) ($data['how_to_register'] ?? ''), format: 'markdown');
    }

    // Set the optional fields. Null clears.
    if (!$partial || array_key_exists('country', $data)) {
      $this->setTermField($node, 'field_country', 'country', $data['country'] ?? []);
    }

    if (!$partial || array_key_exists('dates', $data)) {
      if (!empty($data['dates']) && is_array($data['dates'])) {
        $this->setField($node, 'field_training_date', [
          'start' => $data['dates']['start'],
          'end' => $data['dates']['end'],
        ]);
        $this->setDateField($node, 'field_registration_deadline', (string) ($data['dates']['registration_deadline'] ?? ''));
      }
      else {
        $this->setField($node, 'field_training_date', NULL);
        $this->setDateField($node, 'field_registration_deadline', '');
      }
    }

    if (!$partial || array_key_exists('fee_information', $data)) {
      $this->setTextField($node, 'field_fee_information', $data['fee_information'] ?? '', format: 'plain');
    }

    if (!$partial || array_key_exists('professional_function', $data)) {
      $this->setTermField($node, 'field_career_categories', 'career_category', $data['professional_function'] ?? []);
    }
    if (!$partial || array_key_exists('theme', $data)) {
      $this->setTermField($node, 'field_theme', 'theme', $data['theme'] ?? []);
    }

    // Save the entity.
    $this->save($node, $provider, $data);

    return $node;
  }

  /**
   * {@inheritdoc}
   */
  protected function validatePartialConditionals(array $data): void {
    if (empty($data['partial'])) {
      return;
    }

    $touched = array_intersect(self::CONDITIONAL_FIELDS, array_keys($data));
    if ($touched === []) {
      return;
    }

    $uuid = $data['uuid'] ?? '';
    if (!is_string($uuid) || $uuid === '') {
      throw new ContentProcessorException(ContentProcessorMessage::MissingDocumentUuid->value);
    }

    $stored = $this->loadStoredConditionalFields($uuid);
    if ($stored === NULL) {
      throw new ContentProcessorException(ContentProcessorMessage::DocumentNotFound->value);
    }

    $format = array_key_exists('format', $data)
      ? $this->normalizeTermIds($data['format'] ?? [])
      : $stored['format'];
    $country = array_key_exists('country', $data)
      ? $this->normalizeTermIds($data['country'] ?? [])
      : $stored['country'];
    $cost = array_key_exists('cost', $data)
      ? (string) ($data['cost'] ?? '')
      : $stored['cost'];
    $fee_information = array_key_exists('fee_information', $data)
      ? (string) ($data['fee_information'] ?? '')
      : $stored['fee_information'];

    $on_site = in_array(self::ON_SITE_FORMAT_ID, $format, TRUE);
    if ($on_site && $country === []) {
      throw new ContentProcessorException(ContentProcessorMessage::CountryMandatoryForOnSite->value);
    }
    if (!$on_site && $country !== []) {
      throw new ContentProcessorException(ContentProcessorMessage::CountryNotAllowed->value);
    }

    $fee_based = $cost === 'fee-based';
    if ($fee_based && trim($fee_information) === '') {
      throw new ContentProcessorException(ContentProcessorMessage::FeeInformationMandatory->value);
    }
    if (!$fee_based && trim($fee_information) !== '') {
      throw new ContentProcessorException(ContentProcessorMessage::FeeInformationNotAllowed->value);
    }
  }

  /**
   * Load stored training fields used by schema conditionals.
   *
   * @param string $uuid
   *   Document UUID.
   *
   * @return array{format: list<int>, country: list<int>, cost: string, fee_information: string}|null
   *   Stored values, or NULL if the document does not exist.
   */
  protected function loadStoredConditionalFields(string $uuid): ?array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid, '=')
      ->condition('type', 'training', '=')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $nid = (int) reset($ids);

    $format = $this->database
      ->select('node__field_training_format', 'format')
      ->fields('format', ['field_training_format_target_id'])
      ->condition('format.entity_id', $nid)
      ->condition('format.deleted', 0)
      ->execute()
      ?->fetchCol() ?? [];

    $country = $this->database
      ->select('node__field_country', 'country')
      ->fields('country', ['field_country_target_id'])
      ->condition('country.entity_id', $nid)
      ->condition('country.deleted', 0)
      ->execute()
      ?->fetchCol() ?? [];

    $cost = $this->database
      ->select('node__field_cost', 'cost')
      ->fields('cost', ['field_cost_value'])
      ->condition('cost.entity_id', $nid)
      ->condition('cost.deleted', 0)
      ->range(0, 1)
      ->execute()
      ?->fetchField();

    $fee_information = $this->database
      ->select('node__field_fee_information', 'fee_information')
      ->fields('fee_information', ['field_fee_information_value'])
      ->condition('fee_information.entity_id', $nid)
      ->condition('fee_information.deleted', 0)
      ->range(0, 1)
      ->execute()
      ?->fetchField();

    return [
      'format' => $this->normalizeTermIds($format),
      'country' => $this->normalizeTermIds($country),
      'cost' => is_string($cost) ? $cost : '',
      'fee_information' => is_string($fee_information) ? $fee_information : '',
    ];
  }

  /**
   * Normalize term ID lists from the API or the database.
   *
   * @param mixed $ids
   *   Term IDs or null/empty clear.
   *
   * @return list<int>
   *   Integer term IDs.
   */
  protected function normalizeTermIds(mixed $ids): array {
    if (!is_array($ids)) {
      return [];
    }
    $normalized = [];
    foreach ($ids as $id) {
      if (is_int($id) || (is_string($id) && ctype_digit($id))) {
        $normalized[] = (int) $id;
      }
    }
    return $normalized;
  }

}
