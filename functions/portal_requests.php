<?php

/*
 * Versioned client-portal request catalog.
 *
 * Catalog items and fields are editable drafts. Publishing creates an immutable
 * version and pins the ticket template's current immutable runbook version.
 * Portal submissions retain validated typed responses plus hashes so approval
 * and ticket creation can fail closed if either snapshot is later corrupted.
 */

function portalRequestTypes() {
    return [
        'new_user' => 'New user',
        'termination' => 'Termination',
        'new_device' => 'New device',
        'access_change' => 'Access change',
        'incident' => 'Incident',
        'scheduled_work' => 'Scheduled work',
        'other' => 'Other request',
    ];
}

function portalRequestFieldTypes() {
    return [
        'text' => 'Short text',
        'textarea' => 'Long text',
        'email' => 'Email address',
        'phone' => 'Phone number',
        'integer' => 'Whole number',
        'date' => 'Date',
        'datetime' => 'Date and time',
        'select' => 'Choice list',
        'checkbox' => 'Confirmation checkbox',
        'asset' => 'Client device',
        'contact' => 'Client contact',
    ];
}

function portalRequestPermissionRules() {
    return [
        'any' => 'Any portal user',
        'manager' => 'Portal managers',
        'technical' => 'Technical contacts',
        'billing' => 'Billing contacts',
        'primary' => 'Primary contacts',
    ];
}

function portalRequestApplicabilityRules() {
    return [
        'all' => 'All clients',
        'service_name' => 'Clients with an exact service name',
        'service_category' => 'Clients with a service category',
        'client_allowlist' => 'Only listed client IDs',
        'client_denylist' => 'All except listed client IDs',
    ];
}

function portalRequestApprovalRules() {
    return [
        'none' => 'No pre-approval',
        'manager' => 'Another portal manager',
        'technical' => 'Another technical contact',
        'billing' => 'Another billing contact',
        'primary' => 'Another primary contact',
        'internal' => 'Internal support approval',
    ];
}

function portalRequestStatusLabels() {
    return [
        'Submitted' => 'Submitted',
        'PendingApproval' => 'Waiting for approval',
        'Approved' => 'Approved',
        'Declined' => 'Declined',
        'Initiated' => 'Ticket created',
    ];
}

function portalRequestStatusLabel($status) {
    $status = (string) $status;
    return portalRequestStatusLabels()[$status] ?? 'Unknown';
}

function portalRequestStatusBadgeClass($status) {
    $status = (string) $status;
    if ($status === 'Initiated') {
        return 'badge-success';
    }
    if ($status === 'Declined') {
        return 'badge-danger';
    }
    return 'badge-warning';
}

/**
 * Client-facing audit history deliberately exposes an identity class, not a
 * durable contact/user primary key. The exact IDs remain in the append-only
 * event table for authorized internal audit and retention checks.
 */
function portalRequestClientEventActorLabel($event) {
    $actor_type = (string) ($event['portal_request_submission_event_actor_type'] ?? '');
    $action = (string) ($event['portal_request_submission_event_action'] ?? '');
    if ($actor_type === 'agent') {
        return 'Internal support';
    }
    if ($actor_type === 'system') {
        return 'System';
    }
    if ($actor_type === 'contact') {
        return $action === 'submitted' ? 'Requesting contact' : 'Authorized client contact';
    }
    return 'Unknown actor';
}

function portalRequestNormalizeKey($value, $fallback = 'request') {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');
    return substr($value === '' ? $fallback : $value, 0, 100);
}

function portalRequestDbQuery($sql, $context) {
    global $mysqli;

    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        throw new RuntimeException($context . ': ' . mysqli_error($mysqli));
    }
    return $result;
}

function portalRequestCanonicalize($value) {
    if (!is_array($value)) {
        return $value;
    }
    if (array_keys($value) !== range(0, count($value) - 1)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $child) {
        $value[$key] = portalRequestCanonicalize($child);
    }
    return $value;
}

function portalRequestCanonicalJson($value) {
    $json = json_encode(
        portalRequestCanonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
    if ($json === false) {
        throw new RuntimeException('The request data could not be serialized safely');
    }
    return $json;
}

function portalRequestDefinitionHash($definition) {
    return hash('sha256', portalRequestCanonicalJson($definition));
}

function portalRequestParseInteger($raw) {
    $raw = trim((string) $raw);
    if ($raw === '-0' || !preg_match('/^-?(0|[1-9]\d*)$/', $raw)) {
        return null;
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => -2147483648, 'max_range' => 2147483647],
    ]);
    return $value === false ? null : intval($value);
}

function portalRequestParseEntityId($raw) {
    $raw = trim((string) $raw);
    if (!preg_match('/^[1-9]\d{0,9}$/', $raw)) {
        return 0;
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647],
    ]);
    return $value === false || (string) $value !== $raw ? 0 : intval($value);
}

/**
 * Parse a published client allow/deny list without PHP integer coercion. A
 * null return means at least one entry was empty, ambiguous or out of range.
 */
function portalRequestParseClientIdList($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $ids = [];
    foreach (preg_split('/\s*,\s*/', $raw) as $part) {
        $id = portalRequestParseEntityId($part);
        if (!$id) {
            return null;
        }
        $ids[$id] = $id;
    }
    return array_values($ids);
}

/**
 * Produce the lexical values used to bind one browser idempotency credential
 * to one immutable release and one request body. This intentionally avoids
 * live entity lookups so an exact retry still succeeds if a selected entity is
 * archived after the original transaction commits.
 */
function portalRequestCanonicalSubmittedValues($definition, $submitted) {
    $submitted = is_array($submitted) ? $submitted : [];
    $values = [];
    foreach (($definition['fields'] ?? []) as $field) {
        $key = (string) $field['key'];
        $type = (string) $field['type'];
        $raw = $submitted[$key] ?? '';
        if (is_array($raw)) {
            $raw = '';
        }
        $raw = str_replace("\0", '', trim((string) $raw));
        if ($type === 'checkbox') {
            $values[$key] = $raw === '1';
        } elseif ($raw === '') {
            $values[$key] = null;
        } elseif ($type === 'email') {
            $values[$key] = strtolower($raw);
        } elseif ($type === 'integer') {
            $integer = portalRequestParseInteger($raw);
            $values[$key] = $integer === null ? $raw : $integer;
        } elseif ($type === 'asset' || $type === 'contact') {
            $entity_id = portalRequestParseEntityId($raw);
            $values[$key] = $entity_id ?: $raw;
        } elseif ($type === 'datetime' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw)) {
            $values[$key] = str_replace('T', ' ', $raw) . ':00';
        } else {
            $values[$key] = $raw;
        }
    }
    return portalRequestCanonicalize($values);
}

function portalRequestSubmissionRequestHash($version_id, $definition, $submitted) {
    return hash('sha256', portalRequestCanonicalJson([
        'version_id' => intval($version_id),
        'responses' => portalRequestCanonicalSubmittedValues($definition, $submitted),
    ]));
}

/**
 * Compatibility adapter for agreement/SLA selection. Goal 8 can ask for a
 * stable request key without knowing any Goal 7 table names.
 */
