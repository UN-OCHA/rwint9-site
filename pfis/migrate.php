<?php

use Drupal\reliefweb_entities\Entity\Report;

const XML_FILE = '/var/www/pfis/wp.xml';
const MAX_ITEMS = 999999;

function pfis_migrate(&$global_categories, &$global_tags) {
  $items = [];
  $images = [];
  $counter = 0;
  $xml = simplexml_load_file(XML_FILE);

  foreach ($xml->children()->children() as $child) {
    if ($child->getName() != 'item') {
      continue;
    }

    $counter++;
    if ($counter > MAX_ITEMS) {
      return $items;
    }

    $fields = [
      'type' => 'report',
      'uid' => 2,
      'field_language' => 267,
      'field_content_format' => 8,
      'field_source' => 1503,
      'field_ocha_product' => 12350,
      'title' => (string) $child->title,
      'body' => [
        'value' => str_replace([
          '<!-- wp:paragraph -->',
          '<!-- /wp:paragraph -->',
        ], '', (string) $child->children('content', TRUE)),
        'format' => 'markdown_editor',
      ],
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
        $fields['link'] = str_replace('https://pooled-funds-impact-story-hub.site.strattic.io/', 'https://pooledfunds.impact.unocha.org/', (string) $property);
      }
    }

    foreach ($child->children('wp', TRUE) as $property) {
      if ($property->getName() == 'post_date') {
        $fields['created'] = (string) $property;
      }
      elseif ($property->getName() == 'post_modified') {
        $fields['updated'] = (string) $property;
      }
      elseif ($property->getName() == 'post_id') {
        $fields['post_id'] = (string) $property;
        $fields['post_link'] = 'https://pooledfunds.impact.unocha.org/?p=' . $fields['post_id'];
      }
      elseif ($property->getName() == 'attachment_url') {
        $fields['image'] = str_replace('https://pooled-funds-impact-story-hub.site.strattic.io/', 'https://pooledfunds.impact.unocha.org/', (string) $property);
      }
      elseif ($property->getName() == 'post_type') {
        $fields['post_type'] = (string) $property;
      }
      elseif ($property->getName() == 'postmeta') {
        if ((string) $property->meta_key == '_thumbnail_id') {
          $fields['image'] = $images[(string) $property->meta_value];
        }
      }
    }

    // Image?
    if ($fields['post_type'] == 'attachment') {
      $images[$fields['post_id']] = $fields['image'];
      continue;
    }

    // Post.
    if ($fields['post_type'] == 'page' || $fields['post_type'] == 'post') {
      $fields['categories'] = $categories;
      $fields['tags'] = $tags;
      $fields['body']['value'] .= '<p><em>Pool Funds Impact Stories series</em></p>';

      $items[] = $fields;

      $global_categories = array_unique(array_merge($global_categories ?? [], $categories ?? []));
      $global_tags = array_unique(array_merge($global_tags ?? [], $tags ?? []));
    }
  }

  return $items;
}

$global_categories = [];
$global_tags = [];

$items = pfis_migrate($global_categories, $global_tags);
sort($global_categories);
sort($global_tags);

foreach ($items as &$item) {
  $report = Report::create($item);
  $report->save();
  $item['new_url'] = $report->toUrl();
}

//print_r([
//  'categories' => $global_categories,
//  'tags' => $global_tags,
//  'items' => $items,
//]);
