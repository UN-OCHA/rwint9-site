<?php

namespace Drupal\reliefweb_rivers;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;

/**
 * Base for river services.
 */
abstract class RiverServiceBase implements RiverServiceInterface {

  use StringTranslationTrait;

  /**
   * The ReliefWeb API Client service.
   *
   * @var \Drupal\reliefweb_api\Services\ReliefWebApiClient
   */
  protected $apiClient;

  /**
   * The river name.
   *
   * @var string
   */
  protected $river;

  /**
   * The API resource for the river.
   *
   * @var string
   */
  protected $resource;


  /**
   * The entity type associated with the resource.
   *
   * @var string
   */
  protected $entityType;

  /**
   * The entity bundle associated with the resource.
   *
   * @var string
   */
  protected $bundle;

  /**
   * Constructor.
   *
   * @param \Drupal\reliefweb_api\Services\ReliefWebApiClient $api_client
   *   The ReliefWeb API Client service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager service.
   */
  public function __construct(ReliefWebApiClient $api_client, TranslationInterface $string_translation) {
    $this->apiClient = $api_client;
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  abstract public function parseApiData(array $api_data, $view = '');

  /**
   * {@inheritdoc}
   */
  public static function getLanguageCode(array &$data = NULL) {
    if (isset($data['langcode'])) {
      $langcode = $data['langcode'];
    }
    // Extract the main language code from the entity language tag.
    elseif (isset($data['tags']['language'])) {
      // English has priority over the other languages. If not present we
      // just get the first language code in the list.
      foreach ($data['tags']['language'] as $item) {
        if (isset($item['code'])) {
          if ($item['code'] === 'en') {
            $langcode = 'en';
            break;
          }
          elseif (!isset($langcode)) {
            $langcode = $item['code'];
          }
        }
      }
    }
    return $langcode ?? 'en';
  }

  /**
   * {@inheritdoc}
   */
  public static function createDate($date) {
    return new \DateTime($date, new \DateTimeZone('UTC'));
  }

}
