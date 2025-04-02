<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin\ReliefWebImporter;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_import\Attribute\ReliefWebImporter;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginBase;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface;
use Drupal\reliefweb_post_api\Helpers\HashHelper;

/**
 * Import reports from the UNHCR Data API.
 */
#[ReliefWebImporter(
  id: 'unhcr_data',
  label: new TranslatableMarkup('UNHCR Data importer'),
  description: new TranslatableMarkup('Import reports from the UNHCR Data API.')
)]
class UnhcrDataImporter extends ReliefWebImporterPluginBase {

  /**
   * ReliefWeb country ID to UNHCR country geo ID mapping.
   *
   * The UNHCR IDs were manually extracted from the "location" filter on
   * https://data.unhcr.org/en/search with mapping of the country names to
   * ReliefWeb ones when available to finally map the RW IDs to the UNHCR ones.
   *
   * The documents in the UNHCR API have a location field where entries contain
   * both the location name and a code. This code is the UNHCR country ID used
   * in this mapping.
   *
   * @var array<int, int|null>
   */
  protected array $countryMapping = [
    // Afghanistan.
    13 => 575,
    // Aland Islands (Finland).
    14 => NULL,
    // Albania.
    15 => 576,
    // Algeria.
    16 => 769,
    // American Samoa.
    17 => 778,
    // Andorra.
    18 => 577,
    // Angola.
    19 => 578,
    // Anguilla.
    20 => 770,
    // Antigua and Barbuda.
    21 => 579,
    // Argentina.
    22 => 580,
    // Armenia.
    23 => 581,
    // Aruba (The Netherlands).
    24 => 11773,
    // Australia.
    25 => 582,
    // Austria.
    26 => 583,
    // Azerbaijan.
    27 => 584,
    // Azores Islands (Portugal).
    28 => NULL,
    // Bahamas.
    29 => 592,
    // Bahrain.
    30 => 585,
    // Bangladesh.
    31 => 591,
    // Barbados.
    32 => 586,
    // Belarus.
    33 => 595,
    // Belgium.
    34 => 588,
    // Belize.
    35 => 602,
    // Benin.
    36 => 589,
    // Bermuda.
    37 => 590,
    // Bhutan.
    38 => 593,
    // Bolivia (Plurinational State of).
    39 => 596,
    // Bonaire, Saint Eustatius and Saba (The Netherlands).
    14894 => NULL,
    // Bosnia and Herzegovina.
    40 => 600,
    // Botswana.
    41 => 597,
    // Brazil.
    42 => 598,
    // British Virgin Islands.
    43 => 771,
    // Brunei Darussalam.
    44 => 599,
    // Bulgaria.
    45 => 601,
    // Burkina Faso.
    46 => 594,
    // Burundi.
    47 => 587,
    // Cabo Verde.
    52 => 615,
    // Cambodia.
    48 => 603,
    // Cameroon.
    49 => 349,
    // Canada.
    50 => 604,
    // Canary Islands (Spain).
    51 => 11076,
    // Cayman Islands.
    53 => 605,
    // Central African Republic.
    54 => 399,
    // Chad.
    55 => 410,
    // Channel Islands.
    56 => NULL,
    // Chile.
    57 => 607,
    // China.
    58 => 606,
    // China - Hong Kong (Special Administrative Region).
    59 => 646,
    // China - Macau (Special Administrative Region).
    60 => 674,
    // China - Taiwan Province.
    61 => NULL,
    // Christmas Island (Australia).
    62 => NULL,
    // Cocos (Keeling) Islands (Australia).
    63 => NULL,
    // Colombia.
    64 => 612,
    // Comoros.
    65 => 610,
    // Congo.
    66 => 476,
    // Cook Islands.
    67 => 611,
    // Costa Rica.
    68 => 613,
    // Côte d'Ivoire.
    69 => 509,
    // Croatia.
    70 => 648,
    // Cuba.
    71 => 614,
    // Curaçao (The Netherlands).
    14893 => 11774,
    // Cyprus.
    72 => 616,
    // Czechia.
    73 => 617,
    // Democratic People's Republic of Korea.
    74 => 663,
    // Democratic Republic of the Congo.
    75 => 486,
    // Denmark.
    76 => 618,
    // Djibouti.
    77 => 12122,
    // Dominica.
    78 => 619,
    // Dominican Republic.
    79 => 620,
    // Easter Island (Chile).
    80 => NULL,
    // Ecuador.
    81 => 621,
    // Egypt.
    82 => 1,
    // El Salvador.
    83 => 720,
    // Equatorial Guinea.
    84 => 622,
    // Eritrea.
    85 => 157,
    // Estonia.
    86 => 623,
    // Eswatini.
    223 => 736,
    // Ethiopia.
    87 => 160,
    // Falkland Islands (Malvinas).
    88 => 772,
    // Faroe Islands (Denmark).
    89 => 630,
    // Fiji.
    90 => 625,
    // Finland.
    91 => 626,
    // France.
    92 => 629,
    // French Guiana (France).
    93 => 624,
    // French Polynesia (France).
    94 => 628,
    // Gabon.
    96 => 632,
    // Galapagos Islands (Ecuador).
    97 => 12041,
    // Gambia.
    98 => 633,
    // Georgia.
    100 => 635,
    // Germany.
    101 => 636,
    // Ghana.
    102 => 637,
    // Gibraltar.
    103 => 638,
    // Greece.
    104 => 640,
    // Greenland (Denmark).
    105 => 785,
    // Grenada.
    106 => 641,
    // Guadeloupe (France).
    107 => NULL,
    // Guam.
    108 => 775,
    // Guatemala.
    109 => 12151,
    // Guinea.
    110 => 643,
    // Guinea-Bissau.
    111 => 639,
    // Guyana.
    112 => 644,
    // Haiti.
    113 => 645,
    // Heard Island and McDonald Islands (Australia).
    114 => NULL,
    // Holy See.
    115 => 756,
    // Honduras.
    116 => 647,
    // Hungary.
    117 => 649,
    // Iceland.
    118 => 650,
    // India.
    119 => 651,
    // Indonesia.
    120 => 652,
    // Iran (Islamic Republic of).
    121 => 654,
    // Iraq.
    122 => 5,
    // Ireland.
    123 => 653,
    // Isle of Man (The United Kingdom of Great Britain and Northern Ireland).
    124 => NULL,
    // Israel.
    125 => 655,
    // Italy.
    126 => 656,
    // Jamaica.
    127 => 657,
    // Japan.
    128 => 658,
    // Jordan.
    129 => 36,
    // Kazakhstan.
    130 => 659,
    // Kenya.
    131 => 178,
    // Kiribati.
    132 => 661,
    // Kuwait.
    133 => 664,
    // Kyrgyzstan.
    134 => 660,
    // Lao People's Democratic Republic (the).
    135 => 665,
    // Latvia.
    136 => 673,
    // Lebanon.
    137 => 71,
    // Lesotho.
    138 => 668,
    // Liberia.
    139 => 535,
    // Libya.
    140 => 666,
    // Liechtenstein.
    141 => 669,
    // Lithuania.
    142 => 671,
    // Luxembourg.
    143 => 672,
    // Madagascar.
    144 => 675,
    // Madeira (Portugal).
    145 => NULL,
    // Malawi.
    146 => 686,
    // Malaysia.
    147 => 685,
    // Maldives.
    148 => 681,
    // Mali.
    149 => 684,
    // Malta.
    150 => 690,
    // Marshall Islands.
    151 => 683,
    // Martinique (France).
    152 => 676,
    // Mauritania.
    153 => 677,
    // Mauritius.
    154 => 692,
    // Mayotte (France).
    155 => 768,
    // Mexico.
    156 => 682,
    // Micronesia (Federated States of).
    157 => 631,
    // Moldova.
    158 => 680,
    // Monaco.
    159 => 679,
    // Mongolia.
    160 => 687,
    // Montenegro.
    161 => 691,
    // Montserrat.
    162 => 773,
    // Morocco.
    163 => 688,
    // Mozambique.
    164 => 689,
    // Myanmar.
    165 => 693,
    // Namibia.
    166 => 694,
    // Nauru.
    167 => 702,
    // Nepal.
    168 => 695,
    // Netherlands.
    169 => 696,
    // Netherlands Antilles (The Netherlands).
    170 => NULL,
    // New Caledonia (France).
    171 => 627,
    // New Zealand.
    172 => 703,
    // Nicaragua.
    173 => 698,
    // Niger.
    174 => 1621,
    // Nigeria.
    175 => 699,
    // Niue (New Zealand).
    176 => 700,
    // Norfolk Island (Australia).
    177 => 779,
    // Northern Mariana Islands (The United States of America).
    178 => 776,
    // Norway.
    179 => 701,
    // Occupied Palestinian territory.
    180 => NULL,
    // Oman.
    181 => 704,
    // Pakistan.
    182 => 705,
    // Palau.
    183 => 710,
    // Panama.
    184 => 706,
    // Papua New Guinea.
    185 => 711,
    // Paraguay.
    186 => 707,
    // Peru.
    187 => 708,
    // Philippines.
    188 => 709,
    // Pitcairn Islands.
    189 => 784,
    // Poland.
    190 => 712,
    // Portugal.
    191 => 713,
    // Puerto Rico (The United States of America).
    192 => 714,
    // Qatar.
    193 => 715,
    // Republic of Korea.
    194 => 662,
    // Réunion (France).
    195 => NULL,
    // Romania.
    196 => 716,
    // Russian Federation.
    197 => 12065,
    // Rwanda.
    198 => 719,
    // Saint Barthélemy (France).
    14890 => NULL,
    // Saint Helena.
    199 => 783,
    // Saint Kitts and Nevis.
    200 => 731,
    // Saint Lucia.
    201 => 667,
    // Saint Martin (France).
    14891 => NULL,
    // Saint Pierre and Miquelon (France).
    202 => 781,
    // Saint Vincent and the Grenadines.
    203 => 757,
    // Samoa.
    204 => 759,
    // San Marino.
    205 => 727,
    // Sao Tome and Principe.
    206 => 732,
    // Saudi Arabia.
    207 => 721,
    // Senegal.
    208 => 723,
    // Serbia.
    209 => 722,
    // Seychelles.
    210 => 724,
    // Sierra Leone.
    211 => 726,
    // Singapore.
    212 => 725,
    // Sint Maarten (The Netherlands).
    14892 => 12170,
    // Slovakia.
    213 => 734,
    // Slovenia.
    214 => 735,
    // Solomon Islands.
    215 => 728,
    // Somalia.
    216 => 192,
    // South Africa.
    217 => 717,
    // South Sudan.
    8657 => 259,
    // Spain.
    218 => 729,
    // Sri Lanka.
    219 => 670,
    // Sudan.
    220 => 295,
    // Suriname.
    221 => 733,
    // Svalbard and Jan Mayen Islands.
    222 => 780,
    // Sweden.
    224 => 737,
    // Switzerland.
    225 => 738,
    // Syrian Arab Republic.
    226 => 112,
    // Tajikistan.
    227 => 742,
    // Thailand.
    228 => 741,
    // The Republic of North Macedonia.
    229 => 678,
    // Timor-Leste.
    230 => 744,
    // Togo.
    231 => 745,
    // Tokelau.
    232 => 777,
    // Tonga.
    233 => 746,
    // Trinidad and Tobago.
    234 => 747,
    // Tunisia.
    235 => 748,
    // Türkiye.
    236 => 113,
    // Turkmenistan.
    237 => 743,
    // Turks and Caicos Islands.
    238 => 740,
    // Tuvalu.
    239 => 749,
    // Uganda.
    240 => 220,
    // Ukraine.
    241 => 751,
    // United Arab Emirates.
    242 => 750,
    // United Kingdom of Great Britain and Northern Ireland.
    243 => 634,
    // United Republic of Tanzania.
    244 => 217,
    // United States of America.
    245 => 753,
    // United States Virgin Islands.
    246 => 774,
    // Uruguay.
    247 => 752,
    // Uzbekistan.
    248 => 754,
    // Vanuatu.
    249 => 755,
    // Venezuela (Bolivarian Republic of).
    250 => 758,
    // Viet Nam.
    251 => 730,
    // Wallis and Futuna (France).
    252 => 782,
    // Western Sahara.
    253 => 760,
    // World.
    254 => 9999,
    // Yemen.
    255 => 225,
    // Zambia.
    256 => 761,
    // Zimbabwe.
    257 => 762,
  ];

