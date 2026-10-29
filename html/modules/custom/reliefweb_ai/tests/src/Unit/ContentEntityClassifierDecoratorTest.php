<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_ai\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\ocha_content_classification\Service\ContentEntityClassifierInterface;
use Drupal\reliefweb_ai\Dto\ReportLanguageDetectionResult;
use Drupal\reliefweb_ai\Enum\LanguageDetectionSample;
use Drupal\reliefweb_ai\Enum\LanguageDetectionSource;
use Drupal\reliefweb_ai\Service\ContentEntityClassifierDecorator;
use Drupal\reliefweb_ai\Service\ReportLanguageDetector;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests simulation decoration that applies report language detection.
 */
#[CoversClass(ContentEntityClassifierDecorator::class)]
#[Group('reliefweb_ai')]
class ContentEntityClassifierDecoratorTest extends UnitTestCase {

  /**
   * Language detection runs on successful report simulations.
   */
  public function testSimulateClassificationRunsLanguageDetectionForReports(): void {
    $language_field = new class() {

      /**
       * Language term ID.
       */
      // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
      public mixed $target_id = 111;

      /**
       * Check if the language field is empty.
       */
      public function isEmpty(): bool {
        return $this->target_id === NULL || $this->target_id === '';
      }

    };

    $simulated = $this->createMock(ContentEntityInterface::class);
    $simulated->method('getEntityTypeId')->willReturn('node');
    $simulated->method('bundle')->willReturn('report');
    $simulated->method('hasField')->willReturnCallback(static function (string $field_name): bool {
      return $field_name === 'field_language';
    });
    $simulated->method('get')->willReturnCallback(static function (string $field_name) use ($language_field) {
      return $field_name === 'field_language' ? $language_field : NULL;
    });
    $simulated->expects($this->exactly(2))
      ->method('set')
      ->willReturnCallback(static function (string $name, mixed $value) use ($language_field, $simulated) {
        if ($name === 'field_language') {
          $language_field->target_id = $value;
        }
        return $simulated;
      });

    $inner = $this->createMock(ContentEntityClassifierInterface::class);
    $inner->expects($this->once())
      ->method('simulateClassification')
      ->willReturn([
        'success' => TRUE,
        'updated_fields' => ['title', 'field_country'],
        'simulated' => $simulated,
      ]);

    $outcome = new ReportLanguageDetectionResult(
      termId: 269,
      code: 'ot',
      source: LanguageDetectionSource::DefaultNone,
      detectedCode: NULL,
      sampleSource: LanguageDetectionSample::None,
    );

    $detector = $this->createMock(ReportLanguageDetector::class);
    $detector->expects($this->once())
      ->method('resolveLanguage')
      ->with($simulated)
      ->willReturn($outcome);

    $decorator = new ContentEntityClassifierDecorator($inner, $detector);
    $entity = $this->createMock(ContentEntityInterface::class);
    $result = $decorator->simulateClassification($entity);

    $this->assertTrue($result['success']);
    $this->assertSame($simulated, $result['simulated']);
    $this->assertSame($outcome, $result['language_detection']);
    $this->assertSame(269, $language_field->target_id);
    $this->assertContains('field_language', $result['updated_fields']);
    $this->assertContains('title', $result['updated_fields']);
    $this->assertContains('field_country', $result['updated_fields']);
  }

  /**
   * Non-report simulations are left unchanged.
   */
  public function testSimulateClassificationSkipsNonReports(): void {
    $simulated = $this->createMock(ContentEntityInterface::class);
    $simulated->method('getEntityTypeId')->willReturn('node');
    $simulated->method('bundle')->willReturn('job');

    $inner = $this->createMock(ContentEntityClassifierInterface::class);
    $inner->expects($this->once())
      ->method('simulateClassification')
      ->willReturn([
        'success' => TRUE,
        'updated_fields' => ['title'],
        'simulated' => $simulated,
      ]);

    $detector = $this->createMock(ReportLanguageDetector::class);
    $detector->expects($this->never())->method('resolveLanguage');

    $decorator = new ContentEntityClassifierDecorator($inner, $detector);
    $entity = $this->createMock(ContentEntityInterface::class);
    $result = $decorator->simulateClassification($entity);

    $this->assertSame(['title'], $result['updated_fields']);
  }

  /**
   * Failed simulations do not run language detection.
   */
  public function testSimulateClassificationSkipsFailures(): void {
    $inner = $this->createMock(ContentEntityClassifierInterface::class);
    $inner->expects($this->once())
      ->method('simulateClassification')
      ->willReturn([
        'success' => FALSE,
        'error' => 'boom',
      ]);

    $detector = $this->createMock(ReportLanguageDetector::class);
    $detector->expects($this->never())->method('resolveLanguage');

    $decorator = new ContentEntityClassifierDecorator($inner, $detector);
    $entity = $this->createMock(ContentEntityInterface::class);
    $result = $decorator->simulateClassification($entity);

    $this->assertFalse($result['success']);
  }

}
