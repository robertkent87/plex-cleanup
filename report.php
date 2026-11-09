<?php
// report.php - read-only Plex library report
// Usage: php report.php > report.csv
// Also saves the report for the review page (public/index.php).

// Warnings go to stderr so they can never end up in the CSV on stdout.
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

require __DIR__ . '/src/report.php';

try {
    $rows = buildReport();
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

saveReportCache($rows);

$out = fopen('php://stdout', 'w');
writeReportCsv($out, $rows);
fclose($out);
