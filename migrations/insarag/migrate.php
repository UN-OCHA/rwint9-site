<?php

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\Entity\Media;
use Drupal\reliefweb_entities\Entity\Report;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\Console\Output\ConsoleOutput;

const XML_FILE = __DIR__ . '/posts.xml';
const XML_FILE_MEDIA = __DIR__ . '/media.xml';
const LOGFILE = __DIR__ . '/reports.csv';
const MAX_ITEMS = 9999;
const FORCE_UPDATE = TRUE;
global $source_id;
global $base_url;

include_once __DIR__ . '/create_reliefweb_file.php';

function migrateItems(&$global_categories, &$global_tags, $media_items) {
  $items = [];
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
      'field_theme' => [
        4590,
        4591,
      ],
      'field_language' => 267,
      'field_content_format' => 9,
      'field_source' => 1503,
      'title' => (string) $child->title,
      'body' => [
        'value' => str_replace([
          '<!-- wp:paragraph -->',
          '<!-- /wp:paragraph -->',
        ], '', (string) $child->children('content', TRUE)),
        'format' => 'markdown_editor',
      ],
      'field_origin_notes' => '',
      'field_primary_country' => 254,
      'field_country' => [
        254,
      ],
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
        $item['field_origin_notes'] = (string) $property;
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
        $item['post_link'] = 'https://insarag.org/?p=' . $item['post_id'];
      }
      elseif ($property->getName() == 'attachment_url') {
        $item['attachment_url'] = (string) $property;
      }
      elseif ($property->getName() == 'post_type') {
        $item['post_type'] = (string) $property;
      }
      elseif ($property->getName() == 'postmeta') {
        if ((string) $property->meta_key == '_thumbnail_id') {
          $item['image'] = $media_items[(string) $property->meta_value]['url'];
          $item['caption'] = $media_items[(string) $property->meta_value]['caption'];
        }
      }
    }

    // Only posts.
    if ($item['post_type'] != 'post') {
      continue;
    }

    $counter++;

    sort($categories);
    sort($tags);

    $item['categories'] = $categories;
    $item['tags'] = $tags;
    $item['body']['value'] .= '<p><em>Insarag</em></p>';

    if (!empty($categories)) {
      $item['body']['value'] .= '<p>#' . implode(', #', $categories) . '</p>';
    }

    $items[] = $item;

    $global_categories = array_unique(array_merge($global_categories ?? [], $categories ?? []));
    $global_tags = array_unique(array_merge($global_tags ?? [], $tags ?? []));
  }

  return $items;
}

/**
 * Create Insarag as source (if needed).
 */
function createSourceInsarag() {
  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
    'vid' => 'source',
    'name' => 'Insarag',
  ]);

  if (empty($terms)) {
    $term = Term::create([
      'name' => 'Insarag',
      'vid' => 'source',
      'langcode' => 'en',
      'field_allowed_content_types' => [
        '1',
      ],
      'field_shortname' => 'Insarag',
    ]);
    $term->setPublished()->save();
  }
  else {
    $term = reset($terms);
  }

  return $term->id();
}

/**
 * Import media data.
 */
function importMediaItems() {
  $items = [];
  $xml = simplexml_load_file(XML_FILE_MEDIA);

  foreach ($xml->getDocNamespaces() as $prefix => $namespace) {
    $xml->registerXPathNamespace($prefix,$namespace);
  }

  foreach ($xml->children()->children() as $child) {
    if ($child->getName() != 'item') {
      continue;
    }

    $post_type = (string) $child->xpath('//wp:post_type')[0];
    if ($post_type != 'attachment') {
      continue;
    }

    $item = [
      'type' => 'media',
    ];

    foreach ($child->children() as $property) {
      switch ($property->getName()) {
        case 'title':
          $item['caption'] = (string) $property;
          break;

      }
    }

    foreach ($child->children('wp', TRUE) as $property) {
      switch ($property->getName()) {
        case 'post_date':
          $item['created'] = strtotime((string) $property);
          break;

        case 'post_id':
          $item['post_id'] = (string) $property;
          $item['post_link'] = 'https://insarag.org/?p=' . $item['post_id'];
          break;

        case 'attachment_url':
          $item['url'] = (string) $property;
      }
    }

    $items[$item['post_id']] = $item;
  }

  return $items;
}

$output = new ConsoleOutput();

$output->writeln('<info>Importing media items.</info>');
$media_items = importMediaItems();

$output->writeln('<info>Adding Insarag as source.</info>');
$source_id = createSourceInsarag();

$global_categories = [];
$global_tags = [];

$items = migrateItems($global_categories, $global_tags, $media_items);
sort($global_categories);
sort($global_tags);

// Instantiate the transliteration class.
$transliteration = \Drupal::transliteration();

/** @var \Drupal\Core\File\FileSystemInterface $file_system */
$file_system = \Drupal::service('file_system');
$directory = 'public://insarag';
$file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

/** @var \Drupal\file\FileRepository $file_repository */
$file_repository = \Drupal::service('file.repository');

/** @var \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator */
$file_url_generator = \Drupal::service('file_url_generator');

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

$file_mapping = [];

