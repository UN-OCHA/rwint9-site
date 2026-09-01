<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_entities\Unit\Services;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\reliefweb_entities\Entity\Report;
use Drupal\reliefweb_entities\Services\PublicationNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\DependencyInjection\Container;

/**
 * Tests the publication notifier service.
 */
#[CoversClass(PublicationNotifier::class)]
#[Group('reliefweb_entities')]
class PublicationNotifierTest extends UnitTestCase {

  /**
   * The service under test.
   */
  protected PublicationNotifier $service;

  /**
   * The mocked language manager.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The mocked mail manager.
   */
  protected MailManagerInterface $mailManager;

  /**
   * The mocked state service.
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new Container();
    $this->languageManager = $this->createMock(LanguageManagerInterface::class);
    $this->mailManager = $this->createMock(MailManagerInterface::class);
    $this->state = $this->createMock(StateInterface::class);

    $container->set('language_manager', $this->languageManager);
    $container->set('plugin.manager.mail', $this->mailManager);
    $container->set('state', $this->state);

    \Drupal::setContainer($container);

    $this->service = new PublicationNotifier(
      $this->mailManager,
      $this->languageManager,
    );
  }

  /**
   * Tests that report mocks are supported by the service.
   */
  public function testSupportsReportMock(): void {
    $entity = $this->createReportMock();
    $method = new \ReflectionMethod($this->service, 'supports');
    $this->assertTrue($method->invoke($this->service, $entity));
  }

  /**
   * Tests send() when there are no staged emails.
   */
  public function testSendNoStagedEmails(): void {
    $entity = $this->createReportMock();

    $this->mailManager->expects($this->never())->method('mail');

    $this->service->send($entity);
  }

  /**
   * Tests prepare() stages recipient emails on the entity.
   */
  public function testPrepareStagesEmails(): void {
    $entity = $this->createReportMockForPrepare('test@example.com');
    $this->service->prepare($entity);

    $get_staged = new \ReflectionMethod($this->service, 'getStagedEmails');
    $this->assertSame(['test@example.com'], $get_staged->invoke($this->service, $entity));
  }

  /**
   * Tests send() when there is no submit email configured.
   */
  public function testSendNoSubmitEmail(): void {
    $entity = $this->createReportMockForPrepare('test@example.com');
    $this->service->prepare($entity);

    $this->state->expects($this->once())
      ->method('get')
      ->willReturnCallback(static fn (string $key, mixed $default = NULL): mixed => match ($key) {
        'reliefweb_submit_email' => '',
        default => $default,
      });

    $this->languageManager->expects($this->never())->method('getCurrentLanguage');
    $this->mailManager->expects($this->never())->method('mail');

    $this->service->send($entity);

    $get_staged = new \ReflectionMethod($this->service, 'getStagedEmails');
    $this->assertSame([], $get_staged->invoke($this->service, $entity));
  }

  /**
   * Tests send() when there is no email message configured.
   */
  public function testSendNoMessage(): void {
    $entity = $this->createReportMockForPrepare('test@example.com');
    $this->service->prepare($entity);

    $this->state->expects($this->exactly(2))
      ->method('get')
      ->willReturnCallback(static fn (string $key, mixed $default = NULL): mixed => match ($key) {
        'reliefweb_submit_email' => 'noreply@example.com',
        'reliefweb_report_publication_email_message' => '',
        default => $default,
      });

    $this->languageManager->expects($this->never())->method('getCurrentLanguage');
    $this->mailManager->expects($this->never())->method('mail');

    $this->service->send($entity);
  }

  /**
   * Tests send() when all conditions are met.
   */
  public function testSendSuccess(): void {
    $entity = $this->createReportMockForPrepare('test@example.com');
    $this->service->prepare($entity);

    $this->state->expects($this->exactly(2))
      ->method('get')
      ->willReturnCallback(static fn (string $key, mixed $default = NULL): mixed => match ($key) {
        'reliefweb_submit_email' => 'noreply@example.com',
        'reliefweb_report_publication_email_message' => 'Your report @title has been published at @url',
        default => $default,
      });

    $entity->expects($this->once())
      ->method('label')
      ->willReturn('Test Report');

    $url = $this->createMock(Url::class);
    $url->expects($this->once())
      ->method('toString')
      ->willReturn('http://example.com/report');

    $entity->expects($this->once())
      ->method('toUrl')
      ->with('canonical', ['absolute' => TRUE, 'path_processing' => FALSE])
      ->willReturn($url);

    $language = $this->createMock(LanguageInterface::class);
    $language->expects($this->once())
      ->method('getId')
      ->willReturn('en');

    $this->languageManager->expects($this->once())
      ->method('getCurrentLanguage')
      ->willReturn($language);

    $this->mailManager->expects($this->once())
      ->method('mail')
      ->with(
        'reliefweb_entities',
        'report_publication_notification',
        'test@example.com',
        'en',
        $this->callback(function (array $parameters): bool {
          return $parameters['subject'] === 'ReliefWeb: Your submission has been published'
            && $parameters['content'] === 'Your report Test Report has been published at http://example.com/report';
        }),
        'noreply@example.com',
        TRUE,
      );

    $this->service->send($entity);
  }

  /**
   * Tests prepare() does nothing when moderation status is not publishable.
   */
  public function testPrepareSkipsDraftStatus(): void {
    $entity = $this->createReportMock('draft');

    $entity->expects($this->never())->method('get');
    $entity->expects($this->never())->method('set');

    $this->service->prepare($entity);
  }

  /**
   * Creates a minimal report mock with configurable moderation status.
   *
   * @param string $moderationStatus
   *   Moderation status returned by the mock entity.
   *
   * @return \Drupal\reliefweb_entities\Entity\Report&\PHPUnit\Framework\MockObject\MockObject
   *   A report entity mock with fields required by send() and supports().
   */
  protected function createReportMock(string $moderationStatus = 'published'): Report&MockObject {
    $entity = $this->createMock(Report::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn('report');
    $entity->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_notify',
    );
    $entity->method('getModerationStatus')->willReturn($moderationStatus);
    return $entity;
  }

  /**
   * Creates a report mock configured for prepare().
   *
   * @param string $notifyValue
   *   Value for field_notify on the mock entity.
   * @param string $moderationStatus
   *   Moderation status returned by the mock entity.
   *
   * @return \Drupal\reliefweb_entities\Entity\Report&\PHPUnit\Framework\MockObject\MockObject
   *   A report entity mock with notify field and revision log expectations.
   */
  protected function createReportMockForPrepare(
    string $notifyValue,
    string $moderationStatus = 'published',
  ): Report&MockObject {
    $entity = $this->createReportMock($moderationStatus);

    $notify_field = new class() {
      /**
       * The value of the notify field.
       *
       * @var string
       */
      public string $value = '';
    };
    $notify_field->value = $notifyValue;

    $entity->method('get')
      ->with('field_notify')
      ->willReturn($notify_field);

    $entity->method('set')
      ->with('field_notify', []);

    $entity->method('getRevisionLogMessage')->willReturn('');
    $entity->expects($this->once())
      ->method('setRevisionLogMessage')
      ->with($this->stringContains('Publication notification sent to test@example.com'));

    return $entity;
  }

}
