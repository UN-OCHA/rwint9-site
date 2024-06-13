<?php

use Drupal\ocha_ai_tag\Services\OchaAiTagTagger;
use Drush\Drush;
use Symfony\Component\DomCrawler\Crawler;

function processDoc(string $text) {
    // Load vocabularies.
    $mapping = [];
    $term_cache_tags = [];
    $vocabularies = [
      'career_category' => 'field_example_job_posting',
      //'theme',
    ];
    foreach ($vocabularies as $vocabulary => $field_name) {
      $mapping[$vocabulary] = [];

      $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
        'status' => 1,
        'vid' => $vocabulary,
      ]);

      /** @var \Drupal\taxonomy\Entity\Term $term */
      foreach ($terms as $term) {
        $mapping[$vocabulary][$term->getName()] = $term->getDescription() ?? $term->getName();
        if ($term->hasField($field_name) && !$term->get($field_name)->isEmpty()) {
          $mapping[$vocabulary][$term->getName()] = $term->get($field_name)->value;
        }
        $term_cache_tags = array_merge($term_cache_tags, $term->getCacheTags());

        // This doesn't work, bodies are too generic.
        // Get 5 example jobs.
//        $job_ids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
//          ->accessCheck(FALSE)
//          ->condition('status', 1)
//          ->condition('type', 'job')
//          ->condition('field_career_categories', $term->id())
//          ->condition('field_job_type', 263)
//          ->range(0, 5)
//          ->sort('nid', 'ASC')
//          ->execute();
//
//        print_r([$term->getName() => $job_ids]);
//        $jobs = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($job_ids);
//        foreach ($jobs as $job) {
//          $mapping[$vocabulary][$term->getName()] .= "\n\n" . $job->get('body')->value;
//        }
      }
    }

    $jobTagger = \Drupal::service('ocha_ai_tag.tagger');
    $data = $jobTagger
      ->setVocabularies($mapping, $term_cache_tags)
      ->tag($text, [OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF], OchaAiTagTagger::AVERAGE_FULL_AVERAGE);

    $data = $data[OchaAiTagTagger::AVERAGE_FULL_AVERAGE][OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF];

    return $data['career_category'];
}

/** @var \Drush\Drush $this */
$id = $this->input()->getArguments()['extra'][1];
$this->output()->writeln('Processing ' . $id);

$filename = __DIR__ . '/' . $id . '.txt';
if (!file_exists($filename)) {
  $this->output()->writeln('File not found at ' . $filename);

  // fetch from production.
  $url = 'https://reliefweb.int/node/' . $id;
  $html = file_get_contents($url);
  $crawler = new Crawler($html);
  $class = $crawler->filter('.rw-article__content')->first();
  $data = [];
  foreach ($class->children() as $child) {
    $data[] = $child->nodeValue;
  }

  $this->output()->writeln('File written to ' . $filename);
  file_put_contents($filename, implode("\n", $data));
}

$text = file_get_contents($filename);
$data = processDoc($text);

$this->output()->writeln('## Results for ' . $id);
$this->output()->writeln('');
$this->output()->writeln('| Category | Percentage |');
$this->output()->writeln('| -------- | ---------- |');
foreach ($data as $category => $percentage) {
  $this->output()->writeln('| ' . $category . ' | ' . round(100 * $percentage, 2) . ' |');
}