foreach ($items as &$item) {
  $attachments = [];

  // Set source.
  $item['field_source'] = $source_id;

  $output->writeln('<info>Processing "' . $item['title'] . '"</info>');
  if (empty($item['title'])) {
    $output->writeln('<comment>Skipping, no title.</comment>');
    continue;
  }

  // Parse the HTML string.
  $flags = LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING;
  $meta = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
  $dom = new \DomDocument();
  $html = $item['body']['value'];
  $dom->loadHTML($meta . $html, $flags);

  $links = $dom->getElementsByTagName('a');
  foreach ($links as $link) {
    $url = $link->getAttribute('href');
    $source = parse_url($url);
    if (!empty($source) && isset($source['host']) && $source['host'] == 'insarag.org') {
      // Ignore images.
      if (
        str_ends_with(strtolower($source['path']), '.jpg')
        || str_ends_with(strtolower($source['path']), '.jpeg')
        || str_ends_with(strtolower($source['path']), '.png')
      ) {
        continue;
      }

      $attachments[] = $url;
      $path = str_replace('/wp-content/uploads/', '/sites/default/files/insarag/', $source['path']);
      $uri = str_replace('/wp-content/uploads/', 'public://insarag/', $source['path']);

      // Existing file.
      if (isset($file_mapping[$path])) {
        $path = $file_mapping[$path];
      }
      else {
        $file = $file_repository->loadByUri($uri);
        if (!$file) {
          try {
            $data = (string) \Drupal::httpClient()->get($url)->getBody();
            $file_system->prepareDirectory(dirname($uri), FileSystemInterface::CREATE_DIRECTORY);
            /** @var \Drupal\file\FileInterface $file */
            $file = $file_repository->writeData($data, $uri, FileExists::Replace);
            $new_path = $file_url_generator->generate($file->getFileUri())->toString();
            $file_mapping[$path] = $new_path;
            $path = $new_path;
          }
          catch (\Exception $e) {
            $path = '';
          }
        }
        if (!empty($path)) {
          $link->setAttribute('href', $path);
        }
      }
    }
  }

  if (isset($item['attachment_url'])) {
    if (!empty($item['attachment_url'])) {
      $attachments[] = $item['attachment_url'];
    }
    unset($item['attachment_url']);
  }

  // Avoid duplicates.
  $attachments = array_unique($attachments);
  if (empty($attachments)) {
    $output->writeln('<comment>Skipping, no attachments found.</comment>');
    continue;
  }

  $images = $dom->getElementsByTagName('img');
  foreach ($images as $image) {
    $url = $image->getAttribute('src');
    $source = parse_url($url);
    if (!empty($source) && isset($source['host']) && $source['host'] == 'insarag.org') {
      $path = str_replace('/wp-content/uploads/', '/sites/default/files/insarag/', $source['path']);
      $uri = str_replace('/wp-content/uploads/', 'public://insarag/', $source['path']);

      // Existing file.
      if (isset($file_mapping[$path])) {
        $path = $file_mapping[$path];
      }
      else {
        $file = $file_repository->loadByUri($uri);
        if (!$file) {
          try {
            $data = (string) \Drupal::httpClient()->get($url)->getBody();
            $file_system->prepareDirectory(dirname($uri), FileSystemInterface::CREATE_DIRECTORY);
            /** @var \Drupal\file\FileInterface $file */
            $file = $file_repository->writeData($data, $uri, FileExists::Replace);
            $new_path = $file_url_generator->generate($file->getFileUri())->toString();
            $file_mapping[$path] = $new_path;
            $path = $new_path;
          }
          catch (\Exception $e) {
            $path = '';
          }
        }
        if (!empty($path)) {
          $image->setAttribute('src', $path);
        }
      }
    }
  }

  $html = $dom->saveHTML();
  // Search for the body tag and return its content.
  $start = mb_strpos($html, '<body>');
  $end = mb_strrpos($html, '</body>');
  if ($start !== FALSE && $end !== FALSE) {
    $start += 6;
    $html = trim(mb_substr($html, $start, $end - $start));
  }

  $item['body']['value'] = $html;

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
      try {
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
      catch (\Exception $e) {
        $path = '';
      }
    }
  }

  // Make sure report doesn't exist.
  $reports = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
    'type' => 'report',
    'field_origin_notes' => $item['field_origin_notes']
  ]);

  if (empty($reports)) {
    $report = Report::create($item);
    $report->save();
  }
  else {
    $report = reset($reports);
    if (FORCE_UPDATE) {
      $output->writeln('<comment>Deleting "' . $item['title'] . '"</comment>');
      $report->delete();
      $report = Report::create($item);

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

  // Add files to report.
  if (!empty($attachments)) {
    $output->writeln('<info>Adding attachments to "' . $report->id() . '"</info>');
    $output->writeln('<info>Files: ' . implode(', ', $attachments) . '</info>');

    $files = array_map(fn($attachment) => [
      'url' => $attachment,
      'filename' => basename($attachment),
    ], $attachments);

    set_reliefweb_file_field($report, 'field_file', $files);
    $report->save();
  }

  $item['new_url'] = $report->toUrl()->toString();
  $item['nid'] = $report->id();

  $item['body'] = str_replace(["\n", "\r"], ' ', substr($item['body']['value'], 0, 100) . ' ...');
  $item['categories'] = implode(', ', $item['categories']);
  $item['tags'] = implode(', ', $item['tags']);
  $item['field_country'] = implode(', ', $item['field_country']);
  $item['field_theme'] = implode(', ', $item['field_theme']);

  fputcsv($log, $item);
  sleep(1);
}

fclose($log);
