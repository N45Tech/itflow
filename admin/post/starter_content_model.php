<?php
defined('FROM_POST_HANDLER') || defined('FROM_STARTER_CONTENT') || die("Direct file access is not allowed");

/*
 * ITFlow - Starter content library
 *
 * A catalog of opinionated defaults for a typical MSP, loaded on demand from
 * Maintenance > Starter Content rather than at install time. Every pack is
 * idempotent - anything already present by name is skipped - so a pack can be
 * loaded on a brand new install or a five year old one and only ever adds what
 * is missing. Nothing here updates or deletes an existing row.
 *
 * The file is deliberately named _model so admin/post.php does not glob it in
 * on every admin request. It is required on demand by the page and the handler.
 */

// ------------------------------
// starterContentPacks
// Registry - the key is what the buttons post back, and the only accepted value.
// Order is load order. A pack with a 'requires' still loads on its own, it just
// produces less - products land uncategorised, project templates land with no stages.
// ------------------------------
function starterContentPacks() {
    return [
        'categories' => [
            'label' => 'Categories',
            'icon' => 'fa-list-ul',
            'description' => 'Expense, income, referral and ticket categories for an MSP. Income categories are also what products are filed under.',
        ],
        'tags' => [
            'label' => 'Tags',
            'icon' => 'fa-tags',
            'description' => 'Operational client, location, contact, credential and asset tags for routing, authorization, ownership and lifecycle reporting.',
        ],
        'ticket_templates' => [
            'label' => 'Ticket Templates',
            'icon' => 'fa-life-ring',
            'description' => 'Onboarding, offboarding, deployments, maintenance and incident response, each with its task list.',
        ],
        'project_templates' => [
            'label' => 'Project Templates',
            'icon' => 'fa-project-diagram',
            'description' => 'Common MSP projects, built out of the ticket templates above.',
            'requires' => 'ticket_templates',
        ],
        'vendor_templates' => [
            'label' => 'Vendor Templates',
            'icon' => 'fa-building',
            'description' => 'Vendors most clients end up with. Account numbers and support numbers are left blank.',
        ],
        'document_templates' => [
            'label' => 'Document Templates',
            'icon' => 'fa-file-alt',
            'description' => 'Runbooks, build sheets, checklists and plans as fill-in-the-blank skeletons.',
        ],
        'contract_templates' => [
            'label' => 'Contract Templates',
            'icon' => 'fa-file-contract',
            'description' => 'Operational starting points for managed service, project and hourly support agreements. Legal review is still required.',
        ],
        'software_templates' => [
            'label' => 'Software Templates',
            'icon' => 'fa-cube',
            'description' => 'The standard management, productivity, automation and endpoint software stack.',
        ],
        'products' => [
            'label' => 'Products & Services',
            'icon' => 'fa-cubes',
            'description' => 'Recurring services, labor rates, project work and hardware lines. Prices are starting points - hardware and resold SKUs come in at zero.',
            'requires' => 'categories',
        ],
    ];
}

// ------------------------------
// starterInsert
// Builds an "INSERT INTO <table> SET col = val" from a column => value map.
// Integers pass through unquoted, everything else is escaped and quoted.
// Columns named in $html_columns hold rich text and are escaped without
// strip_tags, matching how the post handlers store TinyMCE content.
// Returns the new row ID.
// ------------------------------
function starterInsert($mysqli, $table, $fields, $html_columns = []) {
    $set = [];
    foreach ($fields as $column => $value) {
        if (is_int($value)) {
            $set[] = "$column = $value";
        } elseif (in_array($column, $html_columns)) {
            $value = mysqli_real_escape_string($mysqli, $value);
            $set[] = "$column = '$value'";
        } else {
            $value = escapeSql($value);
            $set[] = "$column = '$value'";
        }
    }
    $set = implode(', ', $set);
    if (!mysqli_query($mysqli, "INSERT INTO $table SET $set")) {
        throw new RuntimeException("Could not insert starter content into $table: " . mysqli_error($mysqli));
    }
    return intval(mysqli_insert_id($mysqli));
}

function starterRunbookKey($value, $fallback = 'task') {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');
    return substr($value ?: $fallback, 0, 100);
}

function starterRunbookTaskDefinition($task, $order) {
    if (array_is_list($task)) {
        $task = [
            'name' => $task[0],
            'estimate' => intval($task[1] ?? 0),
        ];
    }

    return [
        'key' => starterRunbookKey($task['key'] ?? $task['name'], 'task-' . intval($order)),
        'name' => (string) $task['name'],
        'instructions' => (string) ($task['instructions'] ?? ''),
        'order' => intval($order),
        'estimate' => max(0, intval($task['estimate'] ?? 0)),
        'condition_type' => (string) ($task['condition_type'] ?? 'always'),
        'condition_value' => (string) ($task['condition_value'] ?? ''),
        'owner_type' => (string) ($task['owner_type'] ?? 'unassigned'),
        'owner_user_id' => max(0, intval($task['owner_user_id'] ?? 0)),
        'due_offset_minutes' => max(0, intval(round(floatval($task['due_offset_hours'] ?? 0) * 60))),
        'initial_state' => (string) ($task['initial_state'] ?? 'Ready'),
        'approval_scope' => (string) ($task['approval_scope'] ?? ''),
        'approval_type' => (string) ($task['approval_type'] ?? ''),
        'approval_user_id' => max(0, intval($task['approval_user_id'] ?? 0)),
        'evidence_type' => (string) ($task['evidence_type'] ?? 'none'),
        'evidence_prompt' => (string) ($task['evidence_prompt'] ?? ''),
        'depends_on' => array_values(array_unique(array_map(
            static fn($key) => starterRunbookKey($key),
            $task['depends_on'] ?? []
        ))),
    ];
}

/**
 * Expand a concise canonical workflow into immutable runbook task metadata.
 *
 * The starter library contains a few long-standing ticket templates whose
 * human-readable task lists predate versioned runbooks.  Portal catalog
 * reconciliation needs stable keys, dependency order, ownership, evidence and
 * (where appropriate) an explicit client approval gate.  Keeping that
 * mechanical metadata here makes the source lists readable while producing
 * the same deterministic definition on every install and reconciliation.
 */
function starterCanonicalRunbookTaskSequence(
    $prefix,
    $workflow_name,
    $steps,
    $approval_index = null,
    $approval_type = 'technical'
) {
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]+/', '', (string) $prefix));
    $workflow_name = trim((string) $workflow_name);
    $steps = is_array($steps) ? array_values($steps) : [];
    $approval_index = $approval_index === null ? null : intval($approval_index);
    $conditional_index = count($steps) > 4 ? count($steps) - 3 : -1;
    if ($conditional_index === $approval_index) {
        $conditional_index--;
    }

    $tasks = [];
    $previous_key = null;
    foreach ($steps as $index => $step) {
        $step = is_array($step) ? $step : [(string) $step, 0];
        $name = trim((string) ($step[0] ?? ''));
        $estimate = max(1, intval($step[1] ?? 15));
        $number = ($index + 1) * 10;
        $source_key = $prefix . '-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
        $is_approval = $approval_index !== null && $index === $approval_index;
        $is_conditional = $index === $conditional_index;
        $is_last = $index === count($steps) - 1;

        $tasks[] = [
            'key' => $source_key,
            'name' => $source_key . ' ' . $name,
            'instructions' => 'Complete this ' . $workflow_name . ' step within the immutable approved request scope: '
                . $name . '. Stop if authority, scope or the live state differs materially; record the outcome and route exceptions as separately owned follow-up work.',
            'estimate' => $estimate,
            'condition_type' => $is_conditional ? 'manual_confirm' : 'always',
            'condition_value' => $is_conditional
                ? 'This step applies to the approved request scope and live environment' : '',
            'owner_type' => 'ticket_assignee',
            'due_offset_hours' => ($index + 1) * 4,
            'initial_state' => $is_approval ? 'Waiting' : 'Ready',
            'approval_scope' => $is_approval ? 'client' : '',
            'approval_type' => $is_approval ? $approval_type : '',
            'evidence_type' => $index === 1 ? 'file' : ($is_last ? 'any' : 'note'),
            'evidence_prompt' => 'Retain redacted evidence for ' . strtolower($name)
                . ', including the result, actor or owner, timestamp and any unresolved exception; never store credentials or bearer tokens.',
            'depends_on' => $previous_key === null ? [] : [$previous_key],
        ];
        $previous_key = $source_key;
    }
    return $tasks;
}

function starterInsertTicketTemplateTasks($mysqli, $ticket_template_id, $tasks) {
    $ticket_template_id = intval($ticket_template_id);
    $definitions = [];
    $task_ids = [];
    $order = 1;

    foreach ($tasks as $task) {
        $definition = starterRunbookTaskDefinition($task, $order);
        if (isset($task_ids[$definition['key']])) {
            throw new RuntimeException('Duplicate task key in starter runbook: ' . $definition['key']);
        }

        $task_id = starterInsert($mysqli, 'task_templates', [
            'task_template_name' => $definition['name'],
            'task_template_key' => $definition['key'],
            'task_template_instructions' => $definition['instructions'],
            'task_template_order' => $definition['order'],
            'task_template_completion_estimate' => $definition['estimate'],
            'task_template_condition_type' => $definition['condition_type'],
            'task_template_condition_value' => $definition['condition_value'],
            'task_template_owner_type' => $definition['owner_type'],
            'task_template_owner_user_id' => $definition['owner_user_id'],
            'task_template_due_offset_minutes' => $definition['due_offset_minutes'],
            'task_template_initial_state' => $definition['initial_state'],
            'task_template_approval_scope' => $definition['approval_scope'],
            'task_template_approval_type' => $definition['approval_type'],
            'task_template_approval_user_id' => $definition['approval_user_id'],
            'task_template_evidence_type' => $definition['evidence_type'],
            'task_template_evidence_prompt' => $definition['evidence_prompt'],
            'task_template_ticket_template_id' => $ticket_template_id,
        ]);

        $definitions[] = $definition;
        $task_ids[$definition['key']] = $task_id;
        $order++;
    }

    foreach ($definitions as $definition) {
        foreach ($definition['depends_on'] as $dependency_key) {
            if (!isset($task_ids[$dependency_key])) {
                throw new RuntimeException(
                    'Starter runbook task ' . $definition['key'] . ' depends on missing task ' . $dependency_key
                );
            }
            starterInsert($mysqli, 'task_template_dependencies', [
                'task_template_id' => $task_ids[$definition['key']],
                'depends_on_task_template_id' => $task_ids[$dependency_key],
            ]);
        }
    }

    return count($definitions);
}

// ------------------------------
// starterExistingNames
// One query per pack rather than one per row. Keys are lower cased so a pack
// does not re-add a name that differs only in case.
// ------------------------------
function starterExistingNames($mysqli, $table, $name_column, $key_column = null) {
    $existing = [];
    $columns = $key_column ? "$name_column, $key_column" : $name_column;
    $sql = mysqli_query($mysqli, "SELECT $columns FROM $table");
    while ($row = mysqli_fetch_assoc($sql)) {
        $key = mb_strtolower($row[$name_column]);
        if ($key_column) {
            $key = $row[$key_column] . '|' . $key;
        }
        $existing[$key] = true;
    }
    return $existing;
}

// ------------------------------
// starterCategoryId
// ------------------------------
function starterCategoryId($mysqli, $name, $type) {
    $name = escapeSql($name);
    $type = escapeSql($type);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT category_id FROM categories WHERE category_name = '$name' AND category_type = '$type' LIMIT 1"));
    return intval($row['category_id'] ?? 0);
}

// ------------------------------
// starterTicketTemplateId
// ------------------------------
function starterTicketTemplateId($mysqli, $name) {
    $name = escapeSql($name);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = '$name' LIMIT 1"));
    return intval($row['ticket_template_id'] ?? 0);
}

// ------------------------------
// starterContentLoad
// Single entry point. $dry_run counts what would be added without touching
// anything, which is what the page uses to show the missing column.
// Returns the number of top level rows added (or that would be added).
// ------------------------------
function starterContentLoad($mysqli, $pack, $dry_run = false) {
    switch ($pack) {
        case 'categories':
            return starterLoadCategories($mysqli, $dry_run);
        case 'tags':
            return starterLoadTags($mysqli, $dry_run);
        case 'ticket_templates':
            return starterLoadTicketTemplates($mysqli, $dry_run);
        case 'project_templates':
            return starterLoadProjectTemplates($mysqli, $dry_run);
        case 'vendor_templates':
            return starterLoadVendorTemplates($mysqli, $dry_run);
        case 'document_templates':
            return starterLoadDocumentTemplates($mysqli, $dry_run);
        case 'contract_templates':
            return starterLoadContractTemplates($mysqli, $dry_run);
        case 'software_templates':
            return starterLoadSoftwareTemplates($mysqli, $dry_run);
        case 'products':
            return starterLoadProducts($mysqli, $dry_run);
    }
    return 0;
}

// ------------------------------
// starterContentStatus
// Per pack totals for the page - how many the library holds, how many are
// already present, and how many loading would add.
// ------------------------------
function starterContentStatus($mysqli) {
    $status = [];
    foreach (starterContentPacks() as $pack => $details) {
        $total = starterContentTotal($pack);
        $missing = starterContentLoad($mysqli, $pack, true);
        $status[$pack] = [
            'total' => $total,
            'missing' => $missing,
            'present' => $total - $missing,
        ];
    }
    return $status;
}

// ------------------------------
// starterContentTotal
// ------------------------------
function starterContentTotal($pack) {
    switch ($pack) {
        case 'categories':
            return count(starterContentCategories());
        case 'tags':
            return count(starterContentTags());
        case 'ticket_templates':
            return count(starterContentTicketTemplates());
        case 'project_templates':
            return count(starterContentProjectTemplates());
        case 'vendor_templates':
            return count(starterContentVendorTemplates());
        case 'document_templates':
            return count(starterContentDocumentTemplates());
        case 'contract_templates':
            return count(starterContentContractTemplates());
        case 'software_templates':
            return count(starterContentSoftwareTemplates());
        case 'products':
            return count(starterContentProducts());
    }
    return 0;
}

// ------------------------------
// starterLoadCategories
// ------------------------------
function starterLoadCategories($mysqli, $dry_run = false) {
    $existing = starterExistingNames($mysqli, 'categories', 'category_name', 'category_type');
    $added = 0;

    foreach (starterContentCategories() as $category) {
        if (isset($existing[$category['type'] . '|' . mb_strtolower($category['name'])])) {
            continue;
        }
        $added++;
        if ($dry_run) {
            continue;
        }
        $fields = [
            'category_name' => $category['name'],
            'category_type' => $category['type'],
            'category_color' => $category['color'],
            'category_description' => $category['description'],
        ];
        if ($category['icon']) {
            $fields['category_icon'] = $category['icon'];
        }
        if ($category['order']) {
            $fields['category_order'] = $category['order'];
        }
        starterInsert($mysqli, 'categories', $fields);
    }

    return $added;
}

// ------------------------------
// starterLoadTags
// ------------------------------
function starterLoadTags($mysqli, $dry_run = false) {
    $existing = starterExistingNames($mysqli, 'tags', 'tag_name', 'tag_type');
    $added = 0;

    foreach (starterContentTags() as $tag) {
        if (isset($existing[$tag[0] . '|' . mb_strtolower($tag[1])])) {
            continue;
        }
        $added++;
        if ($dry_run) {
            continue;
        }
        starterInsert($mysqli, 'tags', [
            'tag_type' => $tag[0],
            'tag_name' => $tag[1],
            'tag_color' => $tag[2],
            'tag_icon' => $tag[3],
        ]);
    }

    return $added;
}

// ------------------------------
// starterLoadTicketTemplates
// A template is added whole or not at all - an existing name is left alone
// rather than having its task list topped up.
// ------------------------------
function starterLoadTicketTemplates($mysqli, $dry_run = false) {
    global $session_user_id;

    $existing = starterExistingNames($mysqli, 'ticket_templates', 'ticket_template_name');
    $added = 0;

    foreach (starterContentTicketTemplates() as $ticket_template) {
        if (isset($existing[mb_strtolower($ticket_template['name'])])) {
            continue;
        }
        $added++;
        if ($dry_run) {
            continue;
        }

        $template_fields = [
            'ticket_template_name' => $ticket_template['name'],
            'ticket_template_description' => $ticket_template['description'],
            'ticket_template_subject' => $ticket_template['subject'],
            'ticket_template_details' => $ticket_template['details'],
        ];
        if (!empty($ticket_template['runbook_key'])) {
            $template_fields['ticket_template_runbook_key'] = starterRunbookKey($ticket_template['runbook_key'], 'runbook');
            $template_fields['ticket_template_runbook_type'] = $ticket_template['runbook_type'] ?? 'standard';
        }

        $publish_runbook = !empty($ticket_template['publish_runbook']);
        if ($publish_runbook && !mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the starter runbook transaction: ' . mysqli_error($mysqli));
        }

        try {
            $ticket_template_id = starterInsert($mysqli, 'ticket_templates', $template_fields, ['ticket_template_details']);
            if (!$ticket_template_id) {
                throw new RuntimeException('The starter ticket template did not receive an ID');
            }
            starterInsertTicketTemplateTasks($mysqli, $ticket_template_id, $ticket_template['tasks']);

            if ($publish_runbook) {
                $version_id = publishRunbookVersion(
                    $ticket_template_id,
                    intval($session_user_id ?? 0),
                    'Canonical N45 starter runbook'
                );
                if (!$version_id) {
                    $definition = runbookDraftDefinition($ticket_template_id);
                    $errors = $definition ? runbookValidateDefinition($definition) : ['draft could not be loaded'];
                    throw new RuntimeException(
                        'Could not publish starter runbook ' . $ticket_template['name'] . ': ' . implode('; ', $errors)
                    );
                }
                if (!mysqli_commit($mysqli)) {
                    throw new RuntimeException('Could not commit the starter runbook transaction: ' . mysqli_error($mysqli));
                }
            }
        } catch (Throwable $exception) {
            if ($publish_runbook) {
                mysqli_rollback($mysqli);
            }
            throw $exception;
        }
    }

    return $added;
}

// ------------------------------
// starterLoadProjectTemplates
// Links are resolved by ticket template name - one that is not present is
// skipped, so loading this pack before the ticket templates still works and
// simply produces a project template with fewer stages.
// ------------------------------
function starterLoadProjectTemplates($mysqli, $dry_run = false) {
    $existing = starterExistingNames($mysqli, 'project_templates', 'project_template_name');
    $added = 0;

    foreach (starterContentProjectTemplates() as $project_template) {
        if (isset($existing[mb_strtolower($project_template['name'])])) {
            continue;
        }
        $added++;
        if ($dry_run) {
            continue;
        }

        $project_template_id = starterInsert($mysqli, 'project_templates', [
            'project_template_name' => $project_template['name'],
            'project_template_description' => $project_template['description'],
        ]);

        $order = 1;
        foreach ($project_template['ticket_templates'] as $ticket_template_name) {
            $ticket_template_id = starterTicketTemplateId($mysqli, $ticket_template_name);
            if ($ticket_template_id) {
                starterInsert($mysqli, 'project_template_ticket_templates', [
                    'project_template_id' => $project_template_id,
                    'ticket_template_id' => $ticket_template_id,
                    'ticket_template_order' => $order,
                    'ticket_template_runbook_version_id' => intval(mysqli_fetch_row(mysqli_query(
                        $mysqli,
                        "SELECT ticket_template_published_version_id FROM ticket_templates WHERE ticket_template_id = $ticket_template_id"
                    ))[0] ?? 0),
                ]);
                $order++;
            }
        }
    }

    return $added;
}

