<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatch;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchCandidate;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests DuplicateMatchCandidate scoring gates.
 */
#[CoversClass(DuplicateMatchCandidate::class)]
#[Group('reliefweb_content_analyzer')]
class DuplicateMatchCandidateTest extends UnitTestCase {

  /**
   * Build settings with short min body length for fixtures.
   *
   * @param array<string, mixed> $overrides
   *   Config overrides.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings
   *   Settings.
   */
  protected function settings(array $overrides = []): DuplicateMatchSettings {
    return DuplicateMatchSettings::fromConfigArray($overrides + [
      'minimum_body_length' => 20,
      'minimum_length_ratio' => 0.85,
      'similarity_threshold' => 0.92,
      'tfidf_similarity_threshold' => 0.70,
    ]);
  }

  /**
   * Short candidate body is skipped without scores.
   */
  public function testBodyTooShort(): void {
    $source = str_repeat('word ', 30);
    $candidate = DuplicateMatchCandidate::score(
      nid: 1,
      title: 'Short',
      url: '/node/1',
      created: 1,
      normalized: trim($source),
      candidateNormalized: 'too short',
      sourceHash: hash('sha256', trim($source)),
      settings: $this->settings(),
    );

    $this->assertSame('body_too_short', $candidate->skipReason);
    $this->assertFalse($candidate->isDuplicate);
    $this->assertNull($candidate->jaccardScore);
    $this->assertNull($candidate->tfidfScore);
    $this->assertNull($candidate->toMatch());
  }

  /**
   * Identical bodies with length ratio pass as hard Jaccard duplicates.
   */
  public function testHardJaccardDuplicate(): void {
    $text = implode(' ', array_fill(0, 40, 'humanitarian')) . ' needs continue among displaced people';
    $candidate = DuplicateMatchCandidate::score(
      nid: 2,
      title: 'Hard',
      url: '/node/2',
      created: 2,
      normalized: $text,
      candidateNormalized: $text,
      sourceHash: hash('sha256', $text),
      settings: $this->settings(),
    );

    $this->assertTrue($candidate->isDuplicate);
    $this->assertSame(DuplicateMatch::METHOD_JACCARD, $candidate->method);
    $this->assertSame(1.0, $candidate->jaccardScore);
    $this->assertSame(1.0, $candidate->lengthRatio);
    $match = $candidate->toMatch();
    $this->assertNotNull($match);
    $this->assertSame(DuplicateMatch::METHOD_JACCARD, $match->method);
    $this->assertSame(1.0, $match->score);
  }

