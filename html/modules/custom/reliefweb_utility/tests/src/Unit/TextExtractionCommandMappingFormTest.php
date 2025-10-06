<?php

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_utility\Form\TextExtractionCommandMappingForm;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests for TextExtractionCommandMappingForm.
 */
#[CoversClass(TextExtractionCommandMappingForm::class)]
#[Group('reliefweb_utility')]
class TextExtractionCommandMappingFormTest extends UnitTestCase {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * The config object.
   *
   * @var \Drupal\Core\Config\Config|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $messenger;

  /**
   * The translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $translation;

  /**
   * The form under test.
   *
   * @var \Drupal\reliefweb_utility\Form\TextExtractionCommandMappingForm
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config = $this->createMock(Config::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->translation = $this->createMock(TranslationInterface::class);

    $this->configFactory->expects($this->any())
      ->method('getEditable')
      ->with('reliefweb_utility.settings')
      ->willReturn($this->config);

    $this->configFactory->expects($this->any())
      ->method('get')
      ->with('reliefweb_utility.settings')
      ->willReturn($this->config);

    // Set up Drupal container for translation.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->translation);
    \Drupal::setContainer($container);

    $this->form = new TextExtractionCommandMappingForm($this->configFactory);
  }

  /**
   * Test form ID.
   */
  public function testGetFormId() {
    $this->assertEquals('reliefweb_utility_text_extraction_command_mapping_form', $this->form->getFormId());
  }

  /**
   * Test form creation.
   */
  public function testCreate() {
    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->once())
      ->method('get')
      ->with('config.factory')
      ->willReturn($this->configFactory);