// ------------------------------
// starterLoadVendorTemplates
// ------------------------------
function starterLoadVendorTemplates($mysqli, $dry_run = false) {
    $existing = starterExistingNames($mysqli, 'vendor_templates', 'vendor_template_name');
    $added = 0;

    foreach (starterContentVendorTemplates() as $vendor_template) {
        if (isset($existing[mb_strtolower($vendor_template[0])])) {
            continue;
        }
        $added++;
        if ($dry_run) {
            continue;
        }
        starterInsert($mysqli, 'vendor_templates', [
            'vendor_template_name' => $vendor_template[0],
            'vendor_template_description' => $vendor_template[1],
            'vendor_template_website' => $vendor_template[2],
        ]);
    }

    return $added;
}

// ------------------------------
// starterLoadDocumentTemplates
// ------------------------------
function starterLoadDocumentTemplates($mysqli, $dry_run = false) {
    global $session_user_id;

    $existing = starterExistingNames($mysqli, 'document_templates', 'document_template_name');
    $added = 0;

    foreach (starterContentDocumentTemplates() as $document_template) {
        if (isset($existing[mb_strtolower($document_template[0])])) {
            continue;
        }
        $added++;
        if ($dry_run) {
            continue;
        }
        starterInsert($mysqli, 'document_templates', [
            'document_template_name' => $document_template[0],
            'document_template_description' => $document_template[1],
            'document_template_content' => $document_template[2],
            'document_template_created_by' => intval($session_user_id ?? 0),
        ], ['document_template_content']);
    }

    return $added;
}

// ------------------------------
// starterLoadContractTemplates
// ------------------------------
function starterLoadContractTemplates($mysqli, $dry_run = false) {
    $existing = starterExistingNames($mysqli, 'contract_templates', 'contract_template_name');
    $added = 0;

    foreach (starterContentContractTemplates() as $contract_template) {
        if (isset($existing[mb_strtolower($contract_template['name'])])) {
            continue;
        }
        $added++;
        if ($dry_run) {
            continue;
        }
        starterInsert($mysqli, 'contract_templates', [
            'contract_template_name' => $contract_template['name'],
            'contract_template_description' => $contract_template['description'],
            'contract_template_type' => $contract_template['type'],
            'contract_template_renewal_frequency' => $contract_template['renewal_frequency'],
            'contract_template_sla_low_response_time' => $contract_template['sla_low_response'],
            'contract_template_sla_low_resolution_time' => $contract_template['sla_low_resolution'],
            'contract_template_sla_medium_response_time' => $contract_template['sla_medium_response'],
            'contract_template_sla_medium_resolution_time' => $contract_template['sla_medium_resolution'],
            'contract_template_sla_high_response_time' => $contract_template['sla_high_response'],
            'contract_template_sla_high_resolution_time' => $contract_template['sla_high_resolution'],
            'contract_template_rate_standard' => $contract_template['rate_standard'],
            'contract_template_rate_after_hours' => $contract_template['rate_after_hours'],
            'contract_template_support_hours' => $contract_template['support_hours'],
            'contract_template_net_terms' => $contract_template['net_terms'],
            'contract_template_details' => $contract_template['details'],
        ], ['contract_template_details']);
    }

    return $added;
}

// ------------------------------
// starterLoadSoftwareTemplates
// ------------------------------
function starterLoadSoftwareTemplates($mysqli, $dry_run = false) {
    $existing = starterExistingNames($mysqli, 'software_templates', 'software_template_name');
    $added = 0;

    foreach (starterContentSoftwareTemplates() as $software_template) {
        if (isset($existing[mb_strtolower($software_template['name'])])) {
            continue;
        }
        $added++;
        if ($dry_run) {
            continue;
        }
        starterInsert($mysqli, 'software_templates', [
            'software_template_name' => $software_template['name'],
            'software_template_description' => $software_template['description'],
            'software_template_version' => $software_template['version'],
            'software_template_type' => $software_template['type'],
            'software_template_license_type' => $software_template['license_type'],
            'software_template_notes' => $software_template['notes'],
        ]);
    }

    return $added;
}

// ------------------------------
// starterLoadProducts
// Products are filed under income categories. A category that is not present
// leaves the product uncategorised rather than blocking the load, so this pack
// works whether or not the categories pack has been loaded.
// ------------------------------
function starterLoadProducts($mysqli, $dry_run = false) {
    global $session_company_currency;

    $existing = starterExistingNames($mysqli, 'products', 'product_name');
    $added = 0;
    $category_ids = [];

    foreach (starterContentProducts() as $product) {
        if (isset($existing[mb_strtolower($product[0])])) {
            continue;
        }
        $added++;
        if ($dry_run) {
            continue;
        }

        if (!isset($category_ids[$product[4]])) {
            $category_ids[$product[4]] = starterCategoryId($mysqli, $product[4], 'Income');
        }

        starterInsert($mysqli, 'products', [
            'product_name' => $product[0],
            'product_type' => $product[1],
            'product_code' => $product[2],
            'product_price' => $product[3],
            'product_category_id' => $category_ids[$product[4]],
            'product_description' => $product[5],
            'product_currency_code' => $session_company_currency ?? '',
            'product_tax_id' => 0,
        ]);
    }

    return $added;
}

// ------------------------------
// starterContentCategories
// Flattened to one row per category so the loader and the counter share a shape.
// ------------------------------
function starterContentCategories() {

    $expense_categories = [
        ['Office Supplies', '#007bff', 'Consumables and general office purchases'],
        ['Travel', '#6f42c1', 'Airfare, lodging and out of town travel'],
        ['Advertising', '#fd7e14', 'Marketing, campaigns and sponsorships'],
        ['Processing Fee', '#6c757d', 'Card and payment gateway processing fees'],
        ['Shipping and Postage', '#20c997', 'Freight, courier and postage'],
        ['Software', '#17a2b8', 'Internal software and tooling subscriptions'],
        ['Bank Fees', '#ffc107', 'Account, wire and merchant service charges'],
        ['Payroll', '#28a745', 'Wages, payroll taxes and benefits'],
        ['Professional Services', '#001f3f', 'Legal, accounting and consulting fees'],
        ['Contractor', '#795548', 'Subcontracted labor and 1099 work'],
        ['Insurance', '#dc3545', 'General liability, errors and omissions, cyber'],
        ['Infrastructure', '#3d9970', 'Colocation, racks, power and connectivity'],
        ['Equipment', '#adb5bd', 'Internal hardware and capital equipment'],
        ['Education', '#e83e8c', 'Training, certifications and exam fees'],
        ['Hardware - Cost of Goods', '#343a40', 'Hardware bought for resale to a client'],
        ['Licensing - Cost of Goods', '#17a2b8', 'Licenses and subscriptions resold to a client'],
        ['Cloud and Hosting', '#6610f2', 'Cloud compute, storage and hosting spend'],
        ['Telecom and Internet', '#fd7e14', 'Circuits, mobile plans and phone service'],
        ['Rent and Utilities', '#795548', 'Office rent, power, water and refuse'],
        ['Vehicle and Fuel', '#d81b60', 'Fuel, maintenance and vehicle costs'],
        ['Meals', '#e83e8c', 'Business meals and client entertainment'],
        ['Tools and Test Equipment', '#6c757d', 'Hand tools, testers and shop equipment'],
        ['Dues and Subscriptions', '#007bff', 'Memberships, associations and publications'],
        ['Taxes', '#dc3545', 'Business, sales and franchise taxes'],
        ['Owner Distribution', '#28a745', 'Owner draw or distribution of profit'],
    ];

    $income_categories = [
        ['Managed Services', '#28a745', 'Recurring managed service agreements'],
        ['Consulting', '#007bff', 'Advisory, vCIO and strategy work'],
        ['Projects', '#6f42c1', 'Fixed scope project work'],
        ['Hardware Sales', '#adb5bd', 'Resale of hardware and peripherals'],
        ['Software Sales', '#17a2b8', 'Resale of software and perpetual licenses'],
        ['Cloud Services', '#6610f2', 'Hosted servers, storage and platforms'],
        ['Support', '#ffc107', 'Reactive help desk and support labor'],
        ['Training', '#e83e8c', 'End user and administrator training'],
        ['Telecom Services', '#fd7e14', 'Voice, SIP and connectivity services'],
        ['Backup', '#001f3f', 'Backup, replication and disaster recovery'],
        ['Security', '#dc3545', 'Security tooling, monitoring and response'],
        ['Licensing', '#20c997', 'Recurring per seat license billing'],
        ['Monitoring', '#3d9970', 'Remote monitoring and alerting'],
        ['Labor', '#795548', 'Hourly and after hours labor'],
        ['Web and Hosting', '#6c757d', 'Websites, domains, certificates and hosting'],
        ['Onboarding', '#d81b60', 'One off onboarding and setup fees'],
        ['Reimbursable Expenses', '#343a40', 'Pass through costs rebilled to a client'],
        ['Late Fees', '#dc3545', 'Interest and late payment charges'],
    ];

    $referral_categories = [
        ['Friend', '#007bff', 'Personal recommendation'],
        ['Search', '#fd7e14', 'Found through a search engine'],
        ['Social Media', '#28a745', 'Came from a social platform'],
        ['Email', '#ffc107', 'Responded to an email campaign'],
        ['Partner', '#6f42c1', 'Referred by a channel partner'],
        ['Event', '#dc3545', 'Met at a trade show or event'],
        ['Affiliate', '#e83e8c', 'Referred by an affiliate'],
        ['Client', '#17a2b8', 'Referred by an existing client'],
        ['Website', '#20c997', 'Enquiry submitted through the website'],
        ['Networking Group', '#001f3f', 'Referred through a networking group'],
        ['Chamber of Commerce', '#3d9970', 'Referred through a chamber or association'],
        ['Vendor', '#adb5bd', 'Referred by a vendor or distributor'],
        ['Cold Outreach', '#6c757d', 'Result of outbound prospecting'],
        ['Direct Mail', '#795548', 'Responded to a mailer'],
        ['Acquisition', '#6610f2', 'Came across with an acquired book of business'],
        ['Other', '#343a40', 'Source does not fit any standard category'],
    ];

    $ticket_categories = [
        ['Workstation', '#007bff', 'fa-desktop', 'Desktop and laptop issues'],
        ['Server', '#001f3f', 'fa-server', 'Physical and virtual server issues'],
        ['Network', '#6610f2', 'fa-network-wired', 'Switching, routing and cabling'],
        ['Firewall', '#dc3545', 'fa-shield-alt', 'Firewall, VPN and perimeter security'],
        ['Wireless', '#17a2b8', 'fa-wifi', 'Access points, coverage and roaming'],
        ['Printer', '#6c757d', 'fa-print', 'Printers, scanners and copiers'],
        ['Email', '#fd7e14', 'fa-envelope', 'Mail flow, delivery and client issues'],
        ['Microsoft 365', '#0078d4', 'fa-cloud', 'Tenant, licensing and cloud app issues'],
        ['Account and Access', '#ffc107', 'fa-user-lock', 'Passwords, MFA and permissions'],
        ['Software', '#20c997', 'fa-window-restore', 'Standard application issues'],
        ['Line of Business Application', '#6f42c1', 'fa-cubes', 'Client specific business applications'],
        ['Backup and Recovery', '#3d9970', 'fa-database', 'Backup jobs, restores and replication'],
        ['Security Incident', '#d81b60', 'fa-user-secret', 'Suspected or confirmed compromise'],
        ['Phishing Report', '#e83e8c', 'fa-fish', 'User reported suspicious email'],
        ['Phone and VoIP', '#28a745', 'fa-phone-alt', 'Handsets, extensions and call routing'],
        ['Mobile Device', '#795548', 'fa-mobile-alt', 'Phones, tablets and mobile management'],
        ['Hardware Failure', '#343a40', 'fa-tools', 'Failed hardware, RMA and replacement'],
        ['Onboarding', '#28a745', 'fa-user-plus', 'New user and new device setup'],
        ['Offboarding', '#dc3545', 'fa-user-minus', 'Departing user and device recovery'],
        ['Procurement', '#adb5bd', 'fa-shopping-cart', 'Quotes, orders and purchasing'],
        ['Website and DNS', '#17a2b8', 'fa-globe', 'Hosting, domains and certificates'],
        ['Maintenance', '#6c757d', 'fa-calendar-check', 'Scheduled and preventative maintenance'],
        ['Monitoring Alert', '#ffc107', 'fa-bell', 'Alerts raised by monitoring tooling'],
        ['Project Work', '#6f42c1', 'fa-project-diagram', 'Work carried out under a project'],
        ['Billing', '#001f3f', 'fa-file-invoice-dollar', 'Invoice and account questions'],
        ['Training', '#e83e8c', 'fa-graduation-cap', 'How to questions and user training'],
        ['Vendor Coordination', '#795548', 'fa-handshake', 'Work being driven with a third party'],
        ['Other', '#343a40', 'fa-question-circle', 'Does not fit any standard category'],
    ];

    $categories = [];

    $simple_types = [
        'Expense' => $expense_categories,
        'Income' => $income_categories,
        'Referral' => $referral_categories,
    ];
    foreach ($simple_types as $type => $rows) {
        foreach ($rows as $row) {
            $categories[] = [
                'type' => $type,
                'name' => $row[0],
                'color' => $row[1],
                'icon' => '',
                'description' => $row[2],
                'order' => 0,
            ];
        }
    }

    $order = 1;
    foreach ($ticket_categories as $row) {
        $categories[] = [
            'type' => 'Ticket',
            'name' => $row[0],
            'color' => $row[1],
            'icon' => $row[2],
            'description' => $row[3],
            'order' => $order,
        ];
        $order++;
    }

    return $categories;

}

// ------------------------------
// starterContentTags
// tag_type 1 Client, 2 Location, 3 Contact, 4 Credential, 5 Asset.
// tag_icon is stored without the fa- prefix, the views add it.
// ------------------------------
function starterContentTags() {

    $tags = [
        // Client
        [1, 'Managed Care', '#28a745', 'handshake'],
        [1, 'Co-Managed IT', '#20c997', 'people-arrows'],
        [1, 'Hourly Support', '#fd7e14', 'wrench'],
        [1, 'Block Hours', '#6f42c1', 'hourglass-half'],
        [1, 'Project Only', '#795548', 'project-diagram'],
        [1, 'Prospect', '#17a2b8', 'binoculars'],
        [1, 'Onboarding', '#007bff', 'user-plus'],
        [1, 'Offboarding', '#dc3545', 'user-minus'],
        [1, 'Key Account', '#ffc107', 'star'],
        [1, 'At Risk', '#d81b60', 'exclamation-triangle'],
        [1, 'Past Due', '#dc3545', 'file-invoice-dollar'],
        [1, 'Service Hold', '#6c757d', 'pause-circle'],
        [1, 'Multi-Site', '#001f3f', 'map-marked-alt'],
        [1, 'After-Hours Authorized', '#343a40', 'moon'],
        [1, 'Compliance Scope', '#795548', 'balance-scale'],
        [1, 'Cyber Insurance', '#6610f2', 'shield-alt'],
        [1, 'Internal', '#6c757d', 'building'],

        // Location
        [2, 'Headquarters', '#007bff', 'building'],
        [2, 'Branch', '#17a2b8', 'store'],
        [2, 'Remote Office', '#20c997', 'laptop-house'],
        [2, 'Home Office', '#20c997', 'house-user'],
        [2, 'Warehouse', '#795548', 'warehouse'],
        [2, 'Retail', '#e83e8c', 'cash-register'],
        [2, 'Data Center', '#001f3f', 'server'],
        [2, 'Restricted Access', '#dc3545', 'lock'],
        [2, 'Onsite Spares', '#6c757d', 'boxes'],
        [2, 'After-Hours Access Required', '#343a40', 'moon'],

        // Contact
        [3, 'Primary Contact', '#007bff', 'user-tie'],
        [3, 'Technical Contact', '#17a2b8', 'laptop-code'],
        [3, 'Billing Contact', '#28a745', 'file-invoice-dollar'],
        [3, 'Authorized Approver', '#6f42c1', 'check-double'],
        [3, 'Executive Sponsor', '#001f3f', 'crown'],
        [3, 'Emergency Contact', '#dc3545', 'phone-volume'],
        [3, 'After-Hours Contact', '#343a40', 'moon'],
        [3, 'Onsite Contact', '#20c997', 'map-marker-alt'],
        [3, 'Security Contact', '#6610f2', 'shield-alt'],
        [3, 'HR Contact', '#e83e8c', 'users'],
        [3, 'Not Authorized', '#6c757d', 'ban'],
        [3, 'Former Contact', '#795548', 'user-slash'],

        // Credential
        [4, 'Privileged Admin', '#dc3545', 'user-shield'],
        [4, 'Service Account', '#6c757d', 'robot'],
        [4, 'Break Glass', '#d81b60', 'fire-extinguisher'],
        [4, 'API / Integration', '#17a2b8', 'plug'],
        [4, 'Shared', '#ffc107', 'users'],
        [4, 'MFA Protected', '#28a745', 'shield-alt'],
        [4, 'Rotation Required', '#6f42c1', 'sync-alt'],
        [4, 'Client-Owned', '#795548', 'user-tag'],
        [4, 'Provider-Managed', '#20c997', 'user-cog'],
        [4, 'Firewall', '#001f3f', 'shield-alt'],
        [4, 'Network', '#6610f2', 'network-wired'],
        [4, 'Hypervisor', '#343a40', 'server'],
        [4, 'Backup Console', '#3d9970', 'database'],
        [4, 'Microsoft 365', '#0078d4', 'cloud'],
        [4, 'Registrar / DNS', '#20c997', 'globe'],
        [4, 'Hosting', '#795548', 'hdd'],
        [4, 'Vendor Portal', '#adb5bd', 'external-link-alt'],
        [4, 'Legacy / Replace', '#adb5bd', 'exclamation-triangle'],

        // Asset
        [5, 'Business Critical', '#d81b60', 'exclamation-circle'],
        [5, 'Internet-Facing', '#dc3545', 'globe'],
        [5, 'Monitored', '#3d9970', 'heartbeat'],
        [5, 'Backed Up', '#001f3f', 'database'],
        [5, 'Backup Exception', '#ffc107', 'exclamation-triangle'],
        [5, 'Endpoint Protection', '#6f42c1', 'user-shield'],
        [5, 'Encryption Verified', '#28a745', 'lock'],
        [5, 'Patch Exception', '#ffc107', 'ban'],
        [5, 'Under Warranty', '#28a745', 'certificate'],
        [5, 'Warranty Expired', '#fd7e14', 'calendar-times'],
        [5, 'End of Life', '#adb5bd', 'skull-crossbones'],
        [5, 'Replacement Planned', '#6f42c1', 'calendar-check'],
        [5, 'Leased', '#6c757d', 'file-contract'],
        [5, 'Spare Stock', '#795548', 'boxes'],
        [5, 'Loaner', '#17a2b8', 'exchange-alt'],
    ];

    return $tags;

}

