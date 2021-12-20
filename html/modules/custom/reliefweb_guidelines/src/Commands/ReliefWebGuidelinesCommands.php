<?php

namespace Drupal\reliefweb_guidelines\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Url;
use Drupal\guidelines\Entity\Guideline;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;

/**
 * ReliefWeb migration Drush commandfile.
 *
 * @todo remove after the migration from D7 to D9.
 */
class ReliefWebGuidelinesCommands extends DrushCommands {

  /**
   * The account switcher service.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The HTTP client service.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * ReliefWeb trello config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $trelloConfig;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    AccountSwitcherInterface $account_switcher,
    ConfigFactoryInterface $config_factory,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    ClientInterface $http_client
  ) {
    $this->accountSwitcher = $account_switcher;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->trelloConfig = $config_factory->get('reliefweb_trello.settings');
  }

  /**
   * Migrate the guidelines from Trello.
   *
   * @command rw-guidelines:migrate
   *
   * @aliases rw-gm,rw-guidelines-migrate
   *
   * @usage rw-guidelines:migrate
   *   Migrate the guidelines from Trello.
   *
   * @validate-module-enabled reliefweb_guidelines,reliefweb_utility
   */
  public function migrateFromTrello() {
    $key = $this->trelloConfig->get('key');
    $token = $this->trelloConfig->get('token');
    $board_id = $this->trelloConfig->get('board_id');

    if (empty($key) || empty($token) || empty($board_id)) {
      $this->logger->error('Missing Trello key, token or board id.');
    }

    // Switch to the system user.
    $system_user = $this->entityTypeManager->getStorage('user')->load(2);
    $this->accountSwitcher->switchTo($system_user);

    // Delete all the existing guidelines first.
    $this->deleteAllGuidelines();

    $shortlinks = [];
    $field_pattern = '/^field--(?<entity_type_id>[^.]+)\.(?<bundle>[^.]+)\.(?<field_name>[^.]+)$/';

    // Retrieve the data from the Guidelines Trello board.
    $lists = $this->getLists($board_id);
    foreach ($lists as $weight_list => $list) {
      $this->logger->info('List: ' . $list['name']);

      // Create guideline.
      $values = [
        'type' => 'guideline_list',
        'name' => $list['name'],
        'parent' => [],
        'weight' => $weight_list,
        'field_title' => $list['name'],
      ];

      $guideline_list = Guideline::create($values);
      $guideline_list->save();

      // Get cards.
      $cards = $this->getCards($list['id']);
      foreach ($cards as $weight_card => $card) {
        $this->logger->info('    Card: ' . $card['name']);

        $shortlink = [
          'short_link' => $card['shortLink'],
          'links_found' => $this->extractLinks($card['desc']),
        ];

        // Extract and fetch images.
        $images = $this->extractImages($card['desc']);

        // Create guideline.
        $values = [
          'type' => 'field_guideline',
          'name' => $card['name'],
          'parent' => [],
          'weight' => $weight_card,
          'field_short_link' => $card['shortLink'],
          'field_description' => [
            'value' => $card['desc'],
            'format' => 'guideline',
          ],
          'field_images' => $images,
        ];

        // Check if the card has labels associated to form fields.
        foreach ($card['labels'] as $label) {
          if (preg_match($field_pattern, $label['name'], $match) === 1) {
            $entity_type_id = $match['entity_type_id'];
            $bundle = $match['bundle'];
            $field_name = $match['field_name'];
            $key = $entity_type_id . '.' . $bundle . '.' . $field_name;

            try {
              $field_definitions = $this->entityFieldManager
                ->getFieldDefinitions($entity_type_id, $bundle);

              if (isset($field_definitions[$field_name])) {
                $values['field_field'][] = [
                  'value' => $key,
                ];
              }
              else {
                throw new \Exception('No field found for ' . $key);
              }
            }
            catch (\Exception $exception) {
              $this->logger->notice($exception->getMessage());
            }
          }
        }

        // Get attachments.
        if ($card['badges']['attachments'] > 0) {
          $attachments = $this->getCardAttachments($card['id']);
          foreach ($attachments as $attachment) {
            // Add external links, skip images.
            if (empty($attachment['isUpload'])) {
              $values['field_links'][] = [
                'uri' => $attachment['url'],
                'title' => $attachment['name'],
              ];
            }
          }
        }

        $guideline = Guideline::create($values);
        $guideline->setParents([$guideline_list]);
        $guideline->save();

        $shortlink['guideline'] = $guideline;
        $shortlink['guideline_link'] = '/guideline/' . $shortlink['short_link'];
        $shortlinks[$shortlink['short_link']] = $shortlink;
      }
    }

    // Fix shortlinks.
    foreach ($shortlinks as $shortlink) {
      // Skip if the guideline doesn't reference other cards.
      if (empty($shortlink['links_found'])) {
        continue;
      }

      $guideline = $shortlink['guideline'];
      $text = $guideline->field_description->value;
      $replaced = FALSE;

      foreach ($shortlink['links_found'] as $link => $id) {
        // Skip if it's not a link to a guideline.
        if (!isset($shortlinks[$id])) {
          continue;
        }

        // Replace the link with the guideline link.
        $text = str_replace($link, $shortlinks[$id]['guideline_link'], $text);

        $replaced = TRUE;
      }

      if ($replaced) {
        $guideline->field_description->value = $text;
        $guideline->save();
      }
    }

    $this->accountSwitcher->switchBack();
  }

