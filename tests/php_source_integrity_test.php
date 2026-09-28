<?php

$root = dirname(__DIR__);
$failures = [];

$source_roots = [
    'admin',
    'agent',
    'api',
    'client',
    'cron',
    'functions',
    'guest',
    'includes',
    'n45',
    'scripts',
    'tests',
];

foreach ($source_roots as $source_root) {
    $directory = $root . '/' . $source_root;
    if (!is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $contents = @file_get_contents($file->getPathname());
        $relative_path = substr($file->getPathname(), strlen($root) + 1);

        if (!is_string($contents)) {
            $failures[] = "$relative_path cannot be read";
            continue;
        }
        if (str_contains($contents, "\0")) {
            $failures[] = "$relative_path contains binary NUL bytes";
        }
        if (preg_match('//u', $contents) !== 1) {
            $failures[] = "$relative_path is not valid UTF-8 source text";
        }
    }
}

foreach (['agent/ticket.php', 'n45/manifest.php'] as $critical_source) {
    $contents = @file_get_contents($root . '/' . $critical_source);
    if (!is_string($contents) || !str_starts_with($contents, '<?php')) {
        $failures[] = "$critical_source does not begin with a PHP opening tag";
    }
}

if ($failures) {
    fwrite(STDERR, "PHP source integrity test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PHP source integrity test passed\n";
