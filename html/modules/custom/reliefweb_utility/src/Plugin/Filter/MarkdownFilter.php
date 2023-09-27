<?php

namespace Drupal\reliefweb_utility\Plugin\Filter;

use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\reliefweb_utility\Helpers\MarkdownHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a filter to display any HTML as plain text.
 *
 * @Filter(
 *   id = "filter_markdown",
 *   title = @Translation("Convert a markdown text to HTML"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 *   weight = -20
 * )
 */
class MarkdownFilter extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * Renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Current request.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a markdown filter plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RendererInterface $renderer, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->renderer = $renderer;
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
      $container->get('renderer'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $html = MarkdownHelper::convertToHtml($text, [
      $this->requestStack->getCurrentRequest()->getHost(),
      'reliefweb.int',
    ]);
    return new FilterProcessResult($html);
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    $help = '<h4>Markdown reference</h4>
    <table class="markdown-reference">
        <thead>
            <tr>
                <th>Type</th>
                <th>… to Get</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>*Italic*</td>
                <td><em>Italic</em></td>
            </tr>
            <tr>
                <td>**Bold**</td>
                <td><strong>Bold</strong></td>
            </tr>
            <tr>
                <td>
                    # Heading 1
                </td>
                <td>
                    <h1>Heading 1</h1>
                </td>
            </tr>
            <tr>
                <td>
                    ## Heading 2
                </td>
                <td>
                    <h2>Heading 2</h2>
                </td>
            </tr>
            <tr>
                <td>
                    ### Heading 3
                </td>
                <td>
                    <h3>Heading 3</h3>
                </td>
            </tr>
            <tr>
                <td>
                    #### Heading 4
                </td>
                <td>
                    <h4>Heading 4</h4>
                </td>
            </tr>
            <tr>
                <td>
                    ##### Heading 5
                </td>
                <td>
                    <h5>Heading 5</h5>
                </td>
            </tr>
            <tr>
                <td>
                    ###### Heading 6
                </td>
                <td>
                    <h6>Heading 6</h6>
                </td>
            </tr>
            <tr>
                <td>
                    [Link](http://a.com)
                </td>
                <td><a href="https://commonmark.org/help">Link</a></td>
            </tr>
            <tr>
                <td>
                    ![Image](http://url/reliefweb.png)
                </td>
                <td>
                    <img src="/themes/custom/common_design_subtheme/img/logos/rw-logo-desktop.svg" width="140" height="60" alt="">
                </td>
            </tr>
            <tr>
                <td>
                    &gt; Blockquote
                </td>
                <td>
                    <blockquote>Blockquote</blockquote>
                </td>
            </tr>
            <tr>
                <td>
                    <p>
                        - List<br>
                        - List<br>
                        - List
                    </p>
                </td>
                <td>
                    <ul>
                        <li>List</li>
                        <li>List</li>
                        <li>List</li>
                    </ul>
                </td>
            </tr>
            <tr>
                <td>
                    <p>
                        1. One<br>
                        2. Two<br>
                        3. Three
                    </p>
                </td>
                <td>
                    <ol>
                        <li>One</li>
                        <li>Two</li>
                        <li>Three</li>
                    </ol>
                </td>
            </tr>
            <tr>
                <td>
                    Horizontal rule:
                    <br>
                    ---
                </td>
                <td>
                    Horizontal rule:
                    <hr>
                </td>
            </tr>
        </tbody>
    </table>';

    $tips = [
      '#type' => 'container',
    ];

    $tips[] = [
      '#markup' => Markup::create($help),
    ];

    // Documentation link.
    $url = Url::fromUri('https://commonmark.org/help', [
      'attributes' => ['target' => '_blank', 'rel' => 'noopener noreferrer'],
    ]);
    $tips[] = [
      '#markup' => $this->t('For complete details on the Markdown syntax, see the @link', [
        '@link' => Link::fromTextAndUrl($this->t('Markdown documentation'), $url)->toString(),
      ]),
    ];

    return $this->renderer->render($tips);
  }

}