function requestCatalogAgreementKeyForTicket(array $ticket): string {
    global $mysqli;

    $ticket_id = intval($ticket['ticket_id'] ?? 0);
    $client_id = intval($ticket['ticket_client_id'] ?? 0);
    if (!$ticket_id) {
        return '';
    }
    $client_filter = $client_id ? "AND s.portal_request_submission_client_id = $client_id" : '';
    try {
        $result = mysqli_query($mysqli, "SELECT s.portal_request_submission_version_id
            FROM portal_request_submissions s
            WHERE s.portal_request_submission_ticket_id = $ticket_id $client_filter LIMIT 1");
        if ($result === false) {
            throw new RuntimeException(mysqli_error($mysqli));
        }
        $row = mysqli_fetch_assoc($result);
        if (!$row) {
            return '';
        }
        $definition = portalRequestAssertVersion(intval($row['portal_request_submission_version_id']));
        return (string) ($definition['key'] ?? '');
    } catch (Throwable $exception) {
        error_log("Could not resolve the request catalog agreement key for ticket $ticket_id: " . $exception->getMessage());
        return '';
    }
}

function portalRequestOptions($json) {
    $options = json_decode((string) $json, true);
    if (!is_array($options)) {
        return [];
    }
    $clean = [];
    foreach ($options as $option) {
        $option = trim((string) $option);
        if ($option !== '' && strlen($option) <= 200 && !in_array($option, $clean, true)) {
            $clean[] = $option;
        }
    }
    return array_slice($clean, 0, 100);
}

function portalRequestDraftDefinition($item_id) {
    global $mysqli;

    $item_id = intval($item_id);
    $item = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT i.*,
        tt.ticket_template_published_version_id
        FROM portal_request_catalog_items i
        LEFT JOIN ticket_templates tt
            ON tt.ticket_template_id = i.portal_request_catalog_item_ticket_template_id
            AND tt.ticket_template_archived_at IS NULL
        WHERE i.portal_request_catalog_item_id = $item_id LIMIT 1"));
    if (!$item) {
        return null;
    }

    $definition = [
        'key' => (string) $item['portal_request_catalog_item_key'],
        'type' => (string) $item['portal_request_catalog_item_type'],
        'name' => (string) $item['portal_request_catalog_item_name'],
        'description' => (string) $item['portal_request_catalog_item_description'],
        'instructions' => (string) $item['portal_request_catalog_item_instructions'],
        'icon' => (string) $item['portal_request_catalog_item_icon'],
        'category_id' => intval($item['portal_request_catalog_item_category_id']),
        'ticket_template_id' => intval($item['portal_request_catalog_item_ticket_template_id']),
        'runbook_version_id' => intval($item['ticket_template_published_version_id']),
        'permission_rule' => (string) $item['portal_request_catalog_item_permission_rule'],
        'applicability_rule' => (string) $item['portal_request_catalog_item_applicability_rule'],
        'applicability_value' => (string) $item['portal_request_catalog_item_applicability_value'],
        'approval_rule' => (string) $item['portal_request_catalog_item_approval_rule'],
        'sort_order' => intval($item['portal_request_catalog_item_order']),
        'fields' => [],
    ];

    $fields = mysqli_query($mysqli, "SELECT * FROM portal_request_catalog_fields
        WHERE portal_request_catalog_field_item_id = $item_id
        ORDER BY portal_request_catalog_field_order ASC, portal_request_catalog_field_id ASC");
    if ($fields) {
        while ($field = mysqli_fetch_assoc($fields)) {
            $definition['fields'][] = [
                'key' => (string) $field['portal_request_catalog_field_key'],
                'label' => (string) $field['portal_request_catalog_field_label'],
                'help' => (string) $field['portal_request_catalog_field_help'],
                'type' => (string) $field['portal_request_catalog_field_type'],
                'required' => intval($field['portal_request_catalog_field_required']) === 1,
                'options' => portalRequestOptions($field['portal_request_catalog_field_options']),
                'max_length' => intval($field['portal_request_catalog_field_max_length']),
                'min_value' => $field['portal_request_catalog_field_min_value'] === null
                    ? null : intval($field['portal_request_catalog_field_min_value']),
                'max_value' => $field['portal_request_catalog_field_max_value'] === null
                    ? null : intval($field['portal_request_catalog_field_max_value']),
                'order' => intval($field['portal_request_catalog_field_order']),
            ];
        }
    }
    return portalRequestCanonicalize($definition);
}

function portalRequestVersionDefinition($version_id) {
    global $mysqli;

    $version_id = intval($version_id);
    $version = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT *
        FROM portal_request_catalog_versions
        WHERE portal_request_catalog_version_id = $version_id LIMIT 1"));
    if (!$version) {
        return null;
    }
    $definition = [
        'key' => (string) $version['portal_request_catalog_version_key'],
        'type' => (string) $version['portal_request_catalog_version_type'],
        'name' => (string) $version['portal_request_catalog_version_name'],
        'description' => (string) $version['portal_request_catalog_version_description'],
        'instructions' => (string) $version['portal_request_catalog_version_instructions'],
        'icon' => (string) $version['portal_request_catalog_version_icon'],
        'category_id' => intval($version['portal_request_catalog_version_category_id']),
        'ticket_template_id' => intval($version['portal_request_catalog_version_ticket_template_id']),
        'runbook_version_id' => intval($version['portal_request_catalog_version_runbook_version_id']),
        'permission_rule' => (string) $version['portal_request_catalog_version_permission_rule'],
        'applicability_rule' => (string) $version['portal_request_catalog_version_applicability_rule'],
        'applicability_value' => (string) $version['portal_request_catalog_version_applicability_value'],
        'approval_rule' => (string) $version['portal_request_catalog_version_approval_rule'],
        'sort_order' => intval($version['portal_request_catalog_version_order']),
        'fields' => [],
    ];
    $fields = mysqli_query($mysqli, "SELECT * FROM portal_request_catalog_version_fields
        WHERE portal_request_catalog_version_field_version_id = $version_id
        ORDER BY portal_request_catalog_version_field_order ASC,
            portal_request_catalog_version_field_id ASC");
    if ($fields) {
        while ($field = mysqli_fetch_assoc($fields)) {
            $definition['fields'][] = [
                'key' => (string) $field['portal_request_catalog_version_field_key'],
                'label' => (string) $field['portal_request_catalog_version_field_label'],
                'help' => (string) $field['portal_request_catalog_version_field_help'],
                'type' => (string) $field['portal_request_catalog_version_field_type'],
                'required' => intval($field['portal_request_catalog_version_field_required']) === 1,
                'options' => portalRequestOptions($field['portal_request_catalog_version_field_options']),
                'max_length' => intval($field['portal_request_catalog_version_field_max_length']),
                'min_value' => $field['portal_request_catalog_version_field_min_value'] === null
                    ? null : intval($field['portal_request_catalog_version_field_min_value']),
                'max_value' => $field['portal_request_catalog_version_field_max_value'] === null
                    ? null : intval($field['portal_request_catalog_version_field_max_value']),
                'order' => intval($field['portal_request_catalog_version_field_order']),
            ];
        }
    }
    return portalRequestCanonicalize($definition);
}

function portalRequestValidateDefinition($definition) {
    $errors = [];
    if (!$definition) {
        return ['The catalog item does not exist.'];
    }
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', (string) ($definition['key'] ?? ''))) {
        $errors[] = 'The stable key is invalid.';
    }
    if (!isset(portalRequestTypes()[$definition['type'] ?? ''])) {
        $errors[] = 'Choose a supported request type.';
    }
    if (trim((string) ($definition['name'] ?? '')) === '') {
        $errors[] = 'A request name is required.';
    }
    if (!preg_match('/^[a-z0-9 -]{1,60}$/i', (string) ($definition['icon'] ?? ''))) {
        $errors[] = 'The icon must be a Font Awesome class list.';
    }
    if (!isset(portalRequestPermissionRules()[$definition['permission_rule'] ?? ''])) {
        $errors[] = 'The portal permission rule is invalid.';
    }
    if (!isset(portalRequestApplicabilityRules()[$definition['applicability_rule'] ?? ''])) {
        $errors[] = 'The applicability rule is invalid.';
    }
    if (($definition['applicability_rule'] ?? 'all') !== 'all'
        && trim((string) ($definition['applicability_value'] ?? '')) === '') {
        $errors[] = 'The applicability rule needs a value.';
    }
    if (in_array(($definition['applicability_rule'] ?? ''), ['client_allowlist', 'client_denylist'], true)
        && portalRequestParseClientIdList($definition['applicability_value'] ?? '') === null) {
        $errors[] = 'Client applicability must be a comma-separated list of canonical positive client IDs.';
    }
    if (!isset(portalRequestApprovalRules()[$definition['approval_rule'] ?? ''])) {
        $errors[] = 'The approval rule is invalid.';
    }
    if (($definition['permission_rule'] ?? '') === 'primary'
        && ($definition['approval_rule'] ?? '') === 'primary') {
        $errors[] = 'A primary-only request cannot require another primary contact to approve it.';
    }
    if (!intval($definition['ticket_template_id'] ?? 0)
        || !intval($definition['runbook_version_id'] ?? 0)) {
        $errors[] = 'Select a ticket template with a published runbook.';
    }
    if (empty($definition['fields'])) {
        $errors[] = 'Add at least one request field.';
    } elseif (count($definition['fields']) > 100) {
        $errors[] = 'A request may contain no more than 100 fields.';
    }

    $keys = [];
    foreach (($definition['fields'] ?? []) as $field) {
        $key = (string) ($field['key'] ?? '');
        $type = (string) ($field['type'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{0,99}$/', $key)) {
            $errors[] = 'Every field needs a valid stable key.';
        } elseif (isset($keys[$key])) {
            $errors[] = "Field key $key is duplicated.";
        }
        $keys[$key] = true;
        if (trim((string) ($field['label'] ?? '')) === '') {
            $errors[] = "Field $key needs a label.";
        }
        if (!isset(portalRequestFieldTypes()[$type])) {
            $errors[] = "Field $key has an unsupported type.";
        }
        if ($type === 'select' && empty($field['options'])) {
            $errors[] = "Choice field $key needs at least one option.";
        }
        $max_length = intval($field['max_length'] ?? 0);
        if ($max_length < 1 || $max_length > 10000) {
            $errors[] = "Field $key needs a maximum length between 1 and 10000.";
        }
        $min = $field['min_value'] ?? null;
        $max = $field['max_value'] ?? null;
        if ($min !== null && $max !== null && intval($min) > intval($max)) {
            $errors[] = "Field $key has an invalid numeric range.";
        }
        if ($type === 'integer'
            && (($min !== null && (intval($min) < -2147483648 || intval($min) > 2147483647))
                || ($max !== null && (intval($max) < -2147483648 || intval($max) > 2147483647)))) {
            $errors[] = "Field $key must stay within the supported integer range.";
        }
    }
    return array_values(array_unique($errors));
}

function portalRequestAssertVersion($version_id) {
    global $mysqli;

    $version_id = intval($version_id);
    $row = mysqli_fetch_assoc(portalRequestDbQuery("SELECT portal_request_catalog_version_definition_hash,
        portal_request_catalog_version_ticket_template_id,
        portal_request_catalog_version_runbook_version_id
        FROM portal_request_catalog_versions
        WHERE portal_request_catalog_version_id = $version_id LIMIT 1",
        'Could not load the published request version'));
    $definition = portalRequestVersionDefinition($version_id);
    if (!$row || portalRequestValidateDefinition($definition)) {
        throw new RuntimeException('The published request definition is incomplete or invalid');
    }
    $stored_hash = strtolower(trim((string) $row['portal_request_catalog_version_definition_hash']));
    $actual_hash = portalRequestDefinitionHash($definition);
    if (!preg_match('/^[a-f0-9]{64}$/', $stored_hash) || !hash_equals($stored_hash, $actual_hash)) {
        throw new RuntimeException('The published request definition failed its integrity check');
    }
    $runbook_version_id = intval($row['portal_request_catalog_version_runbook_version_id']);
    $template_id = intval($row['portal_request_catalog_version_ticket_template_id']);
    $runbook = mysqli_fetch_assoc(portalRequestDbQuery("SELECT runbook_version_definition_hash
        FROM runbook_versions WHERE runbook_version_id = $runbook_version_id
        AND runbook_version_ticket_template_id = $template_id LIMIT 1",
        'Could not validate the pinned runbook release'));
    if (!$runbook) {
        throw new RuntimeException('The request catalog runbook release is unavailable');
    }
    runbookAssertVersionDefinitionHash($runbook_version_id, $runbook['runbook_version_definition_hash']);
    return $definition;
}

function portalRequestPublish($item_id, $created_by = 0, $notes = '') {
    global $mysqli;

    $item_id = intval($item_id);
    $created_by = intval($created_by);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the request publication transaction');
    }
    try {
        $item = mysqli_fetch_assoc(portalRequestDbQuery("SELECT portal_request_catalog_item_id,
            portal_request_catalog_item_ticket_template_id,
            portal_request_catalog_item_archived_at
            FROM portal_request_catalog_items
            WHERE portal_request_catalog_item_id = $item_id LIMIT 1 FOR UPDATE",
            'Could not lock the request catalog item'));
        if (!$item || !empty($item['portal_request_catalog_item_archived_at'])) {
            throw new RuntimeException('The request catalog item is unavailable or archived');
        }
        $template_id = intval($item['portal_request_catalog_item_ticket_template_id']);
        $template = mysqli_fetch_assoc(portalRequestDbQuery("SELECT ticket_template_published_version_id,
            ticket_template_archived_at FROM ticket_templates
            WHERE ticket_template_id = $template_id LIMIT 1 FOR UPDATE",
            'Could not lock the request runbook template'));
        if (!$template || !empty($template['ticket_template_archived_at'])) {
            throw new RuntimeException('The selected ticket template is unavailable or archived');
        }
        $definition = portalRequestDraftDefinition($item_id);
        $errors = portalRequestValidateDefinition($definition);
        if ($errors) {
            throw new RuntimeException($errors[0]);
        }
        $category_id = intval($definition['category_id']);
        if ($category_id) {
            $category = mysqli_fetch_assoc(portalRequestDbQuery("SELECT category_id FROM categories
                WHERE category_id = $category_id AND category_type = 'Ticket'
                AND category_archived_at IS NULL LIMIT 1 FOR UPDATE",
                'Could not lock the request ticket category'));
            if (intval($category['category_id'] ?? 0) !== $category_id) {
                throw new RuntimeException('The selected ticket category is unavailable or archived');
            }
        }
        if (intval($definition['runbook_version_id']) !== intval($template['ticket_template_published_version_id'])) {
            throw new RuntimeException('The ticket template publication changed; refresh and try again');
        }
        $runbook_version_id = intval($definition['runbook_version_id']);
        $runbook = mysqli_fetch_assoc(portalRequestDbQuery("SELECT runbook_version_definition_hash
            FROM runbook_versions WHERE runbook_version_id = $runbook_version_id
            AND runbook_version_ticket_template_id = $template_id LIMIT 1",
            'Could not validate the selected runbook release'));
        if (!$runbook) {
            throw new RuntimeException('The selected runbook release no longer exists');
        }
        runbookAssertVersionDefinitionHash($runbook_version_id, $runbook['runbook_version_definition_hash']);

        $hash = portalRequestDefinitionHash($definition);
        $hash_sql = mysqli_real_escape_string($mysqli, $hash);
        $existing = mysqli_fetch_assoc(portalRequestDbQuery("SELECT portal_request_catalog_version_id
            FROM portal_request_catalog_versions
            WHERE portal_request_catalog_version_item_id = $item_id
            AND portal_request_catalog_version_definition_hash = '$hash_sql' LIMIT 1",
            'Could not check existing request versions'));
        if ($existing) {
            $version_id = intval($existing['portal_request_catalog_version_id']);
            portalRequestAssertVersion($version_id);
        } else {
            $next = mysqli_fetch_row(portalRequestDbQuery("SELECT COALESCE(MAX(portal_request_catalog_version_number), 0) + 1
                FROM portal_request_catalog_versions
                WHERE portal_request_catalog_version_item_id = $item_id",
                'Could not allocate the request version number'));
            $version_number = intval($next[0] ?? 1);
            $text = static function ($value) use ($mysqli) {
                return mysqli_real_escape_string($mysqli, (string) $value);
            };
            $key = $text($definition['key']);
            $type = $text($definition['type']);
            $name = $text($definition['name']);
            $description = $text($definition['description']);
            $instructions = $text($definition['instructions']);
            $icon = $text($definition['icon']);
            $permission = $text($definition['permission_rule']);
            $applicability = $text($definition['applicability_rule']);
            $applicability_value = $text($definition['applicability_value']);
            $approval = $text($definition['approval_rule']);
            $notes_sql = $text(substr(trim((string) $notes), 0, 255));
            portalRequestDbQuery("INSERT INTO portal_request_catalog_versions SET
                portal_request_catalog_version_item_id = $item_id,
                portal_request_catalog_version_number = $version_number,
                portal_request_catalog_version_definition_hash = '$hash_sql',
                portal_request_catalog_version_key = '$key',
                portal_request_catalog_version_type = '$type',
                portal_request_catalog_version_name = '$name',
                portal_request_catalog_version_description = '$description',
                portal_request_catalog_version_instructions = '$instructions',
                portal_request_catalog_version_icon = '$icon',
                portal_request_catalog_version_category_id = " . intval($definition['category_id']) . ",
                portal_request_catalog_version_ticket_template_id = $template_id,
                portal_request_catalog_version_runbook_version_id = $runbook_version_id,
                portal_request_catalog_version_permission_rule = '$permission',
                portal_request_catalog_version_applicability_rule = '$applicability',
                portal_request_catalog_version_applicability_value = '$applicability_value',
                portal_request_catalog_version_approval_rule = '$approval',
                portal_request_catalog_version_order = " . intval($definition['sort_order']) . ",
                portal_request_catalog_version_notes = '$notes_sql',
                portal_request_catalog_version_created_by = $created_by",
                'Could not publish the request catalog version');
            $version_id = intval(mysqli_insert_id($mysqli));
            if (!$version_id) {
                throw new RuntimeException('The request publication did not receive an ID');
            }
            foreach ($definition['fields'] as $field) {
                $field_key = $text($field['key']);
                $label = $text($field['label']);
                $help = $text($field['help']);
                $field_type = $text($field['type']);
                $options = $text(portalRequestCanonicalJson(array_values($field['options'])));
                $min = $field['min_value'] === null ? 'NULL' : (string) intval($field['min_value']);
                $max = $field['max_value'] === null ? 'NULL' : (string) intval($field['max_value']);
                portalRequestDbQuery("INSERT INTO portal_request_catalog_version_fields SET
                    portal_request_catalog_version_field_version_id = $version_id,
                    portal_request_catalog_version_field_key = '$field_key',
                    portal_request_catalog_version_field_label = '$label',
                    portal_request_catalog_version_field_help = '$help',
                    portal_request_catalog_version_field_type = '$field_type',
                    portal_request_catalog_version_field_required = " . (!empty($field['required']) ? 1 : 0) . ",
                    portal_request_catalog_version_field_options = '$options',
                    portal_request_catalog_version_field_max_length = " . intval($field['max_length']) . ",
                    portal_request_catalog_version_field_min_value = $min,
                    portal_request_catalog_version_field_max_value = $max,
                    portal_request_catalog_version_field_order = " . intval($field['order']),
                    'Could not publish a request catalog field');
            }
            portalRequestAssertVersion($version_id);
        }
        portalRequestDbQuery("UPDATE portal_request_catalog_items
            SET portal_request_catalog_item_published_version_id = $version_id
            WHERE portal_request_catalog_item_id = $item_id",
            'Could not select the published request version');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the request publication');
        }
        return $version_id;
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        throw $exception;
    }
}

function portalRequestContactContext($contact_id, $client_id, $lock = false) {
    global $mysqli;

    $contact_id = intval($contact_id);
    $client_id = intval($client_id);
    if ($lock) {
        // Delete and submission paths take this same client-first lock order.
        // A hard delete can therefore either finish before this lookup or see
        // the committed submission during its retention recheck, never race it.
        $client = mysqli_fetch_assoc(portalRequestDbQuery("SELECT client_id FROM clients
            WHERE client_id = $client_id AND client_archived_at IS NULL LIMIT 1 FOR UPDATE",
            'Could not lock the portal request client'));
        if (!$client) {
            return null;
        }
    }
    $lock_sql = $lock ? ' FOR UPDATE' : '';
    $sql = "SELECT contact_id, contact_client_id,
        contact_primary, contact_billing, contact_technical,
        contact_portal_ticket_scope, contact_portal_asset_scope,
        contact_portal_manage_contacts, contact_user_id
        FROM contacts
        INNER JOIN users ON user_id = contact_user_id
        INNER JOIN clients ON client_id = contact_client_id
        WHERE contact_id = $contact_id AND contact_client_id = $client_id
        AND contact_archived_at IS NULL AND client_archived_at IS NULL
        AND user_type = 2 AND user_status = 1 AND user_archived_at IS NULL LIMIT 1$lock_sql";
    $result = $lock
        ? portalRequestDbQuery($sql, 'Could not lock the portal requester')
        : mysqli_query($mysqli, $sql);
    return $result ? mysqli_fetch_assoc($result) : null;
}

function portalRequestContactMatchesRule($context, $rule) {
    if (!$context) {
        return false;
    }
    $primary = intval($context['contact_primary'] ?? 0) === 1;
    if ($rule === 'any') {
        return true;
    }
    if ($rule === 'manager') {
        return $primary || (
            ($context['contact_portal_ticket_scope'] ?? 'own') === 'client'
            && ($context['contact_portal_asset_scope'] ?? 'assigned') === 'client'
        );
    }
    if ($rule === 'technical') {
        return $primary || intval($context['contact_technical'] ?? 0) === 1;
    }
    if ($rule === 'billing') {
        return $primary || intval($context['contact_billing'] ?? 0) === 1;
    }
    if ($rule === 'primary') {
        return $primary;
    }
    return false;
}

function portalRequestVersionAppliesToClient($definition, $client_id) {
    global $mysqli;

    $client_id = intval($client_id);
    $rule = (string) ($definition['applicability_rule'] ?? 'all');
    $value = trim((string) ($definition['applicability_value'] ?? ''));
    if ($rule === 'all') {
        return true;
    }
    if ($rule === 'service_name' || $rule === 'service_category') {
        $column = $rule === 'service_name' ? 'service_name' : 'service_category';
        $value_sql = mysqli_real_escape_string($mysqli, $value);
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM services
            WHERE service_client_id = $client_id AND LOWER($column) = LOWER('$value_sql')"));
        return intval($row[0] ?? 0) > 0;
    }
    $ids = portalRequestParseClientIdList($value);
    if ($ids === null) {
        return false;
    }
    if ($rule === 'client_allowlist') {
        return in_array($client_id, $ids, true);
    }
    if ($rule === 'client_denylist') {
        return !in_array($client_id, $ids, true);
    }
    return false;
}

function portalRequestContactCanUse($definition, $context, $client_id) {
    return portalRequestContactMatchesRule($context, (string) ($definition['permission_rule'] ?? ''))
        && portalRequestVersionAppliesToClient($definition, $client_id);
}

/**
 * Check that a request which will wait for approval has at least one distinct,
 * currently active principal capable of deciding it. Locking the selected
 * principal serializes the final check with ordinary contact/user mutations.
 */
function portalRequestApprovalRouteAvailable($definition, $client_id, $requester_contact_id, $lock = false) {
    global $mysqli;

    $client_id = intval($client_id);
    $requester_contact_id = intval($requester_contact_id);
    $rule = (string) ($definition['approval_rule'] ?? 'none');
    if ($rule === 'none') {
        return true;
    }
    $lock_sql = $lock ? ' FOR UPDATE' : '';
    if ($rule === 'internal') {
        $result = portalRequestDbQuery("SELECT u.user_id
            FROM users u
            INNER JOIN user_roles r ON r.role_id = u.user_role_id
            INNER JOIN clients c ON c.client_id = $client_id AND c.client_archived_at IS NULL
            WHERE u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL
            AND (r.role_is_admin = 1 OR EXISTS (
                SELECT 1 FROM user_role_permissions p
                INNER JOIN modules m ON m.module_id = p.module_id
                WHERE p.user_role_id = u.user_role_id
                AND m.module_name = 'module_support' AND p.user_role_permission_level >= 2
            ))
            AND (r.role_is_admin = 1 OR (
                NOT EXISTS (SELECT 1 FROM user_client_permissions d
                    WHERE d.user_id = u.user_id AND d.client_id = $client_id
                    AND d.permission_type = 'deny')
                AND (NOT EXISTS (SELECT 1 FROM user_client_permissions a
                        WHERE a.user_id = u.user_id AND a.permission_type = 'allow')
                    OR EXISTS (SELECT 1 FROM user_client_permissions a
                        WHERE a.user_id = u.user_id AND a.client_id = $client_id
                        AND a.permission_type = 'allow'))
            ))
            ORDER BY r.role_is_admin DESC, u.user_id ASC LIMIT 1$lock_sql",
            'Could not validate the internal portal request approval route');
        return mysqli_num_rows($result) === 1;
    }

    $role_sql = '';
    if ($rule === 'manager') {
        $role_sql = "AND (c.contact_primary = 1 OR
            (c.contact_portal_ticket_scope = 'client' AND c.contact_portal_asset_scope = 'client'))";
    } elseif ($rule === 'technical') {
        $role_sql = 'AND (c.contact_primary = 1 OR c.contact_technical = 1)';
    } elseif ($rule === 'billing') {
        $role_sql = 'AND (c.contact_primary = 1 OR c.contact_billing = 1)';
    } elseif ($rule === 'primary') {
        $role_sql = 'AND c.contact_primary = 1';
    } else {
        return false;
    }
    $result = portalRequestDbQuery("SELECT c.contact_id
        FROM contacts c
        INNER JOIN users u ON u.user_id = c.contact_user_id
        INNER JOIN clients cl ON cl.client_id = c.contact_client_id
        WHERE c.contact_client_id = $client_id AND c.contact_id <> $requester_contact_id
        AND c.contact_archived_at IS NULL AND cl.client_archived_at IS NULL
        AND u.user_type = 2 AND u.user_status = 1 AND u.user_archived_at IS NULL
        $role_sql ORDER BY c.contact_id ASC LIMIT 1$lock_sql",
        'Could not validate the contact portal request approval route');
    return mysqli_num_rows($result) === 1;
}

