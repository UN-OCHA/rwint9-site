<?php

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\Entity\Media;
use Drupal\reliefweb_entities\Entity\Report;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\Console\Output\ConsoleOutput;

const XML_FILE = __DIR__ . '/resources.xml';
const XML_FILE_MEDIA = __DIR__ . '/media.xml';
const LOGFILE = __DIR__ . '/reports.csv';
const MAX_ITEMS = 25;
const FORCE_UPDATE = TRUE;
const DRY_RUN = FALSE;
global $source_id;
global $base_url;

include_once __DIR__ . '/create_reliefweb_file.php';

function migrateItems($media_items) {
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
      'field_theme' => [],
      'field_language' => 267,
      'field_content_format' => 9,
      'field_source' => [],
      'field_disaster_type' => [],
      'field_ocha_product' => '',
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
      'extra' => [],
    ];

    $categories = [];
    $tags = [];

    foreach ($child->children() as $property) {
      if ($property->getName() == 'category') {
        $domain = (string) $property->attributes()['domain'];
        if (!isset($categories[$domain])) {
          $categories[$domain] = [];
        }
        $categories[$domain][] = (string) $property;
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
        $item['post_link'] = 'https://resources.eecentre.org/?p=' . $item['post_id'];
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
        else {
          $item['extra'][(string) $property->meta_key] = (string) $property->meta_value;
        }
      }
      elseif ($property->getName() == 'status') {
        if ((string) $property == 'publish') {
          $item['moderation_status'] = 'published';
          $item['status'] = 1;
        }
      }
    }

    // Only posts.
    if ($item['post_type'] != 'resources') {
      continue;
    }

    // Use mappings to get terms.
    foreach ($categories as $categorie => &$values) {
      foreach ($values as &$value) {
        $new = contentMapWp2Rw($categorie, $value);
        if (empty($new)) {
          continue;
        }

        switch ($categorie) {
          case 'resource_type':
            $item['field_content_format'] = $new;
            break;

          case 'cluster':
            $item['field_source'][] = $new;

            $new = contentMapWp2Rw('cluster_to_theme', $value);
            if (empty($new)) {
              continue 2;
            }
            $item['field_theme'][] = $new;

            break;

          case 'crisis_type':
            $item['field_disaster_type'][] = $new;
            break;

          case 'themes':
            $item['field_theme'][] = $new;
            break;

          case 'country':
            // Remove world
            if (in_array('254', $item['field_country'])) {
              $item['field_country'] = array_diff($item['field_country'], ['254']);
            }

            $item['field_country'][] = $new;
            $item['field_primary_country'] = $new;
            break;
        }
      }
    }

    $counter++;

    $item['categories'] = $categories;
    $item['tags'] = $tags;
    $item['body']['value'] .= '<p><em>#JEU</em></p>';

    if (!empty($categories['themes'] ?? [])) {
      sort($categories['themes']);
      $item['body']['value'] .= '<p>#' . implode(', #', $categories['themes']) . '</p>';
    }

    $items[] = $item;
  }

  return $items;
}

/**
 * Mapping from WP to RW.
 */
