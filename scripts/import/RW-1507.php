<?php

/**
 * @file
 * Import script for RW-1507.
 */

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_files\Plugin\Field\FieldType\ReliefWebFile as ReliefWebFileType;

$proceed = TRUE;
$save = TRUE;
$dry_run = FALSE;
$limit = 0;
$single_file = "";
$skip_existing = TRUE;
$source_dir = "/srv/www/shared/private/ocha-yemen";

if (isset($extra) && is_array($extra)) {
  foreach ($extra as $arg) {
    if ($arg === "--dry-run") {
      $dry_run = TRUE;
      $save = FALSE;
    }
    elseif ($arg === "--skip-existing") {
      $skip_existing = TRUE;
    }
    elseif ($arg === "--no-skip-existing") {
      $skip_existing = FALSE;
    }
    elseif (str_starts_with($arg, "--limit=")) {
      $limit = (int) substr($arg, 8);
    }
    elseif (str_starts_with($arg, "--file=")) {
      $single_file = substr($arg, 7);
    }
    elseif (str_starts_with($arg, "--source-dir=")) {
      $source_dir = substr($arg, 13);
    }
  }
}

$rw1507_resolve_path = static function (string $input_path): string {
  if ($input_path === "" || $input_path[0] === "/") {
    return $input_path;
  }
  return dirname(\Drupal::root()) . "/" . $input_path;
};

$rw1507_parse_filename = static function (string $pdf_path): array {
  $basename = pathinfo($pdf_path, PATHINFO_FILENAME);
  $basename = preg_replace("/\s+\(\d+\)$/", "", $basename);
  if (!is_string($basename)) {
    throw new \RuntimeException("Unable to normalize filename for " . $pdf_path);
  }

  if (!preg_match("/_([A-Za-z]+)_(\d{4})_([A-Z]{2})$/", $basename, $matches)) {
    throw new \RuntimeException("Filename does not match expected pattern: " . $pdf_path);
  }

  return [
    "month_name" => $matches[1],
    "year" => (int) $matches[2],
    "lang_code" => $matches[3],
  ];
};

$rw1507_parse_folder_date = static function (string $pdf_path): ?\DateTimeImmutable {
  $folder = basename(dirname($pdf_path));
  $formats = ["F Y", "M Y", "M. Y"];
  foreach ($formats as $format) {
    $date = \DateTimeImmutable::createFromFormat($format, $folder, new \DateTimeZone("UTC"));
    if ($date instanceof \DateTimeImmutable) {
      return $date;
    }
  }
  return NULL;
};

$rw1507_load_language_map = static function (): array {
  $rows = \Drupal::database()->query("
    SELECT tlc.entity_id AS tid, tlc.field_language_code_value AS code
    FROM {taxonomy_term__field_language_code} AS tlc
    INNER JOIN {taxonomy_term_field_data} AS tfd ON tfd.tid = tlc.entity_id
    WHERE tfd.vid = :vid
  ", [":vid" => "language"])->fetchAll();

  $map = [];
  foreach ($rows as $row) {
    $map[strtoupper($row->code)] = (int) $row->tid;
  }
  return $map;
};

$rw1507_load_existing_hashes = static function (): array {
  $rows = \Drupal::database()->query("
    SELECT nff.field_file_file_hash AS file_hash, nff.entity_id AS nid
    FROM {node__field_file} AS nff
    INNER JOIN {node_field_data} AS nfd ON nfd.nid = nff.entity_id
    WHERE nfd.type = :bundle
      AND nff.field_file_file_hash <> :empty
  ", [
    ":bundle" => "report",
    ":empty" => "",
  ])->fetchAll();

  $map = [];
  foreach ($rows as $row) {
    if (!empty($row->file_hash)) {
      $map[$row->file_hash] = (int) $row->nid;
    }
  }
  return $map;
};

$rw1507_extract_title = static function (string $pdf_path): string {
  $command = "mutool convert -F text -o - " . escapeshellarg($pdf_path) . " 1 | sed -n \"2p\"";
  $output = shell_exec($command);
  if (!is_string($output)) {
    throw new \RuntimeException("mutool failed for " . $pdf_path);
  }
  $line = trim($output);
  if ($line === "") {
    throw new \RuntimeException("Empty title extracted from " . $pdf_path);
  }
  return $line;
};

