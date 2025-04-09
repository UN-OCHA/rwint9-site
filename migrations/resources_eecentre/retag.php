<?php

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_entities\Entity\Report;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\Console\Output\ConsoleOutput;

const XML_FILE = __DIR__ . '/resources.xml';
const XML_FILE_MEDIA = __DIR__ . '/media.xml';
const LOGFILE = __DIR__ . '/reports-hax.csv';
const MAX_ITEMS = 9999;
const FORCE_UPDATE = TRUE;
const DRY_RUN = FALSE;
global $source_id;
global $base_url;

/*
(
    [0] => type
    [1] => moderation_status
    [2] => uid
    [3] => field_bury
    [4] => field_theme
    [5] => field_language
    [6] => field_source
    [7] => field_disaster_type
    [8] => field_ocha_product
    [9] => title
    [10] => body
    [11] => field_origin_notes
    [12] => field_primary_country
    [13] => field_country
    [14] => field_original_publication_date
    [15] => field_image
    [16] => post_id
    [17] => post_link
    [18] => created
    [19] => changed
    [20] => post_type
    [21] => image
    [22] => caption
    [23] => categories
    [24] => tags
    [25] => new_url
    [26] => nid
    [27] => 
    [28] => 
)
*/

$replace = [
  '*\#JEU*' => '*\#EECentreResources*',
  '<p><em>#JEU</em></p>' => '<p><em>#EECentreResources</em></p>',
];

$output = new ConsoleOutput();

$log = fopen(LOGFILE, "r");
if(!$log)
  die('Oops: ' . LOGFILE);

// header.
$item = fgetcsv($log);

while(!feof($log)) {
  $item = fgetcsv($log, null, ",", "\"", "\\");
  if (empty($item))
    continue;

  if (count($item) < 28)
    die(print_r($item));

  // Load the report, it should exist!
  $report = Node::load($item[26]);
  if (empty($report)) {
    $output->writeln('<comment>Oops, could not load report ' . $item[26] . '</comment>');
  }
  else {
    $output->writeln('<comment>Processing ' . $item[25] . '</comment>');
    $report->body->value = strtr($report->body->value, $replace);
    try {
      $report->save();
    }
    catch (\Throwable $t) {
      print_r($t);
      die();
    }
    catch (\Exception $t) {
      print_r($t);
      die();
    }

    $output->writeln('<comment>Updated /node/' .  $item[26] . '</comment>');

  }
  unset($report);
  sleep(1);
}

fclose($log);