// ------------------------------
// starterContentTicketTemplates
// Details are stored as HTML - the modals edit them through TinyMCE.
// Task estimates are in minutes.
// ------------------------------
function starterContentTicketTemplates() {

    $ticket_templates = [
        [
            'name' => 'User Onboarding',
            'description' => 'Authorized account and access setup for a new user',
            'subject' => 'User onboarding - [Employee Name]',
            'details' => '<p>Do not begin until an authorized approver has confirmed the identity, start date, manager, role, location and required access. Device deployment is a separate scope unless explicitly included.</p>',
            'runbook_key' => 'user-onboarding',
            'runbook_type' => 'onboarding',
            'publish_runbook' => true,
            'tasks' => starterCanonicalRunbookTaskSequence('USR', 'user onboarding', [
                ['Validate the request and approval against the authorized contact list', 15],
                ['Confirm start time, manager, role, location and access baseline', 15],
                ['Create the Entra ID or directory account with a temporary access method', 15],
                ['Assign the approved Microsoft 365 and application licenses', 10],
                ['Configure mailbox aliases, groups and shared resources using least privilege', 25],
                ['Configure approved line-of-business application access', 20],
                ['Enroll multi-factor authentication and verify recovery methods', 20],
                ['Link or create the device deployment work when required', 10],
                ['Verify sign-in, mail, file access and required applications', 20],
                ['Record the user, approvals, licenses and access in ITFlow', 15],
                ['Send the requester a completion summary without transmitting passwords', 10],
            ], 1),
        ],
        [
            'name' => 'User Offboarding',
            'description' => 'Authorized access revocation and data handoff for a departing user',
            'subject' => 'User offboarding - [Employee Name]',
            'details' => '<p>Do not begin without written authorization from an authorized contact. Confirm the effective time, legal-hold or retention requirements, data owner and device disposition before making changes.</p>',
            'runbook_key' => 'user-offboarding',
            'runbook_type' => 'offboarding',
            'publish_runbook' => true,
            'tasks' => starterCanonicalRunbookTaskSequence('TRM', 'user offboarding', [
                ['Validate the authorization, effective time and data-retention decision', 15],
                ['Block sign-in and reset the account credentials', 10],
                ['Revoke sessions, refresh tokens and registered authentication methods', 15],
                ['Remove privileged roles, groups, shared access and application sessions', 20],
                ['Apply the approved mailbox conversion, delegation, forwarding and auto-reply', 20],
                ['Transfer OneDrive, SharePoint and line-of-business data to the approved owner', 30],
                ['Record and coordinate the return of devices, keys and access cards', 20],
                ['Quarantine or reassign managed devices; do not wipe without separate approval', 20],
                ['Recover licenses only after retention and access requirements are satisfied', 15],
                ['Update ITFlow contacts, assets, documentation and billing records', 15],
                ['Send the authorized requester a completion and exception summary', 10],
            ], 0),
        ],
        [
            'name' => 'Access Change',
            'description' => 'Authorized least-privilege access addition, modification or removal',
            'subject' => 'Access change - [Client] - [User]',
            'details' => '<p>Use only the access scope approved in the portal request. Confirm the target identity, system owner, business reason, timing and current access before changing anything. Record the before-and-after state, do not copy secrets into ITFlow, and stop for new approval if the requested scope expands.</p>',
            'runbook_key' => 'access-change',
            'runbook_type' => 'standard',
            'publish_runbook' => true,
            'tasks' => [
                [
                    'key' => 'ACC-010',
                    'name' => 'ACC-010 Validate authority, identity and requested scope',
                    'instructions' => 'Confirm the immutable portal request and approval, the target user belongs to the client, the requested system or data owner, the add, modify or remove action, the business reason and the required effective time. Stop if the request is ambiguous, conflicts with policy or exceeds the recorded approval.',
                    'estimate' => 20,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 2,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the requester, approver, target identity, approved scope and effective time without secret values.',
                    'depends_on' => [],
                ],
                [
                    'key' => 'ACC-020',
                    'name' => 'ACC-020 Capture current access and classify risk',
                    'instructions' => 'Capture the current roles, groups, licenses, sharing, delegated permissions and application access needed to verify the change and recover from error. Identify privileged, shared, regulated or externally managed access and any separation-of-duties conflict.',
                    'estimate' => 25,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 4,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach a redacted current-access export or screenshot set that contains no credentials or tokens.',
                    'depends_on' => ['ACC-010'],
                ],
                [
                    'key' => 'ACC-030',
                    'name' => 'ACC-030 Approve the least-privilege plan and rollback',
                    'instructions' => 'Write the exact additions, removals and retained access; the execution time; validation owner; temporary-access expiry when applicable; session-revocation impact; and rollback steps. Obtain technical client approval for that plan. A prior request approval does not authorize a broader implementation plan.',
                    'estimate' => 25,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 8,
                    'initial_state' => 'Waiting',
                    'approval_scope' => 'client',
                    'approval_type' => 'technical',
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the least-privilege plan, rollback trigger, expiry or review date and approval decision.',
                    'depends_on' => ['ACC-010', 'ACC-020'],
                ],
                [
                    'key' => 'ACC-040',
                    'name' => 'ACC-040 Apply only the approved access change',
                    'instructions' => 'Use an attributable administrative identity and apply only the approved additions, modifications or removals at the approved time. Stop if the live state differs materially from the captured baseline or if the requested result requires additional privilege.',
                    'estimate' => 30,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 12,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the systems changed, actions taken, administrator identity and completion timestamps.',
                    'depends_on' => ['ACC-030'],
                ],
                [
                    'key' => 'ACC-050',
                    'name' => 'ACC-050 Revoke sessions and remove obsolete access',
                    'instructions' => 'When access is removed or reduced, revoke affected sessions or tokens where appropriate and remove superseded group, role, sharing and application grants. Do not disable the account or remove unrelated access unless separately authorized.',
                    'estimate' => 20,
                    'condition_type' => 'manual_confirm',
                    'condition_value' => 'The approved request removes access or reduces privilege',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 14,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the revoked sessions and removed grants, or the reason this conditional task was not applicable.',
                    'depends_on' => ['ACC-040'],
                ],
                [
                    'key' => 'ACC-060',
                    'name' => 'ACC-060 Validate intended access and unintended privilege',
                    'instructions' => 'Test the approved access outcome with the target user or authorized validator. Confirm required access works, removed access fails and no unintended role or data exposure was introduced. If any success check fails or an approved rollback trigger is met, execute the approved rollback, revalidate the restored baseline and keep the ticket open until a stable state is proven.',
                    'estimate' => 25,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 18,
                    'evidence_type' => 'any',
                    'evidence_prompt' => 'Attach or record the positive and negative validation results, any rollback and restored-baseline results, and any exception owner.',
                    'depends_on' => ['ACC-040', 'ACC-050'],
                ],
                [
                    'key' => 'ACC-070',
                    'name' => 'ACC-070 Record the access decision and closeout',
                    'instructions' => 'Update the client access record with the approved before-and-after state, approver, effective time, temporary-access expiry or review date, validation evidence and unresolved exception. Send the authorized requester a completion summary without credentials or sensitive access detail.',
                    'estimate' => 20,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 24,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the documentation updated, review or expiry date, communication sent and unresolved exceptions.',
                    'depends_on' => ['ACC-060'],
                ],
            ],
        ],
        [
            'name' => 'Scheduled Work',
            'description' => 'Plan, authorize, execute and validate coordinated maintenance or change work',
            'subject' => 'Scheduled work - [Client] - [Work Summary]',
            'details' => '<p>Convert the approved portal request into a bounded implementation plan with a maintenance window, affected services and users, communications, validation criteria and rollback decision. Do not treat scheduling approval as permission to expand scope, and do not make a production change without the required current backup or recovery path.</p>',
            'runbook_key' => 'scheduled-work',
            'runbook_type' => 'standard',
            'publish_runbook' => true,
            'tasks' => [
                [
                    'key' => 'SCH-010',
                    'name' => 'SCH-010 Validate the approved request and work boundary',
                    'instructions' => 'Confirm the immutable portal request and approval, requested outcome, systems and locations in scope, preferred time, duration, expected impact, business owner and exclusions. Stop if the request is too broad to implement safely as one work item.',
                    'estimate' => 20,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 4,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the requester, approver, scope, exclusions, desired outcome and scheduling constraints.',
                    'depends_on' => [],
                ],
                [
                    'key' => 'SCH-020',
                    'name' => 'SCH-020 Confirm the window, owners and communications',
                    'instructions' => 'Confirm the maintenance window and timezone, outage tolerance, technical and business contacts, vendor dependencies, access prerequisites, notification audience and escalation path. Resolve scheduling conflicts before implementation planning is approved.',
                    'estimate' => 25,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 8,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the agreed window, timezone, contacts, access readiness and communication audience.',
                    'depends_on' => ['SCH-010'],
                ],
                [
                    'key' => 'SCH-030',
                    'name' => 'SCH-030 Approve implementation, validation and rollback',
                    'instructions' => 'Document the ordered implementation steps, owner for each step, success checks, maximum outage, stop conditions, rollback trigger and recovery steps. Obtain technical client approval of the executable plan; any later material scope change requires a new decision.',
                    'estimate' => 35,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 16,
                    'initial_state' => 'Waiting',
                    'approval_scope' => 'client',
                    'approval_type' => 'technical',
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the approved implementation, validation and rollback plan.',
                    'depends_on' => ['SCH-010', 'SCH-020'],
                ],
                [
                    'key' => 'SCH-040',
                    'name' => 'SCH-040 Capture the recoverable pre-change state',
                    'instructions' => 'For configuration-changing work, capture current configuration, service health and a verified backup or other recoverable state appropriate to the system. Record where recovery material is stored and test that the authorized technician can access it.',
                    'estimate' => 30,
                    'condition_type' => 'manual_confirm',
                    'condition_value' => 'The scheduled work changes production configuration or data',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 20,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the redacted pre-change state and backup or recovery verification, or record why no production change is involved.',
                    'depends_on' => ['SCH-030'],
                ],
                [
                    'key' => 'SCH-050',
                    'name' => 'SCH-050 Release the maintenance communication',
                    'instructions' => 'Send the approved advance notice with scope, window, expected impact, contact path and status-update cadence. Confirm required client, vendor and technician participants acknowledge the window before work begins.',
                    'estimate' => 15,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 22,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the notice recipients, send time, acknowledgements and outstanding coordination risks.',
                    'depends_on' => ['SCH-020', 'SCH-030'],
                ],
                [
                    'key' => 'SCH-060',
                    'name' => 'SCH-060 Execute the approved work and log decisions',
                    'instructions' => 'Start only inside the approved window with required participants available. Follow the approved sequence, record timestamps and results, stop at decision points and do not improvise outside scope. Execute rollback when its trigger is met.',
                    'estimate' => 60,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 30,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record actual start and finish times, actions, decision points, deviations and any rollback action.',
                    'depends_on' => ['SCH-040', 'SCH-050'],
                ],
                [
                    'key' => 'SCH-070',
                    'name' => 'SCH-070 Validate service and obtain acceptance',
                    'instructions' => 'Run every technical and user-impact validation in the approved plan, confirm monitoring and dependent services are healthy, compare results with the baseline and obtain acceptance from the authorized validator. Reopen the rollback decision if any success criterion fails.',
                    'estimate' => 30,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 34,
                    'evidence_type' => 'any',
                    'evidence_prompt' => 'Attach or record the validation results, monitoring state, acceptance and unresolved exceptions.',
                    'depends_on' => ['SCH-060'],
                ],
                [
                    'key' => 'SCH-080',
                    'name' => 'SCH-080 Update records and send the closeout',
                    'instructions' => 'Update affected asset, network, configuration, vendor, service and support documentation; link follow-up work rather than hiding it in this ticket; and send the authorized requester a concise outcome, impact and exception summary.',
                    'estimate' => 25,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 40,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the documentation changed, closeout communication and linked follow-up ticket IDs.',
                    'depends_on' => ['SCH-070'],
                ],
            ],
        ],
        [
            'name' => 'Device Deployment',
            'description' => 'Build, secure, document and hand over a desktop or laptop',
            'subject' => 'Device deployment - [Client] - [User]',
            'details' => '<p>Confirm the approved quote, assigned user, management scope and old-device disposition. Security, backup and software products must only be installed when included in the client agreement or project scope.</p>',
            'runbook_key' => 'device-deployment',
            'runbook_type' => 'standard',
            'publish_runbook' => true,
            'tasks' => starterCanonicalRunbookTaskSequence('DEV', 'device deployment', [
                ['Confirm the quote or purchase order', 10],
                ['Receive, inventory and create the asset record', 20],
                ['Record the serial number and warranty expiry', 10],
                ['Update firmware, operating system and drivers', 60],
                ['Join Entra ID or the approved directory and verify device ownership', 20],
                ['Configure encryption and verify the recovery key is escrowed', 15],
                ['Install the approved standard and line-of-business applications', 45],
                ['Install and verify the Level agent for managed devices', 15],
                ['Install approved endpoint protection and backup products when in scope', 20],
                ['Migrate the user profile and data after confirming a recoverable source copy', 60],
                ['Verify mail, file synchronization, printing and required applications', 25],
                ['Confirm the user has no unintended local administrator access', 10],
                ['Deliver the device, obtain acceptance and record the handoff', 20],
                ['Route the old device to the separately approved reuse, retention or disposal process', 15],
            ], 0),
        ],
        [
            'name' => 'Server Deployment',
            'description' => 'Build and commission a new physical or virtual server',
            'subject' => 'Server deployment - [Client] - [Server Name]',
            'details' => '<p>New server build. Confirm the role, sizing, network placement and maintenance window before starting.</p>',
            'tasks' => [
                ['Confirm the specification, role and sizing', 30],
                ['Rack, cable and label the hardware', 60],
                ['Configure out of band management', 30],
                ['Install the operating system or hypervisor and patch fully', 90],
                ['Configure storage, RAID and volumes', 45],
                ['Set the static addressing, DNS and time source', 20],
                ['Install the server role and application software', 60],
                ['Add to RMM, monitoring and endpoint protection', 20],
                ['Add to the backup job and run a first full backup', 45],
                ['Document the build and hand over to the client', 30],
            ],
        ],
        [
            'name' => 'Managed Care Monthly Review',
            'description' => 'Exception-based monthly operational review for a managed client',
            'subject' => 'Managed Care review - [Client] - [Month]',
            'details' => '<p>Review automated operations by exception. Do not repeat healthy automated checks manually; investigate exceptions, record decisions and raise separately scoped work when required.</p>',
            'tasks' => [
                ['Review Level device health, stale agents and unresolved alerts', 20],
                ['Review patch exceptions, failed jobs and pending reboot exposure', 20],
                ['Review security alerts only for products active in the client scope', 20],
                ['Review backup exceptions and the most recent restore evidence when backup is in scope', 20],
                ['Review asset lifecycle, warranty and unsupported operating systems', 20],
                ['Review Microsoft 365 license and privileged-access exceptions', 20],
                ['Review network monitoring, firmware and subscription exceptions', 20],
                ['Check domain, certificate and critical vendor renewal exposure', 15],
                ['Reconcile ITFlow records with Level and other connected systems', 20],
                ['Raise prioritized remediation tickets instead of hiding work in this review', 15],
                ['Send a concise outcome and exception summary to the client', 20],
            ],
        ],
        [
            'name' => 'Backup Failure Investigation',
            'description' => 'Triage and resolve a failed or missed backup job',
            'subject' => 'Backup failure - [Client] - [Job Name]',
            'details' => '<p>Backup job failed or did not report. Establish the last known good restore point first, then work the cause.</p>',
            'tasks' => [
                ['Identify the last successful restore point', 15],
                ['Review the job log and error detail', 20],
                ['Check source, target and network availability', 20],
                ['Check free space on the backup target', 10],
                ['Check agent and service health on the protected system', 20],
                ['Apply the fix and re-run the job', 30],
                ['Verify the job completes and the restore point is valid', 20],
                ['Document the cause and update the client', 20],
            ],
        ],
        [
            'name' => 'Backup Restore Test',
            'description' => 'Scheduled proof that backups can actually be restored',
            'subject' => 'Restore test - [Client] - [Quarter]',
            'details' => '<p>Scheduled restore test. The goal is a documented, dated proof of recoverability - record the actual recovery time achieved.</p>',
            'tasks' => [
                ['Agree the restore targets and test window with the client', 20],
                ['Restore a file level sample and verify contents', 30],
                ['Perform a full system or virtual machine test restore', 90],
                ['Verify the restored system boots and services start', 45],
                ['Verify application and database integrity', 45],
                ['Record the recovery time actually achieved', 15],
                ['Tear down the test environment', 20],
                ['Document the result and report to the client', 30],
            ],
        ],
        [
            'name' => 'Phishing Report',
            'description' => 'User reported a suspicious email',
            'subject' => 'Reported phishing email - [Client] - [User]',
            'details' => '<p>User reported a suspicious message. Treat as credential compromise until proven otherwise - the first question is always whether credentials were entered.</p>',
            'tasks' => [
                ['Obtain the message with full headers', 15],
                ['Analyze the sender, links and attachments', 20],
                ['Establish whether credentials were entered or attachments opened', 15],
                ['Force a password reset and revoke sessions if in doubt', 20],
                ['Audit mailbox rules and forwarding', 20],
                ['Review sign-in logs for anomalous access', 30],
                ['Block the sender and domain at the mail gateway', 15],
                ['Purge the message from all affected mailboxes', 20],
                ['Feed back to the reporting user and management', 15],
                ['Document the outcome', 15],
            ],
        ],
        [
            'name' => 'Security Incident Response',
            'description' => 'Suspected or confirmed compromise',
            'subject' => 'Security incident - [Client] - [Summary]',
            'details' => '<p>Suspected or confirmed compromise. Preserve evidence before remediating, and check the client cyber insurance policy for notification requirements and approved responders before acting.</p>',
            'runbook_key' => 'incident-response',
            'runbook_type' => 'standard',
            'publish_runbook' => true,
            'tasks' => starterCanonicalRunbookTaskSequence('INC', 'incident response', [
                ['Declare the incident and establish scope and impact', 30],
                ['Isolate affected systems and accounts', 30],
                ['Preserve logs, images and evidence', 60],
                ['Notify the authorized client decision maker and identify insurer, legal and regulatory requirements', 30],
                ['Obtain authorization before any external insurer, legal or regulatory notification', 15],
                ['Contain the spread and close the entry point', 120],
                ['Eradicate persistence and reset all affected credentials', 120],
                ['Restore from a known good backup and verify', 180],
                ['Monitor for reinfection', 60],
                ['Produce the post incident report and remediation plan', 90],
            ]),
        ],
        [
            'name' => 'Managed Care Onboarding',
            'description' => 'Bring a signed client into the documented Managed Care operating model with the approved CIPP and Autopilot baseline',
            'subject' => 'Managed Care onboarding - [Client]',
            'details' => '<p>Do not deploy tools or assume administrative control until the agreement, onboarding scope and authorized contacts are confirmed. Deploy the CIPP standards in the listed order and run Remediate because these standards are manual. Do not start the Autopilot Enrollment Status Page until both Level and SentinelOne readiness variables are true. Conditional Access is a separate, approval-gated stage after licensing and emergency-access accounts are verified. The output is a documented client with explicit supported and excluded systems and a validated pilot device.</p>',
            'runbook_key' => 'managed-care-onboarding',
            'runbook_type' => 'onboarding',
            'publish_runbook' => true,
            'tasks' => [
                [
                    'key' => 'ONB-010',
                    'name' => 'ONB-010 Verify countersigned agreement and authority',
                    'instructions' => 'Attach the countersigned agreement and confirm the legal client name, authorized signers, service start date, notice terms and authority to approve onboarding work. Do not assume administrative control before this gate passes.',
                    'estimate' => 20,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 8,
                    'initial_state' => 'Waiting',
                    'approval_scope' => 'client',
                    'approval_type' => 'billing',
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the countersigned agreement or the approved agreement record.',
                    'depends_on' => [],
                ],
                [
                    'key' => 'ONB-020',
                    'name' => 'ONB-020 Confirm scope, exclusions and deliverables',
                    'instructions' => 'Translate the agreement into explicit supported users, devices, sites, services, minimum billing, onboarding deliverables, exclusions and separately billable work. Record unresolved scope questions as blockers.',
                    'estimate' => 35,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 16,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the agreed scope, exclusions and any open commercial decisions.',
                    'depends_on' => ['ONB-010'],
                ],
                [
                    'key' => 'ONB-030',
                    'name' => 'ONB-030 Hold kickoff and confirm escalation paths',
                    'instructions' => 'Confirm primary, technical, billing, security and emergency contacts; maintenance restrictions; approval authority; communication cadence; incident escalation; and third-party dependencies.',
                    'estimate' => 60,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 24,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record attendees, decisions, contacts and escalation paths.',
                    'depends_on' => ['ONB-020'],
                ],
                [
                    'key' => 'ONB-040',
                    'name' => 'ONB-040 Normalize core client records in ITFlow',
                    'instructions' => 'Create or reconcile the client, locations, contacts, vendors, domains and services. Use durable external tenant, site and account identifiers where available and resolve duplicates before continuing.',
                    'estimate' => 75,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 32,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record created records, merged duplicates and unresolved identity conflicts.',
                    'depends_on' => ['ONB-030'],
                ],
                [
                    'key' => 'ONB-050',
                    'name' => 'ONB-050 Configure and test client portal access',
                    'instructions' => 'Assign least-privilege portal roles only to authorized contacts, send invitations through the approved channel, verify sign-in at the portal hostname, and test that client data and approvals are correctly scoped.',
                    'estimate' => 35,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 48,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record invited contacts, assigned roles and successful scoped portal test.',
                    'depends_on' => ['ONB-040'],
                ],
                [
                    'key' => 'ONB-060',
                    'name' => 'ONB-060 Discover networks and critical dependencies',
                    'instructions' => 'Document sites, circuits, public addressing, firewalls, switching, wireless, VLANs, critical applications, remote access, business owners and outage constraints without making unapproved production changes.',
                    'estimate' => 180,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 72,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the discovery worksheet or current-state network export.',
                    'depends_on' => ['ONB-030'],
                ],
                [
                    'key' => 'ONB-070',
                    'name' => 'ONB-070 Inventory users, assets and external identities',
                    'instructions' => 'Inventory active users and devices, capture serials and durable source identifiers, map each object to the correct client and site, and queue ambiguous, duplicate or cross-client matches for review.',
                    'estimate' => 180,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 96,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the normalized inventory and record all reconciliation exceptions.',
                    'depends_on' => ['ONB-040', 'ONB-060'],
                ],
                [
                    'key' => 'ONB-080',
                    'name' => 'ONB-080 Validate Microsoft licensing and Windows editions',
                    'instructions' => 'Confirm licensing supports the intended Entra, Intune, Autopilot, security and application controls. Identify unsupported Windows editions and separate workstation scope from Windows Server scope.',
                    'estimate' => 35,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Microsoft 365',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 104,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record license counts, supported editions, gaps and approved remediation.',
                    'depends_on' => ['ONB-070'],
                ],
                [
                    'key' => 'ONB-090',
                    'name' => 'ONB-090 Establish GDAP relationship and least privilege',
                    'instructions' => 'Create or validate the GDAP relationship using only approved roles, confirm the customer and tenant identifiers, record expiration and renewal ownership, and verify emergency access does not depend on delegated access.',
                    'estimate' => 45,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Microsoft 365',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 112,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record tenant ID, GDAP relationship ID, roles, expiration and validation result.',
                    'depends_on' => ['ONB-030', 'ONB-040'],
                ],
                [
                    'key' => 'ONB-100',
                    'name' => 'ONB-100 Register and validate the tenant in CIPP',
                    'instructions' => 'Map the correct customer and tenant in CIPP, verify GDAP-backed access, confirm default domains and customer variables, and stop on any tenant-to-client ownership conflict.',
                    'estimate' => 35,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Microsoft 365',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 120,
                    'evidence_type' => 'url',
                    'evidence_prompt' => 'Link to the scoped CIPP tenant record or validation evidence.',
                    'depends_on' => ['ONB-090'],
                ],
                [
                    'key' => 'ONB-110',
                    'name' => 'ONB-110 Capture CIPP pre-change baseline',
                    'instructions' => 'Run the approved CIPP preflight and export the current standards, policies, exclusions, privileged roles and detected conflicts before remediation. Preserve the export as rollback and audit evidence.',
                    'estimate' => 45,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Microsoft 365',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 128,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the dated CIPP pre-change export and exception list.',
                    'depends_on' => ['ONB-100'],
                ],
                [
                    'key' => 'ONB-120',
                    'name' => 'ONB-120 Deploy Windows client assignment filter',
                    'instructions' => 'Deploy the N45 Windows Client OS assignment filter and prove that Windows Server and unsupported editions are excluded. Do not use a broad all-device assignment for workstation policies or applications.',
                    'estimate' => 25,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Intune',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 136,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the filter rule, assignments and a Windows Server exclusion test.',
                    'depends_on' => ['ONB-080', 'ONB-110'],
                ],
                [
                    'key' => 'ONB-130',
                    'name' => 'ONB-130 Assign and remediate onboarding core',
                    'instructions' => 'Assign N45-05-Onboarding-Core to the validated target and run Remediate. Review failures and conflicts before proceeding; do not mark a manual standard complete based only on assignment.',
                    'estimate' => 40,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Microsoft 365',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 144,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record remediation status, failures, conflicts and approved exceptions.',
                    'depends_on' => ['ONB-110'],
                ],
                [
                    'key' => 'ONB-140',
                    'name' => 'ONB-140 Assign and remediate Intune workstation baseline',
                    'instructions' => 'Assign N45-20-Intune-Workstation through the Windows client filter, run Remediate, and validate policy targeting without applying workstation settings to Windows Server.',
                    'estimate' => 50,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Intune',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 152,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record remediation outcome, targeting and policy conflicts.',
                    'depends_on' => ['ONB-120', 'ONB-130'],
                ],
                [
                    'key' => 'ONB-150',
                    'name' => 'ONB-150 Assign and remediate provisioning baseline',
                    'instructions' => 'Assign N45-30-Provisioning to the approved pilot scope, run Remediate, and validate Autopilot groups, Enrollment Status Page prerequisites and enrollment restrictions.',
                    'estimate' => 50,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Intune',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 160,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record provisioning assignments, remediation status and exceptions.',
                    'depends_on' => ['ONB-140'],
                ],
                [
                    'key' => 'ONB-160',
                    'name' => 'ONB-160 Assign and remediate application baseline',
                    'instructions' => 'Assign N45-40-Applications only to the approved Windows client scope, run Remediate, and confirm required versus available application intent before pilot enrollment.',
                    'estimate' => 50,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Intune',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 168,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record application assignments, required intent, available intent and exclusions.',
                    'depends_on' => ['ONB-150'],
                ],
                [
                    'key' => 'ONB-170',
                    'name' => 'ONB-170 Assign and remediate update baseline',
                    'instructions' => 'Assign N45-50-Updates through the workstation filter, run Remediate, and document rings, deadlines, restart behavior and any approved business-critical exclusions.',
                    'estimate' => 40,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Intune',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 176,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record update-ring assignments, remediation result and approved exclusions.',
                    'depends_on' => ['ONB-140'],
                ],
                [
                    'key' => 'ONB-180',
                    'name' => 'ONB-180 Validate installation/detection scripts',
                    'instructions' => 'Review installation, uninstall and detection scripts for architecture, exit codes, retry behavior, version comparison, safe logging and idempotency. Test required app detection without exposing tokens or secrets.',
                    'estimate' => 75,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Intune',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 184,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach redacted install and detection test results for the required applications.',
                    'depends_on' => ['ONB-160'],
                ],
                [
                    'key' => 'ONB-190',
                    'name' => 'ONB-190 Create and map the Level group',
                    'instructions' => 'Create or identify the correct Level group or site, record its immutable ID, map it to the ITFlow client, and resolve any cross-client or duplicate group conflict before agent deployment.',
                    'estimate' => 35,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Level',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 144,
                    'evidence_type' => 'url',
                    'evidence_prompt' => 'Link to the scoped Level group and record its immutable ID.',
                    'depends_on' => ['ONB-040', 'ONB-070'],
                ],
                [
                    'key' => 'ONB-200',
                    'name' => 'ONB-200 Stage Level deployment variables safely',
                    'instructions' => 'Set the approved CIPP levelgroupid and related deployment variables in the protected configuration path. Keep leveldeploymentready false until pilot installation, check-in and mapping are proven.',
                    'estimate' => 30,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Level',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 152,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record variable names, non-secret values and readiness state; never attach tokens.',
                    'depends_on' => ['ONB-190'],
                ],
                [
                    'key' => 'ONB-210',
                    'name' => 'ONB-210 Install and validate the Level pilot agent',
                    'instructions' => 'Install Level on one approved Windows client, verify service health, durable device ID, correct group, check-in, remote management and ITFlow identity mapping. Investigate duplicates rather than remapping automatically.',
                    'estimate' => 60,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Level',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 192,
                    'evidence_type' => 'url',
                    'evidence_prompt' => 'Link to the pilot Level device and record the successful mapping and check-in.',
                    'depends_on' => ['ONB-200'],
                ],
                [
                    'key' => 'ONB-220',
                    'name' => 'ONB-220 Create and map the SentinelOne site',
                    'instructions' => 'Create or identify the correct SentinelOne site, map it to the ITFlow client, set CIPP s1siteid, and place s1sitetoken only in the approved secret store.',
                    'estimate' => 35,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'SentinelOne',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 152,
                    'evidence_type' => 'url',
                    'evidence_prompt' => 'Link to the scoped SentinelOne site and record its immutable ID without the token.',
                    'depends_on' => ['ONB-040', 'ONB-070'],
                ],
                [
                    'key' => 'ONB-230',
                    'name' => 'ONB-230 Install and validate the SentinelOne pilot agent',
                    'instructions' => 'Install SentinelOne on the approved pilot, verify the agent ID, site, policy, protection state, last-active state, console visibility and ITFlow identity mapping. Resolve any competing endpoint protection deliberately.',
                    'estimate' => 60,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'SentinelOne',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 192,
                    'evidence_type' => 'url',
                    'evidence_prompt' => 'Link to the healthy pilot agent and record policy and mapping validation.',
                    'depends_on' => ['ONB-220'],
                ],
                [
                    'key' => 'ONB-240',
                    'name' => 'ONB-240 Release endpoint deployment readiness gates',
                    'instructions' => 'Confirm which Level and SentinelOne services are in scope. Set leveldeploymentready or s1deploymentready true only after the corresponding pilot passes; record excluded products explicitly and never invent readiness.',
                    'estimate' => 25,
                    'condition_type' => 'manual_confirm',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 200,
                    'initial_state' => 'Waiting',
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record each readiness flag, supporting pilot result and any out-of-scope product.',
                    'depends_on' => ['ONB-210', 'ONB-230'],
                ],
                [
                    'key' => 'ONB-250',
                    'name' => 'ONB-250 Register the Autopilot pilot device',
                    'instructions' => 'Register one representative Windows client through OEM registration or a verified hardware-hash import, reconcile the serial and Entra device identity, and confirm the intended ownership and group.',
                    'estimate' => 40,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Intune',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 208,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record pilot serial, Autopilot identity, group and assignment state.',
                    'depends_on' => ['ONB-080', 'ONB-150', 'ONB-240'],
                ],
                [
                    'key' => 'ONB-260',
                    'name' => 'ONB-260 Assign Autopilot Production before OOBE',
                    'instructions' => 'Verify the Autopilot Production profile and Enrollment Status Page target the pilot before OOBE. Confirm readiness variables, user-driven mode, naming behavior and required application set.',
                    'estimate' => 30,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Intune',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 216,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record profile, ESP and group assignment status before reset.',
                    'depends_on' => ['ONB-250'],
                ],
                [
                    'key' => 'ONB-270',
                    'name' => 'ONB-270 Run OOBE and validate ESP required installs',
                    'instructions' => 'Reset the pilot to OOBE when required and complete Autopilot. Prove ESP installs and detects Company Portal, LevelRMM, Microsoft 365 Apps and SentinelOne when each is in scope; capture actionable failure detail.',
                    'estimate' => 90,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Intune',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 240,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the redacted ESP result and required app install and detection evidence.',
                    'depends_on' => ['ONB-180', 'ONB-260'],
                ],
                [
                    'key' => 'ONB-280',
                    'name' => 'ONB-280 Validate Entra and Intune device security',
                    'instructions' => 'Verify Entra join, Intune enrollment, primary user, compliance, BitLocker recovery-key escrow, LAPS, Windows Hello, Secure Boot, update status and least-privilege local administration.',
                    'estimate' => 60,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Intune',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 248,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record each control result, identifiers and approved exceptions without recovery secrets.',
                    'depends_on' => ['ONB-270'],
                ],
                [
                    'key' => 'ONB-290',
                    'name' => 'ONB-290 Confirm optional application availability',
                    'instructions' => 'Confirm whether Firefox, PuTTY, Visual Studio Code and other approved self-service apps should be available but not ESP-required. Record applicability and avoid silently adding unlicensed software.',
                    'estimate' => 20,
                    'condition_type' => 'manual_confirm',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 248,
                    'initial_state' => 'Waiting',
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the approved available-app list or mark it not applicable.',
                    'depends_on' => ['ONB-270'],
                ],
                [
                    'key' => 'ONB-300',
                    'name' => 'ONB-300 Approve and stage Conditional Access',
                    'instructions' => 'Verify licensing and two tested emergency-access accounts, review exclusions and user impact, stage N45-10-Identity-CA in report-only mode, and obtain approval before enforcement.',
                    'estimate' => 60,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Microsoft 365',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 264,
                    'initial_state' => 'Waiting',
                    'approval_scope' => 'client',
                    'approval_type' => 'technical',
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the report-only review, emergency-access test and approved exception list.',
                    'depends_on' => ['ONB-130', 'ONB-280'],
                ],
                [
                    'key' => 'ONB-310',
                    'name' => 'ONB-310 Assign and remediate drift controls',
                    'instructions' => 'After the pilot passes, assign N45-90-Drift to the approved scope, run Remediate, and verify expected deviations generate reviewable exceptions rather than destructive automatic changes.',
                    'estimate' => 35,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Microsoft 365',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 280,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record drift assignment, remediation status and accepted deviations.',
                    'depends_on' => ['ONB-280'],
                ],
                [
                    'key' => 'ONB-320',
                    'name' => 'ONB-320 Roll out approved workstation management',
                    'instructions' => 'Deploy the approved Intune, Level and SentinelOne controls to workstation batches, verify install and detection results, monitor failures, and stop broad rollout on identity, policy or protection conflicts.',
                    'estimate' => 180,
                    'condition_type' => 'client_has_asset_type',
                    'condition_value' => 'Workstation',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 336,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the fleet rollout and exception report with source identifiers.',
                    'depends_on' => ['ONB-240', 'ONB-280', 'ONB-310'],
                ],
                [
                    'key' => 'ONB-330',
                    'name' => 'ONB-330 Prove Windows Server exclusion',
                    'instructions' => 'For every discovered Windows Server, prove workstation assignment filters, Autopilot, ESP applications and workstation-only remediation do not target it. Document separately approved server agents and controls.',
                    'estimate' => 40,
                    'condition_type' => 'client_has_asset_type',
                    'condition_value' => 'Server',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 344,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach assignment results showing Windows Server exclusion and approved server scope.',
                    'depends_on' => ['ONB-120', 'ONB-320'],
                ],
                [
                    'key' => 'ONB-340',
                    'name' => 'ONB-340 Reconcile endpoint coverage and identity',
                    'instructions' => 'Reconcile ITFlow assets against Level, SentinelOne, Intune and Entra records. Resolve or explicitly queue missing, stale, orphaned, duplicate and conflicting mappings; never force a cross-client match.',
                    'estimate' => 90,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 360,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the coverage matrix and unresolved reconciliation queue.',
                    'depends_on' => ['ONB-320', 'ONB-330'],
                ],
                [
                    'key' => 'ONB-350',
                    'name' => 'ONB-350 Configure infrastructure monitoring',
                    'instructions' => 'Add only in-scope hosts, services and notification routes to the approved monitoring platform, label them with stable client and asset identities, and set maintenance and escalation policies.',
                    'estimate' => 60,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Monitoring',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 368,
                    'evidence_type' => 'url',
                    'evidence_prompt' => 'Link to the scoped monitoring view and record notification routes.',
                    'depends_on' => ['ONB-060', 'ONB-340'],
                ],
                [
                    'key' => 'ONB-360',
                    'name' => 'ONB-360 Test monitoring alert and recovery paths',
                    'instructions' => 'Generate a controlled canary failure and recovery for representative monitored services. Verify correct client, device and service context, deduplication, ticket behavior, recovery handling and notification delivery.',
                    'estimate' => 60,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Monitoring',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 384,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the canary alert and recovery evidence, including duplicate-prevention result.',
                    'depends_on' => ['ONB-350'],
                ],
                [
                    'key' => 'ONB-370',
                    'name' => 'ONB-370 Configure approved backup coverage',
                    'instructions' => 'Configure only agreed systems and data, retention, encryption, offsite or immutable copies, alerting and ownership. Record exclusions and recovery objectives; a successful job alone is not proof of recoverability.',
                    'estimate' => 120,
                    'condition_type' => 'client_has_backup',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 336,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the backup coverage, retention and exclusion report.',
                    'depends_on' => ['ONB-020', 'ONB-070'],
                ],
                [
                    'key' => 'ONB-380',
                    'name' => 'ONB-380 Prove backup and representative restore',
                    'instructions' => 'Verify the first successful backup and complete a representative restore appropriate to the protected workload. Record the restored object, integrity check, elapsed recovery time and cleanup.',
                    'estimate' => 120,
                    'condition_type' => 'client_has_backup',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 384,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach dated backup and restore evidence with achieved recovery time.',
                    'depends_on' => ['ONB-370'],
                ],
                [
                    'key' => 'ONB-390',
                    'name' => 'ONB-390 Vault administrative access references',
                    'instructions' => 'Move administrative credentials, emergency-access references, API ownership and recovery procedures into the approved vault. Store references in ITFlow without copying passwords, site tokens or application secrets.',
                    'estimate' => 90,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 384,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record vault item references, owners and access-test results without secret values.',
                    'depends_on' => ['ONB-090', 'ONB-190', 'ONB-220'],
                ],
                [
                    'key' => 'ONB-400',
                    'name' => 'ONB-400 Complete security baseline and exception register',
                    'instructions' => 'Complete the approved Microsoft 365 and operational security baseline, reconcile remaining failed controls, and record unsupported systems, accepted risks, owners and target dates.',
                    'estimate' => 120,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 432,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the final control matrix and risk or exception register.',
                    'depends_on' => ['ONB-300', 'ONB-340', 'ONB-360', 'ONB-380', 'ONB-390'],
                ],
                [
                    'key' => 'ONB-410',
                    'name' => 'ONB-410 Reconcile billing, services and licensing',
                    'instructions' => 'Verify agreement dates, minimums, recurring products, covered users and devices, licenses, onboarding charges and payment method match the delivered and explicitly excluded scope.',
                    'estimate' => 50,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 440,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the billing and license reconciliation plus unresolved exceptions.',
                    'depends_on' => ['ONB-020', 'ONB-340'],
                ],
                [
                    'key' => 'ONB-420',
                    'name' => 'ONB-420 Publish documentation baseline and obtain acceptance',
                    'instructions' => 'Complete and export the client overview, contacts, assets, networks, vendors, services, backup, security, access and exception documentation. Publish only the approved client-safe set to the portal and obtain acceptance.',
                    'estimate' => 120,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 480,
                    'initial_state' => 'Waiting',
                    'approval_scope' => 'client',
                    'approval_type' => 'technical',
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the dated documentation export and record portal publication scope.',
                    'depends_on' => ['ONB-050', 'ONB-060', 'ONB-380', 'ONB-400', 'ONB-410'],
                ],
                [
                    'key' => 'ONB-430',
                    'name' => 'ONB-430 Schedule operations cadence and 30-day review',
                    'instructions' => 'Schedule maintenance, service reporting, documentation review, restore testing and the 30-day review. At review, record client acceptance, unresolved exceptions, ownership and follow-up tickets.',
                    'estimate' => 60,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 720,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record scheduled cadences, review outcome, acceptance and linked follow-up work.',
                    'depends_on' => ['ONB-420'],
                ],
            ],
        ],
        [
            'name' => 'Client Offboarding',
            'description' => 'Cleanly exit a departing client',
            'subject' => 'Client offboarding - [Client]',
            'details' => '<p>Agree in writing what will be handed over, what N45 must retain, when access changes occur and who is authorized to receive credentials. Billing disputes must never delay a security-critical access revocation.</p>',
            'runbook_key' => 'client-offboarding',
            'runbook_type' => 'offboarding',
            'publish_runbook' => true,
            'tasks' => [
                [
                    'key' => 'OFF-010',
                    'name' => 'OFF-010 Confirm termination authority and effective time',
                    'instructions' => 'Obtain written termination authority from an authorized client representative, confirm the effective time and security-critical revocation deadline, and record any legal or insurer constraints. Billing disputes must not delay revocation.',
                    'estimate' => 25,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 8,
                    'initial_state' => 'Waiting',
                    'approval_scope' => 'client',
                    'approval_type' => 'any',
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the written termination authorization and effective-time confirmation.',
                    'depends_on' => [],
                ],
                [
                    'key' => 'OFF-020',
                    'name' => 'OFF-020 Review agreement and exit obligations',
                    'instructions' => 'Review notice, handover, retention, confidentiality, license, hardware, prepaid-work and termination clauses. Separate commercial disputes from the technical security timeline.',
                    'estimate' => 35,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 16,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record contractual exit obligations, owners, dates and commercial exceptions.',
                    'depends_on' => ['OFF-010'],
                ],
                [
                    'key' => 'OFF-030',
                    'name' => 'OFF-030 Verify handover recipients and secure channel',
                    'instructions' => 'Independently verify the identity and authority of each client or incoming-provider recipient, record what each may receive, and agree an encrypted delivery channel plus out-of-band receipt check.',
                    'estimate' => 30,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 24,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record verified recipients, authority, approved content and secure channel.',
                    'depends_on' => ['OFF-010'],
                ],
                [
                    'key' => 'OFF-040',
                    'name' => 'OFF-040 Freeze scope and sequence the exit',
                    'instructions' => 'Agree handover scope, read-only or change freeze, communication plan, agent and access cutover sequence, replacement-provider dependencies, rollback constraints and the exact no-gap protection requirement.',
                    'estimate' => 45,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 32,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the approved offboarding schedule and responsibility matrix.',
                    'depends_on' => ['OFF-020', 'OFF-030'],
                ],
                [
                    'key' => 'OFF-050',
                    'name' => 'OFF-050 Inventory services, systems and ownership',
                    'instructions' => 'Reconcile client-owned and provider-owned devices, tenants, domains, circuits, licenses, vendors, agents, monitoring, backups, automations, vault references and portal accounts before any removal.',
                    'estimate' => 90,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 48,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the final ownership and service inventory with source identifiers.',
                    'depends_on' => ['OFF-040'],
                ],
                [
                    'key' => 'OFF-060',
                    'name' => 'OFF-060 Approve retention, legal hold and destruction',
                    'instructions' => 'Define what N45, the client and the incoming provider must retain; backup transfer or destruction dates; legal holds; evidence retention; and how completion will be proven. Do not destroy data on an informal request.',
                    'estimate' => 35,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 56,
                    'initial_state' => 'Waiting',
                    'approval_scope' => 'client',
                    'approval_type' => 'billing',
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the approved retention, legal-hold, transfer and destruction decision.',
                    'depends_on' => ['OFF-020'],
                ],
                [
                    'key' => 'OFF-070',
                    'name' => 'OFF-070 Capture final identity and coverage reconciliation',
                    'instructions' => 'Capture the final ITFlow, Level, SentinelOne, Intune, Entra and monitoring coverage state. Resolve or list stale, orphaned, duplicate and conflicting records so the export does not misrepresent ownership.',
                    'estimate' => 75,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 72,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the final coverage matrix and unresolved reconciliation list.',
                    'depends_on' => ['OFF-050'],
                ],
                [
                    'key' => 'OFF-080',
                    'name' => 'OFF-080 Prepare the approved documentation export',
                    'instructions' => 'Export the approved client-safe contacts, assets, network, vendor, service, configuration, backup, security, runbook and exception documentation. Exclude internal notes and secret values, and create a manifest with hashes where practical.',
                    'estimate' => 120,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 96,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the redacted export manifest and integrity record, not duplicate secret material.',
                    'depends_on' => ['OFF-050', 'OFF-060', 'OFF-070'],
                ],
                [
                    'key' => 'OFF-090',
                    'name' => 'OFF-090 Prepare the controlled credential handover',
                    'instructions' => 'Prepare only client-owned credentials and recovery references in the approved vault or transfer mechanism. Never put passwords, GDAP secrets, SentinelOne tokens or API keys in tickets, notes or the general documentation export.',
                    'estimate' => 60,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 96,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record vault item references and transfer manifest without secret values.',
                    'depends_on' => ['OFF-030', 'OFF-050'],
                ],
                [
                    'key' => 'OFF-100',
                    'name' => 'OFF-100 Approve export and handover manifest',
                    'instructions' => 'Have the authorized recipient review the documentation and credential manifests, confirm allowed recipients and exclusions, and approve release before anything sensitive leaves N45 control.',
                    'estimate' => 30,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 112,
                    'initial_state' => 'Waiting',
                    'approval_scope' => 'client',
                    'approval_type' => 'any',
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record the approved manifests, recipients and exclusions.',
                    'depends_on' => ['OFF-080', 'OFF-090'],
                ],
                [
                    'key' => 'OFF-110',
                    'name' => 'OFF-110 Deliver export and confirm receipt',
                    'instructions' => 'Deliver documentation and credential handover through the approved secure channels, verify recipient identity out of band, capture receipt, and record exactly which manifest version was released.',
                    'estimate' => 45,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 128,
                    'evidence_type' => 'any',
                    'evidence_prompt' => 'Attach or link the delivery receipt and record the released manifest version.',
                    'depends_on' => ['OFF-100'],
                ],
                [
                    'key' => 'OFF-120',
                    'name' => 'OFF-120 Revoke client portal access',
                    'instructions' => 'At the approved effective time, revoke portal sessions and roles for client and incoming-provider users, disable pending invitations and verify no offboarded contact can access client data or approvals.',
                    'estimate' => 25,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 136,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record revoked portal users, roles, sessions and verification result.',
                    'depends_on' => ['OFF-110'],
                ],
                [
                    'key' => 'OFF-130',
                    'name' => 'OFF-130 Revoke GDAP and CIPP delegated access',
                    'instructions' => 'At the effective time, remove N45 GDAP relationships, delegated groups, CIPP customer access and N45-owned app consent according to the approved sequence. Preserve tenant-owned emergency access and audit evidence.',
                    'estimate' => 60,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Microsoft 365',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 144,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach redacted evidence of GDAP and CIPP revocation with tenant and relationship IDs.',
                    'depends_on' => ['OFF-110'],
                ],
                [
                    'key' => 'OFF-140',
                    'name' => 'OFF-140 Revoke administrative and remote access',
                    'instructions' => 'Remove N45 users, groups, VPN, remote support, vendor, cloud, firewall, network and application access; revoke sessions and tokens; rotate shared credentials with the new owner; and test that former access fails.',
                    'estimate' => 90,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 152,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the access-revocation checklist and failed-access verification results.',
                    'depends_on' => ['OFF-110'],
                ],
                [
                    'key' => 'OFF-150',
                    'name' => 'OFF-150 Disable client-specific integrations and automation',
                    'instructions' => 'Disable client-specific n8n workflows, webhooks, API tokens, scheduled jobs, mail routes and event ingestion only after dependencies are transferred. Preserve redacted logs required by retention policy.',
                    'estimate' => 60,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 160,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record disabled workflows, credentials, schedules and retained evidence locations.',
                    'depends_on' => ['OFF-110'],
                ],
                [
                    'key' => 'OFF-160',
                    'name' => 'OFF-160 Approve the managed-agent removal sequence',
                    'instructions' => 'Confirm replacement management, endpoint protection, monitoring and backup are active or explicitly declined. Approve the device batches, timing, exclusions, rollback and proof required before removing any protective agent.',
                    'estimate' => 35,
                    'condition_type' => 'manual_confirm',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 120,
                    'initial_state' => 'Waiting',
                    'approval_scope' => 'client',
                    'approval_type' => 'technical',
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the approved agent-removal sequence and replacement-coverage evidence.',
                    'depends_on' => ['OFF-040', 'OFF-070'],
                ],
                [
                    'key' => 'OFF-170',
                    'name' => 'OFF-170 Remove Level and retire its mappings',
                    'instructions' => 'Remove the Level agent from approved devices at the scheduled time, verify uninstall and loss of access, retire the external identity mappings without deleting the ITFlow assets, and record exceptions.',
                    'estimate' => 75,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Level',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 176,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the Level uninstall, access-loss and retired-mapping report.',
                    'depends_on' => ['OFF-160'],
                ],
                [
                    'key' => 'OFF-180',
                    'name' => 'OFF-180 Remove SentinelOne without a protection gap',
                    'instructions' => 'After replacement protection is proven or risk is explicitly accepted, remove SentinelOne from approved devices, verify uninstall and console retirement, revoke site access and preserve threat or audit evidence required by policy.',
                    'estimate' => 90,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'SentinelOne',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 184,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach replacement-protection and SentinelOne removal evidence with agent IDs.',
                    'depends_on' => ['OFF-160'],
                ],
                [
                    'key' => 'OFF-190',
                    'name' => 'OFF-190 Transfer or retire Intune and Autopilot control',
                    'instructions' => 'Transfer device ownership and policy documentation, remove N45-specific Intune assignments and Autopilot control only as approved, and reconcile Entra and managed-device records. Never wipe a device without separate explicit approval.',
                    'estimate' => 90,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Intune',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 192,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the Intune and Autopilot transfer or retirement report and exceptions.',
                    'depends_on' => ['OFF-130', 'OFF-160'],
                ],
                [
                    'key' => 'OFF-200',
                    'name' => 'OFF-200 Retire monitoring tests and notification routes',
                    'instructions' => 'After replacement monitoring or explicit risk acceptance is confirmed, remove client hosts, checks, notification routes, maintenance windows and escalation contacts while retaining required historical evidence.',
                    'estimate' => 60,
                    'condition_type' => 'client_has_service',
                    'condition_value' => 'Monitoring',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 200,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the monitoring retirement and replacement-coverage confirmation.',
                    'depends_on' => ['OFF-160'],
                ],
                [
                    'key' => 'OFF-210',
                    'name' => 'OFF-210 Transfer or release licenses and subscriptions',
                    'instructions' => 'Transfer client-owned subscriptions and vendor ownership, remove N45 licenses only after dependencies and retention are satisfied, document prorations, and reconcile final counts against billing.',
                    'estimate' => 60,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 208,
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record each transferred, released, retained or disputed license and effective date.',
                    'depends_on' => ['OFF-110', 'OFF-160'],
                ],
                [
                    'key' => 'OFF-220',
                    'name' => 'OFF-220 Execute the approved backup disposition',
                    'instructions' => 'Transfer, export, retain or destroy backup data exactly as approved. Verify recipient integrity and access for transfers; for destruction, use the scheduled date and preserve non-secret proof of completion.',
                    'estimate' => 90,
                    'condition_type' => 'client_has_backup',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 216,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach transfer receipt, retained-until record or destruction certificate as applicable.',
                    'depends_on' => ['OFF-060', 'OFF-110', 'OFF-160'],
                ],
                [
                    'key' => 'OFF-230',
                    'name' => 'OFF-230 Complete final access-revocation sweep',
                    'instructions' => 'Reconcile portal, GDAP, CIPP, Entra, Level, SentinelOne, Intune, Autopilot, monitoring, backup, vault, vendor, network and automation access. Prove no N45 access or live secret remains beyond approved retention.',
                    'estimate' => 90,
                    'condition_type' => 'always',
                    'owner_type' => 'ticket_assignee',
                    'due_offset_hours' => 224,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the signed final access matrix and unresolved exception list.',
                    'depends_on' => ['OFF-120', 'OFF-130', 'OFF-140', 'OFF-150', 'OFF-170', 'OFF-180', 'OFF-190', 'OFF-200', 'OFF-220'],
                ],
                [
                    'key' => 'OFF-240',
                    'name' => 'OFF-240 Reconcile final billing and retained obligations',
                    'instructions' => 'Prepare the final invoice independently from technical revocation, reconcile licenses and recurring services, record credits or disputes, and list retained evidence, backup or legal-hold obligations with owners and dates.',
                    'estimate' => 50,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 232,
                    'initial_state' => 'Waiting',
                    'approval_scope' => 'client',
                    'approval_type' => 'billing',
                    'evidence_type' => 'note',
                    'evidence_prompt' => 'Record final billing approval or dispute and every retained obligation.',
                    'depends_on' => ['OFF-210', 'OFF-230'],
                ],
                [
                    'key' => 'OFF-250',
                    'name' => 'OFF-250 Finalize handoff and archive readiness',
                    'instructions' => 'Assemble the released client-data package and manifest, confirm recipient access and record retention dates, exceptions and owners. Complete this task only after the handoff receipt is retained; then download the completed ITFlow runbook closeout export for final delivery and archive the client.',
                    'estimate' => 60,
                    'condition_type' => 'always',
                    'owner_type' => 'project_manager',
                    'due_offset_hours' => 240,
                    'evidence_type' => 'file',
                    'evidence_prompt' => 'Attach the released-package manifest and recipient receipt, then record the archive and retention-review dates.',
                    'depends_on' => ['OFF-110', 'OFF-230', 'OFF-240'],
                ],
            ],
        ],
        [
            'name' => 'Firewall Deployment',
            'description' => 'Replace or deploy a perimeter firewall',
            'subject' => 'Firewall deployment - [Client] - [Site]',
            'details' => '<p>Firewall deployment or replacement. Capture the existing configuration and public addressing before the change window, and agree a rollback plan.</p>',
            'tasks' => [
                ['Capture the current configuration, rules and public addressing', 60],
                ['Confirm the license, subscription and support entitlement', 20],
                ['Stage and update the firmware on the bench', 45],
                ['Build the base configuration, interfaces and routing', 90],
                ['Recreate firewall rules, NAT and port forwards', 90],
                ['Configure VPN tunnels and remote access', 60],
                ['Agree the change window and notify the client', 20],
                ['Cut over and verify connectivity and services', 60],
                ['Verify VPN, remote access and any published services', 45],
                ['Document the configuration and store the credentials', 45],
            ],
        ],
        [
            'name' => 'Microsoft 365 Tenant Baseline',
            'description' => 'Document and apply the approved Microsoft 365 management and security baseline',
            'subject' => 'Microsoft 365 tenant baseline - [Client]',
            'details' => '<p>Establish approved delegated access and document the current state before changing policy. Record and obtain approval for user-impacting controls, exclusions and break-glass arrangements.</p>',
            'tasks' => [
                ['Establish delegated administrative access', 30],
                ['Document the tenant ID, domains and license position', 45],
                ['Create the break glass administrator account and vault it', 30],
                ['Capture the current administrative roles and authentication posture', 30],
                ['Approve and enforce multi-factor authentication for all in-scope users', 60],
                ['Review, approve and implement the conditional access baseline', 60],
                ['Block legacy authentication after validating application compatibility', 30],
                ['Review mail flow, connectors and forwarding rules', 45],
                ['Verify SPF, DKIM and DMARC records', 45],
                ['Enable and configure audit logging and alerting', 45],
                ['Configure tenant backup only when licensed and included in scope', 60],
                ['Document approved exceptions, evidence and remaining risks', 45],
                ['Update the Microsoft 365 Tenant Details document and send the baseline summary', 45],
            ],
        ],
        [
            'name' => 'Technology Review',
            'description' => 'Prepare and deliver a recurring client technology and risk review',
            'subject' => 'Technology review - [Client] - [Period]',
            'details' => '<p>Lead with business outcomes, material risks and decisions. Use operational activity only as evidence; do not turn the meeting into a ticket-count recital.</p>',
            'tasks' => [
                ['Pull ticket volume, response and resolution figures', 45],
                ['Review asset age, warranty and end of life exposure', 45],
                ['Review the security posture and any open risks', 45],
                ['Review backup and restore test results for the period', 30],
                ['Review spend against budget and the license position', 30],
                ['Build the roadmap and budget recommendations', 60],
                ['Circulate the pack and hold the meeting', 90],
                ['Log the agreed actions and raise the follow up work', 45],
            ],
        ],
        [
            'name' => 'Same-Day Remote Rescue',
            'description' => 'Time-boxed remote diagnosis and recovery for an unmanaged client',
            'subject' => 'Same-day remote rescue - [Client] - [Issue]',
            'details' => '<p>Confirm the $450 prepaid authorization for the first two hours. This is a best-effort rescue, not an unlimited support commitment. Stop and request approval before exceeding the prepaid scope.</p>',
            'tasks' => [
                ['Confirm payment, authorized requester and callback details', 10],
                ['Record the business impact, symptoms and last known good state', 10],
                ['Establish secure attended remote access', 10],
                ['Protect recoverable data and capture the current state before changing it', 15],
                ['Diagnose and implement the least disruptive safe recovery', 75],
                ['Verify the reported business function with the requester', 15],
                ['Stop at two hours and obtain approval before additional billable work', 5],
                ['Document the result, residual risk and recommended next step', 15],
            ],
        ],
        [
            'name' => 'Microsoft 365 Security Triage',
            'description' => 'Fixed-scope Microsoft 365 posture assessment for up to 25 users',
            'subject' => 'Microsoft 365 security triage - [Client]',
            'details' => '<p>Assessment only. Collect evidence and recommendations without making tenant changes unless the client separately approves remediation in writing.</p>',
            'tasks' => [
                ['Confirm payment, tenant scope, authorized contact and read-only access path', 15],
                ['Inventory domains, licenses, users and privileged roles', 25],
                ['Review multi-factor authentication and authentication-method coverage', 30],
                ['Review conditional access, security defaults and legacy authentication', 30],
                ['Review external sharing, guest access and application consent', 25],
                ['Review mailbox forwarding, connectors and transport rules', 25],
                ['Verify SPF, DKIM and DMARC posture', 20],
                ['Review audit logging, alerting, retention and tenant backup coverage', 25],
                ['Produce prioritized findings with evidence and plain-language impact', 45],
                ['Hold the review call and offer a separately scoped remediation plan', 30],
            ],
        ],
        [
            'name' => 'Security & Continuity Assessment',
            'description' => 'One-site security, identity, backup and continuity assessment',
            'subject' => 'Security and continuity assessment - [Client]',
            'details' => '<p>This engagement identifies operational risk and priorities; it is not a compliance certification, penetration test or guarantee against an incident.</p>',
            'tasks' => [
                ['Confirm scope, authorized contacts, evidence access and assessment limitations', 30],
                ['Inventory users, endpoints, servers, network devices and critical applications', 90],
                ['Review identity, privileged access and account lifecycle controls', 60],
                ['Review endpoint management, patching and active security coverage', 60],
                ['Review firewall, segmentation, wireless and remote access posture', 60],
                ['Review backup coverage, retention, immutability and restore evidence', 60],
                ['Review Microsoft 365, email authentication and external sharing exposure', 60],
                ['Review incident contacts, cyber-insurance constraints and continuity dependencies', 45],
                ['Record unsupported systems, accepted risks and evidence gaps', 30],
                ['Produce a risk-ranked remediation roadmap with indicative effort', 90],
                ['Present the findings and record client decisions', 60],
            ],
        ],
        [
            'name' => 'Network Assessment & Diagram',
            'description' => 'Onsite network discovery, configuration review and current-state diagram',
            'subject' => 'Network assessment and diagram - [Client] - [Site]',
            'details' => '<p>Use non-disruptive discovery by default. Obtain approval before active scans, configuration changes or testing that could affect production.</p>',
            'tasks' => [
                ['Confirm site access, maintenance restrictions and authorized network contacts', 20],
                ['Inventory circuits, public addressing and provider account references', 30],
                ['Back up and review firewall, switch and wireless configurations', 90],
                ['Map physical and logical topology, uplinks, VLANs and subnets', 90],
                ['Review routing, DHCP, DNS, NAT, VPN and remote access', 60],
                ['Review firmware, support status, licensing and lifecycle exposure', 45],
                ['Review wireless coverage, channel use, guest isolation and authentication', 45],
                ['Record undocumented dependencies, single points of failure and access gaps', 45],
                ['Create the Network Overview document and diagram', 90],
                ['Deliver risk-ranked findings and an implementation roadmap', 60],
            ],
        ],
        [
            'name' => 'Stabilization Sprint',
            'description' => 'Time-boxed remediation of the highest-priority operational risks',
            'subject' => 'Stabilization sprint - [Client] - [Scope]',
            'details' => '<p>Work only the signed scope and prioritized outcomes. Record out-of-scope discoveries separately instead of absorbing them into the sprint.</p>',
            'tasks' => [
                ['Confirm the signed scope, deposit, success criteria and decision maker', 20],
                ['Capture the pre-change baseline, dependencies and rollback requirements', 45],
                ['Rank the approved work by business impact and execution risk', 30],
                ['Create change records for user-impacting or high-risk remediation', 30],
                ['Implement the approved remediation in controlled batches', 180],
                ['Validate each change against its success and rollback criteria', 60],
                ['Update ITFlow assets, credentials, services and documentation as work completes', 60],
                ['Separate unresolved and out-of-scope findings into follow-up recommendations', 30],
                ['Produce the closeout summary with evidence and remaining risks', 45],
                ['Hold the client readout and obtain acceptance', 30],
            ],
        ],
        [
            'name' => 'Automation Discovery',
            'description' => 'Map a business process and define a safe, valuable automation scope',
            'subject' => 'Automation discovery - [Client] - [Process]',
            'details' => '<p>Define the source of truth, ownership, exception path and measurable outcome before designing the workflow. Default to client-owned service accounts and automation infrastructure.</p>',
            'tasks' => [
                ['Confirm the process owner, users, pain point and desired outcome', 30],
                ['Document the current process, decisions, handoffs and duplicate entry', 60],
                ['Identify systems, records, APIs, permissions and rate limits', 45],
                ['Define the authoritative source and matching keys for each entity', 30],
                ['Identify sensitive data, retention needs and human approval points', 30],
                ['Define failure handling, retry, alerting and manual fallback', 30],
                ['Estimate business value, build effort and ongoing ownership', 30],
                ['Produce the workflow map, acceptance criteria and fixed-scope proposal', 45],
                ['Review the design and record the go or no-go decision', 30],
            ],
        ],
        [
            'name' => 'Automation Build & Test',
            'description' => 'Build and validate an approved production automation',
            'subject' => 'Automation build and test - [Client] - [Workflow]',
            'details' => '<p>Build from an approved discovery and acceptance criteria. Use least-privilege service identities, non-production data where possible and a controlled release path.</p>',
            'tasks' => [
                ['Confirm approved scope, architecture, owner and acceptance criteria', 20],
                ['Confirm the n8n ownership and licensing model for the client', 15],
                ['Create least-privilege service identities and credential records', 30],
                ['Build deterministic entity matching and duplicate prevention', 60],
                ['Implement validation, idempotency, retries and rate-limit handling', 60],
                ['Implement alerts, audit context and a manual exception queue', 45],
                ['Test normal, duplicate, missing-data and partial-failure cases', 90],
                ['Complete a security and sensitive-data review', 30],
                ['Run user acceptance testing with the process owner', 60],
                ['Deploy through an approved change window with rollback available', 45],
                ['Monitor the initial production runs and record defects', 45],
            ],
        ],
        [
            'name' => 'Automation Handoff & Support',
            'description' => 'Document, hand over and establish ownership for a production automation',
            'subject' => 'Automation handoff - [Client] - [Workflow]',
            'details' => '<p>A workflow is not complete until ownership, credentials, alerts, fallback and change boundaries are documented.</p>',
            'tasks' => [
                ['Create the Automation Runbook with systems, mappings and dependencies', 45],
                ['Document credentials by vault reference without exposing secret values', 20],
                ['Document triggers, schedules, retries, alerts and the exception queue', 30],
                ['Document manual fallback, pause and rollback procedures', 30],
                ['Record data retention, logging and access-review requirements', 20],
                ['Train the process owner and support contact', 45],
                ['Define included monitoring and separately billable change work', 20],
                ['Obtain acceptance and schedule the first health review', 20],
                ['Link the workflow to the client service and vendor records in ITFlow', 15],
            ],
        ],
        [
            'name' => 'Microsoft 365 Migration Planning',
            'description' => 'Discover and design a Microsoft 365 migration before moving data',
            'subject' => 'Microsoft 365 migration planning - [Client]',
            'details' => '<p>Confirm identities, data volume, coexistence, business constraints and rollback before scheduling a pilot or cutover.</p>',
            'tasks' => [
                ['Confirm scope, source platforms, user count, domains and business constraints', 45],
                ['Inventory identities, mailboxes, aliases, groups, files and applications', 90],
                ['Measure source data and identify unsupported or high-risk items', 60],
                ['Design identity matching, licensing, mail flow and coexistence', 60],
                ['Document DNS ownership and required record changes', 30],
                ['Define pilot users, migration waves, acceptance and rollback criteria', 45],
                ['Confirm backup and retention for source and destination', 30],
                ['Build the communication, support and change schedule', 45],
                ['Produce the migration plan and obtain approval', 60],
            ],
        ],
        [
            'name' => 'Microsoft 365 Migration Pilot',
            'description' => 'Validate the migration method with representative pilot users',
            'subject' => 'Microsoft 365 migration pilot - [Client]',
            'details' => '<p>Use representative users and real acceptance criteria. Do not proceed to broad cutover until pilot defects and rollback readiness are reviewed.</p>',
            'tasks' => [
                ['Confirm pilot users, support coverage and rollback decision point', 20],
                ['Create destination identities, licenses and access controls', 45],
                ['Run the initial pilot synchronization', 60],
                ['Validate mail, calendar, contacts, files, permissions and applications', 60],
                ['Validate mobile and desktop client reconfiguration', 45],
                ['Measure duration, throughput and user-impact assumptions', 30],
                ['Resolve defects and update the runbook', 60],
                ['Obtain pilot-user and client approval for cutover', 30],
            ],
        ],
        [
            'name' => 'Microsoft 365 Migration Cutover',
            'description' => 'Execute the approved Microsoft 365 production migration',
            'subject' => 'Microsoft 365 migration cutover - [Client] - [Wave]',
            'details' => '<p>Follow the approved runbook and change window. Pause at defined decision points when validation fails rather than improvising in production.</p>',
            'tasks' => [
                ['Confirm go or no-go with the authorized decision maker', 15],
                ['Capture final source state and verify backup or retention coverage', 30],
                ['Run the final synchronization and record exceptions', 90],
                ['Apply approved DNS and mail-flow changes', 30],
                ['Configure destination access, licensing and security controls', 60],
                ['Reconfigure endpoints and mobile devices', 90],
                ['Validate external and internal mail flow and representative data', 45],
                ['Triage exceptions through the documented support path', 60],
                ['Confirm business acceptance or execute the rollback decision', 30],
                ['Send status and next-step communication', 20],
            ],
        ],
        [
            'name' => 'Microsoft 365 Migration Closeout',
            'description' => 'Complete validation, documentation and controlled source retirement',
            'subject' => 'Microsoft 365 migration closeout - [Client]',
            'details' => '<p>Do not decommission the source until retention, application dependencies, user acceptance and rollback requirements are satisfied.</p>',
            'tasks' => [
                ['Reconcile migrated users, mailboxes, groups, files and exceptions', 60],
                ['Resolve or formally accept remaining migration exceptions', 45],
                ['Verify authentication, security, backup and audit configuration', 45],
                ['Update tenant, user, license and application documentation in ITFlow', 45],
                ['Confirm source retention and decommission approval', 30],
                ['Remove temporary privileges, connectors and migration accounts', 30],
                ['Deliver the closeout report and administrator handoff', 45],
                ['Schedule the post-migration review', 15],
            ],
        ],
        [
            'name' => 'Network Cutover & Validation',
            'description' => 'Execute and validate an approved network change or replacement',
            'subject' => 'Network cutover and validation - [Client] - [Site]',
            'details' => '<p>Use an approved change record, current configuration backup and tested rollback plan. Do not remove the prior configuration until acceptance is complete.</p>',
            'tasks' => [
                ['Confirm the approved design, change window, contacts and rollback trigger', 20],
                ['Back up current configurations and capture cable and port state', 30],
                ['Stage, label and update replacement equipment', 45],
                ['Execute the cutover in the approved sequence', 90],
                ['Validate internet, DNS, DHCP, VLANs, routing and remote access', 60],
                ['Validate wireless, printing, voice and critical applications', 60],
                ['Verify Level monitoring and network alerts', 20],
                ['Obtain client acceptance or execute rollback', 20],
                ['Update asset, network, credential and vendor records in ITFlow', 45],
                ['Update the Network Overview and close the change record', 45],
            ],
        ],
        [
            'name' => 'Backup Design & Deployment',
            'description' => 'Design, deploy and document an approved backup service',
            'subject' => 'Backup design and deployment - [Client]',
            'details' => '<p>Document what is and is not protected, agreed recovery objectives, retention, storage limits and test method. A successful backup job is not proof of recoverability.</p>',
            'tasks' => [
                ['Confirm protected systems, data owners and agreed recovery objectives', 45],
                ['Document exclusions, retention, immutability and storage assumptions', 30],
                ['Confirm licensing, capacity, credentials and network requirements', 30],
                ['Deploy and configure the approved backup agents and jobs', 90],
                ['Configure encryption, offsite copy, retention and alerting', 45],
                ['Run and verify the first successful backup', 60],
                ['Perform a representative restore before declaring service active', 90],
                ['Create the Backup and Recovery Runbook', 60],
                ['Record the service, assets and vendor references in ITFlow', 30],
                ['Obtain client acceptance of coverage and exclusions', 30],
            ],
        ],
        [
            'name' => 'Office Move Planning',
            'description' => 'Plan circuits, cabling, network, systems and user readiness for an office move',
            'subject' => 'Office move planning - [Client] - [Site]',
            'details' => '<p>Work backward from the business move date and identify third-party lead times early. Record who owns circuits, cabling, power, access, moving and application validation.</p>',
            'tasks' => [
                ['Confirm move date, outage tolerance, stakeholders and site access', 30],
                ['Survey the new site for racks, power, cooling, cabling and wireless', 90],
                ['Confirm circuit orders, install dates, addressing and demarcation', 45],
                ['Inventory systems, devices, printers, voice and dependencies to move', 60],
                ['Design the target network, addressing and equipment placement', 60],
                ['Define vendor, mover, client and technician responsibilities', 30],
                ['Create the cutover, communication, validation and rollback plan', 60],
                ['Schedule device, server and network work packages', 30],
                ['Confirm backup and insurance requirements before equipment moves', 30],
                ['Hold the readiness review and record go or no-go criteria', 45],
            ],
        ],
    ];

    return $ticket_templates;

}

