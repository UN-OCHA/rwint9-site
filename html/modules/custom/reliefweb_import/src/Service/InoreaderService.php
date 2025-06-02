<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Service;

use Drupal\Component\Utility\Environment;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service to interact with the Inoreader API.
 */
class InoreaderService {

  /**
   * The HTTP client to use for making requests.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger for the Inoreader service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The state service to store and retrieve state information.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The settings for the Inoreader service.
   *
   * @var array
   */
  protected array $settings;

  /**
   * Mapping of tag aliases to their actual values.
   */
  protected array $tagAliases = [
    'w' => 'wrapper',
    'r' => 'replace',
    'p' => 'puppeteer',
    'p2' => 'puppeteer2',
    'pa' => 'puppeteer-attrib',
    'pb' => 'puppeteer-blob',
    'd' => 'delay',
  ];

  public function __construct(
    ClientInterface $http_client,
    StateInterface $state,
  ) {
    $this->httpClient = $http_client;
    $this->state = $state;
  }

  /**
   * Set the logger for the Inoreader service.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger to use for the Inoreader service.
   */
  public function setLogger(LoggerInterface $logger): void {
    $this->logger = $logger;
  }

  /**
   * Set the settings for the Inoreader service.
   *
   * @param array $settings
   *   The settings to use for the Inoreader service.
   */
  public function setSettings(array $settings): void {
    $this->settings = $settings;
  }

  /**
   * Get authorization token from Inoreader.
   */
  public function getAuthToken(): string {
    $timeout = $this->settings['timeout'] ?? 10;
    $email = $this->settings['email'] ?? '';
    $password = $this->settings['password'] ?? '';
    $app_id = $this->settings['app_id'] ?? '';
    $app_key = $this->settings['app_key'] ?? '';

    $response = $this->httpClient->post("https://www.inoreader.com/accounts/ClientLogin", [
      'connect_timeout' => $timeout,
      'timeout' => $timeout,
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'AppId' => $app_id,
        'AppKey' => $app_key,
      ],
      'form_params' => [
        'Email' => $email,
        'Passwd' => $password,
      ],
    ]);

    if ($response->getStatusCode() !== 200) {
      throw new \Exception('Failure with response code: ' . $response->getStatusCode());
    }

    $auth = '';
    $content = $response->getBody()->getContents();
    foreach (explode("\n", $content) as $line) {
      if (preg_match('/Auth=([^&]+)/', $line, $matches)) {
        $auth = $matches[1];
        break;
      }
    }

    if (empty($auth)) {
      throw new \Exception('Unable to retrieve auth token.');
    }

