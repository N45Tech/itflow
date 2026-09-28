<?php

/* Domain refreshes perform synchronous external lookups, so submitted IDs must
 * be normalized and strictly bounded without disabling PHP's request timeout. */

$root = dirname(__DIR__);
$handler = file_get_contents($root . '/agent/post/domain.php');

$failures = [];
$contains = static function (string $needle, string $message) use ($handler, &$failures): void {
    if (!str_contains($handler, $needle)) {
        $failures[] = $message;
    }
};

$contains('is_array($_POST[\'domain_ids\'])', 'Bulk domain refresh does not reject malformed ID input');
$contains('array_values(array_unique(array_filter(', 'Bulk domain refresh does not normalize and deduplicate IDs');
$contains('static fn ($domain_id) => $domain_id > 0', 'Bulk domain refresh does not discard invalid domain IDs');
$contains('$bulk_refresh_limit = 10;', 'Bulk domain refresh does not have the expected hard batch limit');
$contains('count($domain_ids) > $bulk_refresh_limit', 'Bulk domain refresh does not enforce its batch limit');
$contains('foreach ($domain_ids as $domain_id)', 'Bulk domain refresh does not use the bounded, normalized IDs');

$bulk_refresh_start = strpos($handler, "if (isset(\$_POST['bulk_refresh_domains']))");
$export_start = strpos($handler, "if (isExportRequest('export_domains'))", $bulk_refresh_start);
$bulk_refresh_handler = substr($handler, $bulk_refresh_start, $export_start - $bulk_refresh_start);
if (str_contains($bulk_refresh_handler, 'set_time_limit(0)')) {
    $failures[] = 'Bulk domain refresh disables PHP\'s request timeout';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Domain bulk refresh security contract passed.\n";