// ------------------------------
// starterContentProjectTemplates
// Stages are named ticket templates, resolved at load time.
// ------------------------------
function starterContentProjectTemplates() {

    $project_templates = [
        [
            'name' => 'Managed Care Onboarding',
            'description' => 'Take a signed client from kickoff to an accepted, supportable and documented baseline.',
            'ticket_templates' => ['Managed Care Onboarding', 'Microsoft 365 Tenant Baseline', 'Backup Restore Test', 'Technology Review'],
        ],
        [
            'name' => 'Client Offboarding',
            'description' => 'Cleanly exit a departing client with a documented handover.',
            'ticket_templates' => ['Client Offboarding'],
        ],
        [
            'name' => 'Server Refresh',
            'description' => 'Replace ageing server hardware and migrate roles and data across.',
            'ticket_templates' => ['Server Deployment', 'Backup Restore Test'],
        ],
        [
            'name' => 'Device Refresh',
            'description' => 'Phased replacement of end-of-life workstations with documented handoff and disposition.',
            'ticket_templates' => ['Device Deployment'],
        ],
        [
            'name' => 'Network Refresh',
            'description' => 'Assess, replace and validate switching, firewall and wireless infrastructure.',
            'ticket_templates' => ['Network Assessment & Diagram', 'Firewall Deployment', 'Network Cutover & Validation'],
        ],
        [
            'name' => 'Microsoft 365 Migration',
            'description' => 'Plan, pilot, cut over and close out a Microsoft 365 migration.',
            'ticket_templates' => ['Microsoft 365 Migration Planning', 'Microsoft 365 Migration Pilot', 'Microsoft 365 Migration Cutover', 'Microsoft 365 Migration Closeout'],
        ],
        [
            'name' => 'Security Baseline Remediation',
            'description' => 'Assess risk, approve priorities and implement a controlled security and continuity baseline.',
            'ticket_templates' => ['Security & Continuity Assessment', 'Microsoft 365 Tenant Baseline', 'Stabilization Sprint'],
        ],
        [
            'name' => 'Backup and Disaster Recovery Implementation',
            'description' => 'Design, deploy and prove a backup and recovery solution.',
            'ticket_templates' => ['Backup Design & Deployment', 'Backup Restore Test'],
        ],
        [
            'name' => 'Office Move',
            'description' => 'Relocate a client site including circuits, cabling, network and workstations.',
            'ticket_templates' => ['Office Move Planning', 'Network Cutover & Validation', 'Device Deployment'],
        ],
        [
            'name' => 'Stabilization Sprint',
            'description' => 'Deliver a time-boxed set of approved high-priority remediation outcomes.',
            'ticket_templates' => ['Stabilization Sprint'],
        ],
        [
            'name' => 'Microsoft 365 Security Triage',
            'description' => 'Assess one Microsoft 365 tenant and deliver prioritized findings without unapproved changes.',
            'ticket_templates' => ['Microsoft 365 Security Triage'],
        ],
        [
            'name' => 'Security & Continuity Assessment',
            'description' => 'Assess identity, endpoint, network, backup and continuity posture for one site.',
            'ticket_templates' => ['Security & Continuity Assessment'],
        ],
        [
            'name' => 'Network Assessment & Diagram',
            'description' => 'Discover a site, review configuration and deliver current-state network documentation.',
            'ticket_templates' => ['Network Assessment & Diagram'],
        ],
        [
            'name' => 'Automation Sprint',
            'description' => 'Discover, build, test, document and hand off one production automation.',
            'ticket_templates' => ['Automation Discovery', 'Automation Build & Test', 'Automation Handoff & Support'],
        ],
    ];

    return $project_templates;

}

