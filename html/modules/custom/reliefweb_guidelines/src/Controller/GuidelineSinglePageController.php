<?php

namespace Drupal\reliefweb_guidelines\Controller;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\reliefweb_guidelines\GuidelineLoadTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the guidelines.
 */
class GuidelineSinglePageController extends ControllerBase {

  use GuidelineLoadTrait;

  /**
   * The default cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    CacheBackendInterface $cache_backend,
    AccountProxyInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->cache = $cache_backend;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('cache.default'),
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Get the page content.
   *
   * @return array
   *   Render array for the homepage.
   */
  public function getPageContent() {
    $list = $this->getGuidelineList();

    $build = [
      '#theme' => 'reliefweb_guidelines_list',
      '#title' => $this->t('Guidelines'),
      '#guidelines' => array_filter($list, function ($item) {
        return !empty($item['title']) && !empty($item['children']);
      }),
      '#cache' => [
        'tags' => ['guideline_list'],
        'contexts' => ['user.permissions', 'user.roles'],
      ],
      '#attached' => [
        'library' => ['reliefweb_guidelines/reliefweb-guidelines'],
      ],
    ];

    return $build;
  }

  /**
   * Get the list of guidelines.
   *
   * @return array
   *   The list of guidelines to render.
   */
  protected function getGuidelineList() {
    $list = [];
    $storage = $this->entityTypeManager()->getStorage('guideline');

    // Retrieve the guideline lists accessible to the current user.
    $guideline_list_ids = $this->getAccessibleGuidelineListIds($this->currentUser());
    if (empty($guideline_list_ids)) {
      return [];
    }

    // Generate the cache ID based on the list of guideline lists.
    $cache_id = $this->getGuidelineListCacheId($guideline_list_ids);

    // Attempt to get the data from the cache.
    $cache = $this->cache->get($cache_id);
    if (isset($cache->data)) {
      return $cache->data;
    }

    /** @var \Drupal\reliefweb_guidelines\Entity\GuidelineList[] $guideline_lists */
    $guideline_lists = $storage->loadMultiple($guideline_list_ids);

    foreach ($guideline_lists as $guideline_list) {
      $list[$guideline_list->id()]['title'] = $guideline_list->label();
    }

    // Retrieve the guidelines that are children of those guideline lists.
    $guideline_ids = $storage
      ->getQuery()
      ->condition('status', 1, '=')
      ->condition('type', 'field_guideline', '=')
      ->condition('parent', $guideline_list_ids, 'IN')
      ->sort('type', 'DESC')
      ->sort('weight', 'ASC')
      ->accessCheck(TRUE)
      ->execute();

    /** @var \Drupal\guidelines\Entity\Guideline[] $guidelines */
    $guidelines = $storage->loadMultiple($guideline_ids);

    // Prepare the guideline children.
    foreach ($guidelines as $guideline) {
      // There's supposed to be only one hierarchical level of guidelines.
      $parent = $guideline->getParentIds()[0] ?? NULL;
      if (isset($parent, $list[$parent])) {
        $id = $guideline->field_short_link->value;
        $description = $guideline->field_description->value ?? '';

        // Add the references if any at the bottom of the description. This
        // will be converted to an HTML list when rendering the description.
        // This notably enables replacing internal reference links.
        $references = [];
        foreach ($guideline->field_links as $link) {
          if (!empty($link->uri)) {
            $references[] = $link->uri;
          }
        }
        if (!empty($references)) {
          $description .= "\n## References\n" . implode("\n- ", $references);
        }

        $description = check_markup($description, $guideline->field_description->format);

        $list[$parent]['children'][$id] = [
          'title' => $guideline->label(),
          'description' => static::replaceLinks($description, 'blank-image'),
        ];

        if ($this->currentUser->hasPermission('edit guideline entities')) {
          $list[$parent]['children'][$id]['edit'] = $guideline->toUrl('edit-form')->toString();
        }
      }
    }

    // Filter out guideline lists without children.
    $list = array_filter($list, fn($item) => isset($item['children']));

    // Cache the list of letters permanently. It will be rebuilt when a source
    // is modified.
    $this->cache->set($cache_id, $list, CacheBackendInterface::CACHE_PERMANENT, ['guideline_list']);
    return $list;
  }

  /**
   * Get the cache ID for the guidelines.
   *
   * @param array $guideline_list_ids
   *   IDs of the guideline lists.
   *
   * @return string
   *   Cache ID.
   */
  protected function getGuidelineListCacheId(array $guideline_list_ids): string {
    sort($guideline_list_ids);
    $hash = hash('sha256', serialize($guideline_list_ids));

    return 'reliefweb_guidelines:single-page:' . $hash;
  }