$rw1507_validate_file = static function (File $file, array $validators): array {
  $violations = \Drupal::service("file.validator")->validate($file, $validators);
  $errors = [];
  foreach ($violations as $violation) {
    $errors[] = (string) $violation->getMessage();
  }
  return $errors;
};

$rw1507_collect_pdfs = static function (string $source_dir, string $single_file) use ($rw1507_resolve_path): array {
  if ($single_file !== "") {
    $resolved = $rw1507_resolve_path($single_file);
    if (!is_file($resolved)) {
      throw new \RuntimeException("File not found: " . $resolved);
    }
    return [$resolved];
  }

  $root = $rw1507_resolve_path($source_dir);
  if (!is_dir($root)) {
    throw new \RuntimeException("Source directory not found: " . $root);
  }

  $collected_pdf_paths = [];
  $iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
  );
  foreach ($iterator as $file_info) {
    if (!$file_info->isFile()) {
      continue;
    }
    if (strtolower($file_info->getExtension()) !== "pdf") {
      continue;
    }
    $collected_pdf_paths[] = $file_info->getPathname();
  }
  sort($collected_pdf_paths);
  return $collected_pdf_paths;
};

$rw1507_build_period = static function (string $pdf_path) use ($rw1507_parse_filename, $rw1507_parse_folder_date): array {
  $parsed = $rw1507_parse_filename($pdf_path);
  $month_formats = ["F", "M", "M."];
  $period = NULL;
  foreach ($month_formats as $format) {
    $candidate = \DateTimeImmutable::createFromFormat(
      $format . " Y",
      $parsed["month_name"] . " " . $parsed["year"],
      new \DateTimeZone("UTC")
    );
    if ($candidate instanceof \DateTimeImmutable) {
      $period = $candidate;
      break;
    }
  }

  if (!$period instanceof \DateTimeImmutable) {
    $folder_date = $rw1507_parse_folder_date($pdf_path);
    if ($folder_date instanceof \DateTimeImmutable) {
      $period = $folder_date;
    }
  }

  if (!$period instanceof \DateTimeImmutable) {
    throw new \RuntimeException("Unable to determine month/year for " . $pdf_path);
  }

  $last_day = $period->modify("last day of this month");
  return [
    "lang_code" => $parsed["lang_code"],
    "display_month_year" => $period->format("F Y"),
    "publication_date" => $last_day->format("Y-m-d"),
  ];
};

$rw1507_attach_pdf = static function (Node $node, string $source_path) use ($rw1507_validate_file): ReliefWebFileType {
  $file_name = basename($source_path);
  $definition = $node->get("field_file")->getItemDefinition();
  $item = ReliefWebFileType::createInstance($definition);
  $validators = $item->getUploadValidators($node, FALSE);

  $file_uuid = ReliefWebFileType::generateUuid();
  $extension = ReliefWebFileType::extractFileExtension($file_name);
  $uri = ReliefWebFileType::getFileUriFromUuid($file_uuid, $extension, TRUE);
  $file_mime = ReliefWebFileType::guessFileMimeType($source_path);
  $file = ReliefWebFileType::createFileFromUuid($file_uuid, $uri, $file_name, $file_mime);
  $file->setSize(@filesize($source_path) ?: 0);
  $file->setFileUri($source_path);

  $validation_errors = $rw1507_validate_file($file, $validators);
  $file->setFileUri($uri);
  if (!empty($validation_errors)) {
    throw new \RuntimeException("File validation failed for " . $file_name . ": " . implode("; ", $validation_errors));
  }

  if (!ReliefWebFileType::prepareDirectory($uri)) {
    throw new \RuntimeException("Unable to prepare directory for " . $file_name);
  }

  $file_system = \Drupal::service("file_system");
  if (!$file_system->copy($source_path, $uri, $file_system::EXISTS_REPLACE)) {
    throw new \RuntimeException("Unable to copy file " . $file_name);
  }
  $file->setMimeType(ReliefWebFileType::guessFileMimeType($uri));
  $file->setSize(@filesize($uri) ?: 0);

  $item->setValue([
    "uuid" => ReliefWebFileType::generateUuid(),
    "revision_id" => 0,
    "file_uuid" => $file->uuid(),
    "file_name" => $file->getFilename(),
    "file_mime" => $file->getMimeType(),
    "file_size" => $file->getSize(),
    "page_count" => ReliefWebFileType::getFilePageCount($file),
  ]);

  $violations = $item->validate();
  if ($violations->count() > 0) {
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    \Drupal::service("file_system")->unlink($uri);
    throw new \RuntimeException("Invalid field item for " . $file_name . ": " . implode("; ", $messages));
  }

  $file->setTemporary();
  $file->save();
  $item->updateFileHash();
  $item->generatePreview(1, 0);

  return $item;
};

