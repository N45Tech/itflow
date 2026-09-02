#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must be run from the command line.\n");
    exit(1);
}

$arguments = array_slice($argv, 1);
$allowed_modes = ['--dry-run', '--apply'];
if (count($arguments) !== 3
    || !in_array($arguments[0], $allowed_modes, true)
    || preg_match('/^--client-id=([1-9][0-9]*)$/', $arguments[1], $client_match) !== 1
    || preg_match('/^--actor-id=([1-9][0-9]*)$/', $arguments[2], $actor_match) !== 1) {
    $script_name = basename((string) ($argv[0] ?? 'reconcile_internal_agreement.php'));
    fwrite(STDERR, "Usage: php $script_name (--dry-run|--apply) --client-id=ID --actor-id=ID\n");
    exit(2);
}

$dry_run = $arguments[0] === '--dry-run';
$client_id = intval($client_match[1]);
$actor_id = intval($actor_match[1]);
$app_root = dirname(__DIR__, 2);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $app_root . '/config.php';
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    require $app_root . '/includes/db.php';
}
require $app_root . '/functions/sla.php';
require $app_root . '/functions/agreements.php';

$lock_name = 'n45-itflow-reconcile-internal-agreement';
$lock_acquired = false;
$transaction_open = false;
$exit_code = 0;
$summary = [
    'contracts_created' => 0,
    'versions_created' => 0,
    'versions_published' => 0,
    'agreements_unchanged' => 0,
];

$fail_if_commercial_projection = static function (array $contract, array $specification): void {
    $expected = $specification['contract'];
    if ((string) ($contract['contract_name'] ?? '') !== $expected['name']
        || (string) ($contract['contract_type'] ?? '') !== $expected['type']
        || (string) ($contract['contract_support_hours'] ?? '') !== $expected['support_hours']
        || intval($contract['contract_review_cadence_months'] ?? 0) !== $expected['review_cadence_months']
        || (string) ($contract['contract_details'] ?? '') !== $expected['details']
        || !empty($contract['contract_archived_at'])) {
        throw new RuntimeException('The N45 Internal baseline agreement identity exists with non-canonical metadata');
    }

    $commercial_fields = [
        'contract_sla_low_response_time', 'contract_sla_low_resolution_time',
        'contract_sla_medium_response_time', 'contract_sla_medium_resolution_time',
        'contract_sla_high_response_time', 'contract_sla_high_resolution_time',
        'contract_client_address', 'contract_client_email', 'contract_client_phone',
        'contract_contact_name', 'contract_contact_signature', 'contract_contact_signature_date',
        'contract_agent_name', 'contract_agent_signature', 'contract_agent_signature_date',
        'contract_rate_standard', 'contract_rate_after_hours', 'contract_net_terms',
        'contract_start_date', 'contract_end_date', 'contract_renewal_frequency',
    ];
    foreach ($commercial_fields as $field) {
        if (!is_null($contract[$field] ?? null) && (string) $contract[$field] !== '') {
            throw new RuntimeException("The internal baseline contains commercial data in $field; owner review is required");
        }
    }
};

$assert_definition = static function (int $contract_id, int $version_id): array {
    $actual = agreementGetVersionDefinition($version_id);
    if (!$actual) {
        throw new RuntimeException('The N45 Internal agreement definition could not be loaded');
    }
    $expected = agreementInternalBaselineExpectedDefinition(
        $contract_id,
        intval($actual['version_number'] ?? 0)
    );
    if (!hash_equals(agreementDefinitionHash($expected), agreementDefinitionHash($actual))) {
        throw new RuntimeException('The N45 Internal agreement definition diverges from the non-commercial canary baseline');
    }
    return $actual;
};

