<?php

namespace Drupal\Tests\reliefweb_entities\Unit\Services;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\reliefweb_entities\Entity\Report;
use Drupal\reliefweb_entities\Services\ReportFormAlter;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile;
use Drupal\reliefweb_files\Plugin\Field\FieldWidget\ReliefWebFile as ReliefWebFileWidget;
use Drupal\reliefweb_files\Services\ReliefWebFileDuplicationInterface;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test for the ReportFormAlter service.
 */
#[CoversClass(ReportFormAlter::class)]
#[Group('reliefweb_entities')]
class ReportFormAlterTest extends UnitTestCase {

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * The mocked current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * The mocked entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityFieldManager;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The mocked state service.
   *
   * @var \Drupal\Core\State\StateInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $state;

  /**
   * The mocked string translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $stringTranslation;

  /**
   * The mocked file duplication service.
   *
   * @var \Drupal\reliefweb_files\Services\ReliefWebFileDuplicationInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileDuplication;

  /**
   * The mocked request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $requestStack;

  /**
   * The mocked renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $renderer;

  /**
   * The mocked messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $messenger;

  /**
   * The mocked user posting rights manager.
   *
   * @var \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $userPostingRightsManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->createMock(Connection::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->state = $this->createMock(StateInterface::class);
    $this->stringTranslation = $this->createMock(TranslationInterface::class);
    $this->fileDuplication = $this->createMock(ReliefWebFileDuplicationInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->renderer = $this->createMock(RendererInterface::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->userPostingRightsManager = $this->createMock(UserPostingRightsManagerInterface::class);

    $container = new Container();
    \Drupal::setContainer($container);
  }

  /**
   * Tests that duplicate checking is skipped for AJAX requests.
   */
  public function testCheckForDuplicateFilesSkipsAjaxRequests(): void {
    // Mock the request to return true for isXmlHttpRequest and GET method.
    $request = $this->createMock(Request::class);
    $request->expects($this->once())
      ->method('getMethod')
      ->willReturn('GET');
    $request->expects($this->once())
      ->method('isXmlHttpRequest')
      ->willReturn(TRUE);

    $this->requestStack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn($request);

    // Mock user with permission (needed for the method to proceed past
    // permission check)
    $this->currentUser->expects($this->once())
      ->method('hasPermission')
      ->with('check for duplicate files')
      ->willReturn(TRUE);

    $service = new ReportFormAlter(
      $this->database,
      $this->currentUser,
      $this->entityFieldManager,
      $this->entityTypeManager,
      $this->state,
      $this->stringTranslation,
      $this->userPostingRightsManager,
      $this->fileDuplication,
      $this->requestStack,
      $this->renderer,
      $this->messenger
    );

    $form = [];
    $formState = $this->createMock(FormStateInterface::class);

    // The method should return early for AJAX requests, so no other methods
    // should be called.
    $this->fileDuplication->expects($this->never())->method('findSimilarDocuments');

    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('checkForDuplicateFiles');
    $method->setAccessible(TRUE);
    $method->invokeArgs($service, [&$form, $formState]);
  }

  /**
   * Tests that duplicate checking is skipped when user lacks permission.
   */
  public function testCheckForDuplicateFilesSkipsWithoutPermission(): void {
    // Mock user without permission.
    $this->currentUser->expects($this->once())
      ->method('hasPermission')
      ->with('check for duplicate files')
      ->willReturn(FALSE);

    $service = new ReportFormAlter(
      $this->database,
      $this->currentUser,
      $this->entityFieldManager,
      $this->entityTypeManager,
      $this->state,
      $this->stringTranslation,
      $this->userPostingRightsManager,
      $this->fileDuplication,
      $this->requestStack,
      $this->renderer,
      $this->messenger
    );

    $form = [];
    $formState = $this->createMock(FormStateInterface::class);

    // The method should return early for users without permission, so no other
    // methods should be called.
    $this->fileDuplication->expects($this->never())->method('findSimilarDocuments');

    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('checkForDuplicateFiles');
    $method->setAccessible(TRUE);
    $method->invokeArgs($service, [&$form, $formState]);
  }

