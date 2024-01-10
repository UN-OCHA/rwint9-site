<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_meta\ExistingSite;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\theme_switcher\Entity\ThemeSwitcherRule;
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
    $report_title = 'My report';

    $report = Node::create([
      'type' => 'report',
      'title' => $report_title,
    ]);
    $report->isNew();
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
    $report_title = 'My report';
    $media = $this->createMediaImage('headline.jpg');
    $filename = $media->field_media_image->entity->getFileUri();

    $report = Node::create([
      'type' => 'report',
      'title' => $report_title,
      'field_headline_image' => [
        'target_id' => $media->id(),
      ],
    ]);
    $report->isNew();
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
    $report_title = 'My report';
    $media = $this->createMediaImage('image.jpg');
    $filename = $media->field_media_image->entity->getFileUri();

    $report = Node::create([
      'type' => 'report',
      'title' => $report_title,
      'field_image' => [
        'target_id' => $media->id(),
      ],
    ]);
    $report->isNew();
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
   * Create media item.
   */
  private function createMediaImage(string $name): Media {
    $img = 'https://picsum.photos/200/300.jpg';

    /** @var \Drupal\file\Entity\File $file */
    $file = system_retrieve_file($img, 'public://images/' . $name, TRUE, FileSystemInterface::EXISTS_REPLACE);
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

}