// ------------------------------
// starterContentVendorTemplates
// name, description, website. Phone and account fields differ per region and
// per account, so they are left for whoever adds the vendor to a client.
// ------------------------------
function starterContentVendorTemplates() {

    $vendor_templates = [
        ['Microsoft', 'Cloud, productivity and operating system vendor', 'https://www.microsoft.com'],
        ['Level', 'Remote monitoring, endpoint management and automation platform', 'https://level.io'],
        ['n8n', 'Workflow automation and systems integration platform', 'https://n8n.io'],
        ['SentinelOne', 'Endpoint protection and security platform', 'https://www.sentinelone.com'],
        ['Google', 'Workspace, cloud and domain services', 'https://workspace.google.com'],
        ['Amazon Web Services', 'Cloud infrastructure and hosting', 'https://aws.amazon.com'],
        ['Dell', 'Workstation, laptop and server hardware', 'https://www.dell.com'],
        ['HP', 'Workstation, laptop and printer hardware', 'https://www.hp.com'],
        ['Lenovo', 'Workstation and laptop hardware', 'https://www.lenovo.com'],
        ['Supermicro', 'Server and storage hardware', 'https://www.supermicro.com'],
        ['Apple', 'Workstation, laptop and mobile hardware', 'https://www.apple.com'],
        ['Synology', 'Network attached storage and backup appliances', 'https://www.synology.com'],
        ['QNAP', 'Network attached storage appliances', 'https://www.qnap.com'],
        ['Ubiquiti', 'Networking, wireless and surveillance hardware', 'https://www.ui.com'],
        ['MikroTik', 'Routing and switching hardware', 'https://mikrotik.com'],
        ['Cisco', 'Networking, wireless and collaboration hardware', 'https://www.cisco.com'],
        ['Fortinet', 'Firewall and network security appliances', 'https://www.fortinet.com'],
        ['SonicWall', 'Firewall and network security appliances', 'https://www.sonicwall.com'],
        ['APC by Schneider Electric', 'Uninterruptible power supplies and power distribution', 'https://www.apc.com'],
        ['Brother', 'Printer and scanner hardware', 'https://www.brother.com'],
        ['Ingram Micro', 'Hardware and software distribution', 'https://www.ingrammicro.com'],
        ['TD SYNNEX', 'Hardware and software distribution', 'https://www.tdsynnex.com'],
        ['Veeam', 'Backup, replication and recovery software', 'https://www.veeam.com'],
        ['Acronis', 'Backup and cyber protection software', 'https://www.acronis.com'],
        ['Backblaze', 'Cloud backup and object storage', 'https://www.backblaze.com'],
        ['Wasabi', 'Cloud object storage', 'https://wasabi.com'],
        ['Bitdefender', 'Endpoint protection and security software', 'https://www.bitdefender.com'],
        ['ESET', 'Endpoint protection and security software', 'https://www.eset.com'],
        ['Proofpoint', 'Email security and filtering', 'https://www.proofpoint.com'],
        ['Bitwarden', 'Password management', 'https://bitwarden.com'],
        ['Cloudflare', 'DNS, content delivery and security', 'https://www.cloudflare.com'],
        ['Namecheap', 'Domain registration and hosting', 'https://www.namecheap.com'],
        ['GoDaddy', 'Domain registration and hosting', 'https://www.godaddy.com'],
        ['Adobe', 'Creative and document software', 'https://www.adobe.com'],
        ['Intuit', 'Accounting and payroll software', 'https://www.intuit.com'],
        ['Zoom', 'Video conferencing and collaboration', 'https://zoom.us'],
        ['RingCentral', 'Hosted voice and unified communications', 'https://www.ringcentral.com'],
        ['Stripe', 'Card and online payment processing', 'https://stripe.com'],
    ];

    return $vendor_templates;

}