  /**
   * Replace the internal links to the guidelines.
   *
   * @param string|\Drupal\Component\Render\MarkupInterface $html
   *   HTML text with guidelines links.
   * @param string|false $lazyloading
   *   Whether to lazy load the images. Options are 'attribute' to use the
   *   html 'loading="lazy"' attribute, 'blank-image' to use a blank image
   *   and let the javascript do the lazy loading or FALSE to disable that.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   HTML with links replaced.
   */
  public static function replaceLinks($html, $lazyloading = 'attribute') {
    static $mapping;
    static $hosts;

    if (!is_string($html) && !($html instanceof MarkupInterface)) {
      return '';
    }

    // Trim.
    $html = trim($html);
    if (empty($html)) {
      return Markup::create($html);
    }

    // Load the guideline name and shortlink to use for the link replacements.
    if (!isset($mapping)) {
      $query = \Drupal::database()->select('guideline_field_data', 'g');
      $query->innerJoin('guideline__field_short_link', 'f', 'f.entity_id = g.id');
      $query->fields('g', ['id', 'name']);
      $query->addField('f', 'field_short_link_value', 'shortlink');
      $records = $query->execute() ?? [];

      $mapping = [];
      foreach ($records as $record) {
        $mapping[$record->id] = $record;
        $mapping[$record->shortlink] = $record;
      }
    }

    // Get the current host.
    if (!isset($hosts)) {
      $hosts = ['reliefweb.int' => TRUE];
      $host = \Drupal::request()->getHost();
      if ($host !== 'reliefweb.int') {
        $hosts[\Drupal::request()->getHost()] = TRUE;
      }
    }

    // Parse the HTML string.
    $flags = LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING;
    $meta = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    $dom = new \DomDocument();
    $dom->loadHTML($meta . $html, $flags);

    // Convert links to internal links on the guidelines page.
    $links = $dom->getElementsByTagName('a');
    foreach ($links as $link) {
      // Add attributes to external links.
      $host = parse_url($link->getAttribute('href'), \PHP_URL_HOST);
      if (!empty($host) && !isset($hosts[$host])) {
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', 'noreferrer noopener');
      }

      // Get the path to determine if it's an internal link to the guidelines.
      $path = parse_url($link->getAttribute('href'), \PHP_URL_PATH);
      if (empty($path)) {
        continue;
      }

      // Canonical link.
      if (preg_match('#^/?admin/structure/guideline/(\d+)$#', $path, $match) === 1) {
        if (isset($mapping[$match[1]])) {
          $link->setAttribute('href', '/guidelines#' . $mapping[$match[1]]->shortlink);
        }
      }
      // Alias.
      if (preg_match('#^/?guideline/([0-9a-zA-Z]{8})(\S+)?$#', $path, $match) === 1) {
        if (isset($mapping[$match[1]])) {
          $link->setAttribute('href', '/guidelines#' . $mapping[$match[1]]->shortlink);
        }
      }

      $title = $link->textContent;
      // Canonical link.
      if (preg_match('#^(\s*)/?admin/structure/guideline/(\d+)(\s*)$#', $title, $match) === 1) {
        if (isset($mapping[$match[2]])) {
          $link->textContent = $match[1] . $mapping[$match[2]]->name . $match[3];
        }
      }
      // Alias.
      if (preg_match('#^(\s*)/?guideline/([0-9a-zA-Z]{8})(\s*)$#', $path, $match) === 1) {
        if (isset($mapping[$match[2]])) {
          $link->textContent = $match[1] . $mapping[$match[2]]->name . $match[3];
        }
      }
    }

    // Add the lazy loading attribute to the images.
    if ($lazyloading === 'attribute' || $lazyloading === 'blank-image') {
      $images = $dom->getElementsByTagName('img');
      foreach ($images as $image) {
        if ($lazyloading === 'blank-image') {
          $image->setAttribute('data-src', $image->getAttribute('src'));
          $image->setAttribute('src', static::getBlankGifUrl());
        }
        else {
          $image->setAttribute('laoading', 'lazy');
        }
      }
    }

    // Tiny cleanup of ordered lists...
    $lists = $dom->getElementsByTagName('ol');
    foreach ($lists as $list) {
      if ($list->hasAttribute('start') && $list->getAttribute('start') == 0) {
        $list->removeAttribute('start');
      }
    }

    $html = $dom->saveHTML();

    // Search for the body tag and return its content.
    $start = mb_strpos($html, '<body>');
    $end = mb_strrpos($html, '</body>');
    if ($start !== FALSE && $end !== FALSE) {
      $start += 6;
      $html = trim(mb_substr($html, $start, $end - $start));
    }

    return Markup::create($html);
  }

  /**
   * Get the URL to the blank gif image.
   *
   * @return string
   *   URL to the blank gif image.
   */
  protected static function getBlankGifUrl() {
    static $blank_gif_url;
    if (!isset($blank_gif_url)) {
      $blank_gif_url = '/' . \Drupal::service('extension.path.resolver')
        ->getPath('module', 'reliefweb_guidelines') . '/components/reliefweb-guidelines/assets/blank.gif';
    }
    return $blank_gif_url;
  }

}
