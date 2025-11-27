<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_meta\ExistingSite\Plugin\JsonLdEntity;

use Drupal\json_ld_schema\Entity\JsonLdEntityInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests TermSourceEntity getData method.
 */
class TermSourceEntityTest extends ExistingSiteBase {

  /**
   * Source vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $sourceVocabulary = NULL;

  /**
   * Country vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $countryVocabulary = NULL;

  /**
   * Original allowed social media config.
   *
   * @var array|null
   */
  protected ?array $originalAllowedSocialMediaLinks = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->saveOriginalAllowedSocialMediaConfig();
    $this->setUpSourceVocabulary();
    $this->setUpCountryVocabulary();
    $this->setUpAllowedSocialMediaConfig();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreOriginalAllowedSocialMediaConfig();
    parent::tearDown();
  }

  /**
   * Ensure the source vocabulary exists for the tests.
   */
  protected function setUpSourceVocabulary(): void {
    $this->sourceVocabulary = Vocabulary::load('source');
    if (!$this->sourceVocabulary) {
      $this->sourceVocabulary = Vocabulary::create([
        'vid' => 'source',
        'name' => 'Source',
      ]);
      $this->sourceVocabulary->save();
    }
  }

  /**
   * Ensure the country vocabulary exists for the tests.
   */
  protected function setUpCountryVocabulary(): void {
    $this->countryVocabulary = Vocabulary::load('country');
    if (!$this->countryVocabulary) {
      $this->countryVocabulary = Vocabulary::create([
        'vid' => 'country',
        'name' => 'Country',
      ]);
      $this->countryVocabulary->save();
    }
  }

  /**
   * Configure the allowed social media links for deterministic tests.
   */
  protected function setUpAllowedSocialMediaConfig(): void {
    \Drupal::configFactory()->getEditable('reliefweb_entities.settings')
      ->set('allowed_social_media_links', [
        'facebook_com' => 'Facebook',
        'twitter_com' => 'X',
      ])
      ->save();
  }

  /**
   * Save the original allowed social media configuration.
   */
  protected function saveOriginalAllowedSocialMediaConfig(): void {
    $this->originalAllowedSocialMediaLinks = \Drupal::config('reliefweb_entities.settings')
      ->get('allowed_social_media_links');
  }

  /**
   * Restore the original allowed social media configuration.
   */
  protected function restoreOriginalAllowedSocialMediaConfig(): void {
    $config = \Drupal::configFactory()->getEditable('reliefweb_entities.settings');
    if ($this->originalAllowedSocialMediaLinks !== NULL) {
      $config->set('allowed_social_media_links', $this->originalAllowedSocialMediaLinks);
    }
    else {
      $config->clear('allowed_social_media_links');
    }
    $config->save();
  }

  /**
   * Test isApplicable for source terms.
   */
  public function testIsApplicableForSourceTerms(): void {
    $entity = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Applicable Source',
    ]);

    $plugin = $this->getPlugin('rw_term_source');
    $this->assertTrue($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects non-source terms.
   */
  public function testIsApplicableRejectsNonSourceTerms(): void {
    $vocabulary = Vocabulary::create([
      'vid' => 'test_' . $this->randomMachineName(),
      'name' => 'Test Vocabulary',
    ]);
    $vocabulary->save();

    $entity = $this->createTerm($vocabulary, [
      'name' => 'Not A Source',
    ]);

    $plugin = $this->getPlugin('rw_term_source');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test getData with the basic source schema.
   */
  public function testGetDataBasicSource(): void {
    $entity = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Test Source',
    ]);

    $plugin = $this->getPlugin('rw_term_source');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('ProfilePage', $data['@type']);
    $this->assertArrayHasKey('@id', $data);
    $this->assertArrayHasKey('url', $data);
    $this->assertEquals($data['@id'], $data['url']);

    $this->assertArrayHasKey('mainEntity', $data);
    $org = $data['mainEntity'];
    $this->assertIsArray($org);
    $this->assertEquals('Organization', $org['@type']);
    $this->assertEquals('Test Source', $org['name']);
    $this->assertArrayHasKey('@id', $org);
    $this->assertArrayNotHasKey('alternateName', $org);
    $this->assertArrayNotHasKey('sameAs', $org);
  }

  /**
   * Test getData adds homepage and alternate names.
   */
  public function testGetDataWithHomepageAndAlternateNames(): void {
    $entity = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Example Organization',
      'field_homepage' => [
        ['uri' => 'https://example.org'],
      ],
      'field_shortname' => [
        ['value' => 'Example Org'],
      ],
      'field_longname' => [
        ['value' => 'Example Organization International'],
      ],
      'field_aliases' => [
        ['value' => "Example Org\nExample Organization\n"],
      ],
      'field_spanish_name' => [
        ['value' => 'Organizacion Ejemplo'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_source');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();
    $org = $data['mainEntity'];

    $this->assertEquals('https://example.org', $org['url']);
    $this->assertArrayHasKey('alternateName', $org);
    $this->assertEquals([
      'Example Org',
      'Example Organization International',
      'Organizacion Ejemplo',
    ], $org['alternateName']);
  }

  /**
   * Test getData adds headquarter location when set.
   */
  public function testGetDataWithHeadquarterLocation(): void {
    $country = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
    ]);

    $entity = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Located Source',
      'field_country' => [
        ['target_id' => $country->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_source');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();
    $org = $data['mainEntity'];

    $this->assertArrayHasKey('location', $org);
    $location = $org['location'];
    if (isset($location[0])) {
      $location = $location[0];
    }
    $this->assertEquals('Country', $location['@type']);
    $this->assertEquals('Afghanistan', $location['name']);
  }

  /**
   * Test getData adds social media links.
   */
  public function testGetDataWithSocialMediaLinks(): void {
    $entity = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Social Source',
      'field_links' => [
        ['uri' => 'https://twitter.com/reliefweb'],
        ['uri' => 'https://facebook.com/reliefweb'],
        ['uri' => 'https://example.org/not-allowed'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_source');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();
    $org = $data['mainEntity'];

    $this->assertArrayHasKey('sameAs', $org);
    $this->assertEquals([
      'https://facebook.com/reliefweb',
      'https://twitter.com/reliefweb',
    ], $org['sameAs']);
  }

  /**
   * Get the plugin instance.
   *
   * @param string $plugin_id
   *   The plugin ID.
   *
   * @return \Drupal\json_ld_schema\Entity\JsonLdEntityInterface
   *   The plugin instance.
   */
  protected function getPlugin(string $plugin_id): JsonLdEntityInterface {
    return $this->container->get('plugin.manager.json_ld_schema.entity')->createInstance($plugin_id);
  }

}
