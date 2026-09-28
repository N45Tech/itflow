<?php

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__);
$approvals = file_get_contents($root . '/functions/field_workspace_approvals.php');
$workspace = file_get_contents($root . '/agent/field/workspace.mjs');

$assert(
    str_contains($approvals, "in_array(\$operation, ['retry', 'reroute'], true) && lookupUserPermission('module_support') < 3"),
    'Field approval retries and reroutes must require support administrator access'
);
$assert(
    str_contains($workspace, "state.boot.user.admin&&['pending','declined'].includes(r.status)"),
    'Field approval management actions must only be shown to support administrators'
);

echo "Field approval authorization tests passed.\n";