// ------------------------------
// starterContentDocumentTemplates
// name, description, content. Square bracket placeholders are filled in when
// the document is created from the template.
// ------------------------------
function starterContentDocumentTemplates() {

    $document_templates = [
        [
            'Network Overview',
            'Topology, addressing, VLANs and internet circuits for a site',
            '<h3>Network Overview</h3><p><strong>Site:</strong> [Site]<br><strong>Last reviewed:</strong> [Date]</p><h4>Internet Circuits</h4><table style="width:100%" border="1"><tbody><tr><th>Provider</th><th>Type</th><th>Speed</th><th>Public IP</th><th>Account</th><th>Support</th></tr><tr><td></td><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Addressing and VLANs</h4><table style="width:100%" border="1"><tbody><tr><th>VLAN</th><th>Name</th><th>Subnet</th><th>Gateway</th><th>DHCP Scope</th></tr><tr><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Core Equipment</h4><ul><li>Firewall:</li><li>Core switch:</li><li>Access switches:</li><li>Wireless controller and access points:</li></ul><h4>DNS and DHCP</h4><ul><li>Internal DNS servers:</li><li>External forwarders:</li><li>DHCP server and reservations:</li></ul><h4>Remote Access</h4><ul><li>VPN type and endpoint:</li><li>Who has access:</li></ul><h4>Notes</h4><p></p>',
        ],
        [
            'Server Build Sheet',
            'As-built record for a physical or virtual server',
            '<h3>Server Build Sheet</h3><p><strong>Hostname:</strong> [Hostname]<br><strong>Role:</strong> [Role]<br><strong>Built:</strong> [Date]<br><strong>Built by:</strong> [Technician]</p><h4>Hardware or Virtual Specification</h4><table style="width:100%" border="1"><tbody><tr><th>CPU</th><th>Memory</th><th>Storage</th><th>Host or Chassis</th><th>Serial or Service Tag</th></tr><tr><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Operating System</h4><ul><li>Version and edition:</li><li>Licensing:</li><li>Patch level at handover:</li></ul><h4>Network</h4><ul><li>Addressing:</li><li>Out of band management:</li></ul><h4>Roles and Applications</h4><ul><li></li></ul><h4>Backup</h4><ul><li>Job name and schedule:</li><li>Retention:</li><li>First successful backup:</li></ul><h4>Monitoring</h4><ul><li>Agents installed:</li><li>Alerts configured:</li></ul><h4>Dependencies and Restart Order</h4><p></p>',
        ],
        [
            'Backup and Recovery Runbook',
            'What is protected, how often, and how to restore it',
            '<h3>Backup and Recovery Runbook</h3><p><strong>Client:</strong> [Client]<br><strong>Last restore test:</strong> [Date]</p><h4>Agreed Objectives</h4><ul><li>Recovery point objective:</li><li>Recovery time objective:</li></ul><h4>Protected Systems</h4><table style="width:100%" border="1"><tbody><tr><th>System</th><th>Job</th><th>Schedule</th><th>Retention</th><th>Target</th><th>Offsite Copy</th></tr><tr><td></td><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Not Protected</h4><p>Record anything deliberately excluded and who accepted the risk.</p><h4>Restore Procedure - File Level</h4><ol><li></li></ol><h4>Restore Procedure - Full System</h4><ol><li></li></ol><h4>Restore Test History</h4><table style="width:100%" border="1"><tbody><tr><th>Date</th><th>Scope</th><th>Recovery Time Achieved</th><th>Result</th><th>Tested By</th></tr><tr><td></td><td></td><td></td><td></td><td></td></tr></tbody></table>',
        ],
        [
            'Disaster Recovery Plan',
            'What happens when a site or critical system is lost',
            '<h3>Disaster Recovery Plan</h3><p><strong>Client:</strong> [Client]<br><strong>Approved by:</strong> [Contact]<br><strong>Last reviewed:</strong> [Date]</p><h4>Scope and Assumptions</h4><p></p><h4>Critical Systems in Priority Order</h4><table style="width:100%" border="1"><tbody><tr><th>Priority</th><th>System</th><th>Business Function</th><th>Recovery Target</th></tr><tr><td>1</td><td></td><td></td><td></td></tr></tbody></table><h4>Declaration and Authority</h4><ul><li>Who can declare a disaster:</li><li>How the team is mobilised:</li></ul><h4>Communication Plan</h4><table style="width:100%" border="1"><tbody><tr><th>Audience</th><th>Owner</th><th>Method</th></tr><tr><td></td><td></td><td></td></tr></tbody></table><h4>Recovery Procedures</h4><ol><li></li></ol><h4>Alternate Working Arrangements</h4><p></p><h4>Return to Normal Operations</h4><p></p>',
        ],
        [
            'Site and Access Details',
            'Physical access, keyholders and on-site logistics',
            '<h3>Site and Access Details</h3><p><strong>Site:</strong> [Site]<br><strong>Address:</strong> [Address]</p><h4>Access</h4><ul><li>Building access method:</li><li>Server room access method:</li><li>Notice required before attending:</li><li>Escort required:</li></ul><h4>Key Contacts</h4><table style="width:100%" border="1"><tbody><tr><th>Name</th><th>Role</th><th>Phone</th><th>Hours</th></tr><tr><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Hours and Restrictions</h4><ul><li>Normal site hours:</li><li>Agreed maintenance window:</li><li>Restricted periods:</li></ul><h4>Logistics</h4><ul><li>Parking and loading:</li><li>Delivery instructions:</li><li>Health and safety requirements:</li></ul><p><em>Do not record alarm codes, door codes or key safe combinations here - store those in the credential vault.</em></p>',
        ],
        [
            'Microsoft 365 Tenant Details',
            'Tenant identifiers, licensing position and security baseline',
            '<h3>Microsoft 365 Tenant Details</h3><p><strong>Client:</strong> [Client]<br><strong>Tenant domain:</strong> [Tenant]<br><strong>Tenant ID:</strong> [Tenant ID]</p><h4>Domains</h4><table style="width:100%" border="1"><tbody><tr><th>Domain</th><th>Registrar</th><th>Verified</th><th>Primary</th></tr><tr><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Licensing</h4><table style="width:100%" border="1"><tbody><tr><th>SKU</th><th>Assigned</th><th>Purchased</th><th>Renewal</th></tr><tr><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Administrative Access</h4><ul><li>Delegated access model:</li><li>Break glass account location:</li><li>Global administrators:</li></ul><h4>Security Baseline</h4><ul><li>Multi-factor authentication enforced:</li><li>Conditional access policies:</li><li>Legacy authentication blocked:</li><li>Audit logging enabled:</li></ul><h4>Mail Flow</h4><ul><li>Inbound and outbound connectors:</li><li>SPF, DKIM and DMARC status:</li><li>Filtering platform:</li></ul><h4>Tenant Backup</h4><ul><li>Product and retention:</li></ul>',
        ],
        [
            'Line of Business Application Profile',
            'Everything needed to support a client business critical application',
            '<h3>Line of Business Application Profile</h3><p><strong>Application:</strong> [Application]<br><strong>Business owner:</strong> [Contact]<br><strong>Criticality:</strong> [Critical / High / Normal]</p><h4>Vendor and Support</h4><ul><li>Vendor:</li><li>Support portal:</li><li>Support hours and contract level:</li><li>Account or customer number:</li></ul><h4>Architecture</h4><ul><li>Hosting model:</li><li>Servers and services involved:</li><li>Database platform and location:</li><li>Integrations and dependencies:</li></ul><h4>Access</h4><ul><li>How users connect:</li><li>Licensing model and count:</li><li>Who administers it:</li></ul><h4>Maintenance</h4><ul><li>Update process and cadence:</li><li>Known constraints - patching, reboots, versions:</li><li>Backup approach:</li></ul><h4>Common Issues</h4><p></p>',
        ],
        [
            'User Onboarding Request',
            'Client-facing form for authorizing a new user setup',
            '<h3>User Onboarding Request</h3><p>Return this form at least five working days before the start date. Device procurement and deployment may require additional lead time.</p><h4>User</h4><ul><li>Full name:</li><li>Preferred display name:</li><li>Job title:</li><li>Department:</li><li>Manager:</li><li>Start date and time:</li><li>Working location:</li></ul><h4>Access</h4><ul><li>Approved role or user to use as an access reference:</li><li>Shared mailboxes:</li><li>Groups and shared resources:</li><li>Applications and privileged access:</li></ul><h4>Equipment</h4><ul><li>Assigned existing device or new device required:</li><li>Monitors and peripherals:</li><li>Mobile or voice requirements:</li></ul><h4>Authorization</h4><ul><li>Requested by:</li><li>Approved by:</li><li>Date:</li></ul>',
        ],
        [
            'User Offboarding Request',
            'Client-facing form authorizing access removal and data handoff',
            '<h3>User Offboarding Request</h3><p>This form is written authorization to revoke access. It must come from an authorized contact.</p><h4>User</h4><ul><li>Full name:</li><li>Last working day:</li><li>Effective time for access removal:</li></ul><h4>Mailbox and Data</h4><ul><li>Legal hold or retention requirement:</li><li>Mailbox conversion, forwarding and auto-reply:</li><li>New owner for OneDrive, SharePoint and application data:</li><li>Access that must remain temporarily and why:</li></ul><h4>Equipment</h4><ul><li>Equipment to be returned:</li><li>Collected by:</li><li>Reuse, retain or dispose:</li></ul><h4>Authorization</h4><ul><li>Requested by:</li><li>Approved by:</li><li>Date:</li></ul>',
        ],
        [
            'Change Record',
            'Record of a planned change, its impact and how to roll it back',
            '<h3>Change Record</h3><p><strong>Change:</strong> [Summary]<br><strong>Requested by:</strong> [Contact]<br><strong>Scheduled:</strong> [Date and Window]<br><strong>Risk:</strong> [Low / Medium / High]</p><h4>Reason for Change</h4><p></p><h4>Systems Affected</h4><ul><li></li></ul><h4>Expected Impact</h4><ul><li>User impact:</li><li>Expected downtime:</li><li>Who has been notified:</li></ul><h4>Implementation Steps</h4><ol><li></li></ol><h4>Verification</h4><ol><li></li></ol><h4>Rollback Plan</h4><ol><li></li></ol><h4>Outcome</h4><ul><li>Completed by:</li><li>Result:</li><li>Documentation updated:</li></ul>',
        ],
        [
            'Incident Response Plan',
            'Agreed process and contacts for a security incident',
            '<h3>Incident Response Plan</h3><p><strong>Client:</strong> [Client]<br><strong>Last reviewed:</strong> [Date]</p><h4>Contacts</h4><table style="width:100%" border="1"><tbody><tr><th>Role</th><th>Name</th><th>Contact</th><th>Out of Hours</th></tr><tr><td>Client decision maker</td><td></td><td></td><td></td></tr><tr><td>Technical lead</td><td></td><td></td><td></td></tr><tr><td>Cyber insurer</td><td></td><td></td><td></td></tr><tr><td>Legal counsel</td><td></td><td></td><td></td></tr></tbody></table><h4>Insurance and Notification Requirements</h4><ul><li>Policy number and insurer:</li><li>Notification deadline:</li><li>Approved responders required:</li><li>Regulatory reporting obligations:</li></ul><p><em>The authorized client decision maker controls insurer, legal, law-enforcement and regulatory notification. N45 must not contact an external party without recorded authorization unless independently required by law.</em></p><h4>Severity Definitions</h4><p></p><h4>Response Stages</h4><ol><li>Detect and declare</li><li>Contain and isolate</li><li>Preserve evidence</li><li>Confirm the client-authorized notification decision</li><li>Eradicate</li><li>Recover and verify</li><li>Review</li></ol><h4>Evidence Handling</h4><p></p>',
        ],
        [
            'Technology Review Notes',
            'Standing agenda and decision record for a recurring client technology review',
            '<h3>Technology Review</h3><p><strong>Client:</strong> [Client]<br><strong>Period:</strong> [Period]<br><strong>Attendees:</strong> [Attendees]<br><strong>Date:</strong> [Date]</p><h4>Business Changes and Priorities</h4><p></p><h4>Service Outcomes</h4><ul><li>Material incidents and recurring themes:</li><li>Response and resolution exceptions:</li><li>Operational improvements completed:</li></ul><h4>Security and Continuity</h4><ul><li>Open and accepted risks:</li><li>Backup and restore evidence:</li><li>Identity and security exceptions:</li></ul><h4>Asset and License Position</h4><ul><li>End-of-life exposure:</li><li>Warranty expiry in the next 12 months:</li><li>License changes:</li></ul><h4>Roadmap and Budget</h4><table style="width:100%" border="1"><tbody><tr><th>Item</th><th>Business Driver</th><th>Indicative Cost</th><th>Target Period</th><th>Decision</th></tr><tr><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Agreed Actions</h4><table style="width:100%" border="1"><tbody><tr><th>Action</th><th>Owner</th><th>Due</th></tr><tr><td></td><td></td><td></td></tr></tbody></table>',
        ],
        [
            'Client Service Baseline',
            'Accepted support scope, ownership, tools, exclusions and known risks for a managed client',
            '<h3>Client Service Baseline</h3><p><strong>Client:</strong> [Client]<br><strong>Effective:</strong> [Date]<br><strong>Approved by:</strong> [Contact]</p><h4>Authorized Contacts and Escalation</h4><table style="width:100%" border="1"><tbody><tr><th>Role</th><th>Name</th><th>Authority</th><th>Contact</th></tr><tr><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Locations and Business Hours</h4><p></p><h4>Supported Services and Systems</h4><table style="width:100%" border="1"><tbody><tr><th>Service or System</th><th>Owner</th><th>Support Scope</th><th>Monitoring</th><th>Backup</th></tr><tr><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Explicit Exclusions</h4><p></p><h4>Management and Security Tools</h4><ul><li>Level policy and device count:</li><li>Endpoint protection:</li><li>Email and identity security:</li><li>Backup products and limits:</li></ul><h4>Critical Applications and Vendors</h4><p></p><h4>Known and Accepted Risks</h4><table style="width:100%" border="1"><tbody><tr><th>Risk</th><th>Impact</th><th>Owner</th><th>Decision</th><th>Review Date</th></tr><tr><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Acceptance</h4><p>The client confirms that the supported scope, exclusions and known risks above accurately reflect the service baseline.</p>',
        ],
        [
            'Microsoft 365 Security Triage Report',
            'Fixed-scope tenant findings, evidence and remediation priorities',
            '<h3>Microsoft 365 Security Triage</h3><p><strong>Client:</strong> [Client]<br><strong>Tenant:</strong> [Tenant]<br><strong>Assessment date:</strong> [Date]<br><strong>Assessor:</strong> [Technician]</p><p><em>This is a point-in-time operational assessment, not a compliance certification or penetration test. No tenant changes are included unless separately authorized.</em></p><h4>Scope and Evidence</h4><p></p><h4>Executive Summary</h4><p></p><h4>Findings</h4><table style="width:100%" border="1"><tbody><tr><th>Priority</th><th>Area</th><th>Observation</th><th>Business Impact</th><th>Evidence</th><th>Recommendation</th></tr><tr><td></td><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Immediate Actions</h4><ol><li></li></ol><h4>Remediation Options</h4><p></p><h4>Client Decisions</h4><p></p>',
        ],
        [
            'Security & Continuity Assessment Report',
            'Risk-ranked security, identity, network, backup and continuity findings',
            '<h3>Security &amp; Continuity Assessment</h3><p><strong>Client:</strong> [Client]<br><strong>Site:</strong> [Site]<br><strong>Assessment date:</strong> [Date]</p><p><em>This report is a point-in-time operational assessment. It is not a certification, legal opinion, penetration test or guarantee against an incident.</em></p><h4>Scope, Limitations and Evidence Gaps</h4><p></p><h4>Executive Risk Summary</h4><p></p><h4>Findings</h4><table style="width:100%" border="1"><tbody><tr><th>Priority</th><th>Domain</th><th>Finding</th><th>Impact</th><th>Evidence</th><th>Recommended Action</th><th>Owner</th></tr><tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Continuity and Recovery</h4><ul><li>Critical systems and dependencies:</li><li>Backup coverage and exclusions:</li><li>Most recent restore evidence:</li><li>Recovery objective gaps:</li></ul><h4>Prioritized Roadmap</h4><table style="width:100%" border="1"><tbody><tr><th>Timing</th><th>Outcome</th><th>Indicative Effort</th><th>Decision</th></tr><tr><td>Now</td><td></td><td></td><td></td></tr></tbody></table>',
        ],
        [
            'Network Assessment Report',
            'Configuration findings, topology evidence and a prioritized network roadmap',
            '<h3>Network Assessment &amp; Diagram</h3><p><strong>Client:</strong> [Client]<br><strong>Site:</strong> [Site]<br><strong>Assessment date:</strong> [Date]</p><h4>Scope and Discovery Method</h4><p></p><h4>Current-State Diagram</h4><p>[Attach or link diagram]</p><h4>Inventory and Support Status</h4><table style="width:100%" border="1"><tbody><tr><th>Device</th><th>Role</th><th>Model</th><th>Firmware</th><th>Support or License</th><th>Lifecycle</th></tr><tr><td></td><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Configuration Findings</h4><table style="width:100%" border="1"><tbody><tr><th>Priority</th><th>Area</th><th>Finding</th><th>Impact</th><th>Recommendation</th></tr><tr><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Single Points of Failure and Access Gaps</h4><p></p><h4>Recommended Roadmap</h4><p></p>',
        ],
        [
            'Automation Runbook',
            'Ownership, data mappings, operation, failure handling and support boundaries for an automation',
            '<h3>Automation Runbook</h3><p><strong>Workflow:</strong> [Workflow]<br><strong>Client:</strong> [Client]<br><strong>Business owner:</strong> [Contact]<br><strong>Technical owner:</strong> [Technician]<br><strong>Last reviewed:</strong> [Date]</p><h4>Business Outcome and Trigger</h4><p></p><h4>Systems and Source of Truth</h4><table style="width:100%" border="1"><tbody><tr><th>System</th><th>Role</th><th>Authoritative Fields</th><th>Matching Key</th><th>Credential Vault Reference</th></tr><tr><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Workflow and Data Mapping</h4><p></p><h4>Human Approval Points</h4><p></p><h4>Failure, Retry and Exception Handling</h4><ul><li>Retry policy:</li><li>Alert destination:</li><li>Exception queue:</li><li>Manual fallback:</li></ul><h4>Sensitive Data, Logging and Retention</h4><p></p><h4>Pause, Rollback and Recovery</h4><ol><li></li></ol><h4>Support Boundary and Change Process</h4><p></p><h4>Test and Change History</h4><table style="width:100%" border="1"><tbody><tr><th>Date</th><th>Change</th><th>Result</th><th>Approved By</th></tr><tr><td></td><td></td><td></td><td></td></tr></tbody></table>',
        ],
        [
            'Stabilization Sprint Closeout',
            'Completed outcomes, evidence, remaining risks and client decisions from a sprint',
            '<h3>Stabilization Sprint Closeout</h3><p><strong>Client:</strong> [Client]<br><strong>Sprint:</strong> [Scope]<br><strong>Completed:</strong> [Date]</p><h4>Approved Outcomes</h4><p></p><h4>Completed Work and Evidence</h4><table style="width:100%" border="1"><tbody><tr><th>Outcome</th><th>Change</th><th>Validation</th><th>Documentation Updated</th></tr><tr><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Remaining and Out-of-Scope Findings</h4><table style="width:100%" border="1"><tbody><tr><th>Priority</th><th>Finding</th><th>Business Impact</th><th>Recommended Next Step</th><th>Decision</th></tr><tr><td></td><td></td><td></td><td></td><td></td></tr></tbody></table><h4>Operational Handoff</h4><ul><li>Monitoring and alerts:</li><li>Credential and ownership changes:</li><li>Known exceptions:</li><li>Follow-up review:</li></ul><h4>Acceptance</h4><p></p>',
        ],
    ];

    return $document_templates;

}

