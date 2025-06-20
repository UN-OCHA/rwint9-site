<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Service;

use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionEmptyBody;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptioNoSourceTag;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use Drupal\reliefweb_utility\HtmlToMarkdown\Converters\TextConverter;
use GuzzleHttp\ClientInterface;
use League\HTMLToMarkdown\HtmlConverter;
use Psr\Log\LoggerInterface;

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
    'u' => 'url',
    'r' => 'replace',
    'p' => 'puppeteer',
    'p2' => 'puppeteer2',
    'pa' => 'puppeteer-attrib',
    'pb' => 'puppeteer-blob',
    'd' => 'delay',
    't' => 'timeout',
    's' => 'status',
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
    if (!empty($this->settings['local_file_load'])) {
      $local_file_path = $this->settings['local_file_path'] ?? '/var/www/inoreader.json';
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

    if (!empty($this->settings['local_file_save'])) {
      $local_file_path = $this->settings['local_file_path'] ?? '/var/www/inoreader.json';
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
    $screenshot = NULL;
    $logMessages = [];
    $body = '';

    // Retrieve the title and clean it.
    $title = $this->sanitizeText(html_entity_decode($document['title'] ?? ''));
    if (empty($title)) {
      $title = $url;
    }

    // Retrieve the publication date.
    $published = $document['published'] ?? time();
    $published = DateHelper::format($published, 'custom', 'c');

    $origin_title = trim($this->sanitizeText($document['origin']['title'] ?? ''));
    $sources = [];
    $pdf = '';

    if (strpos($origin_title, '[source:') === FALSE) {
      $this->logger->info(strtr('No source defined for Inoreader @id, skipping. Origin is set to @origin_title', [
        '@id' => $id,
        '@origin_title' => $origin_title,
      ]));

      throw new ReliefwebImportExceptioNoSourceTag(strtr('No source defined for Inoreader @id.', [
        '@id' => $id,
      ]));
    }

    // Extract tags from the origin title.
    $tags = $this->extractTags($origin_title);

    // Source is mandatory, so present.
    $source_id = $tags['source'] ?? '';
    if (strpos($tags['source'], '-') !== FALSE) {
      [$source_id] = explode('-', $tags['source']);
    }

    $sources = [
      (int) $source_id,
    ];

    // Check for custom fetch timeout.
    if (isset($tags['timeout'])) {
      $fetch_timeout = (int) $tags['timeout'];
      unset($tags['timeout']);
    }

    // Status of the new report.
    $status = '';

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
            $screenshot = $puppeteer_result['screenshot'] ?? NULL;
            $logMessages = $puppeteer_result['log'] ?? NULL;
            break;

          case 'content':
            $body = $this->cleanBodyText($document['summary']['content'] ?? '');
            if (empty($body)) {
              $this->logger->error(strtr('Unable to retrieve the body content for Inoreader document @id.', [
                '@id' => $id,
              ]));

              throw new ReliefwebImportExceptionEmptyBody(strtr('No body content found for Inoreader document @id.', [
                '@id' => $id,
              ]));
            }
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
      elseif ($tag_key == 'status') {
        $status = $tag_value;
      }
    }

    if (empty($sources)) {
      $this->logger->info(strtr('No source defined for Inoreader @id, skipping. Origin is set to @origin_title', [
        '@id' => $id,
        '@origin_title' => $origin_title,
      ]));

      throw new ReliefwebImportExceptioNoSourceTag(strtr('No source defined for Inoreader @id.', [
        '@id' => $id,
      ]));
    }

    $has_pdf = !empty($pdf);

    // Force PDF to use HTTPS.
    if ($has_pdf && strpos($pdf, 'http://') === 0) {
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
      '_has_pdf' => $has_pdf,
      '_screenshot' => $screenshot,
      '_log' => $logMessages,
    ];

    if (!empty($status)) {
      $data['status'] = $status;
    }

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
   * Clean body text.
   */
  protected function cleanBodyText(string $text): string {
    // Decode it first.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Convert to markdown.
    $converter = new HtmlConverter();
    $converter->getConfig()->setOption('strip_tags', TRUE);
    $converter->getConfig()->setOption('use_autolinks', FALSE);
    $converter->getConfig()->setOption('header_style', 'atx');
    $converter->getConfig()->setOption('strip_placeholder_links', TRUE);

    // Use our own text converter to avoid unwanted character escaping.
    $converter->getEnvironment()->addConverter(new TextConverter());

    return trim($converter->convert($text));
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
        throw new \Exception('Failure (1) with response code: ' . $response->getStatusCode());
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
          throw new \Exception('Failure (2) with response code: ' . $response->getStatusCode());
        }

        return $response->getBody()->getContents();
      }
      catch (\Exception $exception) {
        // Fail silently.
        $this->logger->info('Failure with response code: ' . $exception->getMessage());
        throw new \Exception('Failure (3) with response code: ' . $exception->getMessage());
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
    $screenshot = FALSE;
    $debug = FALSE;

    // Check if we need to request the PDF as Blob.
    if (isset($tags['puppeteer-blob'])) {
      $blob = TRUE;
    }

    if (isset($tags['delay'])) {
      $delay = (int) $tags['delay'];
    }
    if (isset($tags['screenshot'])) {
      $screenshot = TRUE;
    }
    if (isset($tags['debug'])) {
      $debug = TRUE;
    }

    if (isset($tags['wrapper'])) {
      if (!is_array($tags['wrapper'])) {
        $tags['wrapper'] = [$tags['wrapper']];
      }

      foreach ($tags['wrapper'] as $wrapper) {
        $pdf = reliefweb_import_extract_pdf_file($page_url, $wrapper, $tags['puppeteer'], $tags['puppeteer-attrib'] ?? 'href', $fetch_timeout, $blob, $delay, $screenshot, $debug);
        if ($pdf) {
          break;
        }
      }
    }
    else {
      $pdf = reliefweb_import_extract_pdf_file($page_url, '', $tags['puppeteer'], $tags['puppeteer-attrib'] ?? 'href', $fetch_timeout, $blob, $delay, $screenshot, $debug);
    }

    if (empty($pdf)) {
      return [];
    }

    // If the PDF is a relative URL, convert it to an absolute URL.
    if (!empty($pdf['pdf']) && strpos($pdf['pdf'], 'http') !== 0) {
      $url_parts = parse_url($page_url);
      $pdf['pdf'] = ($url_parts['scheme'] ?? 'https') . '://' . $url_parts['host'] . $pdf['pdf'];
    }

    if (!$blob) {
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
      'X-ReliefWeb-Import' => '2',
    ];
  }

  /**
   * Check if a tag is a multi-value tag.
   */
  protected function isMultiValueTag(string $tag): bool {
    $multi_value_tags = [
      'wrapper',
      'url',
      'puppeteer',
    ];

    return in_array($tag, $multi_value_tags);
  }

  /**
   * Merge tags.
   */
  protected function mergeTags(array $tags, array $extra_tags): array {
    foreach ($extra_tags as $key => $value) {
      // Resolve tag aliases.
      if (isset($this->tagAliases[$key])) {
        $key = $this->tagAliases[$key];
      }

      if (isset($tags[$key])) {
        // If the tag is a multi-value tag, ensure it is an array.
        if ($this->isMultiValueTag($key)) {
          if (!is_array($tags[$key])) {
            $tags[$key] = [
              $tags[$key],
            ];
          }
          if (!is_array($value)) {
            $value = [$value];
          }
          $tags[$key] = array_unique(array_merge($tags[$key], $value));
        }
        else {
          $tags[$key] = $value;
        }
      }
      else {
        $tags[$key] = $value;
      }
    }

    $tags = $this->fixLegacyPuppeteer2Tag($tags);

    return $tags;
  }

  /**
   * Extract tags from a feed title.
   *
   * @param string $feed_name
   *   Inoreader feed name.
   *
   * @return array
   *   Tags.
   */
  public function extractTags(string $feed_name): array {
    if (empty($feed_name)) {
      return [];
    }

    // Extract the tags from the feed name.
    $tags = [];
    if (preg_match_all('/\[(.*?)\]/', $feed_name, $matches) > 0) {
      $matches = $matches[1] ?? [];

      // Parse everything so we can reference it easily.
      $tags = $this->parseTags($matches);
    }

    // Get extra tags from state.
    if (isset($tags['source'])) {
      $extra_tags = $this->state->get('reliefweb_importer_inoreader_extra_tags', []);

      // Merge extra tags if they exist.
      if (!empty($extra_tags[$tags['source']])) {
        $tags = $this->mergeTags($tags, $extra_tags[$tags['source']]);
      }
    }

    return $tags;
  }

  /**
   * Parse tags.
   */
  protected function parseTags(array $matches): array {
    $tags = [];

    foreach ($matches as $match) {
      $tag_parts = explode(':', $match);
      $tag_key = reset($tag_parts);
      $tag_key = trim($tag_key);

      // Resolve tag aliases.
      if (isset($this->tagAliases[$tag_key])) {
        $tag_key = $this->tagAliases[$tag_key];
      }

      array_shift($tag_parts);
      $tag_value = trim(implode(':', $tag_parts));

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

    $tags = $this->fixLegacyPuppeteer2Tag($tags);

    return $tags;
  }

  /**
   * Fix legacy puppeteer2 tag.
   */
  protected function fixLegacyPuppeteer2Tag(array $tags): array {
    // Combine puppeteer and puppeteer2 tags.
    if (isset($tags['puppeteer']) && isset($tags['puppeteer2'])) {
      // Make sure both are strings.
      if (is_string($tags['puppeteer']) && is_string($tags['puppeteer2'])) {
        $tags['puppeteer'] .= '|' . $tags['puppeteer2'];
      }
    }

    return $tags;
  }

}
