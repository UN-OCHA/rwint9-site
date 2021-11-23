<?php

namespace Drupal\guidelines;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;

class GuidelinesFromTrello {
  protected $key = '';
  protected $token = '';

  /**
   * The HTTP client service.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructor.
   *
   * @param string $key
   *   Trello key.
   * @param string $key
   *   Trello token.
   * @param Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   * @param Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct($key, $token, ConfigFactoryInterface $config_factory, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->key = $key;
    $this->token = $token;
    $this->config = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('reliefweb_guidelines');
  }

  public function getBoards() {
    $url = 'https://api.trello.com/1/members/me/boards';
    $parmeters = [
      'fields' => 'name,url',
    ];

    return $this->fetchData($url, $parmeters);
  }

  public function getLists($board_id, $status = 'open') {
    $url = strtr('https://api.trello.com/1/boards/@id/lists/@status', [
      '@id' => $board_id,
      '@status' => $status,
    ]);
    $parmeters = [
      'fields' => 'name,url,pos',
    ];

    return $this->fetchData($url, $parmeters);
  }

  public function getCards($list_id) {
    $url = strtr('https://api.trello.com/1/lists/@id/cards', [
      '@id' => $list_id,
    ]);
    $parmeters = [
      'fields' => 'desc,idBoard,idList,idShort,name,shortLink,badges,shortUrl,labels,idAttachmentCover',
    ];

    return $this->fetchData($url, $parmeters);
  }

  public function getCard($card_id) {
    $url = strtr('https://api.trello.com/1/cards/@id', [
      '@id' => $card_id,
    ]);
    $parmeters = [
      'fields' => 'desc,idBoard,idList,idShort,name,shortLink,badges,shortUrl,labels,idAttachmentCover',
    ];

    return $this->fetchData($url, $parmeters);
  }

  public function getCardAttachments($card_id) {
    $url = strtr('https://api.trello.com/1/cards/@id/attachments', [
      '@id' => $card_id,
    ]);
    $parmeters = [
      'fields' => 'name,isUpload,url,filename,idBoard,idList',
    ];

    return $this->fetchData($url, $parmeters);
  }

  protected function fetchData($url, $parameters = []) {
    $parameters += [
      'key' => $this->key,
      'token' => $this->token,
    ];

    $url = Url::fromUri($url, [
      'query' => $parameters,
    ])->toUriString();
dpm($url);
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
