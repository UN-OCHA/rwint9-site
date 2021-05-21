<?php

namespace Drupal\reliefweb_rivers\Services;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;

/**
 * Base for river services.
 */
abstract class River implements RiverInterface {

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
  abstract public function parseData(array $data);

}
