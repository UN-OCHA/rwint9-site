<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin\ReliefWebImporter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_import\Attribute\ReliefWebImporter;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginBase;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface;
use Drupal\reliefweb_post_api\Helpers\HashHelper;

/**
 * Import reports from the ECHO Flash Update API.
 */
#[ReliefWebImporter(
  id: 'echo_flash_update',
  label: new TranslatableMarkup('Echo Flash Update Update importer'),
  description: new TranslatableMarkup('Import reports from the Echo Flash Update API.')
)]
class EchoFlashUpdateImporter extends ReliefWebImporterPluginBase {

  /**
   * Find country by iso code.
   */
  protected function getCountryByIso(string $iso3): ?int {
    if (empty($iso3)) {
      return 254;
    }

    static $country_mapping = [];
    if (empty($country_mapping)) {
      $countries = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => 'country']);
      foreach ($countries as $country) {
        $country_mapping[strtolower($country->get('field_iso3')->value)] = (int) $country->id();
      }
    }

    $iso3 = strtolower($iso3);
    return $country_mapping[$iso3] ?? 254;
  }

  /**
   * Find disaster type by code.
   */
  protected function getDisasterTypeByCode(string $code): ?int {
    if (empty($code)) {
      return NULL;
    }

    static $disaster_mapping = [];
    if (empty($disaster_mapping)) {
      $disasters = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => 'disaster_type']);
      foreach ($disasters as $disaster) {
        $disaster_code = strtolower($disaster->get('field_disaster_type_code')->value);
        if (in_array($disaster_code, ['ce', 'ot'])) {
          continue;
        }
        $disaster_mapping[$disaster_code] = (int) $disaster->id();
      }
    }

    $code = strtolower($code);
    return $disaster_mapping[$code] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API URL'),
      '#description' => $this->t('The URL of the Echo Flash Update API including ItemsPageSize.'),
      '#default_value' => $form_state->getValue('api_url', $this->getPluginSetting('api_url', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout'),
      '#description' => $this->t('Connection and request timeout in seconds.'),
      '#default_value' => $form_state->getValue('timeout', $this->getPluginSetting('timeout', 10, FALSE)),
      '#min' => 1,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function importContent(int $limit = 50): bool {
    // Get list of documents.
    try {
      $provider_uuid = $this->getPluginSetting('provider_uuid');

      // Retrieve the POST API content processor plugin.
      $plugin = $this->contentProcessorPluginManager->getPluginByResource('reports');

      // Ensure the provider is valid.
      $plugin->getProvider($provider_uuid);

      $this->getLogger()->info('Retrieving documents from the Echo Flash Update API.');

      // Retrieve the latest created documents.
      $documents = $this->getDocuments($limit);

      if (empty($documents)) {
        $this->getLogger()->notice('No documents.');
        return TRUE;
      }
    }
    catch (\Exception $exception) {
      $this->getLogger()->error($exception->getMessage());
      return FALSE;
    }

    $this->getLogger()->info(strtr('Retrieved @count Echo Flash Update documents.', [
      '@count' => count($documents),
    ]));

    // Sort the documents by ID ascending to process the oldest ones first.
    ksort($documents);

    // Process the documents importing new ones and updated ones.
    $processed = $this->processDocuments($documents, $provider_uuid, $plugin);

    // @todo check if we want to return TRUE only if there was no errors or if
    // return TRUE for partial success is fine enough.
    return $processed > 0;
  }

  /**
   * Retrieve documents from the Echo Flash Update API.
   *
   * @return array
   *   List of documents keyed by IDs.
   */
  protected function getDocuments(int $limit = 50): array {
    // Get list of documents.
    try {
      $timeout = $this->getPluginSetting('timeout', 10, FALSE);
      $api_url = $this->getPluginSetting('api_url');

      $api_parts = parse_url($api_url);
      parse_str($api_parts['query'] ?? '', $query);
      $query['ItemsPageSize'] = $limit;
      $api_url = $api_parts['scheme'] . '://' . $api_parts['host'] . $api_parts['path'] . '?' . http_build_query($query);

      $response = $this->httpClient->get($api_url, [
        'connect_timeout' => $timeout,
        'timeout' => $timeout,
      ]);

      if ($response->getStatusCode() !== 200) {
        // @todo try to retrieve the error message.
        throw new \Exception('Failure with response code: ' . $response->getStatusCode());
      }

      $content = $response->getBody()->getContents();

      if (!empty($content)) {
        $documents = json_decode($content, TRUE, flags: \JSON_THROW_ON_ERROR);
      }
      else {
        return [];
      }
    }
    catch (\Exception $exception) {
      $message = $exception->getMessage();
      throw new \Exception($message);
    }

    if (!isset($documents['Items'])) {
      return [];
    }

    // Map the document's data to the document's ID.
    $map = [];
    foreach ($documents['Items'] as $document) {
      if (!isset($document['ContentItemId'])) {
        continue;
      }
      $map[$document['ContentItemId']] = $document;
    }

    return $map;
  }

  /**
   * Process the documents retrieved from the Echo Flash Update API.
   *
   * @param array $documents
   *   Echo Flash Update documents.
   * @param string $provider_uuid
   *   The provider UUID.
   * @param \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface $plugin
   *   The Post API content plugin processor used to import the documents.
   *
   * @return int
   *   The number of documents that were skipped or imported successfully.
   */
  protected function processDocuments(array $documents, string $provider_uuid, ContentProcessorPluginInterface $plugin): int {
    // Source: Echo Flash Update.
    $source = [620];

    // Prepare the documents and submit them.
    $processed = 0;
    foreach ($documents as $document) {
      // Retrieve the document ID.
      if (!isset($document['ContentItemId'])) {
        $this->getLogger()->notice('Undefined Echo Flash Update document ID, skipping document import.');
        continue;
      }
      $id = $document['ContentItemId'];

      // Retrieve the document URL.
      if (!isset($document['Link'])) {
        $this->getLogger()->notice(strtr('Undefined document URL for Echo Flash Update document ID @id, skipping document import.', [
          '@id' => $id,
        ]));
        continue;
      }
      $url = $document['Link'];

      $this->getLogger()->info(strtr('Processing Echo Flash Update document @id.', [
        '@id' => $id,
      ]));

      // Generate the UUID for the document.
      $uuid = $this->generateUuid($url);
      $hash = HashHelper::generateHash($document);

      // Skip if there is already an entity with the same UUID and same content
      // hash since it means the document has been not updated since the last
      // time it was imported.
      $records = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('uuid', $uuid, '=')
        ->condition('field_post_api_hash', $hash, '=')
        ->execute();
      if (!empty($records)) {
        $processed++;
        $this->getLogger()->info(strtr('Echo Flash Update document @id (entity @entity_id) already imported and not changed, skipping.', [
          '@id' => $id,
          '@entity_id' => reset($records),
        ]));
        continue;
      }

      // Retrieve the title and clean it.
      $title = $this->sanitizeText($document['Title'] ?? '');

      // Retrieve the description.
      $body = $this->sanitizeText($document['Description'] ?? '', TRUE);

      // Retrieve the publication date.
      $published = $document['PublishedOnDate'] ?? $document['CreatedOnDate'] ?? NULL;

      // Retrieve the countries.
      $countries = [];
      if (isset($document['Country']['Iso3'])) {
        $country = $this->getCountryByIso($document['Country']['Iso3']);
        if (!empty($country)) {
          $countries[] = $country;
        }
      }
      foreach ($document['Countries'] ?? [] as $location) {
        if (isset($location['Iso3'])) {
          $country = $this->getCountryByIso($location['Iso3']);
          if (!empty($country)) {
            $countries[] = $country;
          }
        }
      }

      // Tag with World if empty so that, at least, we can import.
      if (empty($countries)) {
        $countries = [254];
      }

      $countries = array_unique($countries);

      // Disaster type.
      $disaster_types = [];
      if (isset($document['EventTypeCode'])) {
        if ($type = $this->getDisasterTypeByCode($document['EventTypeCode'])) {
          $disaster_types[] = $type;
        }
      }

      if (isset($document['EventTypes'])) {
        foreach ($document['EventTypes'] ?? [] as $event_type) {
          if (isset($event_type['Code'])) {
            if ($type = $this->getDisasterTypeByCode($event_type['Code'])) {
              $disaster_types[] = $type;
            }
          }
        }
      }

      $disaster_types = array_unique($disaster_types);

      // Submission data.
      $data = [
        'provider' => $provider_uuid,
        'bundle' => 'report',
        'hash' => $hash,
        'url' => $url,
        'uuid' => $uuid,
        'title' => $title,
        'body' => $body,
        'source' => $source,
        'published' => $published,
        'origin' => $url,
        'language' => [267],
        'country' => array_values($countries),
        'format' => [8],
      ];

      if (!empty($disaster_types)) {
        $data['disaster_type'] = array_values($disaster_types);
      }

      // Submit the document directly, no need to go through the queue.
      try {
        $entity = $plugin->process($data);
        $processed++;
        $this->getLogger()->info(strtr('Successfully processed Echo Flash Update document @id to entity @entity_id.', [
          '@id' => $id,
          '@entity_id' => $entity->id(),
        ]));
      }
      catch (\Exception $exception) {
        $this->getLogger()->error(strtr('Unable to process Echo Flash Update document @id: @exception', [
          '@id' => $id,
          '@exception' => $exception->getMessage(),
        ]));
      }
    }

    return $processed;
  }

}
