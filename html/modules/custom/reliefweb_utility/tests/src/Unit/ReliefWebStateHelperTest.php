<?php

namespace Drupal\Tests\reliefweb_utility\Unit\Helpers;

use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\reliefweb_utility\Helpers\ReliefWebStateHelper;
use Symfony\Component\DependencyInjection\Container;

/**
 * @coversDefaultClass \Drupal\reliefweb_utility\Helpers\ReliefWebStateHelper
 * @group reliefweb_utility
 */
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
   * @covers ::getSubmitEmail
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
   * @covers ::getReportPublicationEmailMessage
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
   * @covers ::getJobIrrelevantCountries
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
   * @covers ::getJobIrrelevantThemes
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
   * @covers ::getJobThemelessCategories
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
   * @covers ::getTrainingIrrelevantThemes
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
   * @covers ::getTrainingIrrelevantLanguages
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
   * @covers ::getTrainingIrrelevantTrainingLanguages
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
