<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_project_template'])) {

    validateCSRFToken();

    $name = escapeSql($_POST['name']);
    $description = escapeSql($_POST['description']);

    mysqli_query($mysqli, "INSERT INTO project_templates SET project_template_name = '$name', project_template_description = '$description'");

    $project_template_id = mysqli_insert_id($mysqli);

    logAudit("Project Template", "Create", "$session_name created project template $name", 0, $project_template_id);

    flashAlert("Project Template <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_project_template'])) {

    validateCSRFToken();

    $project_template_id = intval($_POST['project_template_id']);
    $name = escapeSql($_POST['name']);
    $description = escapeSql($_POST['description']);

    mysqli_query($mysqli, "UPDATE project_templates SET project_template_name = '$name', project_template_description = '$description' WHERE project_template_id = $project_template_id");

    logAudit("Project Template", "Edit", "$session_name edited project template $name", 0, $project_template_id);

    flashAlert("Project Template <strong>$name</strong> edited");

    redirect();

}

if (isset($_POST['edit_ticket_template_order'])) {

    validateCSRFToken();

    $ticket_template_id = intval($_POST['ticket_template_id']);
    $project_template_id = intval($_POST['project_template_id']);
    $order = intval($_POST['order']);

    mysqli_query($mysqli, "UPDATE project_template_ticket_templates SET ticket_template_order = $order WHERE ticket_template_id = $ticket_template_id AND project_template_id = $project_template_id");

    redirect();

}

if (isset($_POST['add_ticket_template_to_project_template'])) {

    validateCSRFToken();

    $project_template_id = intval($_POST['project_template_id']);
    $ticket_template_id = intval($_POST['ticket_template_id']);
    $order = intval($_POST['order']);

    $stage = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
        ticket_template_published_version_id, current_version.runbook_version_id,
        (SELECT COUNT(*) FROM runbook_versions history
            WHERE history.runbook_version_ticket_template_id = ticket_templates.ticket_template_id) AS version_count
        FROM ticket_templates
        LEFT JOIN runbook_versions current_version
            ON current_version.runbook_version_id = ticket_template_published_version_id
            AND current_version.runbook_version_ticket_template_id = ticket_template_id
        WHERE ticket_template_id = $ticket_template_id
        AND ticket_template_archived_at IS NULL LIMIT 1"));
    $project_template_exists = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*)
        FROM project_templates WHERE project_template_id = $project_template_id
        AND project_template_archived_at IS NULL"))[0] ?? 0);
    $published_pointer = intval($stage['ticket_template_published_version_id'] ?? 0);
    $runbook_version_id = intval($stage['runbook_version_id'] ?? 0);
    $version_count = intval($stage['version_count'] ?? 0);
    if (!$stage || !$project_template_exists
        || $published_pointer !== $runbook_version_id
        || (!$published_pointer && $version_count)) {
        flashAlert('The project stage cannot be added because its template or published runbook pointer is unavailable', 'error');
        redirect();
    }

    if (!mysqli_query($mysqli, "INSERT INTO project_template_ticket_templates SET project_template_id = $project_template_id, ticket_template_id = $ticket_template_id, ticket_template_order = $order, ticket_template_runbook_version_id = $runbook_version_id")) {
        flashAlert('The project stage could not be added', 'error');
        redirect();
    }

    logAudit("Project Template", "Edit", "$session_name added ticket template to project_template", 0, $project_template_id);

    flashAlert("Ticket template added");

    redirect();

}

