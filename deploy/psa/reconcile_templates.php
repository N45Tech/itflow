#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must be run from the command line.\n");
    exit(1);
}

$arguments = array_slice($argv, 1);
$allowed_modes = ['--dry-run', '--apply'];
if (count($arguments) !== 1 || !in_array($arguments[0], $allowed_modes, true)) {
    $script_name = basename((string) ($argv[0] ?? 'reconcile_templates.php'));
    fwrite(STDERR, "Usage: php $script_name (--dry-run|--apply)\n");
    exit(2);
}
$dry_run = $arguments[0] === '--dry-run';

define('FROM_STARTER_CONTENT', true);

$app_root = dirname(__DIR__, 2);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $app_root . '/config.php';
require $app_root . '/includes/db.php';
require $app_root . '/functions/sanitize.php';
require $app_root . '/admin/post/starter_content_model.php';
require $app_root . '/functions/runbooks.php';

$counts = [
    'ticket_templates' => 0,
    'task_templates' => 0,
    'published_runbooks' => 0,
    'project_templates' => 0,
    'project_stages' => 0,
    'vendor_templates' => 0,
    'document_templates' => 0,
    'contract_templates' => 0,
    'software_templates' => 0,
];

function reconcileTemplateValue($mysqli, $value, $is_html = false) {
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if ($is_html) {
        return "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
    }

    return "'" . escapeSql($value) . "'";
}

function reconcileTemplateUpdate($mysqli, $table, $id_column, $id, $fields, $html_columns = []) {
    $set = [];
    foreach ($fields as $column => $value) {
        $set[] = $column . ' = ' . reconcileTemplateValue($mysqli, $value, in_array($column, $html_columns, true));
    }
    mysqli_query($mysqli, "UPDATE $table SET " . implode(', ', $set) . " WHERE $id_column = " . intval($id));
}