  /**
   * Delete all guidelines.
   *
   * @command rw-guidelines:delete
   *
   * @aliases rw-gd,rw-guidelines-delete
   *
   * @usage rw-guidelines:delete-all
   *   Delete all the guidelines.
   *
   * @validate-module-enabled reliefweb_guidelines
   */
  public function deleteAllGuidelines() {
    $guidelines = Guideline::loadMultiple();
    foreach ($guidelines as $guideline) {
      if ($guideline->hasField('field_images')) {
        foreach ($guideline->field_images as $item) {
          $item->entity->delete();
        }
      }
      $guideline->delete();
    }
  }

  /**
   * Extract and fetch images.
   *
   * @param string $description
   *   Card description.
   *
   * @return array
   *   Values for the guideline field_images field.
   */
  protected function extractImages(&$description) {
    $images = [];
    $pattern = '|(\!\[(.*?)\])(\((http.*?)\))|';

    $description = preg_replace_callback($pattern, function ($matches) use (&$images) {
      $this->logger->notice('      File: ' . $matches[4]);

      // Download the file and save it a managed file.
      $file = $this->fetchImage($matches[4]);

      // If we couldn't retrieve the image, keep the original link so that the
      // editors can update it.
      if (empty($file)) {
        return $matches[0];
      }

      // Add the file ID to the collection.
      $images[] = ['target_id' => $file->id()];

      // Replace the image URL with ours.
      return $matches[1] . '(' . $file->createFileUrl() . ')';
    }, $description);

    return $images;
  }

  /**
   * Fetch external image and save locally.
   *
   * @param string $url
   *   Image URL.
   *
   * @return string
   *   Local file URL.
   */
  protected function fetchImage($url) {
    // Keep the images private as it's for internal use only.
    $destination = 'private://images/guidelines';
    $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY);

    try {
      /** @var \Drupal\file\FileInterface $file */
      $file = system_retrieve_file($url, $destination, TRUE, FileSystemInterface::EXISTS_REPLACE);
      if (!$file) {
        $this->logger->error($url . ' not fetched');
        return NULL;
      }
    }
    catch (\Exception $exception) {
      $this->logger->error($url . ' not accessible');
      return NULL;
    }