function portalRequestClientHasAuditHistory($client_id) {
    global $mysqli;

    $client_id = intval($client_id);
    if (!$client_id) {
        return false;
    }
    $result = mysqli_query($mysqli, "SELECT portal_request_submission_id
        FROM portal_request_submissions
        WHERE portal_request_submission_client_id = $client_id LIMIT 1");
    if ($result === false) {
        error_log("Could not verify portal request audit retention for client $client_id: " . mysqli_error($mysqli));
        return true;
    }
    return mysqli_num_rows($result) > 0;
}

/**
 * Lock a client before a hard-delete retention check. The caller owns the
 * surrounding transaction and must retain this lock through its final delete.
 */
function portalRequestLockClientForAuditRetention($client_id) {
    $client_id = intval($client_id);
    if (!$client_id) {
        return null;
    }
    return mysqli_fetch_assoc(portalRequestDbQuery("SELECT client_id, client_name FROM clients
        WHERE client_id = $client_id LIMIT 1 FOR UPDATE",
        'Could not lock the client for portal request audit retention'));
}

/**
 * Lock a contact and its owning client in the same order used by portal request
 * submission. Supplying the client ID also keeps API deletion tenant-bound.
 */
function portalRequestLockContactForAuditRetention($contact_id, $client_id) {
    $contact_id = intval($contact_id);
    $client_id = intval($client_id);
    if (!$contact_id || !$client_id) {
        return null;
    }
    $client = portalRequestLockClientForAuditRetention($client_id);
    if (!$client) {
        return null;
    }
    return mysqli_fetch_assoc(portalRequestDbQuery("SELECT contact_id, contact_name,
        contact_email, contact_phone, contact_client_id, contact_user_id
        FROM contacts WHERE contact_id = $contact_id AND contact_client_id = $client_id
        LIMIT 1 FOR UPDATE",
        'Could not lock the contact for portal request audit retention'));
}

function portalRequestContactHasAuditHistory($contact_id, $client_id = 0) {
    global $mysqli;

    $contact_id = intval($contact_id);
    $client_id = intval($client_id);
    if (!$contact_id) {
        return false;
    }
    $result = mysqli_query($mysqli, "SELECT s.portal_request_submission_id
        FROM portal_request_submissions s
        LEFT JOIN portal_request_submission_events e
            ON e.portal_request_submission_event_submission_id = s.portal_request_submission_id
        WHERE s.portal_request_submission_contact_id = $contact_id
        OR (s.portal_request_submission_decided_by_type = 'contact'
            AND s.portal_request_submission_decided_by_id = $contact_id)
        OR (e.portal_request_submission_event_actor_type = 'contact'
            AND e.portal_request_submission_event_actor_id = $contact_id)
        LIMIT 1");
    if ($result === false) {
        error_log("Could not verify portal request audit retention for contact $contact_id: " . mysqli_error($mysqli));
        return true;
    }
    if (mysqli_num_rows($result) > 0) {
        return true;
    }

    // A contact chosen as the subject of a termination, device or access
    // request is also part of immutable audit evidence even when they were not
    // the requester or approver. Inspect only the owning tenant's validated
    // snapshots; any query/integrity failure retains the contact fail-closed.
    if (!$client_id) {
        $scope = mysqli_query($mysqli, "SELECT contact_client_id FROM contacts
            WHERE contact_id = $contact_id LIMIT 1");
        $scope_row = $scope ? mysqli_fetch_assoc($scope) : null;
        $client_id = intval($scope_row['contact_client_id'] ?? 0);
        if (!$scope || !$client_id) {
            error_log("Could not resolve portal request reference retention for contact $contact_id");
            return true;
        }
    }
    $references = mysqli_query($mysqli, "SELECT portal_request_submission_version_id,
        portal_request_submission_responses, portal_request_submission_response_hash
        FROM portal_request_submissions
        WHERE portal_request_submission_client_id = $client_id");
    if ($references === false) {
        error_log("Could not inspect portal request response retention for contact $contact_id: " . mysqli_error($mysqli));
        return true;
    }
    while ($submission = mysqli_fetch_assoc($references)) {
        try {
            $definition = portalRequestAssertVersion(intval($submission['portal_request_submission_version_id']));
            $responses = portalRequestResponsePayload($submission);
        } catch (Throwable $exception) {
            error_log("Could not verify portal request response retention for contact $contact_id: " . $exception->getMessage());
            return true;
        }
        foreach ($definition['fields'] as $field) {
            if ($field['type'] !== 'contact') {
                continue;
            }
            $response = $responses[$field['key']] ?? null;
            if (is_array($response) && intval($response['id'] ?? 0) === $contact_id) {
                return true;
            }
        }
    }
    return false;
}

function portalRequestValidateResponses($definition, $submitted, $client_id, $contact_context) {
    global $mysqli;

    $client_id = intval($client_id);
    $contact_id = intval($contact_context['contact_id'] ?? 0);
    $submitted = is_array($submitted) ? $submitted : [];
    $values = [];
    $errors = [];
    foreach ($definition['fields'] as $field) {
        $key = $field['key'];
        $type = $field['type'];
        $raw = $submitted[$key] ?? '';
        if (is_array($raw)) {
            $raw = '';
        }
        $raw = str_replace("\0", '', trim((string) $raw));
        $required = !empty($field['required']);
        $max_length = max(1, min(10000, intval($field['max_length'])));
        if ($type === 'checkbox') {
            $value = $raw === '1';
            if ($required && !$value) {
                $errors[$key] = $field['label'] . ' must be confirmed.';
            }
            $values[$key] = $value;
            continue;
        }
        if ($raw === '') {
            if ($required) {
                $errors[$key] = $field['label'] . ' is required.';
            }
            $values[$key] = null;
            continue;
        }
        if (in_array($type, ['text', 'textarea', 'email', 'phone'], true)
            && strlen($raw) > $max_length) {
            $errors[$key] = $field['label'] . " must be $max_length characters or fewer.";
            continue;
        }
        if ($type === 'email') {
            if (strlen($raw) > 254 || !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                $errors[$key] = $field['label'] . ' must be a valid email address.';
                continue;
            }
            $values[$key] = strtolower($raw);
        } elseif ($type === 'phone') {
            if (!preg_match('/^[0-9+(). ext-]{7,40}$/i', $raw)
                || strlen(preg_replace('/\D/', '', $raw)) < 7) {
                $errors[$key] = $field['label'] . ' must be a valid phone number.';
                continue;
            }
            $values[$key] = $raw;
        } elseif ($type === 'integer') {
            $value = portalRequestParseInteger($raw);
            if ($value === null) {
                $errors[$key] = $field['label'] . ' must be a canonical whole number between -2147483648 and 2147483647.';
                continue;
            }
            if (($field['min_value'] !== null && $value < intval($field['min_value']))
                || ($field['max_value'] !== null && $value > intval($field['max_value']))) {
                $errors[$key] = $field['label'] . ' is outside the allowed range.';
                continue;
            }
            $values[$key] = $value;
        } elseif ($type === 'date' || $type === 'datetime') {
            $format = $type === 'date' ? 'Y-m-d' : 'Y-m-d\TH:i';
            $date = DateTimeImmutable::createFromFormat('!' . $format, $raw);
            $parse_errors = DateTimeImmutable::getLastErrors();
            if (!$date || ($parse_errors !== false
                && (intval($parse_errors['warning_count']) > 0 || intval($parse_errors['error_count']) > 0))
                || $date->format($format) !== $raw) {
                $errors[$key] = $field['label'] . ' must be a valid ' . ($type === 'date' ? 'date.' : 'date and time.');
                continue;
            }
            $values[$key] = $date->format($type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
        } elseif ($type === 'select') {
            if (!in_array($raw, $field['options'], true)) {
                $errors[$key] = $field['label'] . ' has an invalid choice.';
                continue;
            }
            $values[$key] = $raw;
        } elseif ($type === 'asset') {
            $asset_id = portalRequestParseEntityId($raw);
            if (!$asset_id) {
                $errors[$key] = $field['label'] . ' has an invalid device identifier.';
                continue;
            }
            $asset_scope = ($contact_context['contact_portal_asset_scope'] ?? 'assigned') === 'client'
                || intval($contact_context['contact_primary'] ?? 0) === 1
                ? '' : "AND asset_contact_id = $contact_id";
            $asset = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT asset_id, asset_name, asset_type
                FROM assets WHERE asset_id = $asset_id AND asset_client_id = $client_id
                AND asset_archived_at IS NULL $asset_scope LIMIT 1"));
            if (!$asset) {
                $errors[$key] = $field['label'] . ' is outside your permitted device inventory.';
                continue;
            }
            $values[$key] = [
                'id' => intval($asset['asset_id']),
                'label' => trim($asset['asset_name'] . ' (' . $asset['asset_type'] . ')'),
            ];
        } elseif ($type === 'contact') {
            $selected_contact_id = portalRequestParseEntityId($raw);
            if (!$selected_contact_id) {
                $errors[$key] = $field['label'] . ' has an invalid contact identifier.';
                continue;
            }
            $can_select_others = intval($contact_context['contact_primary'] ?? 0) === 1
                || intval($contact_context['contact_portal_manage_contacts'] ?? 0) === 1
                || ($contact_context['contact_portal_ticket_scope'] ?? 'own') === 'client';
            if (!$can_select_others && $selected_contact_id !== $contact_id) {
                $errors[$key] = $field['label'] . ' is outside your permitted contact scope.';
                continue;
            }
            $contact = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_id, contact_name,
                contact_email FROM contacts WHERE contact_id = $selected_contact_id
                AND contact_client_id = $client_id AND contact_archived_at IS NULL LIMIT 1"));
            if (!$contact) {
                $errors[$key] = $field['label'] . ' is unavailable.';
                continue;
            }
            $label = $contact['contact_name'];
            if (!empty($contact['contact_email'])) {
                $label .= ' (' . $contact['contact_email'] . ')';
            }
            $values[$key] = ['id' => intval($contact['contact_id']), 'label' => $label];
        } else {
            $values[$key] = $raw;
        }
    }
    return [portalRequestCanonicalize($values), $errors];
}

function portalRequestResponseText($value) {
    if ($value === null || $value === '') {
        return 'Not provided';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    if (is_array($value)) {
        return (string) ($value['label'] ?? $value['id'] ?? 'Not provided');
    }
    return (string) $value;
}

function portalRequestResponsePayload($submission) {
    $raw = (string) ($submission['portal_request_submission_responses'] ?? '');
    $stored_hash = strtolower(trim((string) ($submission['portal_request_submission_response_hash'] ?? '')));
    $values = json_decode($raw, true);
    if (!is_array($values) || !preg_match('/^[a-f0-9]{64}$/', $stored_hash)
        || !hash_equals($stored_hash, hash('sha256', portalRequestCanonicalJson($values)))) {
        throw new RuntimeException('The submitted request response failed its integrity check');
    }
    return portalRequestCanonicalize($values);
}

function portalRequestRecordEvent($submission_id, $action, $from_status, $to_status, $actor_type, $actor_id, $note = '') {
    global $mysqli;

    $submission_id = intval($submission_id);
    $actions = ['submitted', 'approved', 'declined', 'ticket_created'];
    $statuses = ['Submitted', 'PendingApproval', 'Approved', 'Declined', 'Initiated'];
    $actors = ['contact', 'agent', 'system'];
    if (!$submission_id || !in_array($action, $actions, true)
        || !in_array($to_status, $statuses, true) || !in_array($actor_type, $actors, true)) {
        throw new RuntimeException('Invalid portal request audit event');
    }
    $from_sql = $from_status && in_array($from_status, $statuses, true)
        ? "'" . mysqli_real_escape_string($mysqli, $from_status) . "'" : 'NULL';
    $to_sql = mysqli_real_escape_string($mysqli, $to_status);
    $action_sql = mysqli_real_escape_string($mysqli, $action);
    $actor_sql = mysqli_real_escape_string($mysqli, $actor_type);
    $note = substr(trim((string) $note), 0, 255);
    $note_sql = $note === '' ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $note) . "'";
    portalRequestDbQuery("INSERT INTO portal_request_submission_events SET
        portal_request_submission_event_submission_id = $submission_id,
        portal_request_submission_event_action = '$action_sql',
        portal_request_submission_event_from_status = $from_sql,
        portal_request_submission_event_to_status = '$to_sql',
        portal_request_submission_event_actor_type = '$actor_sql',
        portal_request_submission_event_actor_id = " . max(0, intval($actor_id)) . ",
        portal_request_submission_event_note = $note_sql",
        'Could not append the portal request audit event');
}

function portalRequestEnqueueCustomAction($submission_id, $ticket_id, $trigger = 'ticket_create') {
    global $mysqli;

    $submission_id = intval($submission_id);
    $ticket_id = intval($ticket_id);
    if (!$submission_id || !$ticket_id || $trigger !== 'ticket_create') {
        throw new RuntimeException('Invalid portal request custom-action dispatch');
    }
    $event_key = hash('sha256', "portal-request:$submission_id:$trigger");
    $event_key_sql = mysqli_real_escape_string($mysqli, $event_key);
    $trigger_sql = mysqli_real_escape_string($mysqli, $trigger);
    portalRequestDbQuery("INSERT INTO portal_request_dispatch_outbox SET
        portal_request_dispatch_event_key = '$event_key_sql',
        portal_request_dispatch_submission_id = $submission_id,
        portal_request_dispatch_ticket_id = $ticket_id,
        portal_request_dispatch_trigger = '$trigger_sql'",
        'Could not enqueue the portal request custom action');
    return $event_key;
}

function portalRequestClaimCustomAction($submission_id = 0) {
    global $mysqli;

    $submission_id = intval($submission_id);
    $submission_filter = $submission_id
        ? "AND portal_request_dispatch_submission_id = $submission_id" : '';
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the portal request dispatch claim');
    }
    try {
        $row = mysqli_fetch_assoc(portalRequestDbQuery("SELECT *
            FROM portal_request_dispatch_outbox
            WHERE portal_request_dispatch_delivered_at IS NULL
            $submission_filter
            AND (
                (portal_request_dispatch_status IN ('Pending', 'Failed')
                    AND portal_request_dispatch_available_at <= NOW())
                OR (portal_request_dispatch_status = 'Processing'
                    AND portal_request_dispatch_processing_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
            )
            ORDER BY portal_request_dispatch_id ASC LIMIT 1 FOR UPDATE",
            'Could not lock a portal request custom action'));
        if (!$row) {
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not finish the empty portal request dispatch claim');
            }
            return null;
        }
        $dispatch_id = intval($row['portal_request_dispatch_id']);
        $lease = hash('sha256', random_bytes(32));
        $lease_sql = mysqli_real_escape_string($mysqli, $lease);
        portalRequestDbQuery("UPDATE portal_request_dispatch_outbox SET
            portal_request_dispatch_status = 'Processing',
            portal_request_dispatch_attempts = portal_request_dispatch_attempts + 1,
            portal_request_dispatch_processing_at = NOW(),
            portal_request_dispatch_lease_token = '$lease_sql',
            portal_request_dispatch_last_error = NULL
            WHERE portal_request_dispatch_id = $dispatch_id
            AND portal_request_dispatch_delivered_at IS NULL LIMIT 1",
            'Could not claim the portal request custom action');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The portal request custom action was claimed concurrently');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the portal request dispatch claim');
        }
        $row['portal_request_dispatch_attempts'] = intval($row['portal_request_dispatch_attempts']) + 1;
        $row['portal_request_dispatch_lease_token'] = $lease;
        return $row;
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        throw $exception;
    }
}

/**
 * Deliver one outbox row per invocation. triggerCustomAction() historically
 * includes a tenant-owned handler with include_once, so processing one durable
 * event at a time preserves that contract while stale leases make crashes
 * retryable. The stable event key is exposed to the handler for downstream
 * idempotency; delivery itself is intentionally at-least-once.
 */
function portalRequestProcessCustomActionOutbox($submission_id = 0) {
    global $mysqli;

    try {
        $row = portalRequestClaimCustomAction($submission_id);
    } catch (Throwable $exception) {
        error_log('Could not claim a portal request custom action: ' . $exception->getMessage());
        return ['status' => 'failed', 'dispatch_id' => 0];
    }
    if (!$row) {
        return ['status' => 'skipped', 'dispatch_id' => 0];
    }
    $dispatch_id = intval($row['portal_request_dispatch_id']);
    $submission_id = intval($row['portal_request_dispatch_submission_id']);
    $ticket_id = intval($row['portal_request_dispatch_ticket_id']);
    $trigger = (string) $row['portal_request_dispatch_trigger'];
    $event_key = (string) $row['portal_request_dispatch_event_key'];
    $lease = (string) $row['portal_request_dispatch_lease_token'];
    $lease_sql = mysqli_real_escape_string($mysqli, $lease);
    try {
        $expected_event_key = hash('sha256', "portal-request:$submission_id:$trigger");
        if (!$submission_id || !$ticket_id || $trigger !== 'ticket_create'
            || !preg_match('/^[a-f0-9]{64}$/', $event_key)
            || !hash_equals($expected_event_key, $event_key)) {
            throw new RuntimeException('The portal request custom-action envelope is invalid');
        }
        $link = mysqli_fetch_row(portalRequestDbQuery("SELECT COUNT(*)
            FROM portal_request_submissions s
            INNER JOIN tickets t ON t.ticket_id = s.portal_request_submission_ticket_id
                AND t.ticket_client_id = s.portal_request_submission_client_id
                AND t.ticket_deleted_at IS NULL
            WHERE s.portal_request_submission_id = $submission_id
            AND s.portal_request_submission_ticket_id = $ticket_id
            AND s.portal_request_submission_status = 'Initiated'",
            'Could not validate the portal request custom-action target'));
        if (intval($link[0] ?? 0) !== 1) {
            throw new RuntimeException('The portal request custom-action target no longer matches its submission');
        }
        if (triggerCustomAction($trigger, $ticket_id, $event_key) === false) {
            throw new RuntimeException('The custom-action handler already ran in this PHP process; retry in a fresh worker');
        }
        portalRequestDbQuery("UPDATE portal_request_dispatch_outbox SET
            portal_request_dispatch_status = 'Delivered',
            portal_request_dispatch_delivered_at = NOW(),
            portal_request_dispatch_processing_at = NULL,
            portal_request_dispatch_lease_token = NULL,
            portal_request_dispatch_available_at = NOW()
            WHERE portal_request_dispatch_id = $dispatch_id
            AND portal_request_dispatch_status = 'Processing'
            AND portal_request_dispatch_lease_token = '$lease_sql'
            AND portal_request_dispatch_delivered_at IS NULL LIMIT 1",
            'Could not acknowledge the portal request custom action');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The portal request custom-action lease changed before acknowledgement');
        }
        return ['status' => 'delivered', 'dispatch_id' => $dispatch_id];
    } catch (Throwable $exception) {
        $attempts = max(1, intval($row['portal_request_dispatch_attempts']));
        $delay = min(3600, 30 * (2 ** min(7, $attempts - 1)));
        $available_at = mysqli_real_escape_string($mysqli, date('Y-m-d H:i:s', time() + $delay));
        $error = mysqli_real_escape_string($mysqli, substr($exception->getMessage(), 0, 1000));
        mysqli_query($mysqli, "UPDATE portal_request_dispatch_outbox SET
            portal_request_dispatch_status = 'Failed',
            portal_request_dispatch_available_at = '$available_at',
            portal_request_dispatch_processing_at = NULL,
            portal_request_dispatch_lease_token = NULL,
            portal_request_dispatch_last_error = '$error'
            WHERE portal_request_dispatch_id = $dispatch_id
            AND portal_request_dispatch_status = 'Processing'
            AND portal_request_dispatch_lease_token = '$lease_sql'
            AND portal_request_dispatch_delivered_at IS NULL LIMIT 1");
        error_log("Portal request custom action $dispatch_id failed: " . $exception->getMessage());
        return ['status' => 'failed', 'dispatch_id' => $dispatch_id];
    }
}

function portalRequestDispatchAfterCommit($submission_id) {
    global $mysqli;

    // Wake the registered retry worker, then make one best-effort synchronous
    // attempt so the common path retains the existing immediate behavior.
    mysqli_query($mysqli, "INSERT IGNORE INTO cron_jobs SET
        cron_job_name = 'portal_request_outbox', cron_job_enabled = 1,
        cron_job_schedule = 'Interval', cron_job_interval_minutes = 1");
    mysqli_query($mysqli, "UPDATE cron_jobs SET cron_job_run_now = 1
        WHERE cron_job_name = 'portal_request_outbox'");
    return portalRequestProcessCustomActionOutbox(intval($submission_id));
}

function portalRequestSubject($definition, $responses) {
    $suffix = '';
    foreach (['subject', 'summary', 'name', 'user_name', 'affected_service'] as $preferred) {
        if (isset($responses[$preferred]) && !is_array($responses[$preferred])
            && !is_bool($responses[$preferred]) && trim((string) $responses[$preferred]) !== '') {
            $suffix = preg_replace('/\s+/', ' ', trim((string) $responses[$preferred]));
            break;
        }
    }
    $subject = preg_replace('/\s+/', ' ', trim((string) $definition['name']));
    if ($suffix !== '') {
        $subject .= ' - ' . $suffix;
    }
    return substr($subject, 0, 500);
}

function portalRequestDetailsHtml($submission_id, $definition, $responses) {
    $html = '<p><strong>Portal request:</strong> ' . escapeHtml($definition['name']) . '</p>';
    $html .= '<p><strong>Catalog release:</strong> ' . escapeHtml($definition['key']) . ' / submission #' . intval($submission_id) . '</p>';
    if (trim((string) $definition['instructions']) !== '') {
        $html .= '<p>' . nl2br(escapeHtml($definition['instructions'])) . '</p>';
    }
    $html .= '<dl>';
    foreach ($definition['fields'] as $field) {
        $value = portalRequestResponseText($responses[$field['key']] ?? null);
        $html .= '<dt>' . escapeHtml($field['label']) . '</dt><dd>' . nl2br(escapeHtml($value)) . '</dd>';
    }
    return $html . '</dl>';
}

function portalRequestInitiateLockedSubmission($submission, $definition, $actor_type, $actor_id) {
    global $mysqli, $config_ticket_prefix, $config_ticket_default_billable;

    $submission_id = intval($submission['portal_request_submission_id']);
    $client_id = intval($submission['portal_request_submission_client_id']);
    $contact_id = intval($submission['portal_request_submission_contact_id']);
    $current_status = (string) $submission['portal_request_submission_status'];
    if (!in_array($current_status, ['Submitted', 'Approved'], true)
        || intval($submission['portal_request_submission_ticket_id']) !== 0) {
        throw new RuntimeException('The portal request is not ready to create a ticket');
    }
    $responses = portalRequestResponsePayload($submission);
    $requester_context = portalRequestContactContext($contact_id, $client_id, true);
    if (!$requester_context
        || intval($requester_context['contact_user_id']) !== intval($submission['portal_request_submission_user_id'])
        || !portalRequestContactCanUse($definition, $requester_context, $client_id)) {
        throw new RuntimeException('The requester, client, or catalog applicability changed before initiation');
    }
    foreach ($definition['fields'] as $field) {
        $response = $responses[$field['key']] ?? null;
        if ($response === null || !in_array($field['type'], ['asset', 'contact'], true)) {
            continue;
        }
        $entity_id = is_array($response) ? intval($response['id'] ?? 0) : 0;
        if ($field['type'] === 'asset') {
            $asset_scope = intval($requester_context['contact_primary']) === 1
                || $requester_context['contact_portal_asset_scope'] === 'client'
                ? '' : "AND asset_contact_id = $contact_id";
            $valid = mysqli_fetch_assoc(portalRequestDbQuery("SELECT asset_id FROM assets
                WHERE asset_id = $entity_id AND asset_client_id = $client_id
                AND asset_archived_at IS NULL $asset_scope LIMIT 1 FOR UPDATE",
                'Could not revalidate a selected portal request device'));
        } else {
            $can_select_others = intval($requester_context['contact_primary']) === 1
                || intval($requester_context['contact_portal_manage_contacts']) === 1
                || $requester_context['contact_portal_ticket_scope'] === 'client';
            $contact_scope = $can_select_others ? '' : "AND contact_id = $contact_id";
            $valid = mysqli_fetch_assoc(portalRequestDbQuery("SELECT contact_id FROM contacts
                WHERE contact_id = $entity_id AND contact_client_id = $client_id
                AND contact_archived_at IS NULL $contact_scope LIMIT 1 FOR UPDATE",
                'Could not revalidate a selected portal request contact'));
        }
        if (!$valid) {
            throw new RuntimeException('A selected device or contact is no longer available in the requester scope');
        }
    }
    $subject = mysqli_real_escape_string($mysqli, portalRequestSubject($definition, $responses));
    $details = mysqli_real_escape_string($mysqli, portalRequestDetailsHtml($submission_id, $definition, $responses));
    $priority = $definition['type'] === 'incident' ? 'Medium' : 'Low';
    if (isset($responses['priority']) && is_string($responses['priority'])
        && array_key_exists($responses['priority'], ticketPriorityDefinitions())) {
        $priority = $responses['priority'];
    }
    $priority_sql = mysqli_real_escape_string($mysqli, $priority);
    $url_key = mysqli_real_escape_string($mysqli, randomString(32));
    $asset_id = 0;
    foreach ($definition['fields'] as $field) {
        if ($field['type'] === 'asset' && is_array($responses[$field['key']] ?? null)) {
            $asset_id = intval($responses[$field['key']]['id'] ?? 0);
            break;
        }
    }
    portalRequestDbQuery("UPDATE settings SET
        config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
        config_ticket_next_number = config_ticket_next_number + 1
        WHERE company_id = 1", 'Could not allocate the portal request ticket number');
    $ticket_number = intval(mysqli_insert_id($mysqli));
    if (!$ticket_number) {
        throw new RuntimeException('The portal request ticket number was not allocated');
    }
    $prefix = mysqli_real_escape_string($mysqli, (string) $config_ticket_prefix);
    $category_id = intval($definition['category_id']);
    if ($category_id) {
        $category = mysqli_fetch_assoc(portalRequestDbQuery("SELECT category_id FROM categories
            WHERE category_id = $category_id AND category_type = 'Ticket'
            AND category_archived_at IS NULL LIMIT 1 FOR UPDATE",
            'Could not lock the portal request ticket category'));
        if (!$category) {
            throw new RuntimeException('The published request ticket category is no longer active');
        }
    }
    portalRequestDbQuery("INSERT INTO tickets SET
        ticket_prefix = '$prefix', ticket_number = $ticket_number,
        ticket_source = 'Portal Catalog',
        ticket_category = $category_id,
        ticket_subject = '$subject', ticket_details = '$details',
        ticket_priority = '$priority_sql', ticket_status = 1,
        ticket_billable = " . intval($config_ticket_default_billable) . ",
        ticket_created_by = " . intval($submission['portal_request_submission_user_id']) . ",
        ticket_contact_id = $contact_id, ticket_asset_id = $asset_id,
        ticket_url_key = '$url_key', ticket_client_id = $client_id",
        'Could not create the portal request ticket');
    $ticket_id = intval(mysqli_insert_id($mysqli));
    if (!$ticket_id) {
        throw new RuntimeException('The portal request ticket did not receive an ID');
    }
    // Establish the immutable request-to-ticket seam before SLA selection so
    // agreement rules can resolve the catalog key in this same transaction.
    portalRequestDbQuery("UPDATE portal_request_submissions SET
        portal_request_submission_ticket_id = $ticket_id
        WHERE portal_request_submission_id = $submission_id
        AND portal_request_submission_status = '" . mysqli_real_escape_string($mysqli, $current_status) . "'
        AND portal_request_submission_ticket_id IS NULL LIMIT 1",
        'Could not reserve the portal request ticket link');
    if (mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('The portal request changed while its ticket was being linked');
    }
    applyTicketSla($ticket_id, null, null, true);
    $task_count = instantiateRunbookForTicket($ticket_id, intval($definition['ticket_template_id']), [
        'version_id' => intval($definition['runbook_version_id']),
        'caller_transaction' => true,
        // The originating actor is a portal contact or an approving agent.
        // Existing runbook task events only distinguish agents from system, so
        // avoid misattributing a contact's user ID as an internal technician.
        'started_by' => $actor_type === 'agent' ? intval($actor_id) : 0,
    ]);
    if ($task_count < 1) {
        throw new RuntimeException('The pinned runbook did not create any work tasks');
    }
    portalRequestDbQuery("UPDATE portal_request_submissions SET
        portal_request_submission_status = 'Initiated',
        portal_request_submission_initiated_at = NOW()
        WHERE portal_request_submission_id = $submission_id
        AND portal_request_submission_status = '" . mysqli_real_escape_string($mysqli, $current_status) . "'
        AND portal_request_submission_ticket_id = $ticket_id LIMIT 1",
        'Could not link the portal request ticket');
    if (mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('The portal request changed while its ticket was being created');
    }
    portalRequestRecordEvent(
        $submission_id,
        'ticket_created',
        $current_status,
        'Initiated',
        $actor_type,
        $actor_id,
        "Ticket $prefix$ticket_number created from pinned catalog and runbook releases"
    );
    portalRequestEnqueueCustomAction($submission_id, $ticket_id, 'ticket_create');
    return $ticket_id;
}

function portalRequestSubmit($version_id, $client_id, $contact_id, $user_id, $submitted, $idempotency_key) {
    global $mysqli;

    $version_id = intval($version_id);
    $client_id = intval($client_id);
    $contact_id = intval($contact_id);
    $user_id = intval($user_id);
    $idempotency_key = trim((string) $idempotency_key);
    if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $idempotency_key)) {
        throw new InvalidArgumentException('The request form expired. Refresh it and try again.');
    }
    $idempotency_hash = hash('sha256', "$client_id:$contact_id:$idempotency_key");
    $request_hash = '';
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the portal request transaction');
    }
    try {
        $contact = portalRequestContactContext($contact_id, $client_id, true);
        if (!$contact || intval($contact['contact_user_id']) !== $user_id) {
            throw new RuntimeException('The portal requester is no longer active for this client');
        }
        $idempotency_sql = mysqli_real_escape_string($mysqli, $idempotency_hash);
        $existing = mysqli_fetch_assoc(portalRequestDbQuery("SELECT portal_request_submission_id,
            portal_request_submission_version_id, portal_request_submission_ticket_id,
            portal_request_submission_status, portal_request_submission_request_hash,
            portal_request_submission_responses, portal_request_submission_response_hash
            FROM portal_request_submissions
            WHERE portal_request_submission_idempotency_hash = '$idempotency_sql'
            AND portal_request_submission_client_id = $client_id
            AND portal_request_submission_contact_id = $contact_id LIMIT 1 FOR UPDATE",
            'Could not check duplicate portal requests'));
        if ($existing) {
            $existing_version_id = intval($existing['portal_request_submission_version_id']);
            if ($existing_version_id !== $version_id) {
                throw new InvalidArgumentException('An idempotency credential cannot be reused for another request release');
            }
            $existing_definition = portalRequestAssertVersion($existing_version_id);
            $request_hash = portalRequestSubmissionRequestHash($version_id, $existing_definition, $submitted);
            $stored_request_hash = strtolower(trim((string) $existing['portal_request_submission_request_hash']));
            if (!preg_match('/^[a-f0-9]{64}$/', $stored_request_hash)
                || !hash_equals($stored_request_hash, $request_hash)) {
                throw new InvalidArgumentException('An idempotency credential cannot be reused with changed request content');
            }
            portalRequestResponsePayload($existing);
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not finish the duplicate request check');
            }
            return [
                'submission_id' => intval($existing['portal_request_submission_id']),
                'ticket_id' => intval($existing['portal_request_submission_ticket_id']),
                'status' => (string) $existing['portal_request_submission_status'],
                'duplicate' => true,
            ];
        }
        $release = mysqli_fetch_assoc(portalRequestDbQuery("SELECT
            i.portal_request_catalog_item_id, i.portal_request_catalog_item_published_version_id,
            i.portal_request_catalog_item_archived_at,
            v.portal_request_catalog_version_id
            FROM portal_request_catalog_versions v
            INNER JOIN portal_request_catalog_items i
                ON i.portal_request_catalog_item_id = v.portal_request_catalog_version_item_id
            WHERE v.portal_request_catalog_version_id = $version_id LIMIT 1 FOR UPDATE",
            'Could not lock the portal request release'));
        if (!$release || !empty($release['portal_request_catalog_item_archived_at'])
            || intval($release['portal_request_catalog_item_published_version_id']) !== $version_id) {
            throw new RuntimeException('This request form is no longer the current published release');
        }
        $definition = portalRequestAssertVersion($version_id);
        $request_hash = portalRequestSubmissionRequestHash($version_id, $definition, $submitted);
        if (!portalRequestContactCanUse($definition, $contact, $client_id)) {
            throw new RuntimeException('This request is not available for your portal role or client');
        }
        [$responses, $errors] = portalRequestValidateResponses($definition, $submitted, $client_id, $contact);
        if ($errors) {
            throw new InvalidArgumentException(reset($errors));
        }
        $response_json_raw = portalRequestCanonicalJson($responses);
        $response_hash = hash('sha256', $response_json_raw);
        $response_json = mysqli_real_escape_string($mysqli, $response_json_raw);
        $response_hash_sql = mysqli_real_escape_string($mysqli, $response_hash);
        $request_hash_sql = mysqli_real_escape_string($mysqli, $request_hash);
        $needs_approval = $definition['approval_rule'] !== 'none';
        if ($needs_approval
            && !portalRequestApprovalRouteAvailable($definition, $client_id, $contact_id, true)) {
            throw new RuntimeException('No distinct active approver is currently available for this request');
        }
        $status = $needs_approval ? 'PendingApproval' : 'Submitted';
        portalRequestDbQuery("INSERT INTO portal_request_submissions SET
            portal_request_submission_item_id = " . intval($release['portal_request_catalog_item_id']) . ",
            portal_request_submission_version_id = $version_id,
            portal_request_submission_client_id = $client_id,
            portal_request_submission_contact_id = $contact_id,
            portal_request_submission_user_id = $user_id,
            portal_request_submission_status = '$status',
            portal_request_submission_idempotency_hash = '$idempotency_sql',
            portal_request_submission_request_hash = '$request_hash_sql',
            portal_request_submission_responses = '$response_json',
            portal_request_submission_response_hash = '$response_hash_sql'",
            'Could not save the portal request submission');
        $submission_id = intval(mysqli_insert_id($mysqli));
        if (!$submission_id) {
            throw new RuntimeException('The portal request submission did not receive an ID');
        }
        portalRequestRecordEvent($submission_id, 'submitted', null, $status, 'contact', $contact_id);
        $ticket_id = 0;
        if (!$needs_approval) {
            $submission = mysqli_fetch_assoc(portalRequestDbQuery("SELECT * FROM portal_request_submissions
                WHERE portal_request_submission_id = $submission_id LIMIT 1 FOR UPDATE",
                'Could not lock the new portal request'));
            $ticket_id = portalRequestInitiateLockedSubmission($submission, $definition, 'contact', $contact_id);
            $status = 'Initiated';
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the portal request');
        }
        return [
            'submission_id' => $submission_id,
            'ticket_id' => $ticket_id,
            'status' => $status,
            'duplicate' => false,
        ];
    } catch (Throwable $exception) {
        $duplicate_key = mysqli_errno($mysqli) === 1062;
        mysqli_rollback($mysqli);
        if ($duplicate_key) {
            $idempotency_sql = mysqli_real_escape_string($mysqli, $idempotency_hash);
            $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT portal_request_submission_id,
                portal_request_submission_version_id, portal_request_submission_ticket_id,
                portal_request_submission_status, portal_request_submission_request_hash,
                portal_request_submission_responses, portal_request_submission_response_hash
                FROM portal_request_submissions
                WHERE portal_request_submission_idempotency_hash = '$idempotency_sql'
                AND portal_request_submission_client_id = $client_id
                AND portal_request_submission_contact_id = $contact_id LIMIT 1"));
            if ($existing) {
                $stored_request_hash = strtolower(trim((string) $existing['portal_request_submission_request_hash']));
                if (intval($existing['portal_request_submission_version_id']) !== $version_id
                    || !preg_match('/^[a-f0-9]{64}$/', $stored_request_hash)
                    || !preg_match('/^[a-f0-9]{64}$/', $request_hash)
                    || !hash_equals($stored_request_hash, $request_hash)) {
                    throw new InvalidArgumentException('An idempotency credential cannot be reused for changed request content');
                }
                portalRequestResponsePayload($existing);
                return [
                    'submission_id' => intval($existing['portal_request_submission_id']),
                    'ticket_id' => intval($existing['portal_request_submission_ticket_id']),
                    'status' => (string) $existing['portal_request_submission_status'],
                    'duplicate' => true,
                ];
            }
        }
        throw $exception;
    }
}

function portalRequestAgentCanApprove($user_id, $client_id, $lock = false) {
    global $mysqli;

    $user_id = intval($user_id);
    $client_id = intval($client_id);
    $lock_sql = $lock ? ' FOR UPDATE' : '';
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT u.user_id FROM users u
        INNER JOIN user_roles r ON r.role_id = u.user_role_id
        INNER JOIN clients c ON c.client_id = $client_id AND c.client_archived_at IS NULL
        WHERE u.user_id = $user_id AND u.user_type = 1 AND u.user_status = 1
        AND u.user_archived_at IS NULL
        AND (r.role_is_admin = 1 OR EXISTS (
            SELECT 1 FROM user_role_permissions p
            INNER JOIN modules m ON m.module_id = p.module_id
            WHERE p.user_role_id = u.user_role_id
            AND m.module_name = 'module_support' AND p.user_role_permission_level >= 2
        ))
        AND (r.role_is_admin = 1 OR (
            NOT EXISTS (SELECT 1 FROM user_client_permissions d
                WHERE d.user_id = u.user_id AND d.client_id = $client_id
                AND d.permission_type = 'deny')
            AND (NOT EXISTS (SELECT 1 FROM user_client_permissions a
                    WHERE a.user_id = u.user_id AND a.permission_type = 'allow')
                OR EXISTS (SELECT 1 FROM user_client_permissions a
                    WHERE a.user_id = u.user_id AND a.client_id = $client_id
                    AND a.permission_type = 'allow'))
        )) LIMIT 1$lock_sql"));
    return intval($row['user_id'] ?? 0) === $user_id;
}

function portalRequestDecide($submission_id, $decision, $actor_type, $actor_id, $client_id) {
    global $mysqli;

    $submission_id = intval($submission_id);
    $actor_id = intval($actor_id);
    $client_id = intval($client_id);
    if (!in_array($decision, ['approved', 'declined'], true)
        || !in_array($actor_type, ['contact', 'agent'], true)) {
        throw new InvalidArgumentException('Choose a valid approval decision');
    }
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the request approval transaction');
    }
    try {
        $submission = mysqli_fetch_assoc(portalRequestDbQuery("SELECT * FROM portal_request_submissions
            WHERE portal_request_submission_id = $submission_id LIMIT 1 FOR UPDATE",
            'Could not lock the portal request approval'));
        if (!$submission || intval($submission['portal_request_submission_client_id']) !== $client_id
            || $submission['portal_request_submission_status'] !== 'PendingApproval') {
            throw new RuntimeException('The portal request approval is no longer actionable');
        }
        $definition = portalRequestAssertVersion(intval($submission['portal_request_submission_version_id']));
        $rule = (string) $definition['approval_rule'];
        if ($actor_type === 'agent') {
            if ($rule !== 'internal' || !portalRequestAgentCanApprove($actor_id, $client_id, true)) {
                throw new RuntimeException('You are not eligible to decide this internal request approval');
            }
        } else {
            $contact = portalRequestContactContext($actor_id, $client_id, true);
            if ($rule === 'internal' || $rule === 'none' || !$contact
                || $actor_id === intval($submission['portal_request_submission_contact_id'])
                || !portalRequestContactMatchesRule($contact, $rule)) {
                throw new RuntimeException('Another eligible contact must decide this request approval');
            }
        }
        $next = $decision === 'approved' ? 'Approved' : 'Declined';
        portalRequestDbQuery("UPDATE portal_request_submissions SET
            portal_request_submission_status = '$next',
            portal_request_submission_decided_by_type = '" . mysqli_real_escape_string($mysqli, $actor_type) . "',
            portal_request_submission_decided_by_id = $actor_id,
            portal_request_submission_decided_at = NOW()
            WHERE portal_request_submission_id = $submission_id
            AND portal_request_submission_status = 'PendingApproval' LIMIT 1",
            'Could not decide the portal request approval');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The portal request approval was already decided');
        }
        portalRequestRecordEvent(
            $submission_id,
            $decision,
            'PendingApproval',
            $next,
            $actor_type,
            $actor_id
        );
        $ticket_id = 0;
        if ($decision === 'approved') {
            $submission['portal_request_submission_status'] = 'Approved';
            $ticket_id = portalRequestInitiateLockedSubmission($submission, $definition, $actor_type, $actor_id);
            $next = 'Initiated';
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the portal request approval');
        }
        return ['ticket_id' => $ticket_id, 'status' => $next];
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        throw $exception;
    }
}