function contentMapWp2Rw(string $voc, string $name) : string|NULL {
  if ($voc == 'country') {
    return loadTerm('country', $name) ?? NULL;
  }

  $mapping = [
    'resource_type' => [
      'Case Study' => 'Evaluation or Lessons Learned',
      'Communication Material' => 'News and Press Release',
      'Guideline' => 'Manual and Guideline',
      'Mission Report' => 'Assessment',
      'Policy Document' => 'Manual and Guideline',
      'Report / Study' => 'Manual and Guideline',
      'Tool' => 'Manual and Guideline',
      'Training Material' => 'Manual and Guideline',
    ],
    'cluster' => [
      'Camp Coordination and Camp management (CCCM)' => 'CCCM Cluster',
      'Education' => 'Education Cluster',
      'Food Security, Nutrition and Livelihoods' => 'Food Security Cluster',
      'Health' => 'Health Cluster',
      'Logistics' => 'Logistics Cluster',
      'Protection' => 'Protection Cluster',
      'Shelter and Settlements' => 'Shelter Cluster',
      'Water and Sanitation (WASH)' => 'WASH Cluster',
    ],
    'cluster_to_theme' => [
      'Camp Coordination and Camp management (CCCM)' => 'Camp Coordination and Camp Management',
      'Education' => 'Education',
      'Food Security, Nutrition and Livelihoods' => 'Food and Nutrition',
      'Health' => 'Health',
      'Logistics' => 'Logistics and Telecommunications',
      'Protection' => 'Protection and Human Rights',
      'Shelter and Settlements' => 'Shelter and Non-Food Items',
      'Water and Sanitation (WASH)' => 'Water Sanitation Hygiene',
    ],
    'crisis_type' => [
      'All Hazards' => 'Other',
      'Anthropogenic Hazards' => 'Other',
      'Contamination' => 'Technological Disaster',
      'Industrial hazards' => 'Technological Disaster',
      'Structural Collapse' => 'Technological Disaster',
      'Chemical' => 'Technological Disaster',
      'Environmental' => 'Other',
      'Natural Hazards' => 'Other',
      'Deforestation' => 'Other',
      'Drought' => 'Drought',
      'Earthquake / Tsunami' => 'Earthquake AND/OR Tsunami',
      'Floods' => 'Floods',
      'Landslides & Erosion' => 'Land slide',
      'Tropical storm' => 'Tropical Cyclone',
      'Volcano' => 'Volcano',
      'Wildfire' => 'Wild Fire',
      'Other Hazards' => 'Other',
      'Societal' => 'Other',
      'Technological' => 'Technological Disaster',
    ],
    'themes' => [
      'Cash' => 'Coordination',
      'Chemicals & Pesticides' => 'Climate Change and Environment',
      'Climate Risk' => 'Climate Change and Environment',
      'COVID-19' => 'Health',
      'Debris & Waste' => 'Climate Change and Environment',
      'Waste Management' => 'Climate Change and Environment',
      'Disaster Risk Reduction' => 'Disaster Management',
      'Energy' => 'Climate Change and Environment',
      'Gender & Inclusion' => 'Gender',
      'Geographic Information System (GIS)' => 'Coordination',
      'Materials & Supply Chain' => 'Climate Change and Environment',
      'Natural Resource Management' => 'Climate Change and Environment',
      'Resilience' => 'Disaster Management',
    ],
    'category' => [
      'Mission Report' => 'Assessment',
      'News' => 'News and Press Release',
      'Events' => 'Other',
      'Initiatives' => 'Other',
      'Uncategorised' => 'Other',
    ],
  ];

  $voc2vid = [
    'resource_type' => 'content_format',
    'cluster' => 'source',
    'cluster_to_theme' => 'theme',
    'crisis_type' => 'disaster_type',
    'themes' => 'theme',
    'category' => 'content_format',
  ];

  if (isset($mapping[$voc][$name])) {
    return loadTerm($voc2vid[$voc], $mapping[$voc][$name]);
  }

  return NULL;
}

/**
 * Create EECentre as source (if needed).
 */
function createSourceEECentre() {
  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
    'vid' => 'source',
    'name' => 'EECentre',
  ]);

  if (empty($terms)) {
    $term = Term::create([
      'name' => 'EECentre',
      'vid' => 'source',
      'langcode' => 'en',
      'field_allowed_content_types' => [
        '1',
      ],
      'field_shortname' => 'EECentre',
    ]);
    $term->setPublished()->save();
  }
  else {
    $term = reset($terms);
  }

  return $term->id();
}

/**
 * Load a term.
 */
function loadTerm($voc, $name) {
  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
    'vid' => $voc,
    'name' => $name,
  ]);

  if (empty($terms)) {
    return NULL;
  }

  $term = reset($terms);
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
          $item['post_link'] = 'https://resources.eecentre.org/?p=' . $item['post_id'];
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

$global_categories = [];
$global_tags = [];
$items = migrateItems($media_items);

// Instantiate the transliteration class.
$transliteration = \Drupal::transliteration();

/** @var \Drupal\Core\File\FileSystemInterface $file_system */
$file_system = \Drupal::service('file_system');
$directory = 'public://resources_eecentre';
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
  'field_disaster_type',
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

$file_mapping = [];