    return $auth;
  }

  /**
   * Retrieve documents from the Inoreader.
   *
   * @param int $limit
   *   Number of documents to fetch.
   *
   * @return array
   *   List of documents keyed by IDs.
   */
  public function getDocuments(int $limit = 50): array {
    // Check if we are using a local file for testing.
    if ($this->settings['local_file_load']) {
      $local_file_path = $this->settings['local_file_path'];
      $this->logger->info('Retrieving documents from disk.');
      $documents = file_get_contents($local_file_path);
      if ($documents === FALSE) {
        $this->logger->error('Unable to retrieve the Inoreader documents.');
        return [];
      }
      $documents = json_decode($documents, TRUE, flags: \JSON_THROW_ON_ERROR);
      $documents = array_slice($documents, 0, $limit);

      return $documents;
    }

    $this->logger->info('Retrieving documents from the Inoreader.');

    $real_limit = $limit;
    $continuation = '';
    $use_continuation = FALSE;
    if ($limit > 100) {
      $use_continuation = TRUE;
      $limit = 100;
    }

    $documents = [];

    try {
      $timeout = $this->settings['timeout'] ?? 10;
      $app_id = $this->settings['app_id'] ?? '';
      $app_key = $this->settings['app_key'] ?? '';
      $api_url = $this->settings['api_url'] ?? '';
      $max_age = (int) $this->state->get('reliefweb_importer_inoreader_max_age', 24 * 60 * 60);
      $most_recent_timestamp = (int) $this->state->get('reliefweb_importer_inoreader_most_recent_timestamp', 0);
      $ignore_timestamp = (bool) $this->state->get('reliefweb_importer_inoreader_ignore_timestamp', FALSE);

      if (empty($most_recent_timestamp)) {
        $most_recent_timestamp = (time() - $max_age) * 1_000_000;
      }
      else {
        $most_recent_timestamp -= (60 * 1_000_000);
      }

      $auth = $this->getAuthToken();

      while ($real_limit > 0) {
        $api_parts = parse_url($api_url);
        parse_str($api_parts['query'] ?? '', $query);
        $query['n'] = $limit;
        if (!empty($continuation)) {
          $query['c'] = $continuation;
        }

        if (!$ignore_timestamp) {
          $query['ot'] = $most_recent_timestamp;
          $query['xt'] = 'user/-/state/com.google/starred';
        }

        $request_url = $api_parts['scheme'] . '://' . $api_parts['host'] . $api_parts['path'] . '?' . http_build_query($query);

        $response = $this->httpClient->get($request_url, [
          'connect_timeout' => $timeout,
          'timeout' => $timeout,
          'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'AppId' => $app_id,
            'AppKey' => $app_key,
            'Authorization' => 'GoogleLogin auth=' . $auth,
          ],
        ]);

        if ($response->getStatusCode() !== 200) {
          throw new \Exception('Failure with response code: ' . $response->getStatusCode());
        }

        $content = $response->getBody()->getContents();
        if (!empty($content)) {
          $result = json_decode($content, TRUE, flags: \JSON_THROW_ON_ERROR);
          if (isset($result['items'])) {
            foreach ($result['items'] as $document) {
              if (!isset($document['id'])) {
                continue;
              }
              $documents[$document['id']] = $document;
            }
          }
          if ($use_continuation && isset($result['continuation'])) {
            $continuation = $result['continuation'];
            $real_limit -= $limit;
          }
          else {
            $continuation = '';
            $real_limit = 0;
          }
        }
        else {
          $continuation = '';
          $real_limit = 0;
        }
      }
    }
    catch (\Exception $exception) {
      $this->logger->error('InoreaderService: ' . $exception->getMessage());
      throw $exception;
    }

    if (!empty($settings['local_file_save'])) {
      $local_file_path = $settings['local_file_path'] ?? '/var/www/inoreader.json';
      $f = fopen($local_file_path, 'w');
      if ($f) {
        fwrite($f, json_encode($documents, \JSON_PRETTY_PRINT));
        fclose($f);
        $this->logger->info('Inoreader documents written to ' . $local_file_path);
      }
      else {
        $this->logger->error('Unable to open file ' . $local_file_path . ' for writing.');
      }
    }

    return $documents;
  }

  /**
   * Process the Inoreader document data.
   *
   * @param array $document
   *   The Inoreader document data.
   *
   * @return array
   *   Processed data ready for submission.
   */
  public function processDocumentData(array $document): array {
    $fetch_timeout = $this->settings['fetch_timeout'] ?? 10;

    $data = [];

    $id = $document['id'];
    $url = $document['canonical'][0]['href'];
    $pdf_bytes = NULL;

    // Retrieve the title and clean it.
    $title = $this->sanitizeText(html_entity_decode($document['title'] ?? ''));
    if (empty($title)) {
      $title = $url;
    }

    // Retrieve the publication date.
    $published = $document['published'] ?? time();
    $published = DateHelper::format($published, 'custom', 'c');

    // Retrieve the description.
    $body = $this->sanitizeText(html_entity_decode($document['summary']['content'] ?? ''), TRUE);

    $origin_title = trim($this->sanitizeText($document['origin']['title'] ?? ''));
    $sources = [];
    $pdf = '';

    if (strpos($origin_title, '[source:') === FALSE) {
      $this->logger->info(strtr('No source defined for Inoreader @id, skipping. Origin is set to @origin_title', [
        '@id' => $id,
        '@origin_title' => $origin_title,
      ]));

      return [];
    }

    preg_match_all('/\[(.*?)\]/', $origin_title, $matches);
    $matches = $matches[1];

    // Parse everything so we can reference it easily.
    $tags = [];
    foreach ($matches as $match) {
      $tag_parts = explode(':', $match);
      $tag_key = reset($tag_parts);
      if (isset($this->tagAliases[$tag_key])) {
        $tag_key = $this->tagAliases[$tag_key];
      }

      array_shift($tag_parts);
      $tag_value = implode(':', $tag_parts);

      if (!isset($tags[$tag_key])) {
        $tags[$tag_key] = $tag_value;
      }
      else {
        if (!is_array($tags[$tag_key])) {
          $tags[$tag_key] = [
            $tags[$tag_key],
          ];
        }
        $tags[$tag_key][] = $tag_value;
      }
    }

    // Get extra tags from state.
    $extra_tags = $this->state->get('reliefweb_importer_inoreader_extra_tags', []);

    if (!empty($extra_tags[$tags['source']])) {
      foreach ($extra_tags[$tags['source']] as $key => $value) {
        if (isset($this->tagAliases[$key])) {
          $key = $this->tagAliases[$key];
        }

        if (isset($tags[$key])) {
          if (!is_array($tags[$key])) {
            $tags[$key] = [
              $tags[$key],
            ];
          }
          $tags[$key] = array_merge($tags[$key], $value);
        }
        else {
          $tags[$key] = $value;
        }
      }
    }

    // Source is mandatory, so present.
    $sources = [
      (int) $tags['source'],
    ];

    // Check for custom fetch timeout.
    if (isset($tags['timeout'])) {
      $fetch_timeout = (int) $tags['timeout'];
      unset($tags['timeout']);
    }

    foreach ($tags as $tag_key => $tag_value) {
      if ($tag_key == 'pdf') {
        switch ($tag_value) {
          case 'canonical':
            $pdf = $document['canonical'][0]['href'] ?? '';
            break;

          case 'summary-link':
            $pdf = $this->extractPdfUrl($document['summary']['content'] ?? '', 'a', 'href');
            break;

          case 'page-link':
            $page_url = $document['canonical'][0]['href'] ?? '';
            $html = $this->downloadHtmlPage($page_url, $fetch_timeout);
            if (empty($html)) {
              $this->logger->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                '@id' => $id,
                '@url' => $page_url,
              ]));
            }
            else {
              $pdf = $this->tryToExtractPdfFromHtml($page_url, $html, $tags);
              if (isset($tags['follow'])) {
                // Follow link and fetch PDF from that page.
                if (strpos($pdf, $tags['follow']) !== FALSE) {
                  $page_url = $pdf;
                  $html = $this->downloadHtmlPage($page_url, $fetch_timeout);
                  if (empty($html)) {
                    $this->logger->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                      '@id' => $id,
                      '@url' => $page_url,
                    ]));
                  }
                  else {
                    $pdf = $this->tryToExtractPdfFromHtml($page_url, $html, $tags);
                  }
                }
              }
            }

            break;

          case 'page-object':
            $page_url = $document['canonical'][0]['href'] ?? '';
            $html = $this->downloadHtmlPage($page_url, $fetch_timeout);
            if (empty($html)) {
              $this->logger->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                '@id' => $id,
                '@url' => $page_url,
              ]));
            }
            $pdf = $this->extractPdfUrl($html, 'object', 'data');

            break;

          case 'page-iframe-data-src':
            $page_url = $document['canonical'][0]['href'] ?? '';
            $html = $this->downloadHtmlPage($page_url, $fetch_timeout);
            if (empty($html)) {
              $this->logger->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                '@id' => $id,
                '@url' => $page_url,
              ]));
            }
            $pdf = $this->extractPdfUrl($html, 'iframe', 'data-src');

            break;

          case 'page-iframe-src':
            $page_url = $document['canonical'][0]['href'] ?? '';
            $html = $this->downloadHtmlPage($page_url, $fetch_timeout);
            if (empty($html)) {
              $this->logger->error(strtr('Unable to retrieve the HTML content for Inoreader document @id -- @url.', [
                '@id' => $id,
                '@url' => $page_url,
              ]));
            }
            $pdf = $this->extractPdfUrl($html, 'iframe', 'src');
            break;

          case 'js':
            $page_url = $document['canonical'][0]['href'] ?? '';
            $puppeteer_result = $this->tryToExtractPdfUsingPuppeteer($page_url, $tags, $fetch_timeout);
            $pdf = $puppeteer_result['pdf'] ?? '';
            $pdf_bytes = $puppeteer_result['blob'] ?? NULL;
            break;

        }

        $pdf = $this->rewritePdfLink($pdf, $tags);
      }
      elseif ($tag_key == 'content') {
        switch ($tag_value) {
          case 'clear':
          case 'ignore':
            $body = '';
            break;
        }
      }
      elseif ($tag_key == 'title') {
        switch ($tag_value) {
          case 'filename':
          case 'canonical':
            $title = basename($document['canonical'][0]['href'] ?? '');
            $title = str_replace('.pdf', '', $title);
            $title = str_replace(['-', '_'], ' ', $title);
            $title = $this->sanitizeText($title);
            break;
        }
      }
    }

    if (empty($sources)) {
      $this->logger->info(strtr('No source defined for Inoreader @id, skipping. Origin is set to @origin_title', [
        '@id' => $id,
        '@origin_title' => $origin_title,
      ]));

      return [];
    }

    if (empty($pdf)) {
      $this->logger->info(strtr('No PDF found for Inoreader @id, skipping.', [
        '@id' => $id,
      ]));

      return [];
    }

    // Force PDF to use HTTPS.
    if (strpos($pdf, 'http://') === 0) {
      $pdf = str_replace('http://', 'https://', $pdf);
    }

    // Make sure the title is not too long or too short.
    if (strlen($title) > 255) {
      // Limit the title to 255 characters.
      $title = substr($title, 0, 240) . '...';
    }
    elseif (strlen($title) < 10) {
      // If the title is too short, use the URL instead.
      $title = $url;
    }

    // Force origin to use HTTPS.
    if (strpos($url, 'http://') === 0) {
      $url = str_replace('http://', 'https://', $url);
    }

    // Submission data.
    $data = [
      'title' => $title,
      'body' => substr($body ?? '', 0, 100000),
      'published' => $published,
      'origin' => $url,
      'source' => $sources,
      'language' => [267],
      'country' => [254],
      'format' => [8],
      'file_data' => [
        'pdf' => $pdf,
        'bytes' => $pdf_bytes,
      ],
      '_tags' => $tags,
    ];

    return $data;
  }

  /**
   * Sanitize a UTF-8 string.
   *
   * @param string $text
   *   The input UTF-8 string to be processed.
   * @param bool $preserve_newline
   *   If TRUE, ensure the new lines are preserved.
   *
   * @return string
   *   Sanitized text.
   *
   * @see \Drupal\reliefweb_utility\Helpers\TextHelper::sanitizeText()
   */
  protected function sanitizeText(string $text, bool $preserve_newline = FALSE): string {
    return TextHelper::sanitizeText($text, $preserve_newline);
  }

  /**
   * Try to extract the link to a PDF file from HTML content.
   * */
  protected function tryToExtractPdfFromHtml($page_url, $html, $tags) {
    $pdf = '';
    $contains = [];
    if (isset($tags['url'])) {
      if (is_array($tags['url'])) {
        $contains = $tags['url'];
      }
      else {
        $contains[] = $tags['url'];
      }
    }

    if (isset($tags['wrapper'])) {
      if (is_array($tags['wrapper'])) {
        foreach ($tags['wrapper'] as $wrapper) {
          $pdf = $this->extractPdfUrl($html, 'a', 'href', '', '', $wrapper, $contains);
          if ($pdf) {
            break;
          }
        }
      }
      else {
        $pdf = $this->extractPdfUrl($html, 'a', 'href', '', '', $tags['wrapper'], $contains);
      }
    }
    else {
      $pdf = $this->extractPdfUrl($html, 'a', 'href', '', '', '', $contains);
    }
    if (!empty($pdf) && strpos($pdf, 'http') !== 0) {
      $url_parts = parse_url($page_url);
      $pdf = ($url_parts['scheme'] ?? 'https') . '://' . $url_parts['host'] . $pdf;
    }

    return $pdf;
  }

  /**
   * Extract the PDF URL from the HTML content.
   */
  protected function extractPdfUrl(string $html, string $tag, string $attribute, string $class = '', string $extension = '', string $wrapper = '', array $contains = []): ?string {
    if (empty($html)) {
      return '';
    }

    $dom = new \DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new \DOMXPath($dom);

    $elements = [];
    if (empty($wrapper)) {
      $elements = $xpath->query("//{$tag}[@{$attribute}]");
    }
    else {
      [$wrapper_element, $wrapper_class] = explode('.', $wrapper);
      $parent = $xpath->query("//{$wrapper_element}[contains(@class, '{$wrapper_class}')]")->item(0);
      if (!$parent) {
        return '';
      }

      $elements = $xpath->query(".//{$tag}[@{$attribute}]", $parent);
    }

    /** @var \DOMNode $element */
    foreach ($elements as $element) {
      $url = $element->getAttribute($attribute);
      if (empty($url) || $url == '#') {
        continue;
      }

      if (!empty($class) && $element->hasAttribute('class') && preg_match('/\b' . preg_quote($class, '/') . '\b/', $element->getAttribute('class'))) {
        if (!empty($contains)) {
          foreach ($contains as $contain) {
            if (strpos($url, $contain) !== FALSE) {
              return $url;
            }
          }
        }
        else {
          return $url;
        }
      }

      if (!empty($extension) && preg_match('/\.' . $extension . '$/i', $url)) {
        if (!empty($contains)) {
          foreach ($contains as $contain) {
            if (strpos($url, $contain) !== FALSE) {
              return $url;
            }
          }
        }
        else {
          return $url;
        }
      }

      if (empty($class) && empty($extension)) {
        if (!empty($contains)) {
          foreach ($contains as $contain) {
            if (strpos($url, $contain) !== FALSE) {
              return $url;
            }
          }
        }
        else {
          return $url;
        }
      }
    }

    return '';
  }

  /**
   * Download HTML page as string.
   */
  protected function downloadHtmlPage($url, $fetch_timeout) {
    try {
      $response = $this->httpClient->get($url, [
        'connect_timeout' => $fetch_timeout,
        'timeout' => $fetch_timeout,
        'headers' => $this->getHttpHeaders(),
      ]);

      if ($response->getStatusCode() !== 200) {
        throw new \Exception('Failure with response code: ' . $response->getStatusCode());
      }

      return $response->getBody()->getContents();
    }
    catch (\Exception $exception) {
      try {
        // Try without headers.
        $response = $this->httpClient->get($url, [
          'connect_timeout' => $fetch_timeout,
          'timeout' => $fetch_timeout,
        ]);

        if ($response->getStatusCode() !== 200) {
          throw new \Exception('Failure with response code: ' . $response->getStatusCode());
        }

        return $response->getBody()->getContents();
      }
      catch (\Exception $exception) {
        // Fail silently.
        $this->logger->info('Failure with response code: ' . $exception->getMessage());
        return '';
      }
    }

    return '';
  }

  /**
   * Rewrite PDF link.
   */
  protected function rewritePdfLink($pdf, $tags) {
    if (empty($pdf)) {
      return $pdf;
    }

    if (!isset($tags['replace'])) {
      return $pdf;
    }

    if (!is_array($tags['replace'])) {
      $tags['replace'] = [$tags['replace']];
    }

    foreach ($tags['replace'] as $replace) {
      [$from, $to] = explode(':', $replace);
      $pdf = str_replace($from, $to, $pdf);
    }

    return $pdf;
  }

  /**
   * Try to extract the link to a PDF file from HTML content.
   *
   * @param string $page_url
   *   URL of page to fetch.
   * @param array $tags
   *   Inoreader feed tags.
   * @param int $fetch_timeout
   *   Fetch timeout.
   *
   * @return array
   *   Associative array with a `pdf` key for the PDF URL and an optional
   *   `blob` key for the raw bytes of the file; or an empty array in case
   *   of failure.
   */
  protected function tryToExtractPdfUsingPuppeteer(string $page_url, array $tags, int $fetch_timeout): array {
    $pdf = [];
    $blob = FALSE;
    $delay = 3000;

    // Check if we need to request the PDF as Blob.
    if (isset($tags['puppeteer-blob'])) {
      $blob = TRUE;
    }

    if (isset($tags['delay'])) {
      $delay = (int) $tags['delay'];
    }

    if (isset($tags['wrapper'])) {
      if (!is_array($tags['wrapper'])) {
        $tags['wrapper'] = [$tags['wrapper']];
      }

      foreach ($tags['wrapper'] as $wrapper) {
        $pdf = reliefweb_import_extract_pdf_file($page_url, $wrapper, $tags['puppeteer'], $tags['puppeteer2'] ?? '', $tags['puppeteer-attrib'] ?? 'href', $fetch_timeout, $blob, $delay);
        if ($pdf) {
          break;
        }
      }
    }
    else {
      $pdf = reliefweb_import_extract_pdf_file($page_url, '', $tags['puppeteer'], $tags['puppeteer2'] ?? '', $tags['puppeteer-attrib'] ?? 'href', $fetch_timeout, $blob, $delay);
    }

    if (empty($pdf)) {
      return [];
    }

    if (!$blob) {
      if (!empty($pdf['pdf']) && strpos($pdf['pdf'], 'http') !== 0) {
        $url_parts = parse_url($page_url);
        $pdf['pdf'] = ($url_parts['scheme'] ?? 'https') . '://' . $url_parts['host'] . $pdf['pdf'];
      }

      return $pdf;
    }

    if (empty($pdf['blob'])) {
      $this->logger->error(strtr('Unable to retrieve the PDF blob for Inoreader document @id -- @url.', [
        '@id' => $page_url,
        '@url' => $pdf['pdf'],
      ]));
      return [];
    }

    $pdf['blob'] = base64_decode($pdf['blob']);

    return $pdf;
  }

  /**
   * {@inheritdoc}
   */
  public function generateUuid(string $string, ?string $namespace = NULL): string {
    if (empty($string)) {
      return '';
    }
    /* The default namespace is the UUID generated with
     * Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), 'reliefweb.int')->toRfc4122(); */
    $namespace = $namespace ?? '8e27a998-c362-5d1f-b152-d474e1d36af2';
    return Uuid::v5(Uuid::fromString($namespace), $string)->toRfc4122();
  }

  /**
   * Get the checksum and filename of a remote file.
   *
   * @param string $url
   *   Remote file URL.
   * @param string $default_extension
   *   Default file extension if none could be extracted from the file name.
   * @param ?string $bytes
   *   Raw bytes of the file content.
   *
   * @return array
   *   Checksum, filename and raw bytes of the remote file.
   */
  protected function getRemoteFileInfo(string $url, string $default_extension = 'pdf', ?string $bytes = NULL): array {
    $max_size = Environment::getUploadMaxSize();
    $allowed_extensions = $this->getReportAttachmentAllowedExtensions();
    if (empty($allowed_extensions)) {
      throw new \Exception('No allowed file extensions.');
    }

    // Support raw bytes.
    if (!empty($bytes)) {
      // Validate the size.
      if ($max_size > 0 && strlen($bytes) > $max_size) {
        throw new \Exception('File is too large.');
      }

      // Sanitize the file name.
      $extracted_filename = basename($url);
      $filename = $this->sanitizeFileName($extracted_filename, $allowed_extensions, $default_extension);
      if (empty($filename)) {
        throw new \Exception(strtr('Invalid filename: @filename.', [
          '@filename' => $extracted_filename,
        ]));
      }

      // Compute the checksum.
      $checksum = hash('sha256', $bytes);

      return [
        'checksum' => $checksum,
        'filename' => $filename,
        'bytes' => $bytes,
      ];
    }

    $body = NULL;

    // Remote file.
    try {
      $response = $this->httpClient->get($url, [
        'stream' => TRUE,
        // @todo retrieve that from the configuration.
        'connect_timeout' => 30,
        'timeout' => 600,
        'headers' => $this->getHttpHeaders(),
      ]);

      if ($response->getStatusCode() == 406) {
        // Stream not supported.
        $response = $this->httpClient->get($url, [
          'stream' => FALSE,
          // @todo retrieve that from the configuration.
          'connect_timeout' => 30,
          'timeout' => 600,
          'headers' => $this->getHttpHeaders(),
        ]);
      }

      if ($response->getStatusCode() !== 200) {
        throw new \Exception('Unexpected HTTP status: ' . $response->getStatusCode());
      }

      $content_length = $response->getHeaderLine('Content-Length');
      if ($content_length !== '' && $max_size > 0 && ((int) $content_length) > $max_size) {
        throw new \Exception('File is too large.');
      }

      // Try to get the filename from the Content Disposition header.
      $content_disposition = $response->getHeaderLine('Content-Disposition') ?? '';
      $extracted_filename = UrlHelper::getFilenameFromContentDisposition($content_disposition);

      // Fallback to the URL if no filename is provided.
      if (empty($extracted_filename)) {
        $matches = [];
        $clean_url = UrlHelper::stripParametersAndFragment($url);
        if (preg_match('/\/([^\/]+)$/', $clean_url, $matches) === 1) {
          $extracted_filename = rawurldecode($matches[1]);
        }
        else {
          throw new \Exception('Unable to retrieve file name.');
        }
      }

      // Sanitize the file name.
      $filename = $this->sanitizeFileName($extracted_filename, $allowed_extensions, $default_extension);
      if (empty($filename)) {
        throw new \Exception(strtr('Invalid filename: @filename.', [
          '@filename' => $extracted_filename,
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
      // Return the raw bytes so that we don't have to download the file again
      // in the post api content processor.
      'bytes' => $content,
    ];
  }

  /**
   * Get HTTP headers.
   */
  protected function getHttpHeaders(): array {
    return [
      'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
      'accept-language' => 'en-BE,en;q=0.9',
      'dnt' => '1',
      'priority' => 'u=0, i',
      'sec-ch-ua' => '"Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"',
      'sec-ch-ua-mobile' => ' ?0',
      'sec-ch-ua-platform' => '"Linux"',
      'sec-fetch-dest' => 'document',
      'sec-fetch-mode' => 'navigate',
      'sec-fetch-site' => 'none',
      'sec-fetch-user' => '?1',
      'upgrade-insecure-requests' => '1',
      'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
    ];
  }

}