function portalRequestStarterDefinitions() {
    return [
        ['new-user', 'new_user', 'New user', 'Prepare accounts, licensing and equipment for a new team member.', 'fas fa-user-plus', 'manager', 'manager', [
            ['user_name', 'Employee name', 'text', true], ['email', 'Preferred email address', 'email', true],
            ['job_title', 'Job title', 'text', true], ['start_date', 'Start date', 'date', true],
            ['manager', 'Manager', 'text', true], ['access_needed', 'Applications and access needed', 'textarea', true],
        ]],
        ['termination', 'termination', 'Employee termination', 'Coordinate access removal and equipment recovery.', 'fas fa-user-minus', 'manager', 'manager', [
            ['departing_user', 'Departing user', 'contact', true], ['last_day', 'Last working day', 'date', true],
            ['disable_by', 'Disable access by', 'datetime', true], ['equipment_plan', 'Equipment return plan', 'textarea', true],
        ]],
        ['new-device', 'new_device', 'New device', 'Request a prepared workstation or other managed device.', 'fas fa-laptop', 'manager', 'manager', [
            ['assigned_user', 'Assigned user', 'contact', true], ['device_type', 'Device type', 'select', true, ['Laptop', 'Desktop', 'Tablet', 'Phone', 'Other']],
            ['needed_by', 'Needed by', 'date', true], ['requirements', 'Software and hardware requirements', 'textarea', true],
        ]],
        ['access-change', 'access_change', 'Access change', 'Add, change or remove application and data access.', 'fas fa-user-lock', 'manager', 'manager', [
            ['target_user', 'User', 'contact', true], ['change_type', 'Change type', 'select', true, ['Add access', 'Modify access', 'Remove access']],
            ['access_details', 'Access requested', 'textarea', true], ['business_reason', 'Business reason', 'textarea', true],
            ['needed_by', 'Needed by', 'date', true],
        ]],
        ['incident-report', 'incident', 'Report an incident', 'Report a service interruption, security concern or urgent issue.', 'fas fa-exclamation-triangle', 'any', 'none', [
            ['subject', 'What is affected?', 'text', true], ['affected_device', 'Affected device', 'asset', false],
            ['impact', 'Business impact', 'select', true, ['One person', 'Several people', 'Most or all users', 'Security or data concern']],
            ['started_at', 'When did it start?', 'datetime', false], ['details', 'What happened?', 'textarea', true],
        ]],
        ['scheduled-work', 'scheduled_work', 'Schedule work', 'Plan maintenance, an office change or other coordinated work.', 'fas fa-calendar-check', 'manager', 'manager', [
            ['subject', 'Work summary', 'text', true], ['preferred_time', 'Preferred date and time', 'datetime', true],
            ['duration', 'Estimated duration in minutes', 'integer', false], ['impact', 'Expected user impact', 'textarea', true],
            ['details', 'Scope and coordination notes', 'textarea', true],
        ]],
    ];
}