function reconcileTemplateId($mysqli, $table, $id_column, $name_column, $name, $require_unique = false) {
    $display_name = (string) $name;
    $name = escapeSql($name);
    $limit = $require_unique ? 2 : 1;
    $rows = mysqli_query($mysqli, "SELECT $id_column FROM $table
        WHERE $name_column = '$name' ORDER BY $id_column LIMIT $limit");
    $ids = [];
    while ($row = mysqli_fetch_assoc($rows)) {
        $ids[] = intval($row[$id_column]);
    }
    if ($require_unique && count($ids) > 1) {
        throw new RuntimeException("Duplicate $table name '$display_name'; merge or rename the conflicting templates before reconciling");
    }
    return intval($ids[0] ?? 0);
}

function reconcileTemplateAlias($mysqli, $table, $id_column, $name_column, $archive_column, $old_name, $new_name, $require_unique = false) {
    $old_id = reconcileTemplateId($mysqli, $table, $id_column, $name_column, $old_name, $require_unique);
    if (!$old_id) {
        return;
    }

    $new_id = reconcileTemplateId($mysqli, $table, $id_column, $name_column, $new_name, $require_unique);
    if ($new_id) {
        mysqli_query($mysqli, "UPDATE $table SET $archive_column = COALESCE($archive_column, CURRENT_TIMESTAMP) WHERE $id_column = $old_id");
        return;
    }

    $new_name = escapeSql($new_name);
    mysqli_query($mysqli, "UPDATE $table SET $name_column = '$new_name', $archive_column = NULL WHERE $id_column = $old_id");
}

function reconcileTemplateDeleteTaskDrafts($mysqli, $ticket_template_id) {
    $ticket_template_id = intval($ticket_template_id);
    $task_ids = [];
    $tasks = mysqli_query($mysqli, "SELECT task_template_id FROM task_templates
        WHERE task_template_ticket_template_id = $ticket_template_id");
    while ($task = mysqli_fetch_assoc($tasks)) {
        $task_ids[] = intval($task['task_template_id']);
    }

    if ($task_ids) {
        $id_list = implode(',', $task_ids);
        mysqli_query($mysqli, "DELETE FROM task_template_dependencies
            WHERE task_template_id IN ($id_list) OR depends_on_task_template_id IN ($id_list)");
    }
    mysqli_query($mysqli, "DELETE FROM task_templates
        WHERE task_template_ticket_template_id = $ticket_template_id");
}

function reconcileCurrentRunbookVersionId($mysqli, $ticket_template_id, $ticket_template_name) {
    $ticket_template_id = intval($ticket_template_id);
    $current = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
        template.ticket_template_published_version_id,
        current_version.runbook_version_id,
        (SELECT COUNT(*) FROM runbook_versions history
            WHERE history.runbook_version_ticket_template_id = template.ticket_template_id) AS runbook_version_count
        FROM ticket_templates template
        LEFT JOIN runbook_versions current_version
            ON current_version.runbook_version_id = template.ticket_template_published_version_id
            AND current_version.runbook_version_ticket_template_id = template.ticket_template_id
        WHERE template.ticket_template_id = $ticket_template_id LIMIT 1"));
    if (!$current) {
        throw new RuntimeException("Missing ticket template: $ticket_template_name");
    }

    $published_pointer = intval($current['ticket_template_published_version_id'] ?? 0);
    $verified_version_id = intval($current['runbook_version_id'] ?? 0);
    $runbook_version_count = intval($current['runbook_version_count'] ?? 0);
    if ($published_pointer !== $verified_version_id
        || ($runbook_version_count > 0 && $verified_version_id < 1)) {
        throw new RuntimeException(
            "The authoritative published runbook pointer is unavailable for project stage $ticket_template_name"
        );
    }
    return $verified_version_id;
}

$reconcile_lock_name = 'n45-itflow-reconcile-templates';
$reconcile_lock_acquired = false;
$transaction_open = false;
$reconcile_error = null;

try {
    $lock_name_sql = mysqli_real_escape_string($mysqli, $reconcile_lock_name);
    $lock_row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$lock_name_sql', 0)"));
    if (intval($lock_row[0] ?? 0) !== 1) {
        throw new RuntimeException('Another canonical template reconciliation is already running');
    }
    $reconcile_lock_acquired = true;

    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not start the template reconciliation transaction');
    }
    $transaction_open = true;
    $published_versions = [];
    $resolved_ticket_template_ids = [];

    $ticket_aliases = [
        'New Hire Onboarding' => 'User Onboarding',
        'Employee Offboarding' => 'User Offboarding',
        'Workstation Deployment' => 'Device Deployment',
        'Monthly Maintenance' => 'Managed Care Monthly Review',
        'Client Onboarding' => 'Managed Care Onboarding',
        'Microsoft 365 Tenant Onboarding' => 'Microsoft 365 Tenant Baseline',
        'Quarterly Business Review' => 'Technology Review',
    ];
    foreach ($ticket_aliases as $old_name => $new_name) {
        reconcileTemplateAlias(
            $mysqli,
            'ticket_templates',
            'ticket_template_id',
            'ticket_template_name',
            'ticket_template_archived_at',
            $old_name,
            $new_name,
            true
        );
    }

    foreach (starterContentTicketTemplates() as $template) {
        $template_id = reconcileTemplateId(
            $mysqli,
            'ticket_templates',
            'ticket_template_id',
            'ticket_template_name',
            $template['name'],
            true
        );

        $canonical_runbook_key = !empty($template['runbook_key'])
            ? starterRunbookKey($template['runbook_key'], 'runbook')
            : '';
        if ($canonical_runbook_key !== '') {
            $canonical_key_sql = mysqli_real_escape_string($mysqli, $canonical_runbook_key);
            $key_match = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_template_id
                FROM ticket_templates WHERE ticket_template_runbook_key = '$canonical_key_sql' LIMIT 1"));
            $key_match_id = intval($key_match['ticket_template_id'] ?? 0);
            if ($key_match_id && $template_id && $key_match_id !== $template_id) {
                throw new RuntimeException(
                    'Canonical runbook identity conflict for ' . $template['name']
                    . ': its name and stable key belong to different templates; an explicit fork or merge is required'
                );
            }
            if ($key_match_id) {
                $template_id = $key_match_id;
            }
            if ($template_id) {
                $published_identity = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT runbook_version_key
                    FROM runbook_versions WHERE runbook_version_ticket_template_id = $template_id
                    ORDER BY runbook_version_number ASC LIMIT 1"));
                if ($published_identity
                    && !hash_equals((string) $published_identity['runbook_version_key'], $canonical_runbook_key)) {
                    throw new RuntimeException(
                        'Published runbook identity conflict for ' . $template['name']
                        . ': existing key ' . $published_identity['runbook_version_key']
                        . ' cannot be rewritten as ' . $canonical_runbook_key
                        . '; create an explicit fork or identity mapping first'
                    );
                }
            }
        }

        $fields = [
            'ticket_template_name' => $template['name'],
            'ticket_template_description' => $template['description'],
            'ticket_template_subject' => $template['subject'],
            'ticket_template_details' => $template['details'],
        ];
        if ($canonical_runbook_key !== '') {
            $fields['ticket_template_runbook_key'] = $canonical_runbook_key;
            $fields['ticket_template_runbook_type'] = $template['runbook_type'] ?? 'standard';
        }

        if ($template_id) {
            reconcileTemplateUpdate(
                $mysqli,
                'ticket_templates',
                'ticket_template_id',
                $template_id,
                $fields,
                ['ticket_template_details']
            );
            mysqli_query($mysqli, "UPDATE ticket_templates SET ticket_template_archived_at = NULL WHERE ticket_template_id = $template_id");
            reconcileTemplateDeleteTaskDrafts($mysqli, $template_id);
        } else {
            $template_id = starterInsert($mysqli, 'ticket_templates', $fields, ['ticket_template_details']);
        }

        $counts['task_templates'] += starterInsertTicketTemplateTasks(
            $mysqli,
            $template_id,
            $template['tasks']
        );

        if (!empty($template['publish_runbook'])) {
            $version_id = publishRunbookVersion(
                $template_id,
                0,
                'Canonical N45 starter runbook reconciled by deploy/psa/reconcile_templates.php'
            );
            if (!$version_id) {
                $definition = runbookDraftDefinition($template_id);
                $errors = $definition ? runbookValidateDefinition($definition) : ['draft could not be loaded'];
                throw new RuntimeException(
                    'Could not publish ' . $template['name'] . ': ' . implode('; ', $errors)
                );
            }
            $published_versions[$template_id] = $version_id;
            $counts['published_runbooks']++;
        }
        if (array_key_exists($template['name'], $resolved_ticket_template_ids)) {
            throw new RuntimeException('Duplicate canonical ticket template definition: ' . $template['name']);
        }
        $resolved_ticket_template_ids[$template['name']] = $template_id;
        $counts['ticket_templates']++;
    }

    $project_aliases = [
        'New Client Onboarding' => 'Managed Care Onboarding',
        'Workstation Refresh' => 'Device Refresh',
        'Security Baseline' => 'Security Baseline Remediation',
    ];
    foreach ($project_aliases as $old_name => $new_name) {
        reconcileTemplateAlias(
            $mysqli,
            'project_templates',
            'project_template_id',
            'project_template_name',
            'project_template_archived_at',
            $old_name,
            $new_name,
            true
        );
    }

    foreach (starterContentProjectTemplates() as $template) {
        $template_id = reconcileTemplateId(
            $mysqli,
            'project_templates',
            'project_template_id',
            'project_template_name',
            $template['name'],
            true
        );

        $fields = [
            'project_template_name' => $template['name'],
            'project_template_description' => $template['description'],
        ];

        if ($template_id) {
            reconcileTemplateUpdate($mysqli, 'project_templates', 'project_template_id', $template_id, $fields);
            mysqli_query($mysqli, "UPDATE project_templates SET project_template_archived_at = NULL WHERE project_template_id = $template_id");
            mysqli_query($mysqli, "DELETE FROM project_template_ticket_templates WHERE project_template_id = $template_id");
        } else {
            $template_id = starterInsert($mysqli, 'project_templates', $fields);
        }

        $order = 1;
        foreach ($template['ticket_templates'] as $ticket_template_name) {
            $ticket_template_id = intval($resolved_ticket_template_ids[$ticket_template_name] ?? 0);
            if (!$ticket_template_id) {
                throw new RuntimeException("Missing ticket template: $ticket_template_name");
            }
            $runbook_version_id = reconcileCurrentRunbookVersionId(
                $mysqli,
                $ticket_template_id,
                $ticket_template_name
            );
            if (isset($published_versions[$ticket_template_id])
                && intval($published_versions[$ticket_template_id]) !== $runbook_version_id) {
                throw new RuntimeException(
                    "Published runbook pointer changed while reconciling project stage $ticket_template_name"
                );
            }
            starterInsert($mysqli, 'project_template_ticket_templates', [
                'project_template_id' => $template_id,
                'ticket_template_id' => $ticket_template_id,
                'ticket_template_order' => $order,
                'ticket_template_runbook_version_id' => $runbook_version_id,
            ]);
            $order++;
            $counts['project_stages']++;
        }
        $counts['project_templates']++;
    }

    foreach (starterContentVendorTemplates() as $template) {
        $template_id = reconcileTemplateId(
            $mysqli,
            'vendor_templates',
            'vendor_template_id',
            'vendor_template_name',
            $template[0]
        );
        $fields = [
            'vendor_template_name' => $template[0],
            'vendor_template_description' => $template[1],
            'vendor_template_website' => $template[2],
        ];
        if ($template_id) {
            reconcileTemplateUpdate($mysqli, 'vendor_templates', 'vendor_template_id', $template_id, $fields);
            mysqli_query($mysqli, "UPDATE vendor_templates SET vendor_template_archived_at = NULL WHERE vendor_template_id = $template_id");
        } else {
            starterInsert($mysqli, 'vendor_templates', $fields);
        }
        $counts['vendor_templates']++;
    }

    $document_aliases = [
        'New Hire Onboarding Checklist' => 'User Onboarding Request',
        'Employee Offboarding Checklist' => 'User Offboarding Request',
        'Quarterly Business Review Notes' => 'Technology Review Notes',
    ];
    foreach ($document_aliases as $old_name => $new_name) {
        reconcileTemplateAlias(
            $mysqli,
            'document_templates',
            'document_template_id',
            'document_template_name',
            'document_template_archived_at',
            $old_name,
            $new_name
        );
    }

    foreach (starterContentDocumentTemplates() as $template) {
        $template_id = reconcileTemplateId(
            $mysqli,
            'document_templates',
            'document_template_id',
            'document_template_name',
            $template[0]
        );
        $fields = [
            'document_template_name' => $template[0],
            'document_template_description' => $template[1],
            'document_template_content' => $template[2],
            'document_template_updated_by' => 0,
        ];
        if ($template_id) {
            reconcileTemplateUpdate(
                $mysqli,
                'document_templates',
                'document_template_id',
                $template_id,
                $fields,
                ['document_template_content']
            );
            mysqli_query($mysqli, "UPDATE document_templates SET document_template_archived_at = NULL WHERE document_template_id = $template_id");
        } else {
            $fields['document_template_created_by'] = 0;
            starterInsert($mysqli, 'document_templates', $fields, ['document_template_content']);
        }
        $counts['document_templates']++;
    }

    foreach (starterContentContractTemplates() as $template) {
        $template_id = reconcileTemplateId(
            $mysqli,
            'contract_templates',
            'contract_template_id',
            'contract_template_name',
            $template['name']
        );
        $fields = [
            'contract_template_name' => $template['name'],
            'contract_template_description' => $template['description'],
            'contract_template_type' => $template['type'],
            'contract_template_renewal_frequency' => $template['renewal_frequency'],
            'contract_template_sla_low_response_time' => $template['sla_low_response'],
            'contract_template_sla_low_resolution_time' => $template['sla_low_resolution'],
            'contract_template_sla_medium_response_time' => $template['sla_medium_response'],
            'contract_template_sla_medium_resolution_time' => $template['sla_medium_resolution'],
            'contract_template_sla_high_response_time' => $template['sla_high_response'],
            'contract_template_sla_high_resolution_time' => $template['sla_high_resolution'],
            'contract_template_rate_standard' => $template['rate_standard'],
            'contract_template_rate_after_hours' => $template['rate_after_hours'],
            'contract_template_support_hours' => $template['support_hours'],
            'contract_template_net_terms' => $template['net_terms'],
            'contract_template_details' => $template['details'],
        ];
        if ($template_id) {
            reconcileTemplateUpdate(
                $mysqli,
                'contract_templates',
                'contract_template_id',
                $template_id,
                $fields,
                ['contract_template_details']
            );
            mysqli_query($mysqli, "UPDATE contract_templates SET contract_template_archived_at = NULL WHERE contract_template_id = $template_id");
        } else {
            starterInsert($mysqli, 'contract_templates', $fields, ['contract_template_details']);
        }
        $counts['contract_templates']++;
    }

    foreach (starterContentSoftwareTemplates() as $template) {
        $template_id = reconcileTemplateId(
            $mysqli,
            'software_templates',
            'software_template_id',
            'software_template_name',
            $template['name']
        );
        $fields = [
            'software_template_name' => $template['name'],
            'software_template_description' => $template['description'],
            'software_template_version' => $template['version'],
            'software_template_type' => $template['type'],
            'software_template_license_type' => $template['license_type'],
            'software_template_notes' => $template['notes'],
        ];
        if ($template_id) {
            reconcileTemplateUpdate($mysqli, 'software_templates', 'software_template_id', $template_id, $fields);
            mysqli_query($mysqli, "UPDATE software_templates SET software_template_archived_at = NULL WHERE software_template_id = $template_id");
        } else {
            starterInsert($mysqli, 'software_templates', $fields);
        }
        $counts['software_templates']++;
    }

    if ($dry_run) {
        mysqli_rollback($mysqli);
    } else {
        mysqli_commit($mysqli);
    }
    $transaction_open = false;
} catch (Throwable $exception) {
    if ($transaction_open) {
        try {
            mysqli_rollback($mysqli);
        } catch (Throwable $rollback_exception) {
            error_log('Template reconciliation rollback failed: ' . $rollback_exception->getMessage());
        }
        $transaction_open = false;
    }
    $reconcile_error = $exception;
} finally {
    if ($reconcile_lock_acquired) {
        try {
            $release_row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT RELEASE_LOCK('$lock_name_sql')"));
            if (intval($release_row[0] ?? 0) !== 1) {
                throw new RuntimeException('Could not confirm release of the template reconciliation lock');
            }
        } catch (Throwable $release_exception) {
            if ($reconcile_error === null) {
                $reconcile_error = $release_exception;
            } else {
                error_log('Template reconciliation lock release failed: ' . $release_exception->getMessage());
            }
        }
    }
}

if ($reconcile_error !== null) {
    fwrite(STDERR, 'Template reconciliation failed: ' . $reconcile_error->getMessage() . PHP_EOL);
    exit(1);
}

echo ($dry_run ? 'DRY RUN - rolled back' : 'Templates reconciled') . PHP_EOL;
foreach ($counts as $family => $count) {
    echo $family . ': ' . $count . PHP_EOL;
}
