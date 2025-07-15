<?php

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\reliefweb_utility\Helpers\HtmlOutliner;
use Drupal\reliefweb_utility\Helpers\Outline;
use Drupal\reliefweb_utility\Helpers\Section;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests html outliner.
 */
#[CoversClass(HtmlOutliner::class)]
#[Group('reliefweb_utility')]
class HtmlOutlinerTest extends UnitTestCase {

  /**
   * Test fix node heading hierarchy.
   */
  public function testFixNodeHeadingHierarchy() {
    $html = $expected = '';
    $dom = $this->buildDom($html);
    HtmlOutliner::fixNodeHeadingHierarchy($dom);
    $this->assertEquals($this->domToHtml($dom), $expected);

    $html = '<h1 data-test=3>Handle heading</h1>';
    $expected = '<h1 data-test="3">Handle heading</h1>';
    $dom = $this->buildDom($html);
    HtmlOutliner::fixNodeHeadingHierarchy($dom);
    $this->assertEquals($this->domToHtml($dom), $expected);

    $html = '<section><h1 data-test="3">Handle heading</h1><h3>Sub heading</h3></section>';
    $expected = '<section><h1 data-test="3">Handle heading</h1><h2>Sub heading</h2></section>';
    $dom = $this->buildDom($html);
    HtmlOutliner::fixNodeHeadingHierarchy($dom);
    $this->assertEquals($this->domToHtml($dom), $expected);

    $html = '<section><hgroup><h1 data-test="3">Handle heading</h1><h3>Sub heading</h3></hgroup><p>content</p></section>';
    $expected = '<section><hgroup><h1 data-test="3">Handle heading</h1><h2>Sub heading</h2></hgroup><p>content</p></section>';
    $dom = $this->buildDom($html);
    HtmlOutliner::fixNodeHeadingHierarchy($dom);
    $this->assertEquals($this->domToHtml($dom), $expected);
  }

  /**
   * Test fix node heading hierarchy.
   */
  public function testGetRankingHeading() {
    $html = $expected = '';
    $dom = $this->buildDom($html);
    /** @var \DOMElement */
    $dom_element = HtmlOutliner::getRankingHeading(HtmlOutliner::getBody($dom));
    $this->assertEquals('', $expected);

    $html = '<h1>Handle heading</h1>';
    $expected = '';
    $dom = $this->buildDom($html);
    /** @var \DOMElement */
    $dom_element = HtmlOutliner::getRankingHeading(HtmlOutliner::getBody($dom)->firstChild);
    $this->assertEquals('', $expected);

    $html = '<section><h1>Handle heading</h1><h3>Sub heading</h3></section>';
    $expected = '';
    $dom = $this->buildDom($html);
    /** @var \DOMElement */
    $dom_element = HtmlOutliner::getRankingHeading(HtmlOutliner::getBody($dom)->firstChild);
    $this->assertEquals('', $expected);

    $html = '<hgroup><h1>Handle heading</h1><h3>Sub heading</h3></hgroup><p>content</p>';
    $expected = 'Handle heading';
    $dom = $this->buildDom($html);
    /** @var \DOMElement */
    $dom_element = HtmlOutliner::getRankingHeading(HtmlOutliner::getBody($dom)->firstChild);
    $this->assertEquals($dom_element->textContent, $expected);

    $html = '<hgroup>But no Hx tags</hgroup><p>content</p>';
    $expected = '';
    $dom = $this->buildDom($html);
    /** @var \DOMElement */
    $dom_element = HtmlOutliner::getRankingHeading(HtmlOutliner::getBody($dom)->firstChild);
    $this->assertEquals($dom_element, $expected);
  }

  /**
   * Test outline.
   */
  public function testOutlineGetLastSection() {
    $html = $expected = '';
    $dom = $this->buildDom($html);
    $section = new Section($dom);
    $outline = new Outline($dom, $section);
    $section = $outline->getLastSection();
    $this->assertArrayNotHasKey(0, $section->sections);

    $html = '<section><h1>Handle heading</h1><h3>Sub heading</h3></section>';
    $expected = '<section><h1>Handle heading</h1><h2>Sub heading</h2></section>';
    $dom = $this->buildDom($html);
    $section = new Section($dom);
    $outline = new Outline($dom, $section);
    $section = $outline->getLastSection();
    $this->assertArrayNotHasKey(0, $section->sections);

    $html = '<section><hgroup><h1>Handle heading</h1><h3>Sub heading</h3></hgroup><p>content</p></section>';
    $expected = '?? untitled section';
    $dom = $this->buildDom($html);
    $section = new Section($dom);
    $outline = new Outline($dom, $section);
    $headings = $outline->getHeadings($outline->sections);
    $this->assertEquals($expected, $headings);
  }

  /**
   * Build DOM.
   *
   * @param string $html
   *   HTML string.
   *
   * @return \DomDocument
   *   DOM document.
   */
  private function buildDom($html) {
    // Flags to load the HTML string.
    $flags = LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING;

    $meta = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    $prefix = '<!DOCTYPE html><html><head>' . $meta . '</head><body>';
    $suffix = '</body></html>';

    $dom = new \DOMDocument();
    $dom->loadHTML($prefix . $html . $suffix, $flags);

    return $dom;
  }

  /**
   * Convert DOM to HTML.
   *
   * @param \DomDocument $dom
   *   DOM document.
   *
   * @return string
   *   HTML.
   */
  private function domToHtml($dom) {
    $html = $dom->saveHTML();

    // Search for the body tag and return its content.
    $start = mb_strpos($html, '<body>');
    $end = mb_strrpos($html, '</body>');
    if ($start !== FALSE && $end !== FALSE) {
      $start += 6;
      return trim(mb_substr($html, $start, $end - $start));
    }

    return '';
  }

}
