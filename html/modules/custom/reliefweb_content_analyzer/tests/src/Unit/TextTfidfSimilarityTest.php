<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\Helpers\TextTfidfSimilarity;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests pairwise TF-IDF cosine similarity.
 */
#[CoversClass(TextTfidfSimilarity::class)]
#[Group('reliefweb_content_analyzer')]
class TextTfidfSimilarityTest extends UnitTestCase {

  /**
   * Identical texts score 1.0.
   */
  public function testIdentical(): void {
    $text = 'msf teams are expanding activities in tehran and mashhad';
    $this->assertSame(1.0, TextTfidfSimilarity::similarity($text, $text));
  }

  /**
   * Disjoint texts score near 0.
   */
  public function testDifferent(): void {
    $a = 'alpha beta gamma delta epsilon zeta eta theta';
    $b = 'one two three four five six seven eight';
    $this->assertLessThan(0.1, TextTfidfSimilarity::similarity($a, $b));
  }

  /**
   * Light editorial rewrite with shared facts scores high.
   */
  public function testRewriteStyleOverlap(): void {
    $a = implode(' ', [
      'after seven weeks of war in iran medecins sans frontieres doctors without borders msf teams are expanding activities in tehran',
      'and continuing to respond to growing medical needs while the current ceasefire has brought some relief the situation remains fragile',
      'at the height of the violence intense bombing forced msf to temporarily suspend activities at its clinic in south tehran',
      'the clinic has since reopened and msf has received authorisation to operate the clinic as an advanced medical post',
      'able to receive the wounded and stabilise patients in critical condition it has also expanded its services to all iranians',
      'and the number of consultations has doubled since the ceasefire around 250 patients are now treated at the clinic each day',
      'primary healthcare is often among the first services to be disrupted during emergencies yet it remains one of the most essential',
      'says grigor simonyan msf head of mission in iran people still need treatment for common illnesses and for chronic diseases',
      'such as diabetes and high blood pressure and especially after the trauma of war many will need mental healthcare support',
      'msf plans to open a second clinic in south tehran clinics in kerman city are seeing around 150 patients per day',
      'an estimated 200000 afghan refugees live in the outskirts of the city and msf is one of the only medical organisations',
      'providing healthcare to them in mashhad msf has continued to provide healthcare including mental health support',
      'to more than 160 patients per day as of 15 april the world health organization had verified 24 attacks on healthcare in iran',
      'iran also relies heavily on locally manufactured medicines and pharmaceutical production has been severely disrupted',
      'civilians continue to bear the highest cost of this war full respect and protection of medical facilities is critical',
    ]);
    $b = implode(' ', [
      'iran ceasefire people still cannot access essential medical care after seven weeks of war msf teams in iran are expanding',
      'operations to help meet the growing needs under a strained health care system to meet the growing medical needs in iran',
      'doctors without borders medecins sans frontieres msf teams are expanding our operations in the capital tehran',
      'at the height of the violence intense bombing forced msf to temporarily suspend activities at our clinic in south tehran',
      'the clinic has since reopened and msf has received authorization to run the facility as an advanced medical post with capacity',
      'to receive the wounded and stabilize patients in critical condition we have also expanded our services to all iranians',
      'the number of consultations has doubled since the ceasefire and the clinic now treats around 250 patients each day',
      'primary health care is often among the first services to be disrupted during emergencies yet it remains one of the most essential',
      'says grigor simonyan msf head of mission in iran people still need treatment for common illnesses and for chronic diseases',
      'such as diabetes and high blood pressure and especially after the trauma of war many will need mental health support',
      'msf plans to open a second clinic in south tehran in kerman our clinics have been receiving about 150 patients per day',
      'msf is one of the only medical organizations providing health care to the estimated 200000 afghan refugees living in the area',
      'in mashhad our clinic is receiving more than 160 patients each day as of april 15 the world health organization has verified',
      '24 attacks on health care in iran iran also relies heavily on locally manufactured medicines and pharmaceutical production',
      'has been severely disrupted civilians continue to bear the highest cost of this war full respect and protection of medical',
      'facilities and health workers is critical',
    ]);
    $score = TextTfidfSimilarity::similarity($a, $b, ['en']);
    $this->assertGreaterThan(0.70, $score);
    $this->assertLessThan(1.0, $score);
  }

  /**
   * Stopwords are removed from term frequencies.
   */
  public function testStopwordsRemovedFromFrequencies(): void {
    $tf = TextTfidfSimilarity::termFrequencies('the msf teams and the clinic', ['en']);
    $this->assertArrayNotHasKey('the', $tf);
    $this->assertArrayNotHasKey('and', $tf);
    $this->assertArrayHasKey('msf', $tf);
    $this->assertArrayHasKey('teams', $tf);
    $this->assertArrayHasKey('clinic', $tf);
  }

  /**
   * Stopword filtering lowers similarity driven by function words.
   */
  public function testStopwordsReduceBoilerplateOverlap(): void {
    $a = 'the and of to in for on with as is are was were be been by from that this it its their they we our at has have had who whom which not more than alpha beta gamma';
    $b = 'the and of to in for on with as is are was were be been by from that this it its their they we our at has have had who whom which not more than alpha beta delta';
    // Bypass stopwords by using a language with no overlapping Latin stopwords.
    $raw_a = TextTfidfSimilarity::termFrequencies($a, ['ar']);
    $raw_b = TextTfidfSimilarity::termFrequencies($b, ['ar']);
    $this->assertArrayHasKey('the', $raw_a);
    $this->assertArrayHasKey('the', $raw_b);

    $filtered_a = TextTfidfSimilarity::termFrequencies($a, ['en']);
    $this->assertArrayNotHasKey('the', $filtered_a);
    $this->assertArrayHasKey('alpha', $filtered_a);

    $with_stopwords = TextTfidfSimilarity::similarity($a, $b, ['en']);
    $without_stopwords = TextTfidfSimilarity::similarity($a, $b, ['ar']);
    $this->assertLessThan($without_stopwords, $with_stopwords);
  }

  /**
   * Empty handling.
   */
  public function testEmpty(): void {
    $this->assertSame(1.0, TextTfidfSimilarity::similarity('', ''));
    $this->assertSame(0.0, TextTfidfSimilarity::similarity('text', ''));
    $this->assertSame(0.0, TextTfidfSimilarity::similarity('', 'text'));
  }

}
