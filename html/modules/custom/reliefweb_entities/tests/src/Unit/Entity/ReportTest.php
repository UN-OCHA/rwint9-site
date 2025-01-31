<?php

namespace Drupal\Tests\reliefweb_entities\Unit\Entity;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\reliefweb_entities\Entity\Report;
use Symfony\Component\DependencyInjection\Container;

/**
 * @coversDefaultClass \Drupal\reliefweb_entities\Entity\Report
 * @group reliefweb_entities
 */
class ReportTest extends UnitTestCase {

  /**
   * The mocked language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $languageManager;

  /**
   * The mocked mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $mailManager;

  /**
   * The mocked state service.
   *
   * @var \Drupal\Core\State\StateInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $state;

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
  }

  /**
   * Tests sendPublicationNotification when there are no emails to notify.
   *
   * @covers ::sendPublicationNotification
   */
  public function testSendPublicationNotificationNoEmails(): void {
    $report = $this->getMockBuilder(Report::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['hasPublicationNotificationEmails'])
      ->getMock();

    $report->expects($this->once())
      ->method('hasPublicationNotificationEmails')
      ->willReturn(FALSE);

    $this->state->expects($this->never())->method('get');
    $this->languageManager->expects($this->never())->method('getCurrentLanguage');
    $this->mailManager->expects($this->never())->method('mail');

    $method = new \ReflectionMethod(Report::class, 'sendPublicationNotification');
    $method->setAccessible(TRUE);
    $method->invoke($report);
  }

  /**
   * Tests sendPublicationNotification when there's no submit email.
   *
   * @covers ::sendPublicationNotification
   */
  public function testSendPublicationNotificationNoSubmitEmail(): void {
    $report = $this->getMockBuilder(Report::class)
      ->disableOriginalConstructor()
      ->onlyMethods([
        'hasPublicationNotificationEmails',
        'getPublicationNotificationEmails',
        'resetPublicationNotificationEmails',
      ])
      ->getMock();

    $report->expects($this->once())
      ->method('hasPublicationNotificationEmails')
      ->willReturn(TRUE);

    $report->expects($this->once())
      ->method('getPublicationNotificationEmails')
      ->willReturn(['test@example.com']);

    $report->expects($this->once())
      ->method('resetPublicationNotificationEmails');

    $this->state->expects($this->once())
      ->method('get')
      ->willReturnCallback(function ($key, $default = NULL) {
        $map = [
          'reliefweb_submit_email' => NULL,
        ];
        return $map[$key] ?? $default;
      });

    $this->languageManager->expects($this->never())->method('getCurrentLanguage');
    $this->mailManager->expects($this->never())->method('mail');

    $method = new \ReflectionMethod(Report::class, 'sendPublicationNotification');
    $method->setAccessible(TRUE);
    $method->invoke($report);
  }

  /**
   * Tests sendPublicationNotification when there's no email message.
   *
   * @covers ::sendPublicationNotification
   */
  public function testSendPublicationNotificationNoMessage(): void {
    $report = $this->getMockBuilder(Report::class)
      ->disableOriginalConstructor()
      ->onlyMethods([
        'hasPublicationNotificationEmails',
        'getPublicationNotificationEmails',
        'resetPublicationNotificationEmails',
      ])
      ->getMock();

    $report->expects($this->once())
      ->method('hasPublicationNotificationEmails')
      ->willReturn(TRUE);

    $report->expects($this->once())
      ->method('getPublicationNotificationEmails')
      ->willReturn(['test@example.com']);

    $report->expects($this->once())
      ->method('resetPublicationNotificationEmails');

    $this->state->expects($this->exactly(2))
      ->method('get')
      ->willReturnCallback(function ($key, $default = NULL) {
        $map = [
          'reliefweb_submit_email' => 'noreply@example.com',
          'reliefweb_report_publication_email_message' => NULL,
        ];
        return $map[$key] ?? $default;
      });

    $this->languageManager->expects($this->never())->method('getCurrentLanguage');
    $this->mailManager->expects($this->never())->method('mail');

    $method = new \ReflectionMethod(Report::class, 'sendPublicationNotification');
    $method->setAccessible(TRUE);
    $method->invoke($report);
  }

  /**
   * Tests sendPublicationNotification when all conditions are met.
   *
   * @covers ::sendPublicationNotification
   */
  public function testSendPublicationNotificationSuccess(): void {
    $report = $this->getMockBuilder(Report::class)
      ->disableOriginalConstructor()
      ->onlyMethods([
        'hasPublicationNotificationEmails',
        'getPublicationNotificationEmails',
        'resetPublicationNotificationEmails',
        'label',
        'toUrl',
      ])
      ->getMock();

    $report->expects($this->once())
      ->method('hasPublicationNotificationEmails')
      ->willReturn(TRUE);

    $report->expects($this->once())
      ->method('getPublicationNotificationEmails')
      ->willReturn(['test@example.com']);

    $report->expects($this->once())
      ->method('resetPublicationNotificationEmails');

    $report->expects($this->once())
      ->method('label')
      ->willReturn('Test Report');

    $url = $this->createMock(Url::class);
    $url->expects($this->once())
      ->method('toString')
      ->willReturn('http://example.com/report');

    $report->expects($this->once())
      ->method('toUrl')
      ->with('canonical', ['absolute' => TRUE, 'path_processing' => FALSE])
      ->willReturn($url);

    $this->state->expects($this->exactly(2))
      ->method('get')
      ->willReturnCallback(function ($key, $default = NULL) {
        $map = [
          'reliefweb_submit_email' => 'noreply@example.com',
          'reliefweb_report_publication_email_message' => 'Your report @title has been published at @url',
        ];
        return $map[$key] ?? $default;
      });

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
        $this->callback(function ($parameters) {
          return $parameters['subject'] === 'ReliefWeb: Your submission has been published' &&
            $parameters['content'] === 'Your report Test Report has been published at http://example.com/report';
        }),
        'noreply@example.com',
        TRUE
      );

    $method = new \ReflectionMethod(Report::class, 'sendPublicationNotification');
    $method->setAccessible(TRUE);
    $method->invoke($report);
  }

}
