<?php

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\Entity\Media;
use Drupal\reliefweb_entities\Entity\Report;
use Symfony\Component\Console\Output\ConsoleOutput;

const XML_FILE = __DIR__ . '/wp.xml';
const LOGFILE = __DIR__ . '/reports.csv';
const MAX_ITEMS = 1000;
const FORCE_UPDATE = FALSE;

function pfis_migrate(&$global_categories, &$global_tags) {
  $items = [];
  $images = [];
  $counter = 0;
  $xml = simplexml_load_file(XML_FILE);

  foreach ($xml->children()->children() as $child) {
    if ($child->getName() != 'item') {
      continue;
    }

    if ($counter > MAX_ITEMS) {
      return $items;
    }

    $item = [
      'type' => 'report',
      'moderation_status' => 'draft',
      'status' => 0,
      'uid' => 2,
      'field_bury' => 1,
      'field_theme' => 4597,
      'field_language' => 267,
      'field_content_format' => 8,
      'field_source' => 1503,
      'field_ocha_product' => 12353,
      'title' => (string) $child->title,
      'body' => [
        'value' => str_replace([
          '<!-- wp:paragraph -->',
          '<!-- /wp:paragraph -->',
        ], '', (string) $child->children('content', TRUE)),
        'format' => 'markdown_editor',
      ],
      'field_origin_notes' => '',
      'field_primary_country' => NULL,
      'field_country' => [],
      'field_original_publication_date' => '',
      'field_image' => '',
      'post_id' => '',
      'post_link' => '',
      'created' => '',
      'changed' => '',
      'post_type' => '',
      'image' => '',
      'caption' => '',
      'categories' => [],
      'tags' => [],
      'new_url' => '',
      'nid' => '',
    ];

    $categories = [];
    $tags = [];

    foreach ($child->children() as $property) {
      if ($property->getName() == 'category') {
        if ($property->attributes()['domain'] == 'post_tag') {
          $tags[] = (string) $property;
        }
        elseif ($property->attributes()['domain'] == 'category') {
          $categories[] = (string) $property;
        }
      }
      elseif ($property->getName() == 'post_tag') {
        $tags[] = (string) $property;
      }
      elseif ($property->getName() == 'link') {
        $item['field_origin_notes'] = str_replace('https://pooled-funds-impact-story-hub.site.strattic.io/', 'https://pooledfunds.impact.unocha.org/', (string) $property);
      }
    }

    foreach ($child->children('wp', TRUE) as $property) {
      if ($property->getName() == 'post_date') {
        $item['created'] = strtotime((string) $property);
        $item['field_original_publication_date'] = substr((string) $property, 0, 10);
      }
      elseif ($property->getName() == 'post_modified') {
        $item['changed'] = strtotime((string) $property);
      }
      elseif ($property->getName() == 'post_id') {
        $item['post_id'] = (string) $property;
        $item['post_link'] = 'https://pooledfunds.impact.unocha.org/?p=' . $item['post_id'];
      }
      elseif ($property->getName() == 'attachment_url') {
        $item['image'] = str_replace('https://pooled-funds-impact-story-hub.site.strattic.io/', 'https://pooledfunds.impact.unocha.org/', (string) $property);
      }
      elseif ($property->getName() == 'post_type') {
        $item['post_type'] = (string) $property;
      }
      elseif ($property->getName() == 'postmeta') {
        if ((string) $property->meta_key == '_thumbnail_id') {
          $item['image'] = $images[(string) $property->meta_value]['url'];
          $item['caption'] = $images[(string) $property->meta_value]['caption'];
        }
      }
      elseif ($property->getName() == 'status') {
        if ((string) $property == 'publish') {
          $item['moderation_status'] = 'published';
          $item['status'] = 1;
        }
      }
    }

    // Image?
    if ($item['post_type'] == 'attachment') {
      $images[$item['post_id']] = [
        'url' => $item['image'],
        'caption' => (string) $child->children('excerpt', TRUE) ?? '',
      ];
      continue;
    }

    // Skip pages.
    if ($item['post_type'] == 'page') {
      continue;
    }

    // Post.
    if ($item['post_type'] == 'post') {
      $counter++;

      sort($categories);
      sort($tags);

      $item['categories'] = $categories;
      $item['tags'] = $tags;
      $item['body']['value'] .= '<p><em>Pooled Fund impact stories</em></p>';

      if (!empty($categories)) {
        $item['body']['value'] .= '<p>#' . implode(', #', $categories) . '</p>';
      }

      $items[] = $item;

      $global_categories = array_unique(array_merge($global_categories ?? [], $categories ?? []));
      $global_tags = array_unique(array_merge($global_tags ?? [], $tags ?? []));
    }
  }

  return $items;
}

