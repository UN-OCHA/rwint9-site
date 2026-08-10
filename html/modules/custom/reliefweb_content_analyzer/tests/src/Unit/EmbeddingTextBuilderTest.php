<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingTextResult;
use Drupal\reliefweb_content_analyzer\Services\EmbeddingTextBuilder;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests EmbeddingTextBuilder.
 */
#[CoversClass(EmbeddingTextBuilder::class)]
class EmbeddingTextBuilderTest extends UnitTestCase {

  /**
   * Builder under test.
   */
  private EmbeddingTextBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->builder = new EmbeddingTextBuilder();
  }

  /**
   * Title and body are concatenated with a blank line.
   */
  public function testTitleAndBodyConcatenation(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('label')->willReturn('SitRep title');
    $entity->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'body');
    $entity->method('get')->with('body')->willReturn($this->mockBodyList(
      '<p>' . str_repeat('word ', 50) . '</p>',
    ));

    $result = $this->builder->build($entity, ['title', 'body'], 10);
    $this->assertTrue($result->isEmbeddable());
    $this->assertStringContainsString("SitRep title\n\n", (string) $result->text);
    $this->assertStringContainsString('word', (string) $result->text);
  }

  /**
   * File extraction failure is ignored when body is present.
   */
  public function testFileExtractionFailureIgnored(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('label')->willReturn('Report');
    $entity->method('hasField')->willReturnCallback(
      static fn(string $name): bool => in_array($name, ['body', 'field_file'], TRUE),
    );

    $file_item = $this->createMock(ReliefWebFile::class);
    $file_item->method('extractText')->willThrowException(new \RuntimeException('extract failed'));

    $entity->method('get')->willReturnCallback(function (string $name) use ($file_item) {
      if ($name === 'body') {
        return $this->mockBodyList('<p>' . str_repeat('bodytext ', 40) . '</p>');
      }
      return $this->mockFileList([$file_item]);
    });

    $result = $this->builder->build($entity, ['body', 'field_file'], 10);
    $this->assertTrue($result->isEmbeddable());
    $this->assertStringContainsString('bodytext', (string) $result->text);
  }

  /**
   * Short text is skipped.
   */
  public function testMinLengthSkip(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('label')->willReturn('Short');
    $entity->method('hasField')->willReturn(FALSE);

    $result = $this->builder->build($entity, ['title'], 50);
    $this->assertFalse($result->isEmbeddable());
    $this->assertSame(EmbeddingTextResult::SKIP_SHORT, $result->skipReason);
  }

  /**
   * Hash changes when the field profile changes.
   */
  public function testHashIncludesFieldProfile(): void {
    $text = str_repeat('abc ', 30);
    $hash_body = $this->builder->hash(['body'], $text);
    $hash_title_body = $this->builder->hash(['title', 'body'], $text);
    $this->assertNotSame($hash_body, $hash_title_body);
    $this->assertSame(64, strlen($hash_body));
  }

  /**
   * Mock a body field item list.
   */
  private function mockBodyList(string $value): FieldItemListInterface {
    $property = $this->createMock(TypedDataInterface::class);
    $property->method('getValue')->willReturn($value);

    $item = $this->createMock(FieldItemInterface::class);
    $item->method('get')->with('value')->willReturn($property);

    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn(FALSE);
    $list->method('first')->willReturn($item);
    return $list;
  }

  /**
   * Mock a file field item list.
   *
   * @param object[] $items
   *   Field items.
   */
  private function mockFileList(array $items): FieldItemListInterface {
    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn($items === []);
    $list->method('count')->willReturn(count($items));
    $list->method('get')->willReturnCallback(static fn(int $delta) => $items[$delta] ?? NULL);
    return $list;
  }

}
