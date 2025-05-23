<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin\ReliefWebImporter;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_import\Attribute\ReliefWebImporter;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface;

/**
 * Import reports from the ECHO Map API.
 */
#[ReliefWebImporter(
  id: 'echo_map',
  label: new TranslatableMarkup('Echo Map importer'),
  description: new TranslatableMarkup('Import reports from the Echo Map API.')
)]
class EchoMapImporter extends EchoFlashUpdateImporter {

  /**
   * Source name.
   *
   * @var string
   */
  protected string $sourceName = 'ECHO Map';

  /**
   * URL pattern template to find manually posted documents.
   *
   * @var string
   */
  protected string $manualPostUrlPatternTemplate = 'https://erccportal.jrc.ec.europa.eu/ECHO%Products%/Maps#/%/{id}';

  /**
   * ID extraction regex to find the ID from the manually posted documents.
   *
   * @var string
   */
  protected string $manualPostIdExtractionRegex = '#^https://erccportal\.jrc\.ec\.europa\.eu/ECHO[^/]*Products[/]*/Maps.?/[^/]+/(\d+)[^/]*$#i';

  /**
   * Properties to use to generate the hash.
   *
   * @var array
   */
  protected array $hashDataProperties = [
    'ContentItemId',
    'Link',
    'Title',
    'Description',
    'MapType',
    'MapOf',
    'ItemSources.Name',
    'PublishedOnDate',
    'CreatedOnDate',
    'Description',
    'Country.Iso3',
    'Countries.Iso3',
    'EventTypeCode',
    'EventType.Code',
    'EventTypes.Code',
    'MainFileName',
  ];

  /**
   * {@inheritdoc}
   */
  protected function processDocuments(array $documents, string $provider_uuid, ContentProcessorPluginInterface $plugin): int {
    $bundle = $this->getEntityBundle();
    $schema = $this->getJsonSchema($bundle);
    $plugin->setPluginSetting('schema', $schema);

    return parent::processDocuments($documents, $provider_uuid, $plugin);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['file_url_pattern'] = [
      '#type' => 'url',
      '#title' => $this->t('File download URL pattern'),
      '#description' => $this->t('The file download URL pattern with a `@id` placeholder that is replaced by the document ID.'),
      '#default_value' => $form_state->getValue('file_url_pattern', $this->getPluginSetting('file_url_pattern', '', FALSE)),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function processDocumentData(string $uuid, array $document): array {
    $data = parent::processDocumentData($uuid, $document);

    $file_url_pattern = $this->getPluginSetting('file_url_pattern', '', FALSE);
    // Nothing to import if we do not have a file URL pattern.
    if (empty($file_url_pattern)) {
      return [];
    }

    // Retrieve the data for the attachment if any.
    $files = [];
    $file_url = strtr($file_url_pattern, ['@id' => $document['ContentItemId']]);
    $info = $this->getRemoteFileInfo($file_url);
    if (!empty($info)) {
      $file_uuid = $this->generateUuid($file_url, $uuid);
      $files[] = [
        'url' => $file_url,
        'uuid' => $file_uuid,
      ] + $info;
    }

    // Nothing to import if we do not have a file.
    if (empty($files)) {
      return [];
    }

    $data['file'] = $files;

    // Retrieve the map source and map type so we can generate a title
    // consistent with what was published on ReliefWeb.
    // All recent ECHO maps seem to use `DG ECHO` in the title on ReliefWeb.
    $map_source = 'DG ECHO';
    $map_type = $document['MapType'] ?? 'Daily Map';

    // Create the title.
    if (!empty($document['Description'])) {
      // Extract the date part from the ISO date.
      $title_date = substr($document['MapOf'] ?? $data['published'], 0, 10);
      // Convert to DD-MM-YYYY.
      $title_date = implode('/', array_reverse(explode('-', $title_date)));

      // The actual map tile is contained in the document description
      // which is in HTML. We need to strip the tags and decode it.
      $title_description = strip_tags($document['Description']);
      $title_description = Html::decodeEntities($title_description);

      // Combine to create the title.
      $title = $this->sanitizeText(implode(' ', array_filter([
        $title_description,
        '-',
        $map_source,
        $map_type,
        '|',
        $title_date,
      ])));

      // For some maps the description is not just the map title but a
      // description of its content and we may end up with a title which is
      // too long. In that case we just use the "Daily Map of ..." title so
      // that the map can at least be imported and the editorial team will
      // fix the title later on.
      if (strlen($title) < 255) {
        $data['title'] = $title;
      }
    }

    // Remove the body since it's actually the map title.
    $data['body'] = '';

    // This is a map.
    $data['format'] = [12];

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getJsonSchema(string $bundle): string {
    $schema = parent::getJsonSchema($bundle);
    $decoded = Json::decode($schema);
    if ($decoded) {
      // Allow attachment URLs without a PDF extension.
      unset($decoded['properties']['file']['items']['properties']['url']['pattern']);
      // Allow empty strings as body.
      unset($decoded['properties']['body']['minLength']);
      unset($decoded['properties']['body']['allOf']);
      unset($decoded['properties']['body']['not']);
      $schema = Json::encode($decoded);
    }
    return $schema;
  }

}
