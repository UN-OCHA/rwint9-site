<?php

namespace Drupal\Tests\reliefweb_utility\Unit\Helpers;

use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_utility\Helpers\ReliefWebStateHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\Container;

/**
 * Tests relief web state helper.
 */
#[CoversClass(ReliefWebStateHelper::class)]
#[Group('reliefweb_utility')]
class ReliefWebStateHelperTest extends UnitTestCase {

  /**
   * The state service mock.
   *
   * @var \Drupal\Core\State\StateInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $stateMock;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->stateMock = $this->createMock(StateInterface::class);
    $container = new Container();
    $container->set('state', $this->stateMock);
    \Drupal::setContainer($container);
  }

  /**
   * Test get submit email.
   */
  public function testGetSubmitEmail() {
    $expected_email = 'submit@example.com';
    $this->stateMock->expects($this->once())
      ->method('get')
      ->with('reliefweb_submit_email')
      ->willReturn($expected_email);

    $this->assertEquals($expected_email, ReliefWebStateHelper::getSubmitEmail());
  }

  /**
   * Test get report publication email message.
   */
  public function testGetReportPublicationEmailMessage() {
    $expected_message = 'Your report has been published.';
    $this->stateMock->expects($this->once())
      ->method('get')
      ->with('reliefweb_report_publication_email_message')
      ->willReturn($expected_message);

    $this->assertEquals($expected_message, ReliefWebStateHelper::getReportPublicationEmailMessage());
  }

  /**
   * Test get job irrelevant countries.
   */
  public function testGetJobIrrelevantCountries() {
    $expected_countries = [254, 255];
    $this->stateMock->expects($this->once())
      ->method('get')
      ->with('reliefweb_job_irrelevant_countries', [254])
      ->willReturn($expected_countries);

    $this->assertEquals($expected_countries, ReliefWebStateHelper::getJobIrrelevantCountries());
  }

  /**
   * Test get job irrelevant themes.
   */
  public function testGetJobIrrelevantThemes() {
    $expected_themes = [4589, 4597, 4598, 4599];
    $this->stateMock->expects($this->once())
      ->method('get')
      ->with('reliefweb_job_irrelevant_themes', [4589, 4597, 4598])
      ->willReturn($expected_themes);

    $this->assertEquals($expected_themes, ReliefWebStateHelper::getJobIrrelevantThemes());
  }

  /**
   * Test get job themeless categories.
   */
  public function testGetJobThemelessCategories() {
    $expected_categories = [6863, 6864, 6866, 20966, 20967];
    $this->stateMock->expects($this->once())
      ->method('get')
      ->with('reliefweb_job_themeless_categories', [6863, 6864, 6866, 20966])
      ->willReturn($expected_categories);

    $this->assertEquals($expected_categories, ReliefWebStateHelper::getJobThemelessCategories());
  }

  /**
   * Test get training irrelevant themes.
   */
  public function testGetTrainingIrrelevantThemes() {
    $expected_themes = [4589, 4598, 49458, 49459];
    $this->stateMock->expects($this->once())
      ->method('get')
      ->with('reliefweb_training_irrelevant_themes', [4589, 4598, 49458])
      ->willReturn($expected_themes);

    $this->assertEquals($expected_themes, ReliefWebStateHelper::getTrainingIrrelevantThemes());
  }

  /**
   * Test get training irrelevant languages.
   */
  public function testGetTrainingIrrelevantLanguages() {
    $expected_languages = [6876, 10906, 31996, 31997];
    $this->stateMock->expects($this->once())
      ->method('get')
      ->with('reliefweb_training_irrelevant_languages', [6876, 10906, 31996])
      ->willReturn($expected_languages);

    $this->assertEquals($expected_languages, ReliefWebStateHelper::getTrainingIrrelevantLanguages());
  }

  /**
   * Test get training irrelevant training languages.
   */
  public function testGetTrainingIrrelevantTrainingLanguages() {
    $expected_languages = [31996, 31997];
    $this->stateMock->expects($this->once())
      ->method('get')
      ->with('reliefweb_training_irrelevant_training_languages', [31996])
      ->willReturn($expected_languages);

    $this->assertEquals($expected_languages, ReliefWebStateHelper::getTrainingIrrelevantTrainingLanguages());
  }

}