$assert_publication = static function (
    int $client_id,
    int $contract_id,
    int $version_id,
    array $definition
): void {
    $contract = mysqli_fetch_assoc(agreementDbQuery("SELECT * FROM contracts
        WHERE contract_id = $contract_id AND contract_client_id = $client_id
        LIMIT 1 FOR UPDATE", 'Could not verify the internal baseline pointer'));
    $version = agreementVersionContext($version_id, true);
    if (!$contract || !$version
        || (string) $contract['contract_status'] !== 'Active'
        || intval($contract['contract_published_version_id']) !== $version_id
        || (string) $version['agreement_version_status'] !== 'Published'
        || intval($version['agreement_version_contract_id']) !== $contract_id
        || intval($version['contract_client_id']) !== $client_id) {
        throw new RuntimeException('The internal baseline publication pointer failed verification');
    }
    agreementAssertVersionIntegrity($version);
    $definition_hash = agreementDefinitionHash($definition);
    if (!hash_equals($definition_hash, (string) $version['agreement_version_definition_hash'])) {
        throw new RuntimeException('The internal baseline published hash failed verification');
    }

    $events = agreementDbQuery("SELECT * FROM agreement_version_events
        WHERE agreement_version_event_contract_id = $contract_id
        AND agreement_version_event_version_id = $version_id
        AND agreement_version_event_action = 'Published'
        ORDER BY agreement_version_event_id FOR UPDATE",
        'Could not verify the internal baseline publication event');
    if (mysqli_num_rows($events) !== 1) {
        throw new RuntimeException('The internal baseline publication evidence is missing or ambiguous');
    }
    $event = mysqli_fetch_assoc($events);
    if (intval($event['agreement_version_event_actor_id'] ?? 0) <= 0
        || trim((string) ($event['agreement_version_event_reason'] ?? '')) === ''
        || (string) ($event['agreement_version_event_created_at'] ?? '')
            !== (string) ($version['agreement_version_published_at'] ?? '')
        || !hash_equals(
            $definition_hash,
            (string) ($event['agreement_version_event_definition_hash'] ?? '')
        )) {
        throw new RuntimeException('The internal baseline publication event binding failed verification');
    }

    $rules = [];
    $rules_sql = agreementDbQuery("SELECT * FROM agreement_sla_rules
        WHERE agreement_sla_rule_version_id = $version_id
        ORDER BY agreement_sla_rule_id FOR UPDATE", 'Could not verify the internal canary rule');
    while ($rule = mysqli_fetch_assoc($rules_sql)) {
        $rules[] = $rule;
    }
    $canary = agreementSelectSlaRule($rules, 'n45-internal-agreement-canary', 'Low');
    if (!$canary || intval($canary['agreement_sla_rule_sla_id'] ?? -1) !== 0
        || agreementSelectSlaRule($rules, 'n45-internal-agreement-canary', 'Medium') !== null
        || agreementSelectSlaRule($rules, 'ordinary-support-request', 'Low') !== null) {
        throw new RuntimeException('The internal canary rule no longer preserves ordinary unmatched-ticket fallback');
    }
};

try {
    $lock_name_sql = mysqli_real_escape_string($mysqli, $lock_name);
    $lock_row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$lock_name_sql', 0)"));
    if (intval($lock_row[0] ?? 0) !== 1) {
        throw new RuntimeException('Another N45 Internal agreement reconciliation is already running');
    }
    $lock_acquired = true;

    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the N45 Internal agreement reconciliation transaction');
    }
    $transaction_open = true;

    $specification = agreementInternalBaselineSpecification();
    $client = mysqli_fetch_assoc(agreementDbQuery("SELECT client_id, client_name, client_lead,
        client_archived_at FROM clients WHERE client_id = $client_id LIMIT 1 FOR UPDATE",
        'Could not lock the N45 Internal client'));
    if (!$client
        || (string) $client['client_name'] !== $specification['client_name']
        || intval($client['client_lead']) !== 0
        || !is_null($client['client_archived_at'])) {
        throw new RuntimeException('The supplied client ID must identify the exact active non-lead client named N45 Internal');
    }
    agreementPublishingActor($actor_id, true, $client_id);

    $name_sql = mysqli_real_escape_string($mysqli, $specification['contract']['name']);
    $contract_rows = agreementDbQuery("SELECT * FROM contracts
        WHERE contract_client_id = $client_id AND contract_name = '$name_sql'
        ORDER BY contract_id FOR UPDATE", 'Could not inspect the N45 Internal baseline identity');
    if (mysqli_num_rows($contract_rows) > 1) {
        throw new RuntimeException('Duplicate N45 Internal baseline agreement identities require owner review');
    }
    $contract = mysqli_num_rows($contract_rows) ? mysqli_fetch_assoc($contract_rows) : null;
    $contract_id = intval($contract['contract_id'] ?? 0);

    $other_active = agreementDbQuery("SELECT contracts.contract_id, contracts.contract_name
        FROM contracts
        JOIN agreement_versions
            ON agreement_version_contract_id = contract_id
            AND agreement_version_status = 'Published'
            AND agreement_version_superseded_at IS NULL
        WHERE contract_client_id = $client_id
        AND contract_archived_at IS NULL AND contract_status = 'Active'
        " . ($contract_id > 0 ? "AND contract_id <> $contract_id" : '') . "
        LIMIT 1 FOR UPDATE", 'Could not inspect competing internal agreements');
    if (mysqli_num_rows($other_active)) {
        $other = mysqli_fetch_assoc($other_active);
        throw new RuntimeException('Another published agreement is active for N45 Internal ('
            . intval($other['contract_id']) . ' ' . $other['contract_name']
            . '); do not let the canary change agreement-selection precedence');
    }

    if (!$contract) {
        $contract_spec = $specification['contract'];
        $type_sql = mysqli_real_escape_string($mysqli, $contract_spec['type']);
        $details_sql = mysqli_real_escape_string($mysqli, $contract_spec['details']);
        $support_hours_sql = mysqli_real_escape_string($mysqli, $contract_spec['support_hours']);
        $client_name_sql = mysqli_real_escape_string($mysqli, $specification['client_name']);
        $cadence = intval($contract_spec['review_cadence_months']);
        agreementDbQuery("INSERT INTO contracts SET
            contract_name = '$name_sql', contract_status = 'Draft', contract_type = '$type_sql',
            contract_details = '$details_sql', contract_client_id = $client_id,
            contract_client_name = '$client_name_sql', contract_support_hours = '$support_hours_sql',
            contract_review_cadence_months = $cadence", 'Could not create the N45 Internal baseline agreement');
        $contract_id = intval(mysqli_insert_id($mysqli));
        $contract = mysqli_fetch_assoc(agreementDbQuery("SELECT * FROM contracts
            WHERE contract_id = $contract_id LIMIT 1 FOR UPDATE",
            'Could not reload the N45 Internal baseline agreement'));
        $summary['contracts_created']++;
    }
    $fail_if_commercial_projection($contract, $specification);
    if ((string) ($contract['contract_client_name'] ?? '') !== $specification['client_name']) {
        throw new RuntimeException('The internal baseline client-name snapshot does not match N45 Internal');
    }

    $versions = [];
    $versions_sql = agreementDbQuery("SELECT * FROM agreement_versions
        WHERE agreement_version_contract_id = $contract_id
        ORDER BY agreement_version_number, agreement_version_id FOR UPDATE",
        'Could not inspect N45 Internal agreement versions');
    while ($version = mysqli_fetch_assoc($versions_sql)) {
        $versions[] = $version;
    }
    if (count($versions) > 1) {
        throw new RuntimeException('The internal baseline has unexpected version history; owner review is required');
    }

    $published_pointer = intval($contract['contract_published_version_id'] ?? 0);
    if ($published_pointer > 0) {
        if (count($versions) !== 1
            || intval($versions[0]['agreement_version_id']) !== $published_pointer
            || (string) $versions[0]['agreement_version_status'] !== 'Published') {
            throw new RuntimeException('The internal baseline current-version pointer is inconsistent');
        }
        $version_id = $published_pointer;
        $definition = $assert_definition($contract_id, $version_id);
        $assert_publication($client_id, $contract_id, $version_id, $definition);
        $summary['agreements_unchanged']++;
    } else {
        if ((string) ($contract['contract_status'] ?? '') !== 'Draft'
            || !empty($contract['contract_next_review_at'])) {
            throw new RuntimeException('An unpublished internal baseline must be a clean Draft contract; owner review is required');
        }
        if ($versions) {
            throw new RuntimeException('The unpublished internal baseline already has version history; owner review is required');
        }
        $orphan_events = agreementDbQuery("SELECT agreement_version_event_id
            FROM agreement_version_events
            WHERE agreement_version_event_contract_id = $contract_id LIMIT 1 FOR UPDATE",
            'Could not inspect unpublished internal agreement evidence');
        if (mysqli_num_rows($orphan_events)) {
            throw new RuntimeException('The unpublished internal baseline has retained lifecycle evidence; owner review is required');
        }

        $contract_spec = $specification['contract'];
        $version_spec = $specification['version'];
        $version_name_sql = mysqli_real_escape_string($mysqli, $contract_spec['name']);
        $version_type_sql = mysqli_real_escape_string($mysqli, $contract_spec['type']);
        $version_support_sql = mysqli_real_escape_string($mysqli, $contract_spec['support_hours']);
        $version_details_sql = mysqli_real_escape_string($mysqli, $contract_spec['details']);
        $cadence = intval($contract_spec['review_cadence_months']);
        $notice = intval($version_spec['renewal_notice_days']);
        agreementDbQuery("INSERT INTO agreement_versions SET
            agreement_version_contract_id = $contract_id, agreement_version_number = 1,
            agreement_version_status = 'Draft', agreement_version_name = '$version_name_sql',
            agreement_version_type = '$version_type_sql', agreement_version_effective_from = NULL,
            agreement_version_effective_until = NULL,
            agreement_version_support_hours = '$version_support_sql',
            agreement_version_review_cadence_months = $cadence,
            agreement_version_renewal_notice_days = $notice,
            agreement_version_details = '$version_details_sql',
            agreement_version_created_by = $actor_id",
            'Could not create the N45 Internal baseline version');
        $version_id = intval(mysqli_insert_id($mysqli));

        $entitlement = $specification['entitlement'];
        $scope_type_sql = mysqli_real_escape_string($mysqli, $entitlement['scope_type']);
        $scope_key_sql = mysqli_real_escape_string($mysqli, $entitlement['scope_key']);
        $scope_label_sql = mysqli_real_escape_string($mysqli, $entitlement['scope_label']);
        $classification_sql = mysqli_real_escape_string($mysqli, $entitlement['classification']);
        $notes_sql = mysqli_real_escape_string($mysqli, $entitlement['notes']);
        agreementDbQuery("INSERT INTO agreement_entitlements SET
            agreement_entitlement_version_id = $version_id,
            agreement_entitlement_scope_type = '$scope_type_sql',
            agreement_entitlement_scope_id = 0,
            agreement_entitlement_scope_key = '$scope_key_sql',
            agreement_entitlement_scope_label = '$scope_label_sql',
            agreement_entitlement_quantity_limit = NULL,
            agreement_entitlement_classification = '$classification_sql',
            agreement_entitlement_notes = '$notes_sql'",
            'Could not create the N45 Internal canary entitlement');

        $rule = $specification['sla_rule'];
        $request_key_sql = mysqli_real_escape_string($mysqli, $rule['request_type_key']);
        $priority_sql = mysqli_real_escape_string($mysqli, $rule['priority']);
        $sla_name_sql = mysqli_real_escape_string($mysqli, $rule['sla_name']);
        $timezone_sql = mysqli_real_escape_string($mysqli, $rule['timezone']);
        $rule_classification_sql = mysqli_real_escape_string($mysqli, $rule['classification']);
        agreementDbQuery("INSERT INTO agreement_sla_rules SET
            agreement_sla_rule_version_id = $version_id,
            agreement_sla_rule_request_type_key = '$request_key_sql',
            agreement_sla_rule_priority = '$priority_sql',
            agreement_sla_rule_sla_id = 0, agreement_sla_rule_sla_name = '$sla_name_sql',
            agreement_sla_rule_response_minutes = NULL,
            agreement_sla_rule_resolution_minutes = NULL,
            agreement_sla_rule_calendar_mode = 'none',
            agreement_sla_rule_business_days = NULL,
            agreement_sla_rule_business_hours_start = NULL,
            agreement_sla_rule_business_hours_end = NULL,
            agreement_sla_rule_timezone = '$timezone_sql',
            agreement_sla_rule_classification = '$rule_classification_sql',
            agreement_sla_rule_classification_basis = 'explicit_rule',
            agreement_sla_rule_behavior_version = 1,
            agreement_sla_rule_sla_eligible = 1,
            agreement_sla_rule_ticket_onsite = 0,
            agreement_sla_rule_ticket_billable = 0,
            agreement_sla_rule_order = 0", 'Could not create the inert internal canary SLA rule');
        $summary['versions_created']++;

        $definition = $assert_definition($contract_id, $version_id);
        $publication = agreementPublishVersion(
            $version_id,
            $actor_id,
            'Provision N45 Internal non-commercial agreement canary baseline',
            true
        );
        if (intval($publication['contract_id'] ?? 0) !== $contract_id
            || intval($publication['version_id'] ?? 0) !== $version_id
            || !hash_equals(
                agreementDefinitionHash($definition),
                (string) ($publication['definition_hash'] ?? '')
            )) {
            throw new RuntimeException('The internal baseline publication result failed verification');
        }
        $assert_publication($client_id, $contract_id, $version_id, $definition);
        $summary['versions_published']++;
    }

    if ($dry_run) {
        if (!mysqli_rollback($mysqli)) {
            throw new RuntimeException('Could not roll back the internal agreement reconciliation dry run');
        }
    } elseif (!mysqli_commit($mysqli)) {
        throw new RuntimeException('Could not commit the internal agreement reconciliation');
    }
    $transaction_open = false;
} catch (Throwable $e) {
    if ($transaction_open) {
        mysqli_rollback($mysqli);
        $transaction_open = false;
    }
    fwrite(STDERR, 'N45 Internal agreement reconciliation failed: ' . $e->getMessage() . PHP_EOL);
    $exit_code = 1;
} finally {
    if ($lock_acquired) {
        $released = mysqli_fetch_row(mysqli_query($mysqli, "SELECT RELEASE_LOCK('$lock_name_sql')"));
        if (intval($released[0] ?? 0) !== 1) {
            fwrite(STDERR, "The internal agreement reconciliation advisory lock release could not be confirmed.\n");
            $exit_code = 1;
        }
    }
}

if ($exit_code === 0) {
    $mode = $dry_run ? 'DRY RUN (rolled back)' : 'APPLIED';
    echo "$mode: created {$summary['contracts_created']} contract(s); "
        . "created {$summary['versions_created']} version(s); "
        . "published {$summary['versions_published']} version(s); "
        . "unchanged {$summary['agreements_unchanged']}.\n";
}

exit($exit_code);