  /**
   * Soft TF-IDF alone does not mark a duplicate until embedding confirmation.
   */
  public function testSoftTfidfNeedsEmbeddingConfirmation(): void {
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
    $settings = $this->settings([
      'minimum_length_ratio' => 0.50,
      'similarity_threshold' => 0.92,
      'tfidf_similarity_threshold' => 0.70,
      'embedding_similarity_threshold' => 0.90,
    ]);

    $candidate = DuplicateMatchCandidate::score(
      nid: 3,
      title: 'Soft',
      url: '/node/3',
      created: 3,
      normalized: $a,
      candidateNormalized: $b,
      sourceHash: hash('sha256', $a),
      settings: $settings,
      language_codes: ['en'],
      candidateSource: DuplicateMatchCandidate::SOURCE_EMBEDDING,
    );

    $this->assertSame(DuplicateMatchCandidate::SOURCE_EMBEDDING, $candidate->candidateSource);
    $this->assertNotNull($candidate->jaccardScore);
    $this->assertNotNull($candidate->tfidfScore);
    $this->assertLessThan(0.92, (float) $candidate->jaccardScore);
    $this->assertGreaterThanOrEqual(0.70, (float) $candidate->tfidfScore);
    $this->assertFalse($candidate->isDuplicate);
    $this->assertNull($candidate->method);
    $this->assertTrue($candidate->needsEmbeddingConfirmation($settings));
    $this->assertNull($candidate->toMatch());

    $confirmed = $candidate->withEmbeddingConfirmation(0.91, $settings);
    $this->assertTrue($confirmed->isDuplicate);
    $this->assertSame(DuplicateMatch::METHOD_EMBEDDING, $confirmed->method);
    $this->assertSame(0.91, $confirmed->embeddingScore);
    $this->assertSame(DuplicateMatchCandidate::SOURCE_EMBEDDING, $confirmed->candidateSource);
    $match = $confirmed->toMatch();
    $this->assertNotNull($match);
    $this->assertSame(DuplicateMatch::METHOD_EMBEDDING, $match->method);
    $this->assertSame(0.91, $match->score);

    $discarded = $confirmed->withSeriesSiblingDiscard();
    $this->assertFalse($discarded->isDuplicate);
    $this->assertSame(DuplicateMatchCandidate::DISCARD_SERIES_SIBLING, $discarded->discardReason);
    $this->assertSame(0.91, $discarded->embeddingScore);
    $this->assertNull($discarded->toMatch());
    $this->assertFalse($discarded->needsEmbeddingConfirmation($settings));

    $rejected = $candidate->withEmbeddingConfirmation(0.66, $settings);
    $this->assertFalse($rejected->isDuplicate);
    $this->assertSame(0.66, $rejected->embeddingScore);
    $this->assertNull($rejected->toMatch());

    $failed = $candidate->withEmbeddingConfirmation(NULL, $settings);
    $this->assertFalse($failed->isDuplicate);
    $this->assertNull($failed->embeddingScore);
  }

  /**
   * Hard Jaccard duplicate can be discarded as a series sibling.
   */
  public function testSeriesSiblingDiscardOnHardMatch(): void {
    $text = implode(' ', array_fill(0, 40, 'humanitarian')) . ' needs continue among displaced people';
    $candidate = DuplicateMatchCandidate::score(
      nid: 2,
      title: 'Hard',
      url: '/node/2',
      created: 2,
      normalized: $text,
      candidateNormalized: $text,
      sourceHash: hash('sha256', $text),
      settings: $this->settings(),
      candidateSource: DuplicateMatchCandidate::SOURCE_BOTH,
    );
    $this->assertTrue($candidate->isDuplicate);
    $discarded = $candidate->withSeriesSiblingDiscard();
    $this->assertFalse($discarded->isDuplicate);
    $this->assertNull($discarded->method);
    $this->assertSame(DuplicateMatchCandidate::DISCARD_SERIES_SIBLING, $discarded->discardReason);
    $this->assertSame(DuplicateMatchCandidate::SOURCE_BOTH, $discarded->candidateSource);
    $this->assertNull($discarded->toMatch());
  }

  /**
   * Unrelated texts are not duplicates.
   */
  public function testNeitherGate(): void {
    $a = implode(' ', array_fill(0, 40, 'alpha')) . ' unique source vocabulary only';
    $b = implode(' ', array_fill(0, 40, 'omega')) . ' totally different destination terms';
    $candidate = DuplicateMatchCandidate::score(
      nid: 4,
      title: 'None',
      url: '/node/4',
      created: 4,
      normalized: $a,
      candidateNormalized: $b,
      sourceHash: hash('sha256', $a),
      settings: $this->settings(),
    );

    $this->assertFalse($candidate->isDuplicate);
    $this->assertNull($candidate->method);
    $this->assertNull($candidate->toMatch());
    $this->assertNotNull($candidate->jaccardScore);
    $this->assertNotNull($candidate->tfidfScore);
  }

  /**
   * FormatScore renders percentages and null as an em dash.
   */
  public function testFormatScore(): void {
    $this->assertSame('—', DuplicateMatchCandidate::formatScore(NULL));
    $this->assertSame('90%', DuplicateMatchCandidate::formatScore(0.901));
  }

}