foreach ($items as &$item) {
  // Extract attachments as absolute links.
  $attachments = [];
  if (!empty($item['extra'])) {
    foreach (range(0, 15) as $i) {
      if (isset($item['extra']['additional_content_0_further_reading_' . $i . '_type']) && $item['extra']['additional_content_0_further_reading_' . $i . '_type'] == 'download') {
        if (isset($item['extra']['additional_content_0_further_reading_' . $i . '_download'])) {
          if (!empty($item['extra']['additional_content_0_further_reading_' . $i . '_download'])) {
            $attachments[] = $item['extra']['additional_content_0_further_reading_' . $i . '_download'];
          }
        }
      }
      if (isset($item['extra']['additional_content_1_further_reading_' . $i . '_type']) && $item['extra']['additional_content_1_further_reading_' . $i . '_type'] == 'download') {
        if (isset($item['extra']['additional_content_1_further_reading_' . $i . '_download'])) {
          if (!empty($item['extra']['additional_content_1_further_reading_' . $i . '_download'])) {
            $attachments[] = $item['extra']['additional_content_1_further_reading_' . $i . '_download'];
          }
        }
      }
    }

    // Avoid duplicates.
    $attachments = array_unique($attachments);
  }

  // Remove extra.
  unset($item['extra']);

  $output->writeln('<info>Processing "' . $item['title'] . '"</info>');
  if (empty($item['title'])) {
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
    if (!empty($source) && isset($source['host']) && ($source['host'] == 'resources.eecentre.org' || $source['host'] == 'eecentre.org')) {
      // Ignore images.
      if (
        str_ends_with(strtolower($source['path']), '.jpg')
        || str_ends_with(strtolower($source['path']), '.jpeg')
        || str_ends_with(strtolower($source['path']), '.png')
      ) {
        continue;
      }

      $new_path = '';
      $path = str_replace('/wp-content/uploads/', '/sites/default/files/resources_eecentre/', $source['path']);
      $uri = str_replace('/wp-content/uploads/', 'public://resources_eecentre/', $source['path']);

      // Existing file.
      if (isset($file_mapping[$path])) {
        $new_path = $file_mapping[$path];
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
          }
          catch (\Exception $e) {
            $new_path = '';
          }
        }
      }
      if (!empty($new_path)) {
        $link->setAttribute('href', $new_path);

        $attachments[] = rtrim($base_url, '/') . $new_path;
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
    if (!empty($source) && isset($source['host']) && ($source['host'] == 'resources.eecentre.org' || $source['host'] == 'eecentre.org')) {
      $new_path = '';
      $path = str_replace('/wp-content/uploads/', '/sites/default/files/resources_eecentre/', $source['path']);
      $uri = str_replace('/wp-content/uploads/', 'public://resources_eecentre/', $source['path']);

      // Existing file.
      if (isset($file_mapping[$path])) {
        $new_path = $file_mapping[$path];
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
          }
          catch (\Exception $e) {
            $new_path = '';
          }
        }
      }
      if (!empty($new_path)) {
        $image->setAttribute('src', $path);
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

  if (DRY_RUN) {
    print_r($item);
    continue;
  }

  // Processing will be done by create_reliefweb_file.
  $attachments = array_unique($attachments);

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

  // Remove empty fields.
  $item = array_filter($item);

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

  // Export to CSV.
  $item['body'] = str_replace(["\n", "\r"], ' ', substr($item['body']['value'], 0, 100) . ' ...');

  if (!empty($item['categories'])) {
    $item['categories'] = implode('|' , array_filter(array_map(function($v) {
      return implode(",", array_filter($v));
    }, $item['categories'])));
  }
  else {
    $item['categories'] = '';
  }

  $item['tags'] = implode(', ', $item['tags'] ?? []);
  $item['field_country'] = implode(', ', $item['field_country'] ?? []);
  $item['field_theme'] = implode(', ', $item['field_theme'] ?? []);
  $item['field_source'] = implode(', ', $item['field_source'] ?? []);
  $item['field_disaster_type'] = implode(', ', $item['field_disaster_type'] ?? []);

  unset($item['field_file']);
  unset($item['attachments']);

  fputcsv($log, $item);
  sleep(1);
}

fclose($log);