  /**
   * Language mapping.
   *
   * @var array<string, int>
   */
  protected array $languageMapping = [
    'Arabic' => 6876,
    'English' => 267,
    'French' => 268,
    'Russian' => 10906,
    'Spanish' => 269,
  ];

  /**
   * Content format mapping.
   *
   * @var array<string, int>
   */
  protected array $formatMapping = [
    // Analysis.
    '3RP Documents' => 3,
    'Assessments' => 3,
    'Policy Papers' => 3,
    'Population Profiling' => 3,
    'Reports' => 3,
    'Reports and Assessments' => 3,
    'Reports and Policy Papers' => 3,
    // Appeal.
    'National Refugee Response Plans' => 4,
    'Regional Response Plans' => 4,
    'Regional RRP Documents' => 4,
    // Assessment.
    'CORE' => 5,
    // Evaluation and Lessons Learned.
    'Promising Practices and Case Studies' => 6,
    // Manual and Guideline.
    'Accountability and Inclusion' => 7,
    'Communication with Communities' => 7,
    'Countering Violent Extremism (CVE)' => 7,
    'CRRF' => 7,
    'Guidance' => 7,
    'Training Materials' => 7,
    // News and Press Release.
    'Flash Update' => 8,
    'Media Reports' => 8,
    'Press Releases' => 8,
    'Protection Brief' => 8,
    'Updates' => 8,
    // Other.
    '3W' => 9,
    'Contact List' => 9,
    'COVID-19' => 9,
    'Funding' => 9,
    'Meeting Minutes' => 9,
    'Operations Cell' => 9,
    'Site Profiles' => 9,
    'Strategy Documents' => 9,
    'Terms of Reference (TOR)' => 9,
    'Webinars' => 9,
    'Who What Where' => 9,
    // Situation Report.
    'Situation Reports' => 10,
    'Situation Reports / Updates' => 10,
    'Situation Updates' => 10,
    // Map.
    'Maps' => 12,
    // Infographic.
    'Dashboards & Factsheets' => 12570,
    'Data & Statistics' => 12570,
    'Statistics' => 12570,
  ];

