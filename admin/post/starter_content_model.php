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
            'description' => 'Client, location, contact, credential and asset tags.',
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
    mysqli_query($mysqli, "INSERT INTO $table SET $set");
    return intval(mysqli_insert_id($mysqli));
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

        $ticket_template_id = starterInsert($mysqli, 'ticket_templates', [
            'ticket_template_name' => $ticket_template['name'],
            'ticket_template_description' => $ticket_template['description'],
            'ticket_template_subject' => $ticket_template['subject'],
            'ticket_template_details' => $ticket_template['details'],
        ], ['ticket_template_details']);

        $order = 1;
        foreach ($ticket_template['tasks'] as $task) {
            starterInsert($mysqli, 'task_templates', [
                'task_template_name' => $task[0],
                'task_template_order' => $order,
                'task_template_completion_estimate' => $task[1],
                'task_template_ticket_template_id' => $ticket_template_id,
            ]);
            $order++;
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
        [1, 'Managed', '#28a745', 'handshake'],
        [1, 'Co-Managed', '#20c997', 'people-arrows'],
        [1, 'Break Fix', '#fd7e14', 'wrench'],
        [1, 'Block Hours', '#6f42c1', 'hourglass-half'],
        [1, 'Prospect', '#17a2b8', 'binoculars'],
        [1, 'Onboarding', '#007bff', 'user-plus'],
        [1, 'Offboarding', '#dc3545', 'user-minus'],
        [1, 'Key Account', '#ffc107', 'star'],
        [1, 'At Risk', '#d81b60', 'exclamation-triangle'],
        [1, 'Past Due', '#dc3545', 'file-invoice-dollar'],
        [1, 'Multi Site', '#001f3f', 'map-marked-alt'],
        [1, 'Non Profit', '#3d9970', 'hand-holding-heart'],
        [1, 'After Hours Support', '#343a40', 'moon'],
        [1, 'Compliance', '#795548', 'balance-scale'],
        [1, 'Cyber Insurance', '#6610f2', 'shield-alt'],
        [1, 'Service Hold', '#6c757d', 'pause-circle'],

        // Location
        [2, 'Head Office', '#007bff', 'building'],
        [2, 'Branch', '#17a2b8', 'store'],
        [2, 'Warehouse', '#795548', 'warehouse'],
        [2, 'Retail', '#e83e8c', 'cash-register'],
        [2, 'Home Office', '#20c997', 'house-user'],
        [2, 'Data Center', '#001f3f', 'server'],
        [2, 'Server Room', '#6f42c1', 'network-wired'],
        [2, 'Restricted Access', '#dc3545', 'lock'],
        [2, 'Onsite Spares', '#6c757d', 'boxes'],

        // Contact
        [3, 'Primary', '#007bff', 'user-tie'],
        [3, 'Technical', '#17a2b8', 'laptop-code'],
        [3, 'Billing', '#28a745', 'file-invoice-dollar'],
        [3, 'Authorized Approver', '#6f42c1', 'check-double'],
        [3, 'Executive', '#001f3f', 'crown'],
        [3, 'Emergency', '#dc3545', 'phone-volume'],
        [3, 'After Hours', '#343a40', 'moon'],
        [3, 'Onsite Point of Contact', '#20c997', 'map-marker-alt'],
        [3, 'Not Authorized', '#6c757d', 'ban'],
        [3, 'Departed', '#795548', 'user-slash'],

        // Credential
        [4, 'Domain Admin', '#dc3545', 'user-shield'],
        [4, 'Local Admin', '#fd7e14', 'user-cog'],
        [4, 'Service Account', '#6c757d', 'robot'],
        [4, 'Break Glass', '#d81b60', 'fire-extinguisher'],
        [4, 'Firewall', '#001f3f', 'shield-alt'],
        [4, 'Switch', '#6610f2', 'network-wired'],
        [4, 'Wireless', '#17a2b8', 'wifi'],
        [4, 'Hypervisor', '#343a40', 'server'],
        [4, 'Backup Console', '#3d9970', 'database'],
        [4, 'Microsoft 365', '#0078d4', 'cloud'],
        [4, 'Registrar and DNS', '#20c997', 'globe'],
        [4, 'Hosting', '#795548', 'hdd'],
        [4, 'Vendor Portal', '#adb5bd', 'external-link-alt'],
        [4, 'Shared', '#ffc107', 'users'],
        [4, 'Rotate Quarterly', '#6f42c1', 'sync-alt'],

        // Asset
        [5, 'Workstation', '#007bff', 'desktop'],
        [5, 'Laptop', '#17a2b8', 'laptop'],
        [5, 'Server', '#001f3f', 'server'],
        [5, 'Hypervisor Host', '#343a40', 'layer-group'],
        [5, 'Storage', '#795548', 'hdd'],
        [5, 'Firewall', '#dc3545', 'shield-alt'],
        [5, 'Switch', '#6610f2', 'network-wired'],
        [5, 'Access Point', '#20c997', 'wifi'],
        [5, 'Printer', '#6c757d', 'print'],
        [5, 'UPS', '#ffc107', 'car-battery'],
        [5, 'VoIP Handset', '#28a745', 'phone-alt'],
        [5, 'Mobile', '#e83e8c', 'mobile-alt'],
        [5, 'Business Critical', '#d81b60', 'exclamation-circle'],
        [5, 'Monitored', '#3d9970', 'heartbeat'],
        [5, 'Backed Up', '#001f3f', 'database'],
        [5, 'Endpoint Protection', '#6f42c1', 'user-shield'],
        [5, 'Under Warranty', '#28a745', 'certificate'],
        [5, 'Warranty Expired', '#fd7e14', 'calendar-times'],
        [5, 'End of Life', '#adb5bd', 'skull-crossbones'],
        [5, 'Leased', '#6c757d', 'file-contract'],
        [5, 'Spare Stock', '#795548', 'boxes'],
        [5, 'Patch Excluded', '#ffc107', 'ban'],
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
            'tasks' => [
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
            ],
        ],
        [
            'name' => 'User Offboarding',
            'description' => 'Authorized access revocation and data handoff for a departing user',
            'subject' => 'User offboarding - [Employee Name]',
            'details' => '<p>Do not begin without written authorization from an authorized contact. Confirm the effective time, legal-hold or retention requirements, data owner and device disposition before making changes.</p>',
            'tasks' => [
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
            ],
        ],
        [
            'name' => 'Device Deployment',
            'description' => 'Build, secure, document and hand over a desktop or laptop',
            'subject' => 'Device deployment - [Client] - [User]',
            'details' => '<p>Confirm the approved quote, assigned user, management scope and old-device disposition. Security, backup and software products must only be installed when included in the client agreement or project scope.</p>',
            'tasks' => [
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
            ],
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
            'tasks' => [
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
            ],
        ],
        [
            'name' => 'Managed Care Onboarding',
            'description' => 'Bring a signed client into the documented Managed Care operating model',
            'subject' => 'Managed Care onboarding - [Client]',
            'details' => '<p>Do not deploy tools or assume administrative control until the agreement, onboarding scope and authorized contacts are confirmed. The output is a documented client with explicit supported and excluded systems.</p>',
            'tasks' => [
                ['Confirm the countersigned agreement is on file', 15],
                ['Confirm scope, minimum billing, exclusions and onboarding deliverables', 30],
                ['Hold the kickoff call and agree contacts, approvals and escalation paths', 60],
                ['Create and normalize the client, locations, contacts and vendors in ITFlow', 60],
                ['Discover and document networks, circuits and critical dependencies', 180],
                ['Inventory users and assets and map them to Level records', 180],
                ['Deploy and verify the Level agent on approved managed devices', 120],
                ['Deploy only the security and backup products included in the agreement', 120],
                ['Move administrative credentials into the vault and verify access ownership', 120],
                ['Record supported systems, exclusions, warranties and lifecycle risks', 90],
                ['Complete the Microsoft 365 and operational security baseline', 120],
                ['Verify billing, recurring items, licenses and payment method', 45],
                ['Establish maintenance, review and reporting cadence', 20],
                ['Hold the 30-day review and obtain acceptance of the documented baseline', 60],
            ],
        ],
        [
            'name' => 'Client Offboarding',
            'description' => 'Cleanly exit a departing client',
            'subject' => 'Client offboarding - [Client]',
            'details' => '<p>Agree in writing what will be handed over, what N45 must retain, when access changes occur and who is authorized to receive credentials. Billing disputes must never delay a security-critical access revocation.</p>',
            'tasks' => [
                ['Confirm the notice period and termination date in writing', 20],
                ['Agree the handover scope with the client or incoming provider', 45],
                ['Confirm the identity and authority of every handover recipient', 20],
                ['Prepare the final invoice independently from the technical exit', 30],
                ['Export approved documentation and record exactly what was released', 60],
                ['Hand over credentials through an approved secure channel and confirm receipt', 45],
                ['Remove Level and other agents at the agreed time without leaving protection gaps', 60],
                ['Release or transfer licenses and subscriptions', 45],
                ['Agree the backup retention and destruction date', 20],
                ['Revoke all remaining access and delegated permissions', 45],
                ['Reconcile integrations and disable client-specific automations', 30],
                ['Archive the client record only after retention obligations are recorded', 30],
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