    return $file;
  }

  /**
   * Extract possible shortlinks.
   *
   * @param string $text
   *   Card description.
   *
   * @return array
   *   List of shortlink ids.
   */
  protected function extractLinks($text) {
    if (empty($text)) {
      return [];
    }

    $links = [];
    $matches = [];

    $pattern = '@https?://(?:(?:www.)?trello.com/c/|guidelines.rwdev.org/?#)([0-9a-zA-Z]{8})(?:/[\\p{L}-]*)?@u';
    if (preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER) !== FALSE) {
      foreach ($matches as $index => $match) {
        $links[$matches[$index][0]] = $matches[$index][1];
      }
    }

    return $links;
  }

  /**
   * Get all boards.
   *
   * @return array
   *   List of trello boards.
   */
  protected function getBoards() {
    $url = 'https://api.trello.com/1/members/me/boards';
    $parmeters = [
      'fields' => 'name,url',
    ];

    return $this->fetchData($url, $parmeters);
  }

  /**
   * Get all lists of a board.
   *
   * @param string $board_id
   *   Trello board ID.
   * @param string $status
   *   List status.
   *
   * @return array
   *   List of trello lists.
   */
  protected function getLists($board_id, $status = 'open') {
    $url = strtr('https://api.trello.com/1/boards/@id/lists/@status', [
      '@id' => $board_id,
      '@status' => $status,
    ]);
    $parmeters = [
      'fields' => 'name,url,pos',
    ];

    return $this->fetchData($url, $parmeters);
  }

  /**
   * Get all cards from a list.
   *
   * @param string $list_id
   *   Trello list ID.
   *
   * @return array
   *   List of trello cards.
   */
  protected function getCards($list_id) {
    $url = strtr('https://api.trello.com/1/lists/@id/cards', [
      '@id' => $list_id,
    ]);
    $parmeters = [
      'fields' => 'desc,idBoard,idList,idShort,name,shortLink,badges,shortUrl,labels,idAttachmentCover',
    ];

    return $this->fetchData($url, $parmeters);
  }

  /**
   * Get a card.
   *
   * @param string $card_id
   *   Card ID.
   *
   * @return array
   *   Trello card data.
   */
  protected function getCard($card_id) {
    $url = strtr('https://api.trello.com/1/cards/@id', [
      '@id' => $card_id,
    ]);
    $parmeters = [
      'fields' => 'desc,idBoard,idList,idShort,name,shortLink,badges,shortUrl,labels,idAttachmentCover',
    ];

    return $this->fetchData($url, $parmeters);
  }

  /**
   * Get attachments of a card.
   *
   * @param string $card_id
   *   Card ID.
   *
   * @return array
   *   Trello card attachments.
   */
  protected function getCardAttachments($card_id) {
    $url = strtr('https://api.trello.com/1/cards/@id/attachments', [
      '@id' => $card_id,
    ]);
    $parmeters = [
      'fields' => 'name,isUpload,url,filename,idBoard,idList',
    ];

    return $this->fetchData($url, $parmeters);
  }

  /**
   * Fetch data from API.
   *
   * @param string $url
   *   Trello API url.
   * @param array $parameters
   *   Request parameters.
   *
   * @return mixed
   *   Data from the Trello API.
   */
  protected function fetchData($url, array $parameters = []) {
    $parameters += [
      'key' => $this->trelloConfig->get('key'),
      'token' => $this->trelloConfig->get('token'),
    ];

    $url = Url::fromUri($url, [
      'query' => $parameters,
    ])->toUriString();

    try {
      $response = $this->httpClient->request('GET', $url);
    }
    catch (ClientException $exception) {
      return NULL;
    }

    // Decode the JSON response.
    $data = NULL;
    if ($response->getStatusCode() === 200) {
      $body = (string) $response->getBody();
      if (!empty($body)) {
        // Decode the data, skip if invalid.
        try {
          $data = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
        }
        catch (\Exception $exception) {
          $this->logger->notice('Unable to decode json.');
        }
      }
    }
    else {
      $this->logger->notice('Unable to retrieve data - response code: @code', [
        '@code' => $response->getStatusCode(),
      ]);
    }

    return $data;
  }

}
