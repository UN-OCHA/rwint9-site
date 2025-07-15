<?php

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Core\Render\Renderer;
use Drupal\reliefweb_utility\Plugin\Filter\MarkdownFilter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests markdown filter.
 */
#[CoversClass(MarkdownFilter::class)]
#[Group('reliefweb_utility')]
class MarkdownFilterTest extends UnitTestCase {

  /**
   * Markdown filter.
   *
   * @var \Drupal\reliefweb_utility\Plugin\Filter\MarkdownFilter
   */
  protected $filter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $request = $this->prophesize(Request::class);
    $request->getHost()->willReturn('internal.test');

    $request_stack = $this->prophesize(RequestStack::class);
    $request_stack->getCurrentRequest()->willReturn($request->reveal());

    $container = new ContainerBuilder();
    \Drupal::setContainer($container);
    $container->set('request_stack', $request_stack->reveal());
    $container->set('renderer', $this->prophesize(Renderer::class)->reveal());

    $configuration['settings'] = [];
    $this->filter = MarkdownFilter::create($container, $configuration, 'filter_markdown', [
      'provider' => 'test',
    ]);

    $this->filter->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Test markdown.
   *
   * @param string $text
   *   Markdown text.
   * @param array $expected
   *   Expected output.
   */
  #[DataProvider('providerMarkdown')]
  public function testMarkdown($text, $expected) {
    $this->assertSame($expected, $this->filter->process($text, 'en')->__toString());
  }

  /**
   * Provides data for testMarkdown.
   *
   * @return array
   *   Test data.
   */
  public static function providerMarkdown() {
    return [
      [
        'Just a string',
        '<p>Just a string</p>' . "\n",
      ],
      [
        '#foo',
        '<h1>foo</h1>' . "\n",
      ],
      [
        '#bar #',
        '<h1>bar</h1>' . "\n",
      ],
      [
        '#bar#',
        '<h1>bar#</h1>' . "\n",
      ],
      [
        '##baz',
        '<h2>baz</h2>' . "\n",
      ],
      [
        '##baz #',
        '<h2>baz</h2>' . "\n",
      ],
      [
        '##quz ##',
        '<h2>quz</h2>' . "\n",
      ],
      [
        '##quz##',
        '<h2>quz##</h2>' . "\n",
      ],
      [
        'heading' . "\n" . '===============',
        '<h1>heading</h1>' . "\n",
      ],
      [
        '[Internal](https://internal.test)',
        '<p><a href="https://internal.test">Internal</a></p>' . "\n",
      ],
      [
        '[External](https://external.test)',
        '<p><a rel="noopener noreferrer" target="_blank" href="https://external.test">External</a></p>' . "\n",
      ],
    ];
  }

}