  /**
   * Tests that duplicate checking is skipped for new entities.
   */
  public function testCheckForDuplicateFilesSkipsNewEntities(): void {
    // Mock the request to return false for isXmlHttpRequest and GET method.
    $request = $this->createMock(Request::class);
    $request->expects($this->once())
      ->method('getMethod')
      ->willReturn('GET');
    $request->expects($this->once())
      ->method('isXmlHttpRequest')
      ->willReturn(FALSE);

    $this->requestStack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn($request);

    // Mock user with permission.
    $this->currentUser->expects($this->once())
      ->method('hasPermission')
      ->with('check for duplicate files')
      ->willReturn(TRUE);

    // Mock a new report entity.
    $report = $this->createMock(Report::class);
    $report->expects($this->once())
      ->method('isNew')
      ->willReturn(TRUE);

    $formObject = $this->createMock(EntityFormInterface::class);
    $formObject->expects($this->once())
      ->method('getEntity')
      ->willReturn($report);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->expects($this->once())
      ->method('getFormObject')
      ->willReturn($formObject);

    $service = new ReportFormAlter(
      $this->database,
      $this->currentUser,
      $this->entityFieldManager,
      $this->entityTypeManager,
      $this->state,
      $this->stringTranslation,
      $this->userPostingRightsManager,
      $this->fileDuplication,
      $this->requestStack,
      $this->renderer,
      $this->messenger
    );

    $form = [];

    // The method should return early for new entities, so no other methods
    // should be called.
    $this->fileDuplication->expects($this->never())->method('findSimilarDocuments');

    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('checkForDuplicateFiles');
    $method->setAccessible(TRUE);
    $method->invokeArgs($service, [&$form, $formState]);
  }

  /**
   * Tests that duplicate checking is skipped for non-GET requests.
   */
  public function testCheckForDuplicateFilesSkipsNonGetRequests(): void {
    // Mock user with permission.
    $this->currentUser->expects($this->once())
      ->method('hasPermission')
      ->with('check for duplicate files')
      ->willReturn(TRUE);

    // Mock the request to return POST method.
    $request = $this->createMock(Request::class);
    $request->expects($this->once())
      ->method('getMethod')
      ->willReturn('POST');

    $this->requestStack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn($request);

    $service = new ReportFormAlter(
      $this->database,
      $this->currentUser,
      $this->entityFieldManager,
      $this->entityTypeManager,
      $this->state,
      $this->stringTranslation,
      $this->userPostingRightsManager,
      $this->fileDuplication,
      $this->requestStack,
      $this->renderer,
      $this->messenger
    );

    $form = [];
    $formState = $this->createMock(FormStateInterface::class);

    // The method should return early for non-GET requests, so no other methods
    // should be called.
    $this->fileDuplication->expects($this->never())->method('findSimilarDocuments');

    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('checkForDuplicateFiles');
    $method->setAccessible(TRUE);
    $method->invokeArgs($service, [&$form, $formState]);
  }

