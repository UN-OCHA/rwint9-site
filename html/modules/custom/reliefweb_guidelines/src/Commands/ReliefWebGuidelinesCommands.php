<?php

namespace Drupal\reliefweb_guidelines\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Url;
use Drupal\file\FileRepositoryInterface;
use Drupal\guidelines\Entity\Guideline;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\TransferException;
use Symfony\Component\Uid\Uuid;

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

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
   * The file repository.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected $fileRepository;

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
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    AccountSwitcherInterface $account_switcher,
    ConfigFactoryInterface $config_factory,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    FileRepositoryInterface $file_repository,
    FileSystemInterface $file_system,
    ClientInterface $http_client,
    MessengerInterface $messenger,
    StreamWrapperManagerInterface $stream_wrapper_manager,
  ) {
    $this->accountSwitcher = $account_switcher;
    $this->configFactory = $config_factory;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileRepository = $file_repository;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->messenger = $messenger;
    $this->streamWrapperManager = $stream_wrapper_manager;
  }

  /**
   * Get the trello board ID.
   *
   * @return string
   *   Trello key.
   */
  public function getTrelloBoardId() {
    return $this->configFactory
      ->get('reliefweb_guidelines.settings')
      ->get('trello_board_id');
  }

  /**
   * Get the trello key.
   *
   * @return string
   *   Trello key.
   */
  public function getTrelloKey() {
    return $this->configFactory
      ->get('reliefweb_guidelines.settings')
      ->get('trello_key');
  }

  /**
   * Get the trello token.
   *
   * @return string
   *   Trello key.
   */
  public function getTrelloToken() {
    return $this->configFactory
      ->get('reliefweb_guidelines.settings')
      ->get('trello_token');
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
    $key = $this->getTrelloKey();
    $token = $this->getTrelloToken();
    $board_id = $this->getTrelloBoardId();

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

    // Storage for the path aliases.
    $path_alias_storage = $this->entityTypeManager->getStorage('path_alias');
    $path_alias_id = 10000000;

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
        'moderation_status' => 'published',
        '_is_migrating' => TRUE,
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
          'moderation_status' => 'published',
          '_is_migrating' => TRUE,
          // Disable the automatic creation of the URL alias. We will create
          // it manually to avoid ID conflicts with the other migrated content.
          'path' => [
            'pathauto' => 0,
          ],
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

        // Create the path alias.
        $path_alias_id++;
        $path_alias_alias = '/guideline/' . $shortlink['short_link'];
        $path_alias_url = 'https://reliefweb.int' . $path_alias_alias;
        $path_alias_uuid = Uuid::v3(Uuid::fromString(Uuid::NAMESPACE_URL), $path_alias_url)->toRfc4122();

        $path_alias_storage->create([
          'id' => $path_alias_id,
          'revision_id' => $path_alias_id,
          'uuid' => $path_alias_uuid,
          'path' => '/admin/structure/guideline/' . $guideline->id(),
          'alias' => $path_alias_alias,
          'langcode' => $guideline->language()->getId(),
          'status' => 1,
        ])->save();
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
      $options = [];
      if (strpos($url, 'https://trello.com') === 0) {
        $key = $this->getTrelloKey();
        $token = $this->getTrelloToken();
        $options['headers']['Authorization'] = "OAuth oauth_consumer_key=\"{$key}\", oauth_token=\"{$token}\"";
      }

      /** @var \Drupal\file\FileInterface $file */
      $file = $this->retrieveFile($url, $destination, TRUE, FileSystemInterface::EXISTS_REPLACE, $options);
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
   * Attempts to get a file using Guzzle HTTP client and to store it locally.
   *
   * This replaces `system_retrieve_file()`, adding an Authorization header
   * for Trello images.
   *
   * @param string $url
   *   The URL of the file to grab.
   * @param string $destination
   *   Stream wrapper URI specifying where the file should be placed. If a
   *   directory path is provided, the file is saved into that directory under
   *   its original name. If the path contains a filename as well, that one will
   *   be used instead.
   *   If this value is omitted, the site's default files scheme will be used,
   *   usually "public://".
   * @param bool $managed
   *   If this is set to TRUE, the file API hooks will be invoked and the file
   *   is registered in the database.
   * @param int $replace
   *   Replace behavior when the destination file already exists:
   *   - FileSystemInterface::EXISTS_REPLACE: Replace the existing file.
   *   - FileSystemInterface::EXISTS_RENAME: Append _{incrementing number} until
   *     the filename is unique.
   *   - FileSystemInterface::EXISTS_ERROR: Do nothing and return FALSE.
   * @param array $options
   *   Request ptions to pass to the HTTP client.
   *
   * @return mixed
   *   One of these possibilities:
   *   - If it succeeds and $managed is FALSE, the location where the file was
   *     saved.
   *   - If it succeeds and $managed is TRUE, a \Drupal\file\FileInterface
   *     object which describes the file.
   *   - If it fails, FALSE.
   *
   * @see system_retrieve_file()
   */
  protected function retrieveFile($url, $destination, $managed = FALSE, $replace = FileSystemInterface::EXISTS_RENAME, array $options = []) {
    $replace = FileSystemInterface::EXISTS_REPLACE;

    $parsed_url = parse_url($url);

    if (!isset($parsed_url['path'])) {
      return FALSE;
    }

    if (!isset($destination)) {
      $path = $this->fileSystem
        ->basename($parsed_url['path']);
      $path = $this->configFactory
        ->get('system.file')
        ->get('default_scheme') . '://' . $path;
      $path = $this->streamWrapperManager
        ->normalizeUri($path);
    }
    elseif (is_dir($this->fileSystem->realpath($destination))) {
      // Prevent URIs with triple slashes when glueing parts together.
      $path = str_replace('///', '//', "{$destination}/") .
        $this->fileSystem->basename($parsed_url['path']);
    }
    else {
      $path = $destination;
    }

    try {
      $data = (string) $this->httpClient
        ->get($url, $options)
        ->getBody();

      if ($managed) {
        $local = $this->fileRepository->writeData($data, $path, $replace);
      }
      else {
        $local = $this->fileSystem->saveData($data, $path, $replace);
      }
    }
    catch (TransferException $exception) {
      $this->messenger->addError(strtr('Failed to fetch file due to error "%error"', [
        '%error' => $exception->getMessage(),
      ]));
      return FALSE;
    }
    catch (FileException | InvalidStreamWrapperException $exception) {
      $this->messenger->addError(strtr('Failed to save file due to error "%error"', [
        '%error' => $exception->getMessage(),
      ]));
      return FALSE;
    }
    if (!$local) {
      $this->messenger->addError(strtr('@remote could not be saved to @path.', [
        '@remote' => $url,
        '@path' => $path,
      ]));
    }
    return $local;
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
      'key' => $this->getTrelloKey(),
      'token' => $this->getTrelloToken(),
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
