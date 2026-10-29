<?php

namespace Drupal\Tests\reliefweb_entities\Unit\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
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
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

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
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);

    $container = new Container();
    \Drupal::setContainer($container);

    $this->stringTranslation = $this->getStringTranslationStub();
  }

  /**
   * Configure empty_content.prevent_publication on the mocked factory.
   *
   * @param bool $prevent_publication
   *   Whether prevention is enabled.
   */
  protected function setEmptyContentConfig(bool $prevent_publication): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('empty_content.prevent_publication')
      ->willReturn($prevent_publication);

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('reliefweb_entities.settings')
      ->willReturn($config);
  }

  /**
   * Create a ReportFormAlter service for tests.
   *
   * @return \Drupal\reliefweb_entities\Services\ReportFormAlter
   *   The service.
   */
  protected function createReportFormAlter(): ReportFormAlter {
    return new ReportFormAlter(
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
      $this->messenger,
      $this->configFactory,
    );
  }

  /**
   * Attach a report entity mock to the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface|\PHPUnit\Framework\MockObject\MockObject $form_state
   *   Form state mock.
   * @param \Drupal\reliefweb_entities\Entity\Report|\PHPUnit\Framework\MockObject\MockObject|null $report
   *   Optional report mock.
   *
   * @return \Drupal\reliefweb_entities\Entity\Report|\PHPUnit\Framework\MockObject\MockObject
   *   Report mock.
   */
  protected function attachReportEntityToFormState($form_state, $report = NULL) {
    $report ??= $this->createMock(Report::class);
    $report->method('isPublishedOrEmbargoed')
      ->willReturnCallback(static function (?string $status = NULL): bool {
        return in_array($status, ['published', 'to-review', 'embargoed'], TRUE);
      });

    $form_object = $this->createMock(ContentEntityForm::class);
    $form_object->method('getEntity')->willReturn($report);
    $form_state->method('getFormObject')->willReturn($form_object);

    return $report;
  }

  /**
   * Tests validateBodyOrAttachment rejects empty publish attempts.
   */
  public function testValidateBodyOrAttachmentRejectsEmptyPublish(): void {
    $this->assertValidateBodyOrAttachmentRejectsEmptyStatus('published');
  }

  /**
   * Tests validateBodyOrAttachment rejects empty submit (pending) attempts.
   */
  public function testValidateBodyOrAttachmentRejectsEmptyPending(): void {
    $this->assertValidateBodyOrAttachmentRejectsEmptyStatus('pending');
  }

  /**
   * Assert empty body/file is rejected for the given status.
   *
   * @param string $status
   *   Moderation status.
   */
  protected function assertValidateBodyOrAttachmentRejectsEmptyStatus(string $status): void {
    $this->setEmptyContentConfig(TRUE);

    $form_state = $this->createMock(FormStateInterface::class);
    $this->attachReportEntityToFormState($form_state);
    $form_state->expects($this->any())
      ->method('getValue')
      ->willReturnCallback(function ($key, $default = NULL) use ($status) {
        return match (TRUE) {
          $key === ['moderation_status', 0, 'value'] => $status,
          $key === ['body', 0, 'value'] => '',
          $key === 'field_file' => ['add_more' => []],
          default => $default,
        };
      });
    $form_state->expects($this->exactly(2))
      ->method('setErrorByName')
      ->with(
        $this->logicalOr('body][0][value', 'field_file'),
        $this->callback(function ($message) {
          return (string) $message === 'Enter body text or attach a file.';
        }),
      );

    $form = [
      'body' => [],
      'field_file' => [],
    ];
    $this->createReportFormAlter()->validateBodyOrAttachment($form, $form_state);
  }

  /**
   * Tests validateBodyOrAttachment allows body-only publish.
   */
  public function testValidateBodyOrAttachmentAllowsBody(): void {
    $this->setEmptyContentConfig(TRUE);

    $form_state = $this->createMock(FormStateInterface::class);
    $this->attachReportEntityToFormState($form_state);
    $form_state->expects($this->any())
      ->method('getValue')
      ->willReturnCallback(function ($key, $default = NULL) {
        return match (TRUE) {
          $key === ['moderation_status', 0, 'value'] => 'published',
          $key === ['body', 0, 'value'] => 'Report body text',
          $key === 'field_file' => [],
          default => $default,
        };
      });
    $form_state->expects($this->never())
      ->method('setErrorByName');

    $form = [
      'body' => [],
      'field_file' => [],
    ];
    $this->createReportFormAlter()->validateBodyOrAttachment($form, $form_state);
  }

  /**
   * Tests validateBodyOrAttachment allows attachment-only publish.
   */
  public function testValidateBodyOrAttachmentAllowsAttachment(): void {
    $this->setEmptyContentConfig(TRUE);

    $form_state = $this->createMock(FormStateInterface::class);
    $this->attachReportEntityToFormState($form_state);
    $form_state->expects($this->any())
      ->method('getValue')
      ->willReturnCallback(function ($key, $default = NULL) {
        return match (TRUE) {
          $key === ['moderation_status', 0, 'value'] => 'to-review',
          $key === ['body', 0, 'value'] => '',
          $key === 'field_file' => [
            0 => ['uuid' => 'file-uuid'],
            'add_more' => [],
          ],
          default => $default,
        };
      });
    $form_state->expects($this->never())
      ->method('setErrorByName');

    $form = [
      'body' => [],
      'field_file' => [],
    ];
    $this->createReportFormAlter()->validateBodyOrAttachment($form, $form_state);
  }

  /**
   * Tests validateBodyOrAttachment is skipped when prevention is disabled.
   */
  public function testValidateBodyOrAttachmentSkippedWhenDisabled(): void {
    $this->setEmptyContentConfig(FALSE);

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->never())
      ->method('getValue');
    $form_state->expects($this->never())
      ->method('getFormObject');
    $form_state->expects($this->never())
      ->method('setErrorByName');

    $this->createReportFormAlter()->validateBodyOrAttachment([], $form_state);
  }

  /**
   * Tests validateBodyOrAttachment ignores non-published statuses.
   */
  public function testValidateBodyOrAttachmentIgnoresDraft(): void {
    $this->setEmptyContentConfig(TRUE);

    $form_state = $this->createMock(FormStateInterface::class);
    $this->attachReportEntityToFormState($form_state);
    $form_state->expects($this->once())
      ->method('getValue')
      ->with(['moderation_status', 0, 'value'])
      ->willReturn('draft');
    $form_state->expects($this->never())
      ->method('setErrorByName');

    $this->createReportFormAlter()->validateBodyOrAttachment([
      'body' => [],
      'field_file' => [],
    ], $form_state);
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

    $service = $this->createReportFormAlter();

    $form = [];
    $formState = $this->createMock(FormStateInterface::class);

    // The method should return early for AJAX requests, so no other methods
    // should be called.
    $this->fileDuplication->expects($this->never())->method('findSimilarDocuments');

    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('checkForDuplicateFiles');
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

    $service = $this->createReportFormAlter();

    $form = [];
    $formState = $this->createMock(FormStateInterface::class);

    // The method should return early for users without permission, so no other
    // methods should be called.
    $this->fileDuplication->expects($this->never())->method('findSimilarDocuments');

    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('checkForDuplicateFiles');
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

    $service = $this->createReportFormAlter();

    $form = [];

    // The method should return early for new entities, so no other methods
    // should be called.
    $this->fileDuplication->expects($this->never())->method('findSimilarDocuments');

    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('checkForDuplicateFiles');
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

    $service = $this->createReportFormAlter();

    $form = [];
    $formState = $this->createMock(FormStateInterface::class);

    // The method should return early for non-GET requests, so no other methods
    // should be called.
    $this->fileDuplication->expects($this->never())->method('findSimilarDocuments');

    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('checkForDuplicateFiles');
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

    $service = $this->createReportFormAlter();

    $form = [];

    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('checkForDuplicateFiles');
    $method->invokeArgs($service, [&$form, $formState]);
  }

}