function pfis_get_countries($item) {
  $result = [];
  static $countries = [];

  if (!isset($item['categories']) || empty($item['categories'])) {
    return '';
  }

  if (empty($countries)) {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'country',
    ]);
    foreach ($terms as $term) {
      $countries[strtolower($term->label())] = $term->id();
    }

    // Add others.
    $countries['dr congo'] = 75;
  }

  foreach ($item['categories'] as $category) {
    if (isset($countries[strtolower(trim($category))])) {
      $result[] = $countries[strtolower(trim($category))];
    }
  }

  return $result;
}

$global_categories = [];
$global_tags = [];

$items = pfis_migrate($global_categories, $global_tags);
sort($global_categories);
sort($global_tags);

// Instantiate the transliteration class.
$transliteration = \Drupal::transliteration();

/** @var \Drupal\Core\File\FileSystemInterface $file_system */
$file_system = \Drupal::service('file_system');
$directory = 'public://stories';
$file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

/** @var \Drupal\file\FileRepository $file_repository */
$file_repository = \Drupal::service('file.repository');

$log = fopen(LOGFILE, 'w');
// Headers.
fputcsv($log, [
  'type',
  'moderation_status',
  'status',
  'uid',
  'field_bury',
  'field_theme',
  'field_language',
  'field_content_format',
  'field_source',
  'field_ocha_product',
  'title',
  'body',
  'field_origin_notes',
  'field_primary_country',
  'field_country',
  'field_original_publication_date',
  'field_image',
  'post_id',
  'post_link',
  'created',
  'changed',
  'post_type',
  'image',
  'caption',
  'categories',
  'tags',
  'new_url',
  'nid',
]);

$output = new ConsoleOutput();

foreach ($items as &$item) {
  $output->writeln('<info>Processing "' . $item['title'] . '"</info>');

  // Get country if it exists.
  if ($countries = pfis_get_countries($item)) {
    $item['field_primary_country'] = reset($countries);
    $item['field_country'] = $countries;
  }

  // Get image if needed.
  if (isset($item['image']) && !empty($item['image'])) {
    $url = $item['image'];
    $filename = basename($url);
    $filename = $transliteration->transliterate($filename, 'en');

    $file = $file_repository->loadByUri($directory . '/' . $filename);
    if ($file && $file->getSize() > 0) {
      $media_entities = $this->entityTypeManager()->getStorage('media')->loadByProperties([
        'field_media_image' => $file->id(),
      ]);

      $media = is_array($media_entities) ? array_pop($media_entities) : NULL;
      if (FORCE_UPDATE) {
        $media->set('field_description', $item['caption'])->save();
      }
      $item['field_image'] = $media->id();
    }
    else {
      $data = (string) \Drupal::httpClient()->get($url)->getBody();
      /** @var \Drupal\file\FileInterface $file */
      $file = $file_repository->writeData($data, $directory . '/' . $filename, FileExists::Replace);

      $media = Media::create([
        'bundle' => 'image_report',
        'uid' => 2,
        'field_media_image' => [
          'target_id' => $file->id(),
        ],
        'field_description' => $item['caption'],
      ]);

      $media->setName($filename)
        ->setPublished(TRUE)
        ->save();

      $item['field_image'] = $media->id();
    }
  }

  // Make sure report doesn't exist.
  $reports = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
    'type' => 'report',
    'field_origin_notes' => $item['field_origin_notes']
  ]);

  if (empty($reports)) {
    $report = Report::create($item);
    if ($item['status']) {
      $report->setPublished();
    }
    $report->save();
  }
  else {
    $report = reset($reports);
    if (FORCE_UPDATE) {
      $output->writeln('<comment>Deleting "' . $item['title'] . '"</comment>');
      $report->delete();
      $report = Report::create($item);
      if ($item['status']) {
        $report->setPublished();
      }
      try {
        $report->save();
      }
      catch (\Throwable $t) {
        print_r($item);
      }
      catch (\Exception $t) {
        print_r($item);
      }
    }
  }

  $item['new_url'] = $report->toUrl()->toString();
  $item['nid'] = $report->id();

  $item['body'] = str_replace(["\n", "\r"], ' ', substr($item['body']['value'], 0, 100) . ' ...');
  $item['categories'] = implode(', ', $item['categories']);
  $item['tags'] = implode(', ', $item['tags']);
  $item['field_country'] = implode(', ', $item['field_country']);

  fputcsv($log, $item);
  sleep(1);
}

fclose($log);