  /**
   * Tests that duplicate checking works for existing entities with files.
   */
  public function testCheckForDuplicateFilesWithExistingEntity(): void {
    // Mock the request to return false for isXmlHttpRequest and GET method.
    $request = $this->createMock(Request::class);
    $request->expects($this->once())
      ->method('getMethod')
      ->willReturn('GET');
    $request->expects($this->once())
      ->method('isXmlHttpRequest')
      ->willReturn(FALSE);

    $this->requestStack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn($request);

    // Mock user with permission.
    $this->currentUser->expects($this->once())
      ->method('hasPermission')
      ->with('check for duplicate files')
      ->willReturn(TRUE);

    // Mock an existing report entity with files.
    $report = $this->createMock(Report::class);
    $report->expects($this->once())
      ->method('isNew')
      ->willReturn(FALSE);
    $report->expects($this->once())
      ->method('id')
      ->willReturn(123);
    $report->expects($this->once())
      ->method('bundle')
      ->willReturn('report');
    $report->expects($this->once())
      ->method('hasField')
      ->with('field_file')
      ->willReturn(TRUE);

    // Mock field file items.
    $fieldItem1 = $this->createMock(ReliefWebFile::class);
    $fieldItem1->expects($this->once())
      ->method('extractText')
      ->willReturn('Sample text content from file 1');

    $fieldItem2 = $this->createMock(ReliefWebFile::class);
    $fieldItem2->expects($this->once())
      ->method('extractText')
      ->willReturn('Sample text content from file 2');

    // Create a mock field list that extends the actual base class.
    $fieldList = $this->createMock(FieldItemList::class);
    $fieldList->expects($this->once())
      ->method('isEmpty')
      ->willReturn(FALSE);

    // Make the field list iterable by implementing IteratorAggregate.
    $fieldList->expects($this->once())
      ->method('getIterator')
      ->willReturn(new \ArrayIterator([$fieldItem1, $fieldItem2]));

    $report->expects($this->exactly(2))
      ->method('__get')
      ->with('field_file')
      ->willReturn($fieldList);

    $formObject = $this->createMock(ContentEntityForm::class);
    $formObject->expects($this->once())
      ->method('getEntity')
      ->willReturn($report);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->expects($this->exactly(2))
      ->method('getFormObject')
      ->willReturn($formObject);

    // Mock the file duplication service to return some duplicates.
    $duplicates = [
      [
        'id' => 456,
        'title' => 'Similar Report 1',
        'url' => 'http://example.com/report/456',
        'similarity' => 0.85,
        'similarity_percentage' => '85%',
      ],
      [
        'id' => 789,
        'title' => 'Similar Report 2',
        'url' => 'http://example.com/report/789',
        'similarity' => 0.78,
        'similarity_percentage' => '78%',
      ],
    ];

    // Mock the widget to return the duplicate message build array.
    $widget = $this->createMock(ReliefWebFileWidget::class);
    $widget->expects($this->once())
      ->method('buildDuplicateMessage')
      ->with($duplicates, $this->isType('string'))
      ->willReturn(['#markup' => 'Mock duplicate message']);

    // Mock the widget settings methods.
    $widget->expects($this->exactly(3))
      ->method('getDuplicateMaxDocumentsSetting')
      ->willReturn(5);
    $widget->expects($this->exactly(2))
      ->method('getDuplicateMinimumShouldMatchSetting')
      ->willReturn('80%');
    $widget->expects($this->exactly(2))
      ->method('getDuplicateMaxFilesSetting')
      ->willReturn(20);
    $widget->expects($this->exactly(2))
      ->method('getDuplicateSkipAccessCheckSetting')
      ->willReturn(FALSE);

    // Mock the form display to return the widget.
    $formDisplay = $this->createMock(EntityFormDisplayInterface::class);
    $formDisplay->expects($this->once())
      ->method('getRenderer')
      ->with('field_file')
      ->willReturn($widget);

    $formObject->expects($this->once())
      ->method('getFormDisplay')
      ->with($formState)
      ->willReturn($formDisplay);

    $this->fileDuplication->expects($this->exactly(2))
      ->method('findSimilarDocuments')
      ->with(
        $this->logicalOr('Sample text content from file 1', 'Sample text content from file 2'),
        'report',
        [123],
        5,
        '80%',
        20,
        FALSE
      )
      ->willReturn($duplicates);

    // Mock the renderer and messenger services.
    $this->renderer->expects($this->once())
      ->method('render')
      ->with(['#markup' => 'Mock duplicate message'])
      ->willReturn('Rendered duplicate message');

    $this->messenger->expects($this->once())
      ->method('addWarning')
      ->with('Rendered duplicate message');

    $service = new ReportFormAlter(
      $this->database,
      $this->currentUser,
      $this->entityFieldManager,
      $this->entityTypeManager,
      $this->state,
      $this->stringTranslation,
      $this->userPostingRightsManager,
      $this->fileDuplication,
      $this->requestStack,
      $this->renderer,
      $this->messenger
    );

    $form = [];

    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('checkForDuplicateFiles');
    $method->setAccessible(TRUE);
    $method->invokeArgs($service, [&$form, $formState]);
  }

}