// ------------------------------
// starterContentContractTemplates
// These are operational starting points, not a substitute for counsel.
// ------------------------------
function starterContentContractTemplates() {

    return [
        [
            'name' => 'Managed Care Agreement',
            'description' => 'Operational starting point for the recurring Managed Care service; legal review required before signature',
            'type' => 'Fully Managed',
            'renewal_frequency' => 'Annually',
            'sla_low_response' => 8,
            'sla_low_resolution' => 72,
            'sla_medium_response' => 4,
            'sla_medium_resolution' => 24,
            'sla_high_response' => 1,
            'sla_high_resolution' => 8,
            'rate_standard' => 175,
            'rate_after_hours' => 275,
            'support_hours' => 'Mon-Fri 8am-5pm ET, excluding holidays',
            'net_terms' => 0,
            'details' => '<p><strong>DRAFT OPERATIONAL TEMPLATE - LEGAL REVIEW REQUIRED BEFORE CLIENT SIGNATURE.</strong></p><h4>Service</h4><p>Managed Care is billed per managed user at the rate shown on the accepted order, subject to a $1,750 monthly minimum. Recurring service is invoiced in advance.</p><h4>Included</h4><ul><li>Business-hours remote support for covered users and systems</li><li>Endpoint monitoring and patch management through the approved management platform</li><li>Microsoft 365 and Entra ID administration</li><li>ITFlow documentation, vendor coordination and recurring technology reviews</li></ul><h4>Separate Charges</h4><ul><li>Microsoft and third-party licenses</li><li>Backup products, storage and retention</li><li>Hardware, cabling, projects and migrations</li><li>Scheduled after-hours work at $275 per hour</li><li>Priority-one emergency response at $450 per hour with a two-hour minimum</li></ul><h4>Scope and Baseline</h4><p>The accepted order and Client Service Baseline identify covered users, systems, locations, products and exclusions. Unsupported or newly discovered systems require written scope approval.</p><h4>Response Targets</h4><p>Response and resolution figures are business-hours targets, not guarantees. Resolution depends on access, client decisions, third parties, vendor support, hardware availability and the technical nature of the issue.</p><h4>Client Responsibilities</h4><p>The client will maintain authorized contacts, timely access, supported licensing, lawful data practices and current cyber-insurance information, and will promptly approve or reject material changes.</p><h4>Security and Backup</h4><p>No product is considered active until it is licensed, deployed, checking in and documented. Monitoring and backup do not eliminate risk or guarantee recovery.</p><h4>Changes and Projects</h4><p>Material changes, migrations and projects require an accepted quote or statement of work. Emergency containment may be performed only within the authority documented for the incident.</p><h4>Termination and Handover</h4><p>Offboarding will follow the agreed notice, access, retention and handover plan. Security-critical access revocation will not be delayed by a billing dispute.</p>',
        ],
        [
            'name' => 'Project Services Agreement',
            'description' => 'Operational starting point for fixed-scope assessments, sprints, migrations and implementations; legal review required',
            'type' => 'Partially Managed',
            'renewal_frequency' => 'Manual',
            'sla_low_response' => 8,
            'sla_low_resolution' => 0,
            'sla_medium_response' => 4,
            'sla_medium_resolution' => 0,
            'sla_high_response' => 2,
            'sla_high_resolution' => 0,
            'rate_standard' => 225,
            'rate_after_hours' => 275,
            'support_hours' => 'Scheduled project windows in Eastern Time',
            'net_terms' => 0,
            'details' => '<p><strong>DRAFT OPERATIONAL TEMPLATE - LEGAL REVIEW REQUIRED BEFORE CLIENT SIGNATURE.</strong></p><h4>Statement of Work</h4><p>The accepted quote or statement of work controls scope, deliverables, assumptions, exclusions, schedule and price. Work outside that scope requires written change approval.</p><h4>Payment</h4><p>Assessments and short engagements are prepaid unless the accepted order states otherwise. Stabilization Sprints normally require a 50 percent deposit. Hardware, licensing and non-cancellable vendor charges are due before ordering.</p><h4>Client Dependencies</h4><p>Dates depend on timely access, decisions, accurate information, third-party availability and a client decision maker. Delays outside the provider control may move the schedule and create additional effort.</p><h4>Change Control</h4><p>Material scope, schedule or risk changes will be documented with impact and price before implementation. A technician may pause work when prerequisites or safe rollback are missing.</p><h4>Testing and Acceptance</h4><p>Acceptance criteria are defined before delivery. The client will test and report material exceptions within the review period stated in the order; unresolved exceptions will be documented at closeout.</p><h4>Data and Access</h4><p>Least-privilege access and approved service identities will be used. The client remains responsible for lawful authority over the systems and data placed in scope.</p><h4>Handoff</h4><p>Deliverables include the documentation identified in the order. Ongoing monitoring, support and material changes are separate unless explicitly included.</p>',
        ],
        [
            'name' => 'Hourly Support Terms',
            'description' => 'Operational starting point for prepaid or time-and-materials support outside Managed Care; legal review required',
            'type' => 'Break/Fix',
            'renewal_frequency' => 'Manual',
            'sla_low_response' => 8,
            'sla_low_resolution' => 0,
            'sla_medium_response' => 4,
            'sla_medium_resolution' => 0,
            'sla_high_response' => 2,
            'sla_high_resolution' => 0,
            'rate_standard' => 175,
            'rate_after_hours' => 275,
            'support_hours' => 'Mon-Fri 8am-5pm ET, excluding holidays',
            'net_terms' => 0,
            'details' => '<p><strong>DRAFT OPERATIONAL TEMPLATE - LEGAL REVIEW REQUIRED BEFORE CLIENT SIGNATURE.</strong></p><h4>Rates</h4><p>Remote support is $175 per hour in 15-minute increments. Onsite support is $195 per hour with a two-hour minimum. Scheduled after-hours work is $275 per hour. Priority-one emergency response is $450 per hour with a two-hour minimum.</p><h4>Authorization</h4><p>The requester confirms authority to approve the work and charges. Same-Day Remote Rescue is prepaid at $450 for the first two hours; work stops at the prepaid limit unless additional time is approved.</p><h4>Best Effort</h4><p>Hourly support is best effort and does not include proactive monitoring, backup, security management, guaranteed resolution or an ongoing support commitment.</p><h4>Data Protection</h4><p>The client must identify critical data and available backups. The technician may pause a risky change until recoverability and rollback are reasonably established.</p><h4>Materials and Third Parties</h4><p>Hardware, software, licensing, vendor charges and travel outside the included service radius are separate.</p>',
        ],
    ];
}

