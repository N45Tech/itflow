<?php

/* Global search may locate a credential record, but it must never become a
 * second, unaudited secret-reveal surface. */

$root = dirname(__DIR__);
$search = file_get_contents($root . '/agent/global_search.php');
$top_nav = file_get_contents($root . '/includes/top_nav.php');

$failures = [];
$contains = static function (string $haystack, string $needle, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$notContains = static function (string $haystack, string $needle, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};

$contains($top_nav, 'minlength="2" maxlength="200" required',
    'The global-search field does not communicate its bounded query contract');
$contains($top_nav, "isset(\$_GET['query']) && is_scalar(\$_GET['query'])",
    'The global-search field can reflect a non-scalar query value');
$contains($search, '$raw_query = $search_requested && is_scalar($_GET[\'query\']) ? trim((string)$_GET[\'query\']) : \'\';',
    'Global search does not trim the query before validation');
$contains($search, "mb_strlen(\$raw_query, 'UTF-8')", 'Global search has no Unicode-aware length validation');
$contains($search, "if (\$search_requested && \$query_error === '')", 'Database searches are not gated on valid input');
$contains($search, 'Enter at least two characters to search.', 'Empty and one-character searches have no recovery message');
$contains($search, 'Keep the search to 200 characters or fewer.', 'Oversized searches have no recovery message');
$contains($search, 'credential_description, credential_id, credential_name',
    'Credential search does not return metadata-only records');
$notContains($search, 'credential_password, credential_username',
    'Credential secrets are still selected by global search');
$notContains($search, 'decryptCredentialEntry(', 'Global search still decrypts credential secrets');
$notContains($search, 'data-clipboard-text="<?= $credential_password ?>"',
    'Global search still writes credential passwords into the DOM');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Global search security contract passed.\n";
