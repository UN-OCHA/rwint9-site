<?php

namespace Drupal\reliefweb_utility\Plugin\Filter;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a filter to add attributes to external links to open in a new tab.
 *
 * @Filter(
 *   id = "reliefweb_external_link_filter",
 *   title = @Translation("Open external Links in new tab."),
 *   description = @Translation("Add target and rel attributes to external links so they open in a new tab."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 * )
 */
class ExternalLinkFilter extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * Current request.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Internal host pattern.
   *
   * @var string
   */
  protected $internalHostPattern;

  /**
   * Constructs a markdown filter plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    if (is_string($text) || $text instanceof MarkupInterface) {
      $html = trim($text);
      if ($html !== '') {
        // Adding this meta tag is necessary to tell \DOMDocument we are dealing
        // with UTF-8 encoded html.
        $flags = LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING;
        $meta = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
        $prefix = '<!DOCTYPE html><html><head>' . $meta . '</head><body>';
        $suffix = '</body></html>';
        $dom = new \DOMDocument();
        $dom->loadHTML($prefix . $text . $suffix, $flags);

        // Process the links.
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
          $this->handleLink($link);
        }

        // Get the modified html.
        $html = $dom->saveHTML();

        // Search for the body tag and return its content.
        $start = mb_strpos($html, '<body>');
        $end = mb_strrpos($html, '</body>');
        if ($start !== FALSE && $end !== FALSE) {
          $start += 6;
          $text = trim(mb_substr($html, $start, $end - $start));
        }
      }
    }
    return new FilterProcessResult($text);
  }

  /**
   * Check if a URL is an ReliefWeb URL.
   *
   * @param string $url
   *   URL to check.
   *
   * @return bool
   *   TRUE if the URL is internal.
   */
  protected function isInternalUrl($url) {
    if (empty($url)) {
      return TRUE;
    }

    if (!isset($this->internalHostPattern)) {
      $internal_hosts = [
        preg_quote($this->requestStack->getCurrentRequest()->getHost()),
        preg_quote('reliefweb.int'),
      ];

      $this->internalHostPattern = '#^https?://(' . implode('|', $internal_hosts) . ')(/|$)#';
    }

    return preg_match('#^https?://#', $url) !== 1 ||
      preg_match($this->internalHostPattern, $url) === 1;
  }

  /**
   * Add the target and rel attributes to external links to open in a new tab.
   *
   * @param \DOMNode $node
   *   Link node.
   */
  protected function handleLink(\DOMNode $node) {
    $url = $node->getAttribute('href');

    if (!$this->isInternalUrl($url)) {
      $node->setAttribute('target', '_blank');
      $node->setAttribute('rel', 'noreferrer noopener');
    }
  }

}