// ------------------------------
// starterContentSoftwareTemplates
// ------------------------------
function starterContentSoftwareTemplates() {

    return [
        [
            'name' => 'Level RMM Agent',
            'version' => 'Current',
            'description' => 'Remote monitoring and endpoint management agent',
            'type' => 'System Software',
            'license_type' => 'Device',
            'notes' => 'Required only on approved managed devices. Record the Level organization, device and policy mapping in ITFlow.',
        ],
        [
            'name' => 'Microsoft 365 Apps',
            'version' => 'Current Channel',
            'description' => 'Microsoft productivity desktop applications',
            'type' => 'Productivity Suite',
            'license_type' => 'User',
            'notes' => 'Confirm the assigned Microsoft 365 license supports desktop applications before deployment.',
        ],
        [
            'name' => 'Microsoft OneDrive',
            'version' => 'Current',
            'description' => 'Microsoft file synchronization client',
            'type' => 'Desktop Application',
            'license_type' => 'User',
            'notes' => 'Document Known Folder Move, Files On-Demand and any excluded data locations.',
        ],
        [
            'name' => 'Microsoft Teams',
            'version' => 'Current',
            'description' => 'Microsoft collaboration and meetings client',
            'type' => 'Desktop Application',
            'license_type' => 'User',
            'notes' => 'Confirm licensing and client policy before deployment.',
        ],
        [
            'name' => 'Microsoft Edge',
            'version' => 'Current Stable',
            'description' => 'Managed Microsoft web browser',
            'type' => 'Desktop Application',
            'license_type' => 'Device',
            'notes' => 'Document managed browser policies, extensions and update channel.',
        ],
        [
            'name' => 'Google Chrome',
            'version' => 'Current Stable',
            'description' => 'Google web browser',
            'type' => 'Desktop Application',
            'license_type' => 'Device',
            'notes' => 'Install only when required by the client application stack; document managed policies and extensions.',
        ],
        [
            'name' => 'Adobe Acrobat Reader',
            'version' => 'Current',
            'description' => 'Adobe PDF reader',
            'type' => 'Desktop Application',
            'license_type' => 'Device',
            'notes' => 'Reader only. Paid Acrobat licensing is recorded separately.',
        ],
        [
            'name' => 'n8n',
            'version' => 'Current Supported',
            'description' => 'Workflow automation and integration platform',
            'type' => 'Web Application',
            'license_type' => 'Usage-based',
            'notes' => 'Record instance ownership, licensing, workflow owner, credential vault references, alerting and backup. Default client work to client-owned instances unless commercial hosting rights are confirmed.',
        ],
        [
            'name' => 'SentinelOne Agent',
            'version' => 'Current Supported',
            'description' => 'Endpoint protection agent',
            'type' => 'Security Software',
            'license_type' => 'Device',
            'notes' => 'Staged template only. Do not describe an endpoint as protected until the agent is licensed, assigned to the approved policy and reporting healthy.',
        ],
        [
            'name' => 'Windows 11 Pro',
            'version' => 'Current Supported Release',
            'description' => 'Microsoft workstation operating system',
            'type' => 'Operating System',
            'license_type' => 'Perpetual',
            'notes' => 'Record edition, activation, encryption recovery key location and supported-release status.',
        ],
    ];
}

// ------------------------------
// starterContentProducts
// name, type, code, price, income category, description.
// Hardware and resold SKUs come in at zero - they are quoted per deal or move
// with the vendor price list.
// ------------------------------
function starterContentProducts() {

    $products = [

        // Recurring managed services
        ['Managed Care', 'service', 'MS-CARE', '175.00', 'Managed Services', 'Per user, per month; $1,750 monthly minimum. Business-hours support, endpoint and Microsoft 365 administration, monitoring, documentation, vendor coordination and technology reviews. Licenses, backup, projects and after-hours work are separate.'],
        ['Managed Server', 'service', 'MS-SRV', '250.00', 'Managed Services', 'Per server, per month. Monitoring, patching and administration. Backup, security licensing and project work are separate.'],
        ['Managed Network - Site', 'service', 'MS-NET-SITE', '249.00', 'Managed Services', 'Per site, per month. Includes management of one firewall, one switch and up to three access points. Vendor subscriptions are separate.'],
        ['Additional Network Device', 'service', 'MS-NET', '25.00', 'Managed Services', 'Per additional switch or access point, per month. Monitoring, firmware and configuration management.'],
        ['Additional Managed Firewall', 'service', 'MS-FW', '75.00', 'Managed Services', 'Per additional firewall, per month. Monitoring, firmware and routine rule management; vendor subscriptions are separate.'],
        ['Co-Managed IT', 'service', 'MS-COMG', '75.00', 'Managed Services', 'Per user, per month. Tooling, documentation and escalation support alongside an internal IT team.'],
        ['Microsoft 365 Management', 'service', 'MS-M365', '25.00', 'Managed Services', 'Per user, per month; $375 monthly minimum when sold outside Managed Care. Microsoft licenses and project work are separate.'],
        ['Managed Automation Support', 'service', 'AUTO-MGD', '250.00', 'Managed Services', 'Starting monthly price for monitoring and minor maintenance of documented production automations. New workflows and material changes are separate projects.'],
        ['Virtual CIO', 'service', 'MS-VCIO', '750.00', 'Consulting', 'Starting monthly price for strategic planning, budgeting, roadmap ownership and quarterly business reviews when sold separately.'],

        // Security
        ['Endpoint Protection', 'service', 'SEC-EDR', '15.00', 'Security', 'Per endpoint, per month. Activate only after an approved endpoint protection platform has been assigned and tested.'],
        ['DNS Filtering', 'service', 'SEC-DNS', '5.00', 'Security', 'Per endpoint, per month. Malicious and category-based web filtering.'],
        ['Email Security', 'service', 'SEC-MAIL', '6.00', 'Security', 'Per mailbox, per month. Spam, phishing and malware filtering.'],
        ['Security Awareness Training', 'service', 'SEC-SAT', '5.00', 'Security', 'Per user, per month. Training campaigns and simulated phishing.'],
        ['Password Manager', 'service', 'SEC-PWD', '5.00', 'Security', 'Per user, per month. Managed password vault.'],
        ['Dark Web Monitoring', 'service', 'SEC-DWM', '25.00', 'Security', 'Per client, per month. Credential exposure monitoring for client domains.'],
        ['Microsoft 365 Security Triage', 'service', 'SEC-M365-TRIAGE', '495.00', 'Security', 'Prepaid assessment for one Microsoft 365 tenant with up to 25 users. Includes findings, priority actions and a review call.'],
        ['Security & Continuity Assessment', 'service', 'SEC-ASSESS', '1950.00', 'Security', 'Starting price for one site with up to 25 users. Covers security posture, identity, network, backup and continuity risks with a prioritized remediation plan; not a certification audit.'],

        // Backup
        ['Managed Backup - Workstation', 'service', 'BU-WKS', '25.00', 'Backup', 'Per workstation, per month. Image-based backup with offsite copy; storage and retention limits apply.'],
        ['Managed Backup - Server', 'service', 'BU-SRV', '125.00', 'Backup', 'Per server, per month. Image-based backup with offsite copy and scheduled restore validation; storage and retention limits apply.'],
        ['Microsoft 365 Backup', 'service', 'BU-M365', '8.00', 'Backup', 'Per user, per month. Mail, calendar, contacts, OneDrive and SharePoint; storage and retention limits apply.'],
        ['Offsite Backup Storage', 'service', 'BU-STOR', '25.00', 'Backup', 'Per terabyte, per month. Offsite retention beyond the included allowance.'],

        // Licensing - Microsoft annual commitment list prices as of July 2026
        ['Microsoft 365 Business Basic', 'service', 'LIC-M365BB', '7.00', 'Licensing', 'Per user, per month on an annual commitment. Confirm the current vendor price before quoting.'],
        ['Microsoft 365 Business Standard', 'service', 'LIC-M365BS', '14.00', 'Licensing', 'Per user, per month on an annual commitment. Confirm the current vendor price before quoting.'],
        ['Microsoft 365 Business Premium', 'service', 'LIC-M365BP', '22.00', 'Licensing', 'Per user, per month on an annual commitment. Confirm the current vendor price before quoting.'],
        ['Microsoft 365 Exchange Online Plan 1', 'service', 'LIC-M365EX', '4.00', 'Licensing', 'Per mailbox, per month on an annual commitment. Confirm the current vendor price before quoting.'],
        ['Third Party Software Subscription', 'service', 'LIC-3P', '0.00', 'Software Sales', 'Generic resold subscription. Set the name and price per client.'],

        // Labor
        ['Remote Support', 'service', 'LAB-REM', '175.00', 'Labor', 'Per hour. Remote support outside an agreement, billed in 15-minute increments.'],
        ['Onsite Support', 'service', 'LAB-ONS', '195.00', 'Labor', 'Per hour. Onsite support with a two-hour minimum.'],
        ['After Hours Support', 'service', 'LAB-AH', '275.00', 'Labor', 'Per hour. Scheduled work outside business hours with a two-hour minimum.'],
        ['Emergency Response', 'service', 'LAB-EMERG', '450.00', 'Labor', 'Per hour. Priority-one emergency response with a two-hour minimum.'],
        ['Project Engineering', 'service', 'LAB-PRJ', '225.00', 'Labor', 'Per hour. Project delivery, implementation and automation work.'],
        ['Network Engineering', 'service', 'LAB-NET', '225.00', 'Labor', 'Per hour. Network, firewall and server engineering.'],
        ['Consulting', 'service', 'LAB-CON', '225.00', 'Consulting', 'Per hour. Advisory, design and assessment work.'],
        ['Data Recovery', 'service', 'LAB-REC', '225.00', 'Labor', 'Per hour. Best-effort recovery; successful recovery is not guaranteed.'],
        ['End User Training', 'service', 'LAB-TRN', '175.00', 'Training', 'Per hour. Group or one-to-one training.'],
        ['Block Hours - 10 Hours', 'service', 'LAB-BLK10', '1650.00', 'Labor', 'Prepaid block of ten support hours, valid for twelve months.'],
        ['Block Hours - 20 Hours', 'service', 'LAB-BLK20', '3200.00', 'Labor', 'Prepaid block of twenty support hours, valid for twelve months.'],
        ['Travel - Hour', 'service', 'LAB-TRAVEL', '125.00', 'Reimbursable Expenses', 'Per hour of travel outside the included service radius.'],
        ['Mileage', 'service', 'LAB-MILE', '0.76', 'Reimbursable Expenses', 'Per mile beyond the included service radius. Uses the IRS business mileage rate effective July 1, 2026.'],

        // One off project and setup work
        ['Managed Care Onboarding', 'service', 'ONB-CLIENT', '1750.00', 'Onboarding', 'Starting price equal to one month of Managed Care. Discovery, documentation, tooling deployment and security baseline; $1,750 minimum.'],
        ['Stabilization Sprint', 'service', 'PRJ-STABILIZE', '2500.00', 'Projects', 'Starting price for a fixed-scope remediation sprint. Typical engagements range from $2,500 to $5,000.'],
        ['Stabilization Sprint Deposit', 'service', 'PRJ-STABILIZE-DEP', '1250.00', 'Projects', 'Standard 50 percent deposit against a $2,500 Stabilization Sprint. Adjust when the approved project total is higher.'],
        ['User Onboarding', 'service', 'ONB-USER', '250.00', 'Onboarding', 'Per user outside Managed Care. Account, licensing, access, security enrollment and documentation. Hardware and device deployment are separate.'],
        ['User Offboarding', 'service', 'ONB-OFFBOARD', '250.00', 'Onboarding', 'Per user outside Managed Care. Access revocation, session reset, license recovery, data handoff and documentation.'],
        ['Device Deployment', 'service', 'PRJ-WKS', '350.00', 'Projects', 'Per workstation. Build, enrollment, standard application setup, data migration and deployment; hardware is separate.'],
        ['Server Deployment', 'service', 'PRJ-SRV', '2500.00', 'Projects', 'Starting price per server. Build, configuration, migration and documentation; licenses and hardware are separate.'],
        ['Firewall Deployment', 'service', 'PRJ-FW', '850.00', 'Projects', 'Per firewall. Configuration, cutover and documentation.'],
        ['Wireless Survey and Deployment', 'service', 'PRJ-WIFI', '1500.00', 'Projects', 'Starting price per site. Survey, design, installation and tuning; hardware and cabling are separate.'],
        ['Microsoft 365 Migration', 'service', 'PRJ-MAIL', '150.00', 'Projects', 'Per user with a $1,500 minimum. Migration, cutover and endpoint reconfiguration; licenses are separate.'],
        ['Data Migration', 'service', 'PRJ-DATA', '1500.00', 'Projects', 'Starting price for a file share or data platform migration. Final pricing follows discovery.'],
        ['Equipment Disposal', 'service', 'PRJ-DISP', '25.00', 'Projects', 'Per device. Secure data destruction and certified recycling.'],
        ['Network Assessment & Diagram', 'service', 'PRJ-NET-ASSESS', '1500.00', 'Projects', 'Starting price for an onsite configuration review, inventory, diagram, risk-ranked findings and client readout.'],
        ['Small Office Network Deployment', 'service', 'PRJ-NET-BUILD', '4500.00', 'Projects', 'Starting price for a small-office network replacement. Hardware, subscriptions and structured cabling are quoted separately.'],
        ['Automation Discovery', 'service', 'AUTO-DISC', '750.00', 'Consulting', 'Process mapping, system review and a prioritized automation plan with scope and expected business value.'],
        ['Automation Sprint', 'service', 'AUTO-SPRINT', '2500.00', 'Projects', 'Starting price for one production workflow with testing, documentation and handoff. Material third-party license costs are separate.'],
        ['Same-Day Remote Rescue', 'service', 'SUP-RESCUE', '450.00', 'Support', 'Prepaid same-day remote response covering the first two hours. Additional time is billed at the Remote Support rate.'],

        // Hardware - quoted per deal, seeded at zero
        ['Desktop', 'product', 'HW-DSK', '0.00', 'Hardware Sales', 'Business class desktop. Priced per quote.'],
        ['Laptop', 'product', 'HW-LAP', '0.00', 'Hardware Sales', 'Business class laptop. Priced per quote.'],
        ['Docking Station', 'product', 'HW-DOCK', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Monitor', 'product', 'HW-MON', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Server', 'product', 'HW-SRV', '0.00', 'Hardware Sales', 'Rack or tower server. Priced per quote.'],
        ['Network Attached Storage', 'product', 'HW-NAS', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Firewall Appliance', 'product', 'HW-FW', '0.00', 'Hardware Sales', 'Priced per quote, excludes subscription.'],
        ['Switch - 24 Port', 'product', 'HW-SW24', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Switch - 48 Port', 'product', 'HW-SW48', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Wireless Access Point', 'product', 'HW-AP', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Uninterruptible Power Supply', 'product', 'HW-UPS', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Solid State Drive', 'product', 'HW-SSD', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Memory Upgrade', 'product', 'HW-RAM', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Keyboard and Mouse', 'product', 'HW-KBM', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Headset', 'product', 'HW-HS', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Webcam', 'product', 'HW-CAM', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Printer', 'product', 'HW-PRT', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Toner Cartridge', 'product', 'HW-TON', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['VoIP Handset', 'product', 'HW-PHONE', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Patch Cable', 'product', 'HW-CBL', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Rack Cabinet', 'product', 'HW-RACK', '0.00', 'Hardware Sales', 'Priced per quote.'],
        ['Power Distribution Unit', 'product', 'HW-PDU', '0.00', 'Hardware Sales', 'Priced per quote.'],

    ];

    return $products;

}
