<?php
/**
 * CSV Export Helper
 * Usage: exportCsv('filename.csv', ['Col1','Col2'], [['val1','val2'], ...]);
 *
 * Streams a UTF-8 CSV file to the browser and exits.
 * Call this BEFORE any HTML output (before header includes).
 */
function exportCsv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM so Excel opens the file without encoding issues
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, $headers);

    foreach ($rows as $row) {
        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}
