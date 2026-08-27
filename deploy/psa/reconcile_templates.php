#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must be run from the command line.\n");
    exit(1);
}

define('FROM_STARTER_CONTENT', true);

$app_root = dirname(__DIR__, 2);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $app_root . '/config.php';
require $app_root . '/includes/db.php';
require $app_root . '/functions/sanitize.php';
require $app_root . '/admin/post/starter_content_model.php';

$dry_run = in_array('--dry-run', $argv, true);
$counts = [
    'ticket_templates' => 0,
    'task_templates' => 0,
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

function reconcileTemplateId($mysqli, $table, $id_column, $name_column, $name) {
    $name = escapeSql($name);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT $id_column FROM $table WHERE $name_column = '$name' LIMIT 1"));
    return intval($row[$id_column] ?? 0);
}

function reconcileTemplateAlias($mysqli, $table, $id_column, $name_column, $archive_column, $old_name, $new_name) {
    $old_id = reconcileTemplateId($mysqli, $table, $id_column, $name_column, $old_name);
    if (!$old_id) {
        return;
    }

    $new_id = reconcileTemplateId($mysqli, $table, $id_column, $name_column, $new_name);
    if ($new_id) {
        mysqli_query($mysqli, "UPDATE $table SET $archive_column = COALESCE($archive_column, CURRENT_TIMESTAMP) WHERE $id_column = $old_id");
        return;
    }

    $new_name = escapeSql($new_name);
    mysqli_query($mysqli, "UPDATE $table SET $name_column = '$new_name', $archive_column = NULL WHERE $id_column = $old_id");
}

try {
    mysqli_begin_transaction($mysqli);

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
            $new_name
        );
    }

    foreach (starterContentTicketTemplates() as $template) {
        $template_id = reconcileTemplateId(
            $mysqli,
            'ticket_templates',
            'ticket_template_id',
            'ticket_template_name',
            $template['name']
        );

        $fields = [
            'ticket_template_name' => $template['name'],
            'ticket_template_description' => $template['description'],
            'ticket_template_subject' => $template['subject'],
            'ticket_template_details' => $template['details'],
        ];

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
            mysqli_query($mysqli, "DELETE FROM task_templates WHERE task_template_ticket_template_id = $template_id");
        } else {
            $template_id = starterInsert($mysqli, 'ticket_templates', $fields, ['ticket_template_details']);
        }

        $order = 1;
        foreach ($template['tasks'] as $task) {
            starterInsert($mysqli, 'task_templates', [
                'task_template_name' => $task[0],
                'task_template_order' => $order,
                'task_template_completion_estimate' => intval($task[1]),
                'task_template_ticket_template_id' => $template_id,
            ]);
            $order++;
            $counts['task_templates']++;
        }
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
            $new_name
        );
    }

    foreach (starterContentProjectTemplates() as $template) {
        $template_id = reconcileTemplateId(
            $mysqli,
            'project_templates',
            'project_template_id',
            'project_template_name',
            $template['name']
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
            $ticket_template_id = starterTicketTemplateId($mysqli, $ticket_template_name);
            if (!$ticket_template_id) {
                throw new RuntimeException("Missing ticket template: $ticket_template_name");
            }
            starterInsert($mysqli, 'project_template_ticket_templates', [
                'project_template_id' => $template_id,
                'ticket_template_id' => $ticket_template_id,
                'ticket_template_order' => $order,
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

    echo ($dry_run ? 'DRY RUN - rolled back' : 'Templates reconciled') . PHP_EOL;
    foreach ($counts as $family => $count) {
        echo $family . ': ' . $count . PHP_EOL;
    }
} catch (Throwable $exception) {
    mysqli_rollback($mysqli);
    fwrite(STDERR, 'Template reconciliation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