  /**
   * Theme mapping.
   *
   * @var array<string, int>
   */
  protected array $themeMapping = [
    // Shelter and Non-Food Items.
    'Basic Needs' => 4603,
    // No good match.
    'Bureau' => NULL,
    // Camp Coordination and Camp Management.
    'Camp Coordination and Management' => 49458,
    // Humanitarian Financing.
    'Cash Assistance' => 4597,
    // No good match.
    'Country Operation' => NULL,
    // Recovery and Reconstruction.
    'Early Recovery' => 4601,
    // Education.
    'Education' => 4592,
    // Shelter and Non-Food Items.
    'Emergency Shelter and NFI' => 4603,
    // Logistics and Telecommunications.
    'Emergency Telecommunications' => 4598,
    // Food and Nutrition.
    'Food Security' => 4593,
    // Health.
    'Health' => 4595,
    // Protection and Human Rights.
    'Human Trafficking' => 4600,
    // Logistics and Telecommunications.
    'Logistics' => 4598,
    // No good match.
    'Other' => NULL,
    // Protection and Human Rights.
    'Protection' => 4600,
    // Water Sanitation Hygiene.
    'Water Sanitation Hygiene' => 4604,
  ];

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API URL'),
      '#description' => $this->t('The base URL of the UNHCR Data API.'),
      '#default_value' => $form_state->getValue('api_url', $this->getPluginSetting('api_url', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('The API key for authentication.'),
      '#default_value' => $form_state->getValue('api_key', $this->getPluginSetting('api_key', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['list_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('List Endpoint'),
      '#description' => $this->t('The endpoint path to get a list of documents.'),
      '#default_value' => $form_state->getValue('list_endpoint', $this->getPluginSetting('list_endpoint', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['document_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Document Endpoint'),
      '#description' => $this->t('The endpoint path to get a single document.'),
      '#default_value' => $form_state->getValue('document_endpoint', $this->getPluginSetting('document_endpoint', '', FALSE)),
      '#required' => TRUE,
    ];

    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout'),
      '#description' => $this->t('Connection and request timeout in seconds.'),
      '#default_value' => $form_state->getValue('timeout', $this->getPluginSetting('timeout', 5, FALSE)),
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

      $this->getLogger()->info('Retrieving documents from the UNHCR data API.');

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

    $this->getLogger()->info(strtr('Retrieved @count UNHCR documents.', [
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
   * Retrieve documents from the UNHCR API.
   *
   * @param int $limit
   *   Maximum number of documents to retrieve at once.
   * @param string $order_property
   *   Property to use to sort the documents.
   *
   * @return array
   *   List of documents keyed by IDs.
   */
  protected function getDocuments(int $limit, string $order_property = 'created'): array {
    // Get list of documents.
    try {
      $timeout = $this->getPluginSetting('timeout', 5, FALSE);
      $api_url = $this->getPluginSetting('api_url');
      $api_key = $this->getPluginSetting('api_key');
      $list_endpoint = $this->getPluginSetting('list_endpoint');

      // Query the UNHCR API.
      $query = http_build_query([
        'API_KEY' => $api_key,
        'order' => [$order_property => 'desc'],
        'limit' => $limit,
      ]);

      $url = rtrim($api_url, '/') . '/' . trim($list_endpoint, '?/') . '?' . $query;

      $response = $this->httpClient->get($url, [
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

      // Make sure we do not leak the API key.
      if (isset($api_key)) {
        $message = str_replace($api_key, 'REDACTED_API_KEY', $message);
      }

      throw new \Exception($message);
    }

    // Map the document's data to the document's ID.
    $map = [];
    foreach ($documents as $document) {
      if (!isset($document['id'])) {
        continue;
      }
      $map[$document['id']] = $document;
    }

    return $map;
  }

  /**
   * Process the documents retrieved from the UNHCR API.
   *
   * @param array $documents
   *   UNHCR documents.
   * @param string $provider_uuid
   *   The provider UUID.
   * @param \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginInterface $plugin
   *   The Post API content plugin processor used to import the documents.
   *
   * @return int
   *   The number of documents that were skipped or imported successfully.
   */
  protected function processDocuments(array $documents, string $provider_uuid, ContentProcessorPluginInterface $plugin): int {
    $schema = $this->getJsonSchema('report');

    // This is the list of extensions supported by the report attachment field.
    $extensions = explode(' ', 'csv doc docx jpg jpeg odp ods odt pdf png pps ppt pptx svg xls xlsx zip');
    $allowed_mimetypes = array_filter(array_map(fn($extension) => $this->mimeTypeGuesser->guessMimeType('dummy.' . $extension), $extensions));
    $allowed_mimetypes[] = 'application/octet-stream';

    // Override some plugin settings to accommodate for specifities of the data.
    $plugin->setPluginSetting('schema', $schema);
    $plugin->setPluginSetting('attachments.allowed_mimetypes', $allowed_mimetypes);

    // Disable content type validation because the files to download do not have
    // consistent content type headers (ex: pdf instead of application/pdf).
    $plugin->setPluginSetting('validate_file_content_type', FALSE);

    // Source: UNHCR.
    $source = [2868];

    // The original mapping is ReliefWeb country ID to UNHCR country code for
    // convenience in keeping it up to date. We need to flip it to easiy look
    // up the ID from the UNHCR code below.
    $country_mapping = array_flip(array_filter($this->countryMapping));

    // Prepare the documents and submit them.
    $processed = 0;
    foreach ($documents as $document) {
      // Retrieve the document ID.
      if (!isset($document['id'])) {
        $this->getLogger()->notice('Undefined UNHCR document ID, skipping document import.');
        continue;
      }
      $id = $document['id'];

      // Retrieve the document URL.
      if (!isset($document['documentLink'])) {
        $this->getLogger()->notice(strtr('Undefined document URL for UNHCR document ID @id, skipping document import.', [
          '@id' => $id,
        ]));
        continue;
      }
      $url = $document['documentLink'];

      $this->getLogger()->info(strtr('Processing UNHCR document @id.', [
        '@id' => $id,
      ]));

      // Generate the UUID for the document.
      $uuid = $this->generateUuid($url);

      // Generate a hash from the UNHCR API data without the updated date and
      // download count since those change everytime the document is downloaded.
      $hash = HashHelper::generateHash($document, ['updated', 'downloadCount']);

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
        $this->getLogger()->info(strtr('UNHCR document @id (entity @entity_id) already imported and not changed, skipping.', [
          '@id' => $id,
          '@entity_id' => reset($records),
        ]));
        continue;
      }

      // Retrieve the title and clean it.
      $title = $this->sanitizeText($document['title'] ?? '');

      // The documents in the UNHCR API seldom have descriptions or good ones
      // so we simply skip the body.
      $body = '';

      // Retrieve the publication date.
      $published = $document['publishDate'] ?? $document['created'] ?? NULL;

      // Retrieve the document languages and default to English if none of the
      // supported languages were found.
      $languages = [];
      foreach ($document['languageName'] ?? [] as $language) {
        // Note: UNHCR language items have a 'name' property.
        if (isset($this->languageMapping[$language['name']])) {
          $languages[$language['name']] = $this->languageMapping[$language['name']];
        }
      }
      if (empty($languages)) {
        $languages['English'] = $this->languageMapping['English'];
      }

      // Retrieve the content format and map it to 'Other' if there is no match.
      $formats = [9];
      foreach ($document['docTypeName'] ?? [] as $type) {
        // Note: UNHCR doc type items are name strings directly.
        if (isset($this->formatMapping[$type])) {
          $formats = [$this->formatMapping[$type]];
          break;
        }
      }

      // Retrieve the countries. Consider the first one as the primary country.
      $countries = [];
      foreach ($document['location'] ?? [] as $location) {
        // Note: UNHCR location items have a 'code' property.
        if (isset($country_mapping[$location['code']])) {
          $country = [$location['code'] => $country_mapping[$location['code']]];
          // If the location is in the title, add it at the beginning so it is
          // considered the primary country.
          if (isset($location['name'])) {
            $country_name = trim(str_replace(' (country)', '', $location['name']));
            if (mb_stripos($title, $country_name) !== FALSE) {
              $countries = $country + $countries;
              continue;
            }
          }
          // Otherwise, add it at the end.
          $countries = $countries + $country;
        }
      }
      // Tag with World if empty so that, at least, we can import.
      if (empty($countries)) {
        $countries = [254];
      }

      // Retrieve the themes.
      $themes = [];
      foreach ($document['sectorName'] ?? [] as $sector) {
        // Note: UNHCR sector items are name strings directly.
        if (isset($this->themeMapping[$sector])) {
          $themes[$sector] = $this->themeMapping[$sector];
        }
      }

      // Retrieve the data for the attachment if any.
      $files = [];
      if (isset($document['downloadLink'])) {
        $info = $this->getRemoteFileInfo($document['downloadLink']);
        if (!empty($info)) {
          $file_url = $document['downloadLink'];
          $file_uuid = $this->generateUuid($file_url, $uuid);
          $files[] = [
            'url' => $file_url,
            'uuid' => $file_uuid,
          ] + $info;
        }
      }

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
        'language' => array_values($languages),
        'country' => array_values($countries),
        'format' => array_values($formats),
      ];

      // Add the optional fields.
      $data += array_filter([
        'theme' => array_values($themes),
        'file' => array_values($files),
      ]);

      // Submit the document directly, no need to go through the queue.
      try {
        $entity = $plugin->process($data);
        $processed++;
        $this->getLogger()->info(strtr('Successfully processed UNHCR document @id to entity @entity_id.', [
          '@id' => $id,
          '@entity_id' => $entity->id(),
        ]));
      }
      catch (\Exception $exception) {
        $this->getLogger()->error(strtr('Unable to process UNHCR document @id: @exception', [
          '@id' => $id,
          '@exception' => $exception->getMessage(),
        ]));
      }
    }

    return $processed;
  }

  /**
   * Get the checksum and filename of a remote file.
   *
   * @param string $url
   *   Remote file URL.
   * @param string $max_size
   *   Maximum file size (ex: 2MB). Defaults to the environment upload max size.
   *
   * @return array
   *   Checksum and filenamne of the remote file.
   */
  protected function getRemoteFileInfo(string $url, string $max_size = ''): array {
    $max_size = $this->getReportAttachmentAllowedMaxSize();
    if (empty($max_size)) {
      throw new \Exception('No allowed file max size.');
    }

    $allowed_extensions = $this->getReportAttachmentAllowedExtensions();
    if (empty($allowed_extensions)) {
      throw new \Exception('No allowed file extensions.');
    }

    try {
      $response = $this->httpClient->get($url, [
        'stream' => TRUE,
        // @todo retrieve that from the configuration.
        'connect_timeout' => 30,
        'timeout' => 600,
      ]);

      if ($max_size > 0 && $response->getHeaderLine('Content-Length') > $max_size) {
        throw new \Exception('File is too large.');
      }

      // Retrieve the filename.
      $content_disposition = $response->getHeaderLine('Content-Disposition') ?? '';
      if (preg_match('/filename="?([^"]+)"?/i', $content_disposition, $matches) !== 1) {
        throw new \Exception('Unable to retrieve file name.');
      }

      // Sanitize the file name.
      $filename = $this->sanitizeFileName(urldecode($matches[1]), $allowed_extensions);
      if (empty($filename)) {
        throw new \Exception(strtr('Invalid filename: @filename.', [
          '@filename' => $matches[1],
        ]));
      }

      $body = $response->getBody();

      $content = '';
      if ($max_size > 0) {
        $size = 0;
        while (!$body->eof()) {
          $chunk = $body->read(1024);
          $size += strlen($chunk);
          if ($size > $max_size) {
            $body->close();
            throw new \Exception('File is too large.');
          }
          else {
            $content .= $chunk;
          }
        }
      }
      else {
        $content = $body->getContents();
      }

      $checksum = hash('sha256', $content);
    }
    catch (\Exception $exception) {
      $this->getLogger()->notice(strtr('Unable to retrieve file information for @url: @exception', [
        '@url' => $url,
        '@exception' => $exception->getMessage(),
      ]));
      return [];
    }
    finally {
      if (isset($body)) {
        $body->close();
      }
    }

    return [
      'checksum' => $checksum,
      'filename' => $filename,
    ];
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

  /**
   * {@inheritdoc}
   */
  public function skipContentClassification(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationSpecifiedFieldCheck(array &$fields): void {
    // Mark all the field as optional so that the classification is not skipped
    // if any of the field is already filled.
    $fields = array_map(fn($item) => FALSE, $fields);
  }

  /**
   * {@inheritdoc}
   */
  public function alterContentClassificationForceFieldUpdate(array &$fields): void {
    // Force the update of the fields with the data from the classifier even
    // if they already had a value.
    $fields = array_map(fn($item) => TRUE, $fields);
  }

}
