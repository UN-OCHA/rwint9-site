<?php

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Component\Utility\Random;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests html summarizer.
 */
#[CoversClass(HtmlSummarizer::class)]
#[Group('reliefweb_utility')]
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
    parent::setUp();
    $this->random = new Random();
  }

  /**
   * Test clean text.
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

  /**
   * Test whitespace sanitation.
   */
  public function testSanitizeText() {
    $input = '';
    $expected = '';
    $this->assertEquals(HtmlSummarizer::sanitizeText($input), $expected);

    $input = ' test ';
    $expected = 'test';
    $this->assertEquals(HtmlSummarizer::sanitizeText($input), $expected);

    $input = 'test   test';
    $expected = 'test test';
    $this->assertEquals(HtmlSummarizer::sanitizeText($input), $expected);

    $input = ' test  test
    test ';
    $expected = 'test test test';
    $this->assertEquals(HtmlSummarizer::sanitizeText($input), $expected);
  }

  /**
   * Test paragraph summaries.
   */
  public function testSummarizeParagraphs() {
    $input = [];
    $length = 0;
    $separator_length = 0;
    $expected = [];
    $this->assertEquals($expected, HtmlSummarizer::summarizeParagraphs($input, $length, $separator_length));

    $input = ['test1', 'test2', 'test3'];
    $length = 20;
    $separator_length = 1;
    $expected = ['test1', 'test2', 'test3'];
    $this->assertEquals($expected, HtmlSummarizer::summarizeParagraphs($input, $length, $separator_length));

    $input = ['test1', 'test2', 'test3! test4'];
    $length = 19;
    $separator_length = 1;
    $expected = ['test1', 'test2', 'test3...'];
    $this->assertEquals($expected, HtmlSummarizer::summarizeParagraphs($input, $length, $separator_length));

    $input = ['test1', 'test2', 'test3'];
    $length = 14;
    $separator_length = 1;
    $expected = ['test1', 'test2', '...'];
    $this->assertEquals($expected, HtmlSummarizer::summarizeParagraphs($input, $length, $separator_length));

    $input = ['test1', 'test2', 'test3'];
    $length = 14;
    $separator_length = 3;
    $expected = ['test1', 'test2', '...'];
    $this->assertEquals($expected, HtmlSummarizer::summarizeParagraphs($input, $length, $separator_length));

    $input = ['test1', 'test2', 'test3'];
    $length = 14;
    $separator_length = 4;
    $expected = ['test1', '...'];
    $this->assertEquals($expected, HtmlSummarizer::summarizeParagraphs($input, $length, $separator_length));

    $input = ['test1', 'test2', 'test3'];
    $length = 14;
    $separator_length = 6;
    $expected = ['test1', '...'];
    $this->assertEquals($expected, HtmlSummarizer::summarizeParagraphs($input, $length, $separator_length));
  }

  /**
   * Test multi-byte character handling.
   */
  public function testMultiByteCharacters() {
    // Test Arabic text with multi-byte characters.
    $arabic_text = 'تُعدّ الزراعة منذ زمن بعيد العمود الفقري للاقتصاد الريفي وركيزة الأمن الغذائي في سوريا.';
    $html = '<body><p>' . $arabic_text . '</p></body>';
    $result = HtmlSummarizer::summarize($html);
    $this->assertEquals($arabic_text, $result);
    // Verify UTF-8 encoding is preserved.
    $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    // Verify JSON encoding works without errors.
    $json = json_encode($result);
    $this->assertNotFalse($json);
    $this->assertEquals(JSON_ERROR_NONE, json_last_error());

    // Test Arabic text with question mark (؟) which is a multi-byte character.
    $arabic_with_question = 'ما هو السؤال؟';
    $html = '<body><p>' . $arabic_with_question . '</p></body>';
    $result = HtmlSummarizer::summarize($html);
    $this->assertEquals($arabic_with_question, $result);
    $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    $json = json_encode($result);
    $this->assertNotFalse($json);
    $this->assertEquals(JSON_ERROR_NONE, json_last_error());

    // Test Arabic text with truncation and end marks.
    $long_arabic = 'تُعدّ الزراعة منذ زمن بعيد العمود الفقري للاقتصاد الريفي وركيزة الأمن الغذائي في سوريا. غير أنّ عقدًا من النزاع المسلح، تفاقم بفعل تسارع آثار التغير المناخي، قد أدى إلى انهيار النظام الزراعي والغذائي.';
    $html = '<body><p>' . $long_arabic . '</p></body>';
    $result = HtmlSummarizer::summarize($html, 100);
    // 100 + '...' = 103 characters.
    $this->assertTrue(mb_strlen($result) <= 103);
    $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    $json = json_encode($result);
    $this->assertNotFalse($json);
    $this->assertEquals(JSON_ERROR_NONE, json_last_error());
    $this->assertStringEndsWith('...', $result);

    // Test Arabic text ending with question mark (؟) that should be trimmed.
    $arabic_ending_question = 'ما هو السؤال؟';
    $input = [$arabic_ending_question];
    $result = HtmlSummarizer::summarizeParagraphs($input, 5, 0);
    // The question mark should be preserved, not trimmed incorrectly.
    $this->assertTrue(mb_check_encoding($result[0], 'UTF-8'));
    $json = json_encode($result);
    $this->assertNotFalse($json);
    $this->assertEquals(JSON_ERROR_NONE, json_last_error());

    // Test Chinese characters (also multi-byte).
    $chinese_text = '这是一个测试。';
    $html = '<body><p>' . $chinese_text . '</p></body>';
    $result = HtmlSummarizer::summarize($html);
    $this->assertEquals($chinese_text, $result);
    $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    $json = json_encode($result);
    $this->assertNotFalse($json);
    $this->assertEquals(JSON_ERROR_NONE, json_last_error());

    // Test mixed Arabic and English.
    $mixed_text = 'This is English and هذا عربي';
    $html = '<body><p>' . $mixed_text . '</p></body>';
    $result = HtmlSummarizer::summarize($html);
    $this->assertEquals($mixed_text, $result);
    $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    $json = json_encode($result);
    $this->assertNotFalse($json);
    $this->assertEquals(JSON_ERROR_NONE, json_last_error());

    // Test Arabic text with multiple paragraphs.
    $paragraph1 = 'تُعدّ الزراعة منذ زمن بعيد العمود الفقري للاقتصاد الريفي.';
    $paragraph2 = 'غير أنّ عقدًا من النزاع المسلح قد أدى إلى انهيار النظام الزراعي.';
    $html = '<body><p>' . $paragraph1 . '</p><p>' . $paragraph2 . '</p></body>';
    $result = HtmlSummarizer::summarize($html);
    $expected = $paragraph1 . ' ' . $paragraph2;
    $this->assertEquals($expected, $result);
    $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    $json = json_encode($result);
    $this->assertNotFalse($json);
    $this->assertEquals(JSON_ERROR_NONE, json_last_error());
  }

}
