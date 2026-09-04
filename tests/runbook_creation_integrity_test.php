<?php

require_once __DIR__ . '/../functions/runbooks.php';

$root = dirname(__DIR__);
$failures = [];

$assertTrue = function ($actual, string $message) use (&$failures): void {
    if ($actual !== true) {
        $failures[] = $message;
    }
};

$assertContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};

$section = function (string $contents, string $start, string $end, string $label) use (&$failures): string {
    $start_at = strpos($contents, $start);
    $end_at = $start_at === false ? false : strpos($contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false || $end_at <= $start_at) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr($contents, $start_at, $end_at - $start_at);
};

$definition = [
    'key' => 'creation-integrity',
    'type' => 'standard',
    'name' => 'Creation integrity',
    'subject' => '',
    'tasks' => [[
        'key' => 'validate',
        'name' => 'Validate',
        'condition_type' => 'always',
        'owner_type' => 'unassigned',
        'owner_user_id' => 0,
        'initial_state' => 'Ready',
        'approval_scope' => '',
        'approval_type' => '',
        'approval_user_id' => 0,
        'evidence_type' => 'none',
        'depends_on' => [],
    ]],
];
$assertTrue(
    in_array('The runbook requires a ticket subject.', runbookValidateDefinition($definition), true),
    'A runbook with no ticket subject can be published'
);

$runbooks = file_get_contents($root . '/functions/runbooks.php');
$app = file_get_contents($root . '/functions/app.php');
$ticket_template_post = file_get_contents($root . '/admin/post/ticket_template.php');
$project_template_post = file_get_contents($root . '/admin/post/project_template.php');
$ticket_post = file_get_contents($root . '/agent/post/ticket.php');
$client_post = file_get_contents($root . '/agent/post/client.php');
$project_post = file_get_contents($root . '/agent/post/project.php');

$instantiate = $section(
    $runbooks,
    'function instantiateRunbookForTicket(',
    'function refreshRunbookTaskStates(',
    'runbook instantiation'
);
$assertContains('runbook_version_definition_hash', $instantiate, 'Instantiation does not load the immutable definition hash');
$assertContains('runbookDefinitionHash($snapshot)', $instantiate, 'Instantiation does not recompute the immutable definition hash');
$assertContains('hash_equals($stored_definition_hash, $snapshot_hash)', $instantiate, 'Instantiation does not fail closed on a definition hash mismatch');
$hash_check = strpos($instantiate, 'hash_equals($stored_definition_hash, $snapshot_hash)');
$execution_insert = strpos($instantiate, 'INSERT INTO runbook_executions SET');
$assertTrue(
    $hash_check !== false && $execution_insert !== false && $hash_check < $execution_insert,
    'Instantiation writes an execution before verifying the immutable definition hash'
);

$task_copy = $section(
    $app,
    'function addTasksFromTicketTemplate(',
    'function ticketCreationDbQuery(',
    'ticket template task copy'
);
$assertContains("if (!\$release_row || !empty(\$release_row['ticket_template_archived_at']))", $task_copy, 'A missing or archived template release fails open');
$assertContains("throw new RuntimeException('The ticket template is unavailable or archived')", $task_copy, 'Outer ticket creation cannot roll back a missing template release');

$publish = $section(
    $runbooks,
    'function publishRunbookVersion(',
    'function restoreRunbookVersionToDraft(',
    'runbook publication'
);
$publish_lock = strpos($publish, 'LIMIT 1 FOR UPDATE');
$publish_snapshot = strpos($publish, 'runbookDraftDefinition($ticket_template_id)');
$assertTrue(
    $publish_lock !== false && $publish_snapshot !== false && $publish_lock < $publish_snapshot,
    'Publication snapshots the draft before locking its owning template'
);
$assertContains('The published runbook pointer was not saved', $publish, 'Publication does not verify its authoritative pointer');

$delete = $section(
    $ticket_template_post,
    "if (isset(\$_GET['delete_ticket_template']))",
    "if (isset(\$_POST['add_ticket_template_task']))",
    'ticket template retirement'
);
$assertContains('mysqli_begin_transaction($mysqli)', $delete, 'Ticket template retirement is not transactional');
$assertContains('LIMIT 1 FOR UPDATE', $delete, 'Ticket template retirement does not serialize with publication');
$assertContains('mysqli_commit($mysqli)', $delete, 'Ticket template retirement does not commit atomically');
$assertContains('mysqli_rollback($mysqli)', $delete, 'Ticket template retirement does not roll back on failure');
$assertContains('mysqli_affected_rows($mysqli) !== 1', $delete, 'Ticket template deletion does not verify its target row');

$stage_pin = $section(
    $project_template_post,
    "if (isset(\$_POST['update_project_template_runbook_version']))",
    "if (isset(\$_POST['remove_ticket_template_from_project_template']))",
    'project stage pin update'
);
$assertContains('mysqli_begin_transaction($mysqli)', $stage_pin, 'Project stage pinning is not transactional');
$assertTrue(substr_count($stage_pin, 'FOR UPDATE') >= 2, 'Project stage pinning does not lock the release and association');
$assertContains('ticket_template_published_version_id', $stage_pin, 'Project stage pinning does not re-read the authoritative pointer under lock');
$assertContains('The project stage runbook pin was not saved', $stage_pin, 'Project stage pinning does not verify its saved pointer');
$assertContains('mysqli_commit($mysqli)', $stage_pin, 'Project stage pinning does not commit atomically');
$assertContains('mysqli_rollback($mysqli)', $stage_pin, 'Project stage pinning does not roll back on failure');