if (!empty($proceed)) {
  $mutool = trim((string) shell_exec("command -v mutool"));
  if ($mutool === "") {
    fwrite(STDERR, "mutool is required but was not found in PATH." . PHP_EOL);
    return;
  }

  try {
    $pdf_paths = $rw1507_collect_pdfs($source_dir, $single_file);
  }
  catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    return;
  }

  if ($limit > 0) {
    $pdf_paths = array_slice($pdf_paths, 0, $limit);
  }

  $language_map = $rw1507_load_language_map();
  $existing_hashes = $skip_existing ? $rw1507_load_existing_hashes() : [];
  $seen_in_batch = [];

  $counts = [
    "created" => 0,
    "skipped_duplicate" => 0,
    "failed" => 0,
  ];

  echo "Processing " . count($pdf_paths) . " PDF(s)" . ($dry_run ? " (dry run)" : "") . PHP_EOL;

  foreach ($pdf_paths as $pdf_path) {
    try {
      $hash = hash_file("sha256", $pdf_path);
      if ($hash === FALSE || $hash === "") {
        throw new \RuntimeException("Unable to hash file " . $pdf_path);
      }

      if ($skip_existing && isset($existing_hashes[$hash])) {
        echo "SKIP duplicate (existing nid " . $existing_hashes[$hash] . "): " . $pdf_path . PHP_EOL;
        $counts["skipped_duplicate"]++;
        continue;
      }

      if ($skip_existing && isset($seen_in_batch[$hash])) {
        echo "SKIP duplicate (same as " . $seen_in_batch[$hash] . "): " . $pdf_path . PHP_EOL;
        $counts["skipped_duplicate"]++;
        continue;
      }

      $period = $rw1507_build_period($pdf_path);
      $lang_code = strtoupper($period["lang_code"]);
      if (!isset($language_map[$lang_code])) {
        throw new \RuntimeException("Unknown language code " . $lang_code . " for " . $pdf_path);
      }

      $extracted_title = $rw1507_extract_title($pdf_path);
      $title = "Yemen: " . $extracted_title . " " . $period["display_month_year"];

      echo ($dry_run ? "DRY RUN " : "") . "hash=" . $hash . " title=" . $title . " date=" . $period["publication_date"] . " lang=" . $language_map[$lang_code] . " file=" . $pdf_path . PHP_EOL;

      if ($dry_run || !$save) {
        $seen_in_batch[$hash] = $pdf_path;
        continue;
      }

      $node = Node::create([
        "type" => "report",
        "uid" => 2,
        "title" => $title,
        "status" => 1,
        "moderation_status" => "published",
        "field_bury" => 1,
        "field_source" => [1503],
        "field_country" => [255],
        "field_primary_country" => 255,
        "field_content_format" => 12570,
        "field_theme" => [4590],
        "field_language" => [$language_map[$lang_code]],
        "field_origin" => 1,
        "field_ocha_product" => 12353,
        "field_original_publication_date" => $period["publication_date"],
      ]);

      $file_item = $rw1507_attach_pdf($node, $pdf_path);
      $node->set("field_file", [$file_item->getValue()]);
      $node->notifications_content_disable = TRUE;
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId(2);
      $node->setRevisionCreationTime(time());
      $node->setRevisionLogMessage("Automatic import of OCHA Yemen infographic (Ref: RW-1507).");
      $node->save();

      $existing_hashes[$hash] = (int) $node->id();
      $seen_in_batch[$hash] = $pdf_path;
      $counts["created"]++;
      echo "CREATED nid " . $node->id() . ": " . $title . PHP_EOL;
    }
    catch (\Throwable $exception) {
      $counts["failed"]++;
      fwrite(STDERR, "FAILED " . $pdf_path . ": " . $exception->getMessage() . PHP_EOL);
    }
  }

  echo "Summary: created=" . $counts["created"] . " skipped_duplicate=" . $counts["skipped_duplicate"] . " failed=" . $counts["failed"] . PHP_EOL;
}