    $form = TextExtractionCommandMappingForm::create($container);
    $this->assertInstanceOf(TextExtractionCommandMappingForm::class, $form);
  }

  /**
   * Test form build with default commands.
   */
  public function testBuildFormWithDefaults() {
    $this->config->expects($this->once())
      ->method('get')
      ->with('text_extraction.commands')
      ->willReturn([]);

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('get')
      ->with('commands')
      ->willReturn(NULL);
    $form_state->expects($this->once())
      ->method('set')
      ->with('commands', []);

    $form = $this->form->buildForm([], $form_state);

    $this->assertArrayHasKey('description', $form);
    $this->assertArrayHasKey('commands', $form);
    $this->assertArrayHasKey('add_command', $form);
    $this->assertArrayHasKey('actions', $form);
    $this->assertArrayHasKey('submit', $form['actions']);
  }

  /**
   * Test form build with existing commands.
   */
  public function testBuildFormWithExistingCommands() {
    $existingCommands = [
      [
        'mimetype' => 'application/pdf',
        'command' => '/usr/bin/mutool',
        'args' => 'draw -F txt',
        'options' => '',
        'page' => TRUE,
        'ignore_errors_if_output' => FALSE,
      ],
      [
        'mimetype' => 'application/msword',
        'command' => '/usr/bin/pandoc',
        'args' => '-t plain',
        'options' => '',
        'page' => FALSE,
        'ignore_errors_if_output' => TRUE,
      ],
    ];

    $this->config->expects($this->once())
      ->method('get')
      ->with('text_extraction.commands')
      ->willReturn($existingCommands);

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('get')
      ->with('commands')
      ->willReturn(NULL);
    $form_state->expects($this->once())
      ->method('set')
      ->with('commands', $existingCommands);

    $form = $this->form->buildForm([], $form_state);

    $this->assertArrayHasKey('commands', $form);
    // The form builds additional elements like prefix, suffix, and wrapper.
    $this->assertGreaterThanOrEqual(2, count($form['commands']));
    $this->assertArrayHasKey(0, $form['commands']);
    $this->assertArrayHasKey(1, $form['commands']);
  }

  /**
   * Test form build with commands from form state.
   */
  public function testBuildFormWithCommandsFromFormState() {
    $commandsFromState = [
      [
        'mimetype' => 'text/plain',
        'command' => '/usr/bin/cat',
        'args' => '',
        'options' => '',
        'page' => FALSE,
        'ignore_errors_if_output' => FALSE,
      ],
    ];

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('get')
      ->with('commands')
      ->willReturn($commandsFromState);
    $form_state->expects($this->once())
      ->method('set')
      ->with('commands', $commandsFromState);

    $form = $this->form->buildForm([], $form_state);

    $this->assertArrayHasKey('commands', $form);
    // The form builds additional elements like prefix, suffix, and wrapper.
    $this->assertGreaterThanOrEqual(1, count($form['commands']));
  }

  /**
   * Test form validation with duplicate MIME types.
   */
  public function testValidateFormWithDuplicateMimeTypes() {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('getTriggeringElement')
      ->willReturn(['#name' => 'submit']);
    $form_state->expects($this->once())
      ->method('getValue')
      ->with('commands', [])
      ->willReturn([
        0 => [
          'mimetype' => 'application/pdf',
          'command' => '/usr/bin/mutool',
        ],
        1 => [
          'mimetype' => 'application/pdf',
          'command' => '/usr/bin/mutool',
        ],
      ]);

    $form_state->expects($this->once())
      ->method('setErrorByName')
      ->with('commands][1][mimetype', $this->isInstanceOf(TranslatableMarkup::class));

    $form = [];
    $this->form->validateForm($form, $form_state);
  }

  /**
   * Test form validation with non-executable command.
   */
  public function testValidateFormWithNonExecutableCommand() {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('getTriggeringElement')
      ->willReturn(['#name' => 'submit']);
    $form_state->expects($this->once())
      ->method('getValue')
      ->with('commands', [])
      ->willReturn([
        0 => [
          'mimetype' => 'application/pdf',
          'command' => '/nonexistent/command',
        ],
      ]);

    $form_state->expects($this->once())
      ->method('setErrorByName')
      ->with('commands][0][command', $this->isInstanceOf(TranslatableMarkup::class));

    $form = [];
    $this->form->validateForm($form, $form_state);
  }

  /**
   * Test form validation with multiple duplicate MIME types.
   */
  public function testValidateFormWithMultipleDuplicateMimeTypes() {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('getTriggeringElement')
      ->willReturn(['#name' => 'submit']);
    $form_state->expects($this->once())
      ->method('getValue')
      ->with('commands', [])
      ->willReturn([
        0 => [
          'mimetype' => 'application/pdf',
          'command' => '/usr/bin/mutool',
        ],
        1 => [
          'mimetype' => 'application/pdf',
          'command' => '/usr/bin/mutool',
        ],
        2 => [
          'mimetype' => 'application/pdf',
          'command' => '/usr/bin/mutool',
        ],
      ]);

    $form_state->expects($this->exactly(2))
      ->method('setErrorByName')
      ->willReturnCallback(function ($element, $message) {
        static $callCount = 0;
        $callCount++;
        if ($callCount === 1) {
          $this->assertEquals('commands][1][mimetype', $element);
          $this->assertInstanceOf(TranslatableMarkup::class, $message);
        }
        elseif ($callCount === 2) {
          $this->assertEquals('commands][2][mimetype', $element);
          $this->assertInstanceOf(TranslatableMarkup::class, $message);
        }
      });

    $form = [];
    $this->form->validateForm($form, $form_state);
  }

  /**
   * Test form validation skips empty commands.
   */
  public function testValidateFormSkipsEmptyCommands() {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('getTriggeringElement')
      ->willReturn(['#name' => 'submit']);
    $form_state->expects($this->once())
      ->method('getValue')
      ->with('commands', [])
      ->willReturn([
        0 => [
          'mimetype' => '',
          'command' => '/usr/bin/mutool',
        ],
        1 => [
          'mimetype' => 'application/pdf',
          'command' => '',
        ],
        2 => [
          'mimetype' => 'application/pdf',
          'command' => '/usr/bin/mutool',
        ],
      ]);

    // Should not set any errors for empty commands.
    $form_state->expects($this->never())
      ->method('setErrorByName');

    $form = [];
    $this->form->validateForm($form, $form_state);
  }

  /**
   * Test form validation is skipped for non-submit actions.
   */
  public function testValidateFormSkippedForNonSubmitActions() {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('getTriggeringElement')
      ->willReturn(['#name' => 'add_command']);

    // Should not call getValue or setErrorByName.
    $form_state->expects($this->never())
      ->method('getValue');
    $form_state->expects($this->never())
      ->method('setErrorByName');

    $form = [];
    $this->form->validateForm($form, $form_state);
  }

  /**
   * Test form submission.
   */
  public function testSubmitForm() {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('getTriggeringElement')
      ->willReturn(['#name' => 'submit']);
    $form_state->expects($this->once())
      ->method('getValue')
      ->with('commands', [])
      ->willReturn([
        0 => [
          'mimetype' => 'application/pdf',
          'command' => '/usr/bin/mutool',
          'args' => 'draw -F txt',
          'options' => '',
          'page' => TRUE,
          'ignore_errors_if_output' => TRUE,
        ],
      ]);

    $expected_commands = [
      [
        'mimetype' => 'application/pdf',
        'command' => '/usr/bin/mutool',
        'args' => 'draw -F txt',
        'options' => '',
        'page' => TRUE,
        'ignore_errors_if_output' => TRUE,
      ],
    ];

    $this->config->expects($this->once())
      ->method('set')
      ->with('text_extraction.commands', $expected_commands)
      ->willReturnSelf();
    $this->config->expects($this->once())
      ->method('save');

    // Mock FileHelper static method.
    $this->form = $this->getMockBuilder(TextExtractionCommandMappingForm::class)
      ->setConstructorArgs([$this->configFactory])
      ->onlyMethods(['messenger'])
      ->getMock();

    $this->form->expects($this->once())
      ->method('messenger')
      ->willReturn($this->messenger);

    $this->messenger->expects($this->once())
      ->method('addStatus')
      ->with($this->isInstanceOf(TranslatableMarkup::class));

    $form = [];
    $this->form->submitForm($form, $form_state);
  }

  /**
   * Test form submission with multiple commands.
   */
  public function testSubmitFormWithMultipleCommands() {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('getTriggeringElement')
      ->willReturn(['#name' => 'submit']);
    $form_state->expects($this->once())
      ->method('getValue')
      ->with('commands', [])
      ->willReturn([
        0 => [
          'mimetype' => 'application/pdf',
          'command' => '/usr/bin/mutool',
          'args' => 'draw -F txt',
          'options' => '',
          'page' => TRUE,
          'ignore_errors_if_output' => FALSE,
        ],
        1 => [
          'mimetype' => 'application/msword',
          'command' => '/usr/bin/pandoc',
          'args' => '-t plain',
          'options' => '',
          'page' => FALSE,
          'ignore_errors_if_output' => TRUE,
        ],
      ]);

    $expected_commands = [
      [
        'mimetype' => 'application/pdf',
        'command' => '/usr/bin/mutool',
        'args' => 'draw -F txt',
        'options' => '',
        'page' => TRUE,
        'ignore_errors_if_output' => FALSE,
      ],
      [
        'mimetype' => 'application/msword',
        'command' => '/usr/bin/pandoc',
        'args' => '-t plain',
        'options' => '',
        'page' => FALSE,
        'ignore_errors_if_output' => TRUE,
      ],
    ];

    $this->config->expects($this->once())
      ->method('set')
      ->with('text_extraction.commands', $expected_commands)
      ->willReturnSelf();
    $this->config->expects($this->once())
      ->method('save');

    $this->form = $this->getMockBuilder(TextExtractionCommandMappingForm::class)
      ->setConstructorArgs([$this->configFactory])
      ->onlyMethods(['messenger'])
      ->getMock();

    $this->form->expects($this->once())
      ->method('messenger')
      ->willReturn($this->messenger);

    $this->messenger->expects($this->once())
      ->method('addStatus')
      ->with($this->isInstanceOf(TranslatableMarkup::class));

    $form = [];
    $this->form->submitForm($form, $form_state);
  }

  /**
   * Test form submission is skipped for non-submit actions.
   */
  public function testSubmitFormSkippedForNonSubmitActions() {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('getTriggeringElement')
      ->willReturn(['#name' => 'add_command']);

    // Should not call getValue or set.
    $form_state->expects($this->never())
      ->method('getValue');
    $this->config->expects($this->never())
      ->method('set');

    $form = [];
    $this->form->submitForm($form, $form_state);
  }

  /**
   * Test add command functionality.
   */
  public function testAddCommand() {
    $existingCommands = [
      [
        'mimetype' => 'application/pdf',
        'command' => '/usr/bin/mutool',
        'args' => 'draw -F txt',
        'options' => '',
        'page' => TRUE,
        'ignore_errors_if_output' => FALSE,
      ],
    ];

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('get')
      ->with('commands', [])
      ->willReturn($existingCommands);
    $form_state->expects($this->once())
      ->method('set')
      ->with('commands', $this->callback(function ($commands) use ($existingCommands) {
        // Should have original commands plus one new empty command.
        $this->assertCount(2, $commands);
        $this->assertEquals($existingCommands[0], $commands[0]);
        $this->assertEquals('', $commands[1]['mimetype']);
        $this->assertEquals('', $commands[1]['command']);
        $this->assertEquals('', $commands[1]['args']);
        $this->assertEquals('', $commands[1]['options']);
        $this->assertFalse($commands[1]['page']);
        $this->assertFalse($commands[1]['ignore_errors_if_output']);
        return TRUE;
      }));
    $form_state->expects($this->once())
      ->method('setRebuild')
      ->with(TRUE);

    $form = [];
    $this->form->addCommand($form, $form_state);
  }

  /**
   * Test add command with empty commands array.
   */
  public function testAddCommandWithEmptyCommands() {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('get')
      ->with('commands', [])
      ->willReturn([]);
    $form_state->expects($this->once())
      ->method('set')
      ->with('commands', $this->callback(function ($commands) {
        // Should have one empty command.
        $this->assertCount(1, $commands);
        $this->assertEquals('', $commands[0]['mimetype']);
        $this->assertEquals('', $commands[0]['command']);
        $this->assertEquals('', $commands[0]['args']);
        $this->assertEquals('', $commands[0]['options']);
        $this->assertFalse($commands[0]['page']);
        $this->assertFalse($commands[0]['ignore_errors_if_output']);
        return TRUE;
      }));
    $form_state->expects($this->once())
      ->method('setRebuild')
      ->with(TRUE);

    $form = [];
    $this->form->addCommand($form, $form_state);
  }

  /**
   * Test remove command functionality.
   */
  public function testRemoveCommand() {
    $commands = [
      0 => ['mimetype' => 'application/pdf', 'command' => '/usr/bin/mutool'],
      1 => ['mimetype' => 'application/msword', 'command' => '/usr/bin/pandoc'],
      2 => ['mimetype' => 'text/plain', 'command' => '/usr/bin/cat'],
    ];

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('get')
      ->with('commands', [])
      ->willReturn($commands);
    $form_state->expects($this->once())
      ->method('set')
      ->with('commands', $this->callback(function ($commands) {
        // Should have 2 commands after removing index 1.
        $this->assertCount(2, $commands);
        $this->assertEquals('application/pdf', $commands[0]['mimetype']);
        $this->assertEquals('/usr/bin/mutool', $commands[0]['command']);
        $this->assertEquals('text/plain', $commands[1]['mimetype']);
        $this->assertEquals('/usr/bin/cat', $commands[1]['command']);
        return TRUE;
      }));
    $form_state->expects($this->once())
      ->method('setRebuild')
      ->with(TRUE);

    // Mock the triggering element.
    $triggering_element = ['#name' => 'remove_1'];
    $form_state->expects($this->once())
      ->method('getTriggeringElement')
      ->willReturn($triggering_element);

    $form = [];
    $this->form->removeCommand($form, $form_state);
  }

  /**
   * Test remove command with invalid index.
   */
  public function testRemoveCommandWithInvalidIndex() {
    $commands = [
      0 => ['mimetype' => 'application/pdf', 'command' => '/usr/bin/mutool'],
    ];

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->expects($this->once())
      ->method('get')
      ->with('commands', [])
      ->willReturn($commands);
    // When index is invalid, set should not be called.
    $form_state->expects($this->never())
      ->method('set');
    $form_state->expects($this->once())
      ->method('setRebuild')
      ->with(TRUE);

    // Mock the triggering element with invalid index.
    $triggering_element = ['#name' => 'remove_5'];
    $form_state->expects($this->once())
      ->method('getTriggeringElement')
      ->willReturn($triggering_element);

    $form = [];
    $this->form->removeCommand($form, $form_state);
  }

  /**
   * Test AJAX callback.
   */
  public function testAjaxCallback() {
    $form = [
      'commands' => [
        'test' => 'value',
      ],
    ];

    $form_state = $this->createMock(FormStateInterface::class);
    $result = $this->form->ajaxCallback($form, $form_state);

    $this->assertEquals(['test' => 'value'], $result);
  }

  /**
   * Test cleanCommands method via reflection.
   */
  public function testCleanCommands() {
    $reflection = new \ReflectionClass($this->form);
    $method = $reflection->getMethod('cleanCommands');
    $method->setAccessible(TRUE);

    $commands = [
      [
        'mimetype' => '  application/pdf  ',
        'command' => '  /usr/bin/mutool  ',
        'args' => '  draw -F txt  ',
        'options' => '  --verbose  ',
        'page' => '1',
        'ignore_errors_if_output' => '1',
      ],
      [
        'mimetype' => '',
        'command' => '',
        'args' => '',
        'options' => '',
        'page' => '',
        'ignore_errors_if_output' => '',
      ],
      [
        'mimetype' => 'application/msword',
        'command' => '/usr/bin/pandoc',
        'args' => '',
        'options' => '',
        'page' => 0,
        'ignore_errors_if_output' => 0,
      ],
    ];

    $result = $method->invoke($this->form, $commands);

    $this->assertCount(3, $result);
    $this->assertEquals('application/pdf', $result[0]['mimetype']);
    $this->assertEquals('/usr/bin/mutool', $result[0]['command']);
    $this->assertEquals('draw -F txt', $result[0]['args']);
    $this->assertEquals('--verbose', $result[0]['options']);
    $this->assertTrue($result[0]['page']);
    $this->assertTrue($result[0]['ignore_errors_if_output']);
    $this->assertEquals('', $result[1]['mimetype']);
    $this->assertEquals('', $result[1]['command']);
    $this->assertEquals('', $result[1]['args']);
    $this->assertEquals('', $result[1]['options']);
    $this->assertFalse($result[1]['page']);
    $this->assertFalse($result[1]['ignore_errors_if_output']);
    $this->assertEquals('application/msword', $result[2]['mimetype']);
    $this->assertEquals('/usr/bin/pandoc', $result[2]['command']);
    $this->assertEquals('', $result[2]['args']);
    $this->assertEquals('', $result[2]['options']);
    $this->assertFalse($result[2]['page']);
    $this->assertFalse($result[2]['ignore_errors_if_output']);
  }

  /**
   * Test cleanCommand method via reflection.
   */
  public function testCleanCommand() {
    $reflection = new \ReflectionClass($this->form);
    $method = $reflection->getMethod('cleanCommand');
    $method->setAccessible(TRUE);

    $command = [
      'mimetype' => '  application/pdf  ',
      'command' => '  /usr/bin/mutool  ',
      'args' => '  draw -F txt  ',
      'options' => '  --verbose  ',
      'page' => '1',
      'ignore_errors_if_output' => '1',
    ];

    $result = $method->invoke($this->form, $command);

    $this->assertEquals('application/pdf', $result['mimetype']);
    $this->assertEquals('/usr/bin/mutool', $result['command']);
    $this->assertEquals('draw -F txt', $result['args']);
    $this->assertEquals('--verbose', $result['options']);
    $this->assertTrue($result['page']);
    $this->assertTrue($result['ignore_errors_if_output']);
  }

  /**
   * Test cleanCommand with empty values.
   */
  public function testCleanCommandWithEmptyValues() {
    $reflection = new \ReflectionClass($this->form);
    $method = $reflection->getMethod('cleanCommand');
    $method->setAccessible(TRUE);

    $command = [
      'mimetype' => '',
      'command' => '',
      'args' => '',
      'options' => '',
      'page' => '',
      'ignore_errors_if_output' => '',
    ];

    $result = $method->invoke($this->form, $command);

    $this->assertEquals('', $result['mimetype']);
    $this->assertEquals('', $result['command']);
    $this->assertEquals('', $result['args']);
    $this->assertEquals('', $result['options']);
    $this->assertFalse($result['page']);
    $this->assertFalse($result['ignore_errors_if_output']);
  }

  /**
   * Test cleanCommand with missing keys.
   */
  public function testCleanCommandWithMissingKeys() {
    $reflection = new \ReflectionClass($this->form);
    $method = $reflection->getMethod('cleanCommand');
    $method->setAccessible(TRUE);

    $command = [
      'mimetype' => 'application/pdf',
      'command' => '/usr/bin/mutool',
    ];

    $result = $method->invoke($this->form, $command);

    $this->assertEquals('application/pdf', $result['mimetype']);
    $this->assertEquals('/usr/bin/mutool', $result['command']);
    $this->assertEquals('', $result['args']);
    $this->assertEquals('', $result['options']);
    $this->assertFalse($result['page']);
    $this->assertFalse($result['ignore_errors_if_output']);
  }

}