$project_create = $section(
    $project_post,
    "if (isset(\$_POST['add_project']))",
    "if (isset(\$_POST['edit_project']))",
    'project creation'
);
$project_subject_check = strpos($project_create, "if (trim(\$stage_subject) === '')");
$project_subject_selection = strpos($project_create, '$stage_subject = $pinned_version_id');
$project_number = strpos($project_create, 'Could not allocate a project number');
$assertTrue(
    $project_subject_selection !== false && $project_subject_check !== false && $project_number !== false
        && $project_subject_selection < $project_subject_check && $project_subject_check < $project_number,
    'Project creation allocates a number before rejecting a blank stage subject'
);

$bulk_client = $section(
    $client_post,
    "if (isset(\$_POST['bulk_add_client_ticket']))",
    "if (isset(\$_POST['bulk_edit_client_industry']))",
    'bulk client ticket creation'
);
$client_transaction = strpos($bulk_client, 'mysqli_begin_transaction($mysqli)');
$client_lock = strpos($bulk_client, "clientScopeSql('clients.client_id')", $client_transaction ?: 0);
$client_contact_lock = strpos($bulk_client, 'Could not lock the bulk ticket contact', $client_transaction ?: 0);
$client_number = strpos($bulk_client, 'Could not allocate a bulk ticket number', $client_transaction ?: 0);
$client_subject_check = strpos($bulk_client, "if (trim((string) \$subject) === '')");
$client_published_subject = strpos($bulk_client, "\$subject = escapeSql(\$row['runbook_version_subject']);");
$client_legacy_subject = strpos($bulk_client, "\$subject = escapeSql(\$row['ticket_template_subject']);");
$assertTrue(
    $client_transaction !== false && $client_lock !== false && $client_contact_lock !== false
        && $client_number !== false && $client_transaction < $client_lock
        && $client_lock < $client_contact_lock && $client_contact_lock < $client_number,
    'Bulk client tickets do not lock and revalidate client/contact scope before number allocation'
);
$assertTrue(
    $client_published_subject !== false && $client_legacy_subject !== false && $client_subject_check !== false
        && $client_number !== false && $client_published_subject < $client_subject_check
        && $client_legacy_subject < $client_subject_check && $client_subject_check < $client_number,
    'Bulk client creation allocates a number before rejecting a blank selected subject'
);
$assertContains('AND contact_client_id = $client_id', $bulk_client, 'Bulk client contact revalidation is not client-scoped');
$assertContains('AND contact_primary = 1', $bulk_client, 'Bulk client creation retains a contact that is no longer primary');

$bulk_asset = $section(
    $ticket_post,
    "if (isset(\$_POST['bulk_add_asset_ticket']))",
    "if (isset(\$_POST['add_ticket_reply']))",
    'bulk asset ticket creation'
);
$asset_transaction = strpos($bulk_asset, 'mysqli_begin_transaction($mysqli)');
$asset_client_lock = strpos($bulk_asset, "clientScopeSql('clients.client_id')", $asset_transaction ?: 0);
$asset_lock = strpos($bulk_asset, 'Could not lock the bulk ticket asset', $asset_transaction ?: 0);
$asset_contact_lock = strpos($bulk_asset, 'Could not lock the bulk asset ticket contact', $asset_transaction ?: 0);
$asset_number = strpos($bulk_asset, 'Could not allocate a bulk asset ticket number', $asset_transaction ?: 0);
$asset_subject_check = strpos($bulk_asset, "if (trim((string) \$subject) === '')");
$asset_published_subject = strpos($bulk_asset, "\$subject = escapeSql(\$row['runbook_version_subject']);");
$asset_legacy_subject = strpos($bulk_asset, "\$subject = escapeSql(\$row['ticket_template_subject']);");
$assertTrue(
    $asset_transaction !== false && $asset_client_lock !== false && $asset_lock !== false
        && $asset_contact_lock !== false && $asset_number !== false
        && $asset_transaction < $asset_client_lock && $asset_client_lock < $asset_lock
        && $asset_lock < $asset_contact_lock && $asset_contact_lock < $asset_number,
    'Bulk asset tickets do not lock and revalidate client/asset/contact scope before number allocation'
);
$assertTrue(
    $asset_published_subject !== false && $asset_legacy_subject !== false && $asset_subject_check !== false
        && $asset_number !== false && $asset_published_subject < $asset_subject_check
        && $asset_legacy_subject < $asset_subject_check && $asset_subject_check < $asset_number,
    'Bulk asset creation allocates a number before rejecting a blank selected subject'
);
$assertContains('asset_client_id = $client_id', $bulk_asset, 'Bulk asset revalidation is not client-scoped');
$assertContains('$asset_name = escapeSql($locked_asset[\'asset_name\'])', $bulk_asset, 'Bulk asset subjects retain a stale preflight asset name');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Runbook creation integrity tests passed." . PHP_EOL;