if (isset($_POST['update_project_template_runbook_version'])) {

    validateCSRFToken();

    $project_template_id = intval($_POST['project_template_id']);
    $ticket_template_id = intval($_POST['ticket_template_id']);
    $transaction_started = false;
    $runbook_version_id = 0;
    $version_number = 0;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the project stage pin transaction');
        }
        $transaction_started = true;

        // Publication locks the template before moving its current pointer.
        // Taking the same lock makes this command linearizable with publication.
        $current = mysqli_fetch_assoc(runbookDbQuery("SELECT ticket_template_published_version_id
            FROM ticket_templates WHERE ticket_template_id = $ticket_template_id
            AND ticket_template_archived_at IS NULL LIMIT 1 FOR UPDATE",
            'Could not lock the ticket template release'));
        $runbook_version_id = intval($current['ticket_template_published_version_id'] ?? 0);
        if (!$current || !$runbook_version_id) {
            throw new RuntimeException('A published runbook version is required before this stage can be updated');
        }

        $version = mysqli_fetch_assoc(runbookDbQuery("SELECT runbook_version_number
            FROM runbook_versions WHERE runbook_version_id = $runbook_version_id
            AND runbook_version_ticket_template_id = $ticket_template_id LIMIT 1 FOR UPDATE",
            'Could not validate the current published runbook version'));
        if (!$version) {
            throw new RuntimeException('The current published runbook version is unavailable');
        }
        $version_number = intval($version['runbook_version_number']);

        $stage = mysqli_fetch_assoc(runbookDbQuery("SELECT ticket_template_runbook_version_id
            FROM project_template_ticket_templates WHERE project_template_id = $project_template_id
            AND ticket_template_id = $ticket_template_id FOR UPDATE",
            'Could not lock the project template stage'));
        if (!$stage) {
            throw new RuntimeException('The project template stage is unavailable');
        }

        runbookDbQuery("UPDATE project_template_ticket_templates
            SET ticket_template_runbook_version_id = $runbook_version_id
            WHERE project_template_id = $project_template_id
            AND ticket_template_id = $ticket_template_id",
            'The project stage runbook pin could not be updated');
        $saved_pin = mysqli_fetch_row(runbookDbQuery("SELECT ticket_template_runbook_version_id
            FROM project_template_ticket_templates WHERE project_template_id = $project_template_id
            AND ticket_template_id = $ticket_template_id LIMIT 1",
            'Could not verify the project stage runbook pin'));
        if (intval($saved_pin[0] ?? 0) !== $runbook_version_id) {
            throw new RuntimeException('The project stage runbook pin was not saved');
        }

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the project stage runbook pin');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started && !mysqli_rollback($mysqli)) {
            error_log('Project stage runbook pin rollback failed');
        }
        error_log("Project template $project_template_id stage $ticket_template_id pin failed: " . $exception->getMessage());
        flashAlert('A current published runbook version is required before this stage can be updated', 'error');
        redirect();
    }

    logAudit('Project Template', 'Edit', "$session_name pinned ticket template $ticket_template_id to runbook v$version_number", 0, $project_template_id);
    flashAlert("Project stage updated to published runbook <strong>v$version_number</strong>");
    redirect();
}

if (isset($_POST['remove_ticket_template_from_project_template'])) {

    validateCSRFToken();

    $ticket_template_id = intval($_POST['ticket_template_id']);
    $project_template_id = intval($_POST['project_template_id']);

    mysqli_query($mysqli, "DELETE FROM project_template_ticket_templates WHERE project_template_id = $project_template_id AND ticket_template_id = $ticket_template_id");

    logAudit("Project Template", "Edit", "$session_name removed ticket template from project template", 0, $project_template_id);

    flashAlert("Ticket template removed", 'error');

    redirect();

}

if (isset($_GET['delete_project_template'])) {

    validateCSRFToken();

    $project_template_id = intval($_GET['delete_project_template']);

    $project_template_name = escapeSql(getFieldById('project_templates', $project_template_id, 'project_template_name'));

    mysqli_query($mysqli, "DELETE FROM project_templates WHERE project_template_id = $project_template_id");

    // Remove Associated Ticket Templates
    mysqli_query($mysqli, "DELETE FROM project_template_ticket_templates WHERE project_template_id = $project_template_id");

    logAudit("Project Template", "Delete", "$session_name deleted project template $project_template_name and its associated ticket templates and tasks");

    flashAlert("Project Template <strong>$project_template_name</strong> and its associated ticket templates and tasks deleted", 'error');

    redirect();

}
