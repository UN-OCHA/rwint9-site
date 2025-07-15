<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_meta\ExistingSite;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests meta images.
 */
class ReliefwebMetaTestImages extends ExistingSiteBase {

  use MediaTypeCreationTrait;

  /**
   * Test report - default image.
   */
  public function testDefaultImage() {
    global $base_url;

    $site_name = \Drupal::config('system.site')->get('name');
    $report_title = 'Test default image';

    $report = Node::create([
      'type' => 'report',
      'title' => $report_title,
    ]);

    $report->save();

    if ($report instanceof EntityModeratedInterface) {
      $report->setModerationStatus('published');
    }

    $report->setPublished();
    $report->save();

    // Check operation on landing page.
    $this->drupalGet($report->toUrl());
    $this->assertSession()->titleEquals($report_title . ' | ' . $site_name);
    $this->assertSession()->responseContains('<meta property="og:image" content="' . $base_url . '/modules/custom/reliefweb_meta/images/default.png" />');
  }

  /**
   * Test report - headline image.
   */
  public function testHeadlineImage() {
    global $base_url;

    $site_name = \Drupal::config('system.site')->get('name');
    $report_title = 'Test headline image';
    $media = $this->createMediaImage('headline.jpg');
    $filename = $media->field_media_image->entity->getFileUri();

    $report = Node::create([
      'type' => 'report',
      'title' => $report_title,
      'field_headline_image' => [
        'target_id' => $media->id(),
      ],
    ]);

    $report->save();

    if ($report instanceof EntityModeratedInterface) {
      $report->setModerationStatus('published');
    }

    $report->setPublished();
    $report->save();

    // Check operation on landing page.
    $this->drupalGet($report->toUrl());
    $this->assertSession()->titleEquals($report_title . ' | ' . $site_name);
    $this->assertSession()->responseContains('<meta property="og:image" content="' . $base_url . '/sites/default/files/styles/large/public/' . str_replace('public://', '', $filename));
  }

  /**
   * Test report - normal image.
   */
  public function testNormalImage() {
    global $base_url;

    $site_name = \Drupal::config('system.site')->get('name');
    $report_title = 'Test normal image';
    $media = $this->createMediaImage('image.jpg');
    $filename = $media->field_media_image->entity->getFileUri();

    $report = Node::create([
      'type' => 'report',
      'title' => $report_title,
      'field_image' => [
        'target_id' => $media->id(),
      ],
    ]);

    $report->save();

    if ($report instanceof EntityModeratedInterface) {
      $report->setModerationStatus('published');
    }

    $report->setPublished();
    $report->save();

    // Check operation on landing page.
    $this->drupalGet($report->toUrl());
    $this->assertSession()->titleEquals($report_title . ' | ' . $site_name);
    $this->assertSession()->responseContains('<meta property="og:image" content="' . $base_url . '/sites/default/files/styles/large/public/' . str_replace('public://', '', $filename));
  }

  /**
   * Test report - disaster type.
   */
  public function testDisasterTypeImage() {
    global $base_url;

    $site_name = \Drupal::config('system.site')->get('name');
    $report_title = 'Test disaster type image';

    $disaster_type = $this->createDisasterTypeTerm();

    $report = Node::create([
      'type' => 'report',
      'title' => $report_title,
      'field_disaster_type' => [
        'target_id' => $disaster_type->id(),
      ],
    ]);

    $report->save();

    if ($report instanceof EntityModeratedInterface) {
      $report->setModerationStatus('published');
    }

    $report->setPublished();
    $report->save();

    // Check operation on landing page.
    $this->drupalGet($report->toUrl());
    $this->assertSession()->titleEquals($report_title . ' | ' . $site_name);
    $this->assertSession()->responseContains('<meta property="og:image" content="' . $base_url . '/modules/custom/reliefweb_meta/images/disaster-type/CW.png');
  }

  /**
   * Test report - primary country.
   */
  public function testPrimaryCountryImage() {
    global $base_url;

    $site_name = \Drupal::config('system.site')->get('name');
    $report_title = 'Test primary country image';
    $iso3 = 'bel';

    $country = $this->createCountryTerm($iso3);

    $report = Node::create([
      'type' => 'report',
      'title' => $report_title,
      'field_primary_country' => [
        'target_id' => $country->id(),
      ],
    ]);

    $report->save();

    if ($report instanceof EntityModeratedInterface) {
      $report->setModerationStatus('published');
    }

    $report->setPublished();
    $report->save();

    // Check operation on landing page.
    $this->drupalGet($report->toUrl());
    $this->assertSession()->titleEquals($report_title . ' - ' . $iso3 . ' | ' . $site_name);
    $this->assertSession()->responseContains('<meta property="og:image" content="' . $base_url . '/modules/custom/reliefweb_meta/images/icons/Belgium_BEL.png');
  }

  /**
   * Test report - country.
   */
  public function testCountryImage() {
    global $base_url;

    $site_name = \Drupal::config('system.site')->get('name');
    $report_title = 'Test country image';
    $iso3 = 'afg';

    $country = $this->createCountryTerm($iso3);

    $report = Node::create([
      'type' => 'report',
      'title' => $report_title,
      'field_country' => [
        'target_id' => $country->id(),
      ],
    ]);

    $report->save();

    if ($report instanceof EntityModeratedInterface) {
      $report->setModerationStatus('published');
    }

    $report->setPublished();
    $report->save();

    // Check operation on landing page.
    $this->drupalGet($report->toUrl());
    $this->assertSession()->titleEquals($report_title . ' | ' . $site_name);
    $this->assertSession()->responseContains('<meta property="og:image" content="' . $base_url . '/modules/custom/reliefweb_meta/images/icons/Afghanistan_AFG.png');
  }

  /**
   * Create media item.
   */
  private function createMediaImage(string $name): Media {
    // Create a simple test 1x1 png image file directly.
    $image_content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $file_system = \Drupal::service('file_system');
    $directory = 'public://images';

    // Ensure the directory exists.
    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

    $file_uri = $directory . '/' . $name;

    // Write the image content to the file.
    $file_system->saveData($image_content, $file_uri, FileExists::Replace);

    // Create a file entity.
    $file = \Drupal::entityTypeManager()->getStorage('file')->create([
      'uri' => $file_uri,
      'filename' => $name,
      'filemime' => 'image/png',
      'status' => 1,
    ]);

    $file->save();

    $media = Media::create([
      'name' => $name,
      'bundle' => 'image_report',
      'uid' => 1,
      'langcode' => \Drupal::languageManager()->getCurrentLanguage()->getId(),
      'status' => 1,
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => 'alt',
        'title' => 'title',
      ],
    ]);

    $media->save();

    return $media;
  }

  /**
   * Create disaster type term.
   */
  private function createDisasterTypeTerm() : Term {
    $term = Term::create([
      'vid' => 'disaster_type',
      'name' => 'Coldwave',
      'field_disaster_type_code' => 'CW'
    ]);

    $term->save();
    $term->setPublished()->save();

    return $term;
  }

  /**
   * Create country term.
   */
  private function createCountryTerm($iso3) : Term {
    $term = Term::create([
      'vid' => 'country',
      'name' => $iso3,
      'field_iso3' => $iso3,
    ]);

    $term->save();

    if ($term instanceof EntityModeratedInterface) {
      $term->setModerationStatus('ongoing');
    }

    $term->setPublished()->save();

    return $term;
  }

}
