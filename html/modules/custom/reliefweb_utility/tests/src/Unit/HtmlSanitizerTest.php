<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;

/**
 * Tests date helper.
 *
 * @covers \Drupal\reliefweb_utility\Helpers\HtmlSanitizer
 */
class HtmlSanitizerTest extends UnitTestCase {

  /**
   * Test clean text.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\HtmlSanitizer::sanitize
   */
  public function testSanitize() {
    $html = $expected = '';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = ['not a string'];
    $expected = '';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '      ';
    $expected = '';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '   trim around ';
    $expected = 'trim around';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<x-tag>Not allowed</x-tag>';
    $expected = '';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<i>Convert me</i>';
    $expected = '<em>Convert me</em>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<b>Convert me</b>';
    $expected = '<strong>Convert me</strong>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<b class="my-class">Convert me with a class</b>';
    $expected = '<strong>Convert me with a class</strong>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<b style="font-weight: normal;">Convert me with style</b>';
    $expected = '<strong>Convert me with style</strong>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<b x-style="font-weight: normal;">Convert me with style</b>';
    $expected = '<strong>Convert me with style</strong>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe, 2), $expected);

    $html = '<b x-style="font-weight: normal;">Convert me with style</b>';
    $expected = '<strong x-style="font-weight: normal;">Convert me with style</strong>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe, 2, ['x-style']), $expected);

    $html = '<iframe src="https://example.com">Not allowed</iframe>';
    $expected = '';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<iframe src="https://example.com">Allowed</iframe>';
    $expected = 'Allowed';
    $iframe = TRUE;
    $this->assertStringContainsString($expected, HtmlSanitizer::sanitize($html, $iframe));

    $html = '<iframe width="800" height="200" src="https://example.com">Allowed</iframe>';
    $expected = 'Allowed';
    $iframe = TRUE;
    $this->assertStringContainsString('25', HtmlSanitizer::sanitize($html, $iframe));

    $html = '<iframe src="not a url">No src</iframe>';
    $expected = '';
    $iframe = TRUE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<a href="https://example.com">A normal link</a>';
    $expected = $html;
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<a href="https://example.com" target="_blank">A normal link with target</a>';
    $expected = '<a href="https://example.com" target="_blank" rel="noreferrer noopener">A normal link with target</a>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<a href="not a url">Link without valid url</a>';
    $expected = 'Link without valid url';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<a>Link without url</a>';
    $expected = 'Link without url';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = 'Image <img src="https://example.com">';
    $expected = 'Image <img src="https://example.com" alt="">';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = 'Image <img src="https://example.com" alt="An image" title="with a title">';
    $expected = 'Image <img src="https://example.com" alt="An image" title="with a title">';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = 'Image <img src="https://example.com" alt="An image" title="with a title" width="200">';
    $expected = 'Image <img src="https://example.com" alt="An image" title="with a title">';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = 'Image with invalid url <img src="not a url">';
    $expected = 'Image with invalid url';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = 'Image without src <img>';
    $expected = 'Image without src';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<h1>Handle heading</h1>';
    $expected = '<h3>Handle heading</h3>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<h1>Handle heading</h1><h3>Sub heading</h3>';
    $expected = '<h3>Handle heading</h3><h4>Sub heading</h4>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<table></table>';
    $expected = '';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<table class="my-class"></table>';
    $expected = '';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<table class="my-class"><tbody><tr><td>Single cell</td></tr></tbody></table>';
    $expected = '<div class="table-wrapper">' . $html . '</div>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<table><tbody><tr><td>Single cell</td></tr></tbody></table>';
    $expected = '<div class="table-wrapper">' . $html . '</div>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<ul><li>Single listitem</li></ul>';
    $expected = $html;
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<li>Single listitem</li>';
    $expected = '<ul>' .$html . '</ul>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<p>Text</p><p></p>';
    $expected = '<p>Text</p>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<p><font style="vertical-align: inherit;"><font style="vertical-align: inherit;">Text</font></font></p>';
    $expected = '<p>Text</p>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $html = '<p><font style="vertical-align: inherit;"><strong>Text</strong> <small>With</small> Parts</font</p>';
    $expected = '<p><strong>Text</strong> <small>With</small> Parts</p>';
    $iframe = FALSE;
    $this->assertEquals(HtmlSanitizer::sanitize($html, $iframe), $expected);

    $markdown = 'a **strong** word';
    $expected = '<p>a <strong>strong</strong> word</p>';
    $this->assertEquals(HtmlSanitizer::sanitizeFromMarkdown($markdown), $expected);

    $markdown = "Title\n======";
    $expected = '<h3>Title</h3>';
    $this->assertEquals(HtmlSanitizer::sanitizeFromMarkdown($markdown), $expected);
  }

}
