<?php

/**
 * Contract: repository workflows use a stable runner image and immutable
 * external action revisions so CI does not drift at production release time.
 */

$root = dirname(__DIR__);
$workflowFiles = glob($root . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE);

if ($workflowFiles === false || $workflowFiles === []) {
    fwrite(STDERR, "No GitHub Actions workflows were found.\n");
    exit(1);
}

$errors = [];

foreach ($workflowFiles as $workflowFile) {
    $workflow = file_get_contents($workflowFile);
    $relativePath = substr($workflowFile, strlen($root) + 1);

    if ($workflow === false) {
        $errors[] = "Unable to read {$relativePath}.";
        continue;
    }

    if (preg_match('/^\\s*runs-on:\\s*ubuntu-latest\\s*$/m', $workflow) === 1) {
        $errors[] = "{$relativePath} uses the moving ubuntu-latest runner label.";
    }

    if (preg_match_all('/^\\s*-?\\s*uses:\\s*([^\\s#]+)(?:\\s+#.*)?$/m', $workflow, $matches) === false) {
        $errors[] = "Unable to inspect action references in {$relativePath}.";
        continue;
    }

    foreach ($matches[1] as $actionReference) {
        if (str_starts_with($actionReference, './')) {
            continue;
        }

        if (preg_match('/^[^@\\s]+@[0-9a-f]{40}$/', $actionReference) !== 1) {
            $errors[] = "{$relativePath} has a mutable external action reference: {$actionReference}.";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

echo "GitHub Actions portability contract passed.\n";
