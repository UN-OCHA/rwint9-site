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
 * Controller for the guidelines page.
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

    return [
      '#theme' => 'reliefweb_guidelines_list',
      '#title' => $this->t('Guidelines'),
      '#guidelines' => array_filter($list, function ($item) {
        return !empty($item['title']) && !empty($item['children']);
      }),
      '#cache' => [
        'tags' => ['node_list:guideline', 'taxonomy_term_list:guideline_list'],
        'contexts' => ['user.permissions', 'user.roles'],
      ],
      '#attached' => [
        'library' => ['reliefweb_guidelines/reliefweb-guidelines'],
      ],
    ];
  }

  /**
   * Get the list of guidelines.
   *
   * @return array
   *   The list of guidelines to render.
   */
  protected function getGuidelineList(): array {
    $list = [];
    $storage = $this->entityTypeManager()->getStorage('node');

    $guideline_list_ids = $this->getAccessibleGuidelineListIds($this->currentUser());
    if (empty($guideline_list_ids)) {
      return [];
    }

    $cache_id = $this->getGuidelineListCacheId($guideline_list_ids);
    $cache = $this->cache->get($cache_id);
    if (isset($cache->data)) {
      return $cache->data;
    }

    /** @var \Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList[] $guideline_lists */
    $guideline_lists = $this->entityTypeManager()->getStorage('taxonomy_term')->loadMultiple($guideline_list_ids);

    $is_admin = $this->isUserAdmin($this->currentUser());

    foreach ($guideline_lists as $guideline_list) {
      // Admins can see all the guidelines so we use the prefixed label to
      // differentiate the guideline lists. For other users, they normally only
      // see one type of guideline (ex: guidelines for editors), in which case
      // we use ::getName() to show the non prefixed label for better
      // readability.
      $list[$guideline_list->id()]['title'] = $is_admin ? $guideline_list->label() : $guideline_list->getName();
    }

    // Retrieve the guidelines that are children of those guideline lists.
    $guideline_ids = $storage
      ->getQuery()
      ->condition('status', 1, '=')
      ->condition('type', 'guideline', '=')
      ->condition('field_guideline_list', $guideline_list_ids, 'IN')
      ->sort('field_weight', 'ASC')
      ->accessCheck(TRUE)
      ->execute();

    /** @var \Drupal\reliefweb_guidelines\Entity\Node\Guideline[] $guidelines */
    $guidelines = $this->entityTypeManager()->getStorage('node')->loadMultiple($guideline_ids);

    // Prepare the guideline children.
    foreach ($guidelines as $guideline) {
      // There's supposed to be only one hierarchical level of guidelines.
      $parent = $guideline->field_guideline_list->target_id ?? NULL;
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

        if ($this->currentUser->hasPermission('edit any guideline content')) {
          $list[$parent]['children'][$id]['edit'] = $guideline->toUrl('edit-form')->toString();
        }
      }
    }

    // Filter out guideline lists without children.
    $list = array_filter($list, fn($item) => isset($item['children']));

    // Cached permanently; invalidated via node_list:guideline and
    // taxonomy_term_list:guideline_list cache tags.
    $this->cache->set($cache_id, $list, CacheBackendInterface::CACHE_PERMANENT, [
      'node_list:guideline',
      'taxonomy_term_list:guideline_list',
    ]);
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

    // Load the guideline title and shortlink to use for the link replacements.
    if (!isset($mapping)) {
      $query = \Drupal::database()->select('node_field_data', 'n');
      $query->innerJoin('node__field_short_link', 'f', 'f.entity_id = n.nid');
      $query->fields('n', ['nid', 'title']);
      $query->addField('f', 'field_short_link_value', 'shortlink');
      $query->condition('n.type', 'guideline');
      $records = $query->execute() ?? [];

      $mapping = [];
      foreach ($records as $record) {
        $mapping[$record->nid] = $record;
        $mapping[$record->shortlink] = $record;
      }
    }

    // Get the current host.
    if (!isset($hosts)) {
      $hosts = ['reliefweb.int' => TRUE];
      $host = \Drupal::request()->getHost();
      if ($host !== 'reliefweb.int') {
        $hosts[$host] = TRUE;
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

      // Canonical node link.
      if (preg_match('#^/?node/(\d+)(?:/edit)?$#', $path, $match) === 1) {
        if (isset($mapping[$match[1]])) {
          $link->setAttribute('href', '/guidelines#' . $mapping[$match[1]]->shortlink);
        }
      }
      // Pathauto alias.
      if (preg_match('#^/?guideline/([0-9a-zA-Z]{8})(\S+)?$#', $path, $match) === 1) {
        if (isset($mapping[$match[1]])) {
          $link->setAttribute('href', '/guidelines#' . $mapping[$match[1]]->shortlink);
        }
      }

      $title = $link->textContent;
      // Canonical node link.
      if (preg_match('#^(\s*)/?node/(\d+)(\s*)$#', $title, $match) === 1) {
        if (isset($mapping[$match[2]])) {
          $link->textContent = $match[1] . $mapping[$match[2]]->title . $match[3];
        }
      }
      // Pathauto alias.
      if (preg_match('#^(\s*)/?guideline/([0-9a-zA-Z]{8})(\s*)$#', $path, $match) === 1) {
        if (isset($mapping[$match[2]])) {
          $link->textContent = $match[1] . $mapping[$match[2]]->title . $match[3];
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
          $image->setAttribute('loading', 'lazy');
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
  protected static function getBlankGifUrl(): string {
    static $blank_gif_url;
    if (!isset($blank_gif_url)) {
      $blank_gif_url = '/' . \Drupal::service('extension.path.resolver')
        ->getPath('module', 'reliefweb_guidelines') . '/components/reliefweb-guidelines/assets/blank.gif';
    }
    return $blank_gif_url;
  }

}