function portalRequestInstallStarters($created_by = 0) {
    global $mysqli;

    $created_by = intval($created_by);
    $installed = 0;
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin starter request installation');
    }
    try {
        foreach (portalRequestStarterDefinitions() as $starter) {
            [$key, $type, $name, $description, $icon, $permission, $approval, $fields] = $starter;
            $key_sql = mysqli_real_escape_string($mysqli, $key);
            $exists = mysqli_fetch_row(portalRequestDbQuery("SELECT COUNT(*)
                FROM portal_request_catalog_items
                WHERE portal_request_catalog_item_key = '$key_sql'",
                'Could not check starter request keys'));
            if (intval($exists[0] ?? 0) > 0) {
                continue;
            }
            $type_sql = mysqli_real_escape_string($mysqli, $type);
            $name_sql = mysqli_real_escape_string($mysqli, $name);
            $description_sql = mysqli_real_escape_string($mysqli, $description);
            $icon_sql = mysqli_real_escape_string($mysqli, $icon);
            $permission_sql = mysqli_real_escape_string($mysqli, $permission);
            $approval_sql = mysqli_real_escape_string($mysqli, $approval);
            portalRequestDbQuery("INSERT INTO portal_request_catalog_items SET
                portal_request_catalog_item_key = '$key_sql',
                portal_request_catalog_item_type = '$type_sql',
                portal_request_catalog_item_name = '$name_sql',
                portal_request_catalog_item_description = '$description_sql',
                portal_request_catalog_item_icon = '$icon_sql',
                portal_request_catalog_item_permission_rule = '$permission_sql',
                portal_request_catalog_item_approval_rule = '$approval_sql',
                portal_request_catalog_item_created_by = $created_by,
                portal_request_catalog_item_updated_by = $created_by,
                portal_request_catalog_item_order = " . ($installed * 10),
                'Could not install a starter request');
            $item_id = intval(mysqli_insert_id($mysqli));
            foreach ($fields as $order => $field) {
                [$field_key, $label, $field_type, $required] = $field;
                $options = $field[4] ?? [];
                $field_key_sql = mysqli_real_escape_string($mysqli, $field_key);
                $label_sql = mysqli_real_escape_string($mysqli, $label);
                $field_type_sql = mysqli_real_escape_string($mysqli, $field_type);
                $options_sql = mysqli_real_escape_string($mysqli, portalRequestCanonicalJson($options));
                $max_length = $field_type === 'textarea' ? 4000 : 255;
                portalRequestDbQuery("INSERT INTO portal_request_catalog_fields SET
                    portal_request_catalog_field_item_id = $item_id,
                    portal_request_catalog_field_key = '$field_key_sql',
                    portal_request_catalog_field_label = '$label_sql',
                    portal_request_catalog_field_type = '$field_type_sql',
                    portal_request_catalog_field_required = " . ($required ? 1 : 0) . ",
                    portal_request_catalog_field_options = '$options_sql',
                    portal_request_catalog_field_max_length = $max_length,
                    portal_request_catalog_field_order = " . (($order + 1) * 10),
                    'Could not install a starter request field');
            }
            $installed++;
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit starter requests');
        }
        return $installed;
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        throw $exception;
    }
}
