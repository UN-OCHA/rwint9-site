<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Component\Utility\Random;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\Tests\UnitTestCase;

/**
 * Tests summarize.
 *
 * @covers \Drupal\reliefweb_utility\Helpers\HtmlSummarizer
 */
class HtmlSummarizerTest extends UnitTestCase {

  /**
   * Random helper.
   *
   * @var \Drupal\Component\Utility\Random
   */
  protected $random;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->random = new Random();
  }

  /**
   * Test clean text.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\HtmlSummarizer::summarize
   */
  public function testSummarize() {
    $html = $expected = '';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = ['not a string'];
    $expected = '';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '      ';
    $expected = '';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '   trim around ';
    $expected = 'trim around';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<i>Missing body</i>';
    $expected = '';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<x-tag>Not allowed</x-tag>';
    $expected = '';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<body><b>Convert me</b></body>';
    $expected = '';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<body><p style="font-weight: normal;">Convert me with style</p></body>';
    $expected = 'Convert me with style';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<body><iframe src="https://example.com">Not allowed</iframe><p>But this is</p></body>';
    $expected = 'But this is';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<body><h1>Handle heading</h1></body>';
    $expected = 'Handle heading';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<body><h1>Handle heading</h1><h3>Sub heading</h3></body>';
    $expected = 'Handle heading Sub heading';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<body><table></table></body>';
    $expected = '';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<body><table class="my-class"></table></body>';
    $expected = '';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<body><table class="my-class"><tbody><tr><td>Single cell</td></tr></tbody></table></body>';
    $expected = '';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<body><ul><li>Single listitem</li></ul></body>';
    $expected = 'Single listitem';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<body><li>Single listitem</li></body>';
    $expected = '';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<body><p></p></body>';
    $expected = '';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $html = '<body><p>Text</p><p></p></body>';
    $expected = 'Text';
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $string = $this->random->sentences(15);
    $html = '<body><p>' . $string . '</p></body>';
    $expected = $string;
    $this->assertEquals(HtmlSummarizer::summarize($html), $expected);

    $string = $this->random->sentences(15);
    $html = '<body><p>' . $string . '</p></body>';
    $expected = $string;
    $this->assertNotEquals(HtmlSummarizer::summarize($html, 50), $expected);

    $string = $this->random->sentences(5);
    $html = '<body><p>Caecus ex ibidem refoveo uxor vero.</p><p>Caecus ex ibidem refoveo uxor vero.</p><p>Caecus ex ibidem refoveo uxor vero.</p></body>';
    $expected = 'Caecus ex ibidem refoveo uxor vero. Caecus ex ibidem refoveo uxor vero. Caecus ex ibidem refoveo...';
    $this->assertEquals(HtmlSummarizer::summarize($html, 100), $expected);

    $html = '<body><p></p></body>';
    $expected = '<p></p>';
    $this->assertEquals(HtmlSummarizer::summarize($html, 600, FALSE), $expected);

    $html = '<body><p>Text</p><p></p></body>';
    $expected = '<p>Text</p><p></p>';
    $this->assertEquals(HtmlSummarizer::summarize($html, 600, FALSE), $expected);

    $string = $this->random->sentences(15);
    $html = '<body><p>' . $string . '</p></body>';
    $expected = '<p>' . $string . '</p>';
    $this->assertEquals(HtmlSummarizer::summarize($html, 600, FALSE), $expected);

    $string = $this->random->sentences(5);
    $html = '<body><p>Caecus ex ibidem refoveo uxor vero.</p><p>Caecus ex ibidem refoveo uxor vero.</p><p>Caecus ex ibidem refoveo uxor vero.</p></body>';
    $expected = '<p>Caecus ex ibidem refoveo uxor vero.</p><p>Caecus ex ibidem refoveo uxor vero.</p><p>Caecus ex ibidem refoveo uxor...</p>';
    $this->assertEquals(HtmlSummarizer::summarize($html, 100, FALSE), $expected);

    $string = $this->random->sentences(10);
    $html = '<body><p>' . $string . '</p><p>' . $string . '</p><p>' . $string . '</p></body>';
    $expected = '<p>' . $string . '</p><p>' . $string . '</p><p>' . $string . '</p>';
    $this->assertNotEquals(HtmlSummarizer::summarize($html, 100, FALSE), $expected);

    $string = $this->random->sentences(15);
    $html = '<body><p>' . $string . '</p></body>';
    $expected = $string;
    $this->assertNotEquals(HtmlSummarizer::summarize($html, 50, FALSE), $expected);
  }

}
