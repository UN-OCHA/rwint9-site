<?php

/**
 * @file
 * Data gathering script for RW-1312.
 *
 * Generate analytics data on posting activity by domain and source.
 * Produces CSV files showing node counts per email domain and source
 * organization for the report, job, and training content types, across
 * multiple time periods. Also includes counts of trusted users per domain
 * for each content type. All data is packaged as a zip archive at
 * public://rw-1312.zip.
 */

$database = \Drupal::database();
$file_system = \Drupal::service("file_system");
$delete_only = FALSE;

$periods = [
  "3_months" => "3 MONTH",
  "6_months" => "6 MONTH",
  "12_months" => "12 MONTH",
  "24_months" => "24 MONTH",
  "all_time" => "all"
];

$node_types = ["report", "job", "training"];
$temp_dir = "temporary://rw-1312";
$zip_filename = "public://rw-1312.zip";

if (file_exists($zip_filename)) {
  $file_system->delete($zip_filename);
  echo "Deleted existing zip file\n";
}

if (!$delete_only) {
  $file_system->prepareDirectory($temp_dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

  $csv_files = [];

  foreach ($node_types as $node_type) {
    foreach ($periods as $period_name => $period_interval) {
      if ($period_interval === "all") {
        $date_condition = "1=1";
      }
      else {
        $date_condition = "n.created >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL $period_interval))";
      }

       $domain_query = "
         SELECT
           CASE
             WHEN u.uid = 1 THEN 'ADMIN'
             WHEN u.uid = 2 THEN 'SYSTEM'
             WHEN SUBSTRING_INDEX(u.mail, '@', -1) = '' OR u.mail IS NULL THEN CONCAT('USER_', u.uid)
             ELSE SUBSTRING_INDEX(u.mail, '@', -1)
           END as domain,
           COUNT(*) as count
         FROM node_field_data n
         INNER JOIN users_field_data u
           ON n.uid = u.uid
         WHERE n.type = :node_type
           AND $date_condition
         GROUP BY domain
         ORDER BY count DESC
       ";
      $domain_results = $database->query($domain_query, [":node_type" => $node_type])->fetchAll();

      $domain_filename = "nodes_per_domain_{$node_type}_{$period_name}.csv";
      $domain_filepath = $temp_dir . "/" . $domain_filename;
      $domain_csv = fopen($domain_filepath, "w");
      fputcsv($domain_csv, ["domain", "count"]);
      foreach ($domain_results as $row) {
        fputcsv($domain_csv, [$row->domain, $row->count]);
      }
      fclose($domain_csv);
      $csv_files[] = $domain_filepath;

      $source_query = "
        SELECT
          t.name as source_name,
          ts.field_shortname_value as source_shortname,
          COUNT(*) as post_count
        FROM node_field_data n
        INNER JOIN node__field_source fs
          ON n.nid = fs.entity_id
        INNER JOIN taxonomy_term_field_data t
          ON fs.field_source_target_id = t.tid
        LEFT JOIN taxonomy_term__field_shortname ts
          ON t.tid = ts.entity_id AND ts.deleted = 0
        WHERE n.type = :node_type
          AND $date_condition
        GROUP BY t.tid, t.name, ts.field_shortname_value
        ORDER BY post_count DESC
      ";
      $source_results = $database->query($source_query, [":node_type" => $node_type])->fetchAll();

      $source_filename = "nodes_per_source_{$node_type}_{$period_name}.csv";
      $source_filepath = $temp_dir . "/" . $source_filename;
      $source_csv = fopen($source_filepath, "w");
      fputcsv($source_csv, ["source_name", "source_shortname", "post_count"]);
      foreach ($source_results as $row) {
        fputcsv($source_csv, [$row->source_name, $row->source_shortname ?? "", $row->post_count]);
      }
      fclose($source_csv);
      $csv_files[] = $source_filepath;
    }
  }

  foreach ($node_types as $node_type) {
    $rights_field = "field_user_posting_rights_" . $node_type;

     $trusted_query = "
       SELECT
         CASE
           WHEN u.uid = 1 THEN 'ADMIN'
           WHEN u.uid = 2 THEN 'SYSTEM'
           WHEN SUBSTRING_INDEX(u.mail, '@', -1) = '' OR u.mail IS NULL THEN CONCAT('USER_', u.uid)
           ELSE SUBSTRING_INDEX(u.mail, '@', -1)
         END as domain,
         COUNT(DISTINCT u.uid) as trusted_count
       FROM users_field_data u
       INNER JOIN taxonomy_term__field_user_posting_rights tr
         ON u.uid = tr.field_user_posting_rights_id
       WHERE tr.$rights_field = 3
       GROUP BY domain
       ORDER BY trusted_count DESC
     ";
    $trusted_results = $database->query($trusted_query)->fetchAll();

    $trusted_filename = "trusted_users_per_domain_{$node_type}.csv";
    $trusted_filepath = $temp_dir . "/" . $trusted_filename;
    $trusted_csv = fopen($trusted_filepath, "w");
    fputcsv($trusted_csv, ["domain", "trusted_count"]);
    foreach ($trusted_results as $row) {
      fputcsv($trusted_csv, [$row->domain, $row->trusted_count]);
    }
    fclose($trusted_csv);
    $csv_files[] = $trusted_filepath;
  }

  $zip_real_path = $file_system->realpath($zip_filename);
  $zip = new ZipArchive();
  if ($zip->open($zip_real_path, ZipArchive::CREATE) === TRUE) {
    foreach ($csv_files as $csv_file) {
      $csv_real_path = $file_system->realpath($csv_file);
      $zip->addFile($csv_real_path, basename($csv_file));
    }
    $zip->close();

    $file_system->deleteRecursive($temp_dir);

    print "Generated zip archive: " . $zip_filename . "\n";
    print "Files included:\n";
    foreach ($csv_files as $csv_file) {
      print "- " . basename($csv_file) . "\n";
    }

    $absolute_url = \Drupal::service("file_url_generator")->generateAbsoluteString($zip_filename);
    print "Download URL: " . $absolute_url . "\n";
  }
  else {
    print "Error creating zip archive\n";
    $file_system->deleteRecursive($temp_dir);
  }
}
