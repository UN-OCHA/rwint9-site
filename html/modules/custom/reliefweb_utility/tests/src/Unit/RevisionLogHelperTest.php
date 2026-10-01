<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Helpers\RevisionLogHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for RevisionLogHelper::updateMessage().
 */
#[CoversClass(RevisionLogHelper::class)]
#[Group('reliefweb_utility')]
class RevisionLogHelperTest extends UnitTestCase {

  /**
   * Empty messages are ignored for append/prepend.
   */
  public function testEmptyMessageNoOpForAppend(): void {
    $this->assertSame(
      'Existing.',
      RevisionLogHelper::updateMessage('Existing.', '  ', 'append'),
    );
  }

  /**
   * Empty replace clears the revision log.
   */
  public function testEmptyReplaceClearsLog(): void {
    $this->assertSame(
      '',
      RevisionLogHelper::updateMessage('Existing.', '', 'replace', FALSE),
    );
  }

  /**
   * Append joins with the separator when a log already exists.
   */
  public function testAppendWithSeparator(): void {
    $this->assertSame(
      'Existing. - Added.',
      RevisionLogHelper::updateMessage('Existing.', 'Added.', 'append', TRUE, ' - '),
    );
  }

  /**
   * Append without existing log does not add a leading separator.
   */
  public function testAppendWhenEmpty(): void {
    $this->assertSame(
      'Added.',
      RevisionLogHelper::updateMessage('', 'Added.', 'append'),
    );
  }

  /**
   * Prepend joins with the separator when a log already exists.
   */
  public function testPrependWithSeparator(): void {
    $this->assertSame(
      "First.\nExisting.",
      RevisionLogHelper::updateMessage('Existing.', 'First.', 'prepend', TRUE, "\n"),
    );
  }

  /**
   * Skip_if_present leaves the log unchanged when the message is already there.
   */
  public function testSkipIfPresent(): void {
    $log = 'Embargoed (to be automatically published on 03 Sep 2026 06:00 UTC). Note.';
    $this->assertSame(
      $log,
      RevisionLogHelper::updateMessage(
        $log,
        'Embargoed (to be automatically published on 03 Sep 2026 06:00 UTC).',
        'prepend',
        TRUE,
        "\n",
      ),
    );
  }

  /**
   * A substring fragment must not count as already present.
   */
  public function testSubstringFragmentDoesNotSkip(): void {
    $this->assertSame(
      'Trusted user for WFP. user for',
      RevisionLogHelper::updateMessage('Trusted user for WFP.', 'user for', 'append'),
    );
  }

  /**
   * Clause match works across newline separators.
   */
  public function testSkipIfPresentAcrossNewline(): void {
    $log = "Embargoed (to be automatically published on 03 Sep 2026 06:00 UTC).\nImport note.";
    $this->assertSame(
      $log,
      RevisionLogHelper::updateMessage(
        $log,
        'Embargoed (to be automatically published on 03 Sep 2026 06:00 UTC).',
        'prepend',
        TRUE,
        "\n",
      ),
    );
  }

  /**
   * Clause match works across dashed separators.
   */
  public function testSkipIfPresentAcrossDashSeparator(): void {
    $log = 'Editorial note - Publication notification sent to a@b.com';
    $this->assertSame(
      $log,
      RevisionLogHelper::updateMessage(
        $log,
        'Publication notification sent to a@b.com',
        'append',
        TRUE,
        ' - ',
      ),
    );
  }

  /**
   * Sentence-joined logs still skip when re-adding a trailing sentence clause.
   */
  public function testSkipIfPresentAcrossSentenceBoundary(): void {
    $log = 'Import log. Automated classification skipped.';
    $this->assertSame(
      $log,
      RevisionLogHelper::updateMessage($log, 'Automated classification skipped.', 'append'),
    );
  }

  /**
   * A similar but different clause is still appended.
   */
  public function testDifferentClauseStillAppends(): void {
    $this->assertSame(
      "Embargoed (to be automatically published on 04 Sep 2026 06:00 UTC).\nEmbargoed (to be automatically published on 03 Sep 2026 06:00 UTC).",
      RevisionLogHelper::updateMessage(
        'Embargoed (to be automatically published on 03 Sep 2026 06:00 UTC).',
        'Embargoed (to be automatically published on 04 Sep 2026 06:00 UTC).',
        'prepend',
        TRUE,
        "\n",
      ),
    );
  }

  /**
   * Replace with skip_if_present FALSE overwrites the log.
   */
  public function testReplaceWithoutSkip(): void {
    $this->assertSame(
      'New message.',
      RevisionLogHelper::updateMessage('Old message.', 'New message.', 'replace', FALSE),
    );
  }

  /**
   * Replace with skip_if_present TRUE does nothing if message exists.
   */
  public function testReplaceSkipIfPresent(): void {
    $log = 'Keep this. New message.';
    $this->assertSame(
      $log,
      RevisionLogHelper::updateMessage($log, 'New message.', 'replace', TRUE),
    );
  }

  /**
   * Multi-sentence messages skip when every clause is already in the log.
   */
  public function testSkipIfPresentMultiSentenceMessage(): void {
    $log = 'Blocked user for WFP. Trusted user for OCHA. Editorial note.';
    $this->assertSame(
      $log,
      RevisionLogHelper::updateMessage(
        $log,
        'Blocked user for WFP. Trusted user for OCHA.',
        'prepend',
      ),
    );
  }

  /**
   * Multi-sentence messages still append when only some clauses exist.
   */
  public function testPartialMultiSentenceMessageStillAppends(): void {
    $this->assertSame(
      'Blocked user for WFP. Editorial note. Blocked user for WFP. Trusted user for OCHA.',
      RevisionLogHelper::updateMessage(
        'Blocked user for WFP. Editorial note.',
        'Blocked user for WFP. Trusted user for OCHA.',
        'append',
      ),
    );
  }

  /**
   * Data provider for separator variations.
   */
  public static function separatorProvider(): array {
    return [
      'space' => [' ', 'A B'],
      'dash' => [' - ', 'A - B'],
      'newline' => ["\n", "A\nB"],
    ];
  }

  /**
   * Append respects the configured separator.
   */
  #[DataProvider('separatorProvider')]
  public function testAppendSeparators(string $separator, string $expected): void {
    $this->assertSame(
      $expected,
      RevisionLogHelper::updateMessage('A', 'B', 'append', TRUE, $separator),
    );
  }

}
