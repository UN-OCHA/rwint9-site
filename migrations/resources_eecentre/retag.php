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
const LOGFILE = __DIR__ . '/retag.csv';
const MAX_ITEMS = 9999;
const FORCE_UPDATE = TRUE;
const DRY_RUN = FALSE;
global $source_id;
global $base_url;

$node_options = ['absolute' => TRUE];

$replace = [
  '*#JEU*' => '*#EECentreResources*',
  '*\#JEU*' => '*\#EECentreResources*',
  '<p><em>#JEU</em></p>' => '<p><em>#EECentreResources</em></p>',
];

$output = new ConsoleOutput();

$results = \Drupal::entityQuery('node')
  ->condition('type', 'report')
  ->condition('body', '%#JEU%', 'LIKE')
  ->sort('nid', 'ASC')
  ->accessCheck(FALSE)
  ->execute();


$log = fopen(LOGFILE, "w");
if (!$log) die(LOGFILE);
fputcsv($log, ['nid','url']);

foreach ($results as $nid) {

  // Load the report, it should exist!
  $report = Node::load($nid);
  if (empty($report)) {
    $output->writeln('<comment>Oops, could not load report ' . $nid . '</comment>');
  }
  else {
    $output->writeln('<comment>Processing ' . $nid . '</comment>');
    $report->setNewRevision(TRUE);
    $report->revision_log = 'Replaced #JEU with #EECentreResources';
    $report->setRevisionCreationTime(REQUEST_TIME);
    $report->setRevisionUserId(2);
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

    $output->writeln('<comment>Updated /node/' . $nid . '</comment>');
    fputcsv($log, [$nid, $report->toUrl('canonical', $node_options)->toString()]);

  }
  unset($report);
  sleep(1);
}

fclose($log);
