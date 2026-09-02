<?php

/*
 * ITFlow - Agreement entitlement and service-review helpers
 *
 * Compatibility seams
 * -------------------
 * Goal 7 may expose requestCatalogAgreementKeyForTicket(array $ticket): string.
 * When present, that request-catalog key is used for SLA rule matching. Until
 * then ticket_category is normalized into the same stable key space.
 *
 * Goal 4 may expose documentationServiceReviewReadiness(int $client_id): array.
 * Its result is copied into new review snapshots. Without it, reviews disclose
 * that only basic document counts/freshness were available; they never invent a
 * readiness score. A unified endpoint view can similarly expose
 * unifiedDeviceServiceReviewSnapshot(int $client_id): array.
 */

function agreementScopeTypes(): array
{
    return [
        'users' => 'Users',
        'devices' => 'Devices',
        'services' => 'Services',
        'locations' => 'Locations',
        'hours' => 'Hours',
    ];
}

function agreementClassifications(): array
{
    return [
        'included' => 'Included',
        'excluded' => 'Excluded',
        'onsite' => 'Onsite',
        'after_hours' => 'After hours',
        'billable' => 'Billable',
    ];
}

// Version 1 is an intentionally small, mutually-exclusive operational matrix.
// Entitlement rows describe commercial scope; when the same classification is
// selected by an SLA rule, these effects are authoritative for the ticket.
function agreementClassificationBehavior(string $classification, int $version = 1): array
{
    if ($version !== 1) {
        throw new RuntimeException('Unsupported agreement classification behavior version');
    }

    $matrix = [
        'included' => ['sla_eligible' => 1, 'ticket_onsite' => 0, 'ticket_billable' => 0],
        'excluded' => ['sla_eligible' => 0, 'ticket_onsite' => 0, 'ticket_billable' => 1],
        'onsite' => ['sla_eligible' => 1, 'ticket_onsite' => 1, 'ticket_billable' => 0],
        'after_hours' => ['sla_eligible' => 1, 'ticket_onsite' => 0, 'ticket_billable' => 1],
        'billable' => ['sla_eligible' => 1, 'ticket_onsite' => 0, 'ticket_billable' => 1],
    ];
    if (!isset($matrix[$classification])) {
        throw new RuntimeException('Unsupported agreement classification behavior');
    }

    return ['behavior_version' => $version] + $matrix[$classification];
}

function agreementNormalizeRequestTypeKey($value): string
{
    $value = strtolower(trim((string) $value));
    if ($value === '' || $value === '*') {
        return '*';
    }

    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return substr(trim((string) $value, '-'), 0, 100) ?: '*';
}

function agreementNormalizePriority($value): string
{
    $value = trim((string) $value);
    if ($value === '' || $value === '*') {
        return '*';
    }

    $known = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];
    return $known[strtolower($value)] ?? substr($value, 0, 20);
}

function agreementRuleSpecificity(array $rule, string $request_type_key, string $priority): int
{
    $rule_request = agreementNormalizeRequestTypeKey($rule['request_type_key'] ?? $rule['agreement_sla_rule_request_type_key'] ?? '*');
    $rule_priority = agreementNormalizePriority($rule['priority'] ?? $rule['agreement_sla_rule_priority'] ?? '*');

    if ($rule_request !== '*' && $rule_request !== $request_type_key) {
        return -1;
    }
    if ($rule_priority !== '*' && strcasecmp($rule_priority, $priority) !== 0) {
        return -1;
    }

    return ($rule_request === '*' ? 0 : 2) + ($rule_priority === '*' ? 0 : 1);
}

/**
 * Select one SLA rule with a stable precedence:
 * exact request + exact priority, exact request + any priority,
 * any request + exact priority, then the all-purpose default.
 * Ties use rule_order and finally the durable row id.
 */
function agreementSelectSlaRule(array $rules, $request_type_key, $priority): ?array
{
    $request_type_key = agreementNormalizeRequestTypeKey($request_type_key);
    $priority = agreementNormalizePriority($priority);
    $matches = [];

    foreach ($rules as $rule) {
        $specificity = agreementRuleSpecificity($rule, $request_type_key, $priority);
        if ($specificity < 0) {
            continue;
        }
        $rule['_agreement_specificity'] = $specificity;
        $matches[] = $rule;
    }

    usort($matches, static function (array $left, array $right): int {
        $specificity = intval($right['_agreement_specificity']) <=> intval($left['_agreement_specificity']);
        if ($specificity !== 0) {
            return $specificity;
        }

        $order = intval($left['rule_order'] ?? $left['agreement_sla_rule_order'] ?? 0)
            <=> intval($right['rule_order'] ?? $right['agreement_sla_rule_order'] ?? 0);
        if ($order !== 0) {
            return $order;
        }

        return intval($left['rule_id'] ?? $left['agreement_sla_rule_id'] ?? 0)
            <=> intval($right['rule_id'] ?? $right['agreement_sla_rule_id'] ?? 0);
    });

    return $matches[0] ?? null;
}

function agreementRuleClassification(array $rule): array
{
    $classification = (string) ($rule['classification']
        ?? $rule['agreement_sla_rule_classification'] ?? 'included');
    $basis = (string) ($rule['classification_basis']
        ?? $rule['agreement_sla_rule_classification_basis'] ?? 'explicit_rule');
    if (!isset(agreementClassifications()[$classification]) || $basis !== 'explicit_rule') {
        throw new RuntimeException('Agreement SLA classification semantics are unsupported');
    }

    return ['classification' => $classification, 'classification_basis' => $basis];
}

function agreementRuleOperationalBehavior(array $rule): array
{
    $classification = agreementRuleClassification($rule)['classification'];
    $version = intval($rule['behavior_version']
        ?? $rule['agreement_sla_rule_behavior_version'] ?? 1);
    $behavior = agreementClassificationBehavior($classification, $version);
    $stored_keys = [
        'sla_eligible' => 'agreement_sla_rule_sla_eligible',
        'ticket_onsite' => 'agreement_sla_rule_ticket_onsite',
        'ticket_billable' => 'agreement_sla_rule_ticket_billable',
    ];
    foreach ($stored_keys as $key => $database_key) {
        $stored = $rule[$key] ?? $rule[$database_key] ?? null;
        if (!is_null($stored) && intval($stored) !== $behavior[$key]) {
            throw new RuntimeException("Agreement classification behavior does not match matrix version $version");
        }
    }

    return $behavior;
}

function agreementRuleSlaSnapshot(array $rule): array
{
    $calendar = [
        'calendar_mode' => (string) ($rule['calendar_mode']
            ?? $rule['agreement_sla_rule_calendar_mode'] ?? 'none'),
        'business_days' => $rule['business_days']
            ?? $rule['agreement_sla_rule_business_days'] ?? '',
        'business_hours_start' => $rule['business_hours_start']
            ?? $rule['agreement_sla_rule_business_hours_start'] ?? null,
        'business_hours_end' => $rule['business_hours_end']
            ?? $rule['agreement_sla_rule_business_hours_end'] ?? null,
        'timezone' => (string) ($rule['timezone']
            ?? $rule['agreement_sla_rule_timezone'] ?? 'UTC'),
    ];
    if (function_exists('slaNormalizeCalendarSnapshot')) {
        $calendar = slaNormalizeCalendarSnapshot($calendar);
    }

    return [
        'sla_id' => intval($rule['sla_id'] ?? $rule['agreement_sla_rule_sla_id'] ?? 0),
        'sla_name' => (string) ($rule['sla_name'] ?? $rule['agreement_sla_rule_sla_name'] ?? 'None'),
        'response_minutes' => is_null($rule['response_minutes']
            ?? $rule['agreement_sla_rule_response_minutes'] ?? null)
            ? null : intval($rule['response_minutes'] ?? $rule['agreement_sla_rule_response_minutes']),
        'resolution_minutes' => is_null($rule['resolution_minutes']
            ?? $rule['agreement_sla_rule_resolution_minutes'] ?? null)
            ? null : intval($rule['resolution_minutes'] ?? $rule['agreement_sla_rule_resolution_minutes']),
    ] + $calendar;
}

function agreementCanonicalize($value)
{
    if (!is_array($value)) {
        return $value;
    }

    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    foreach ($value as $key => $child) {
        $value[$key] = agreementCanonicalize($child);
    }

    return $value;
}

function agreementCanonicalJson(array $value): string
{
    return json_encode(
        agreementCanonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    );
}

function agreementDefinitionHash(array $definition): string
{
    return hash('sha256', agreementCanonicalJson($definition));
}

function agreementLegacyDefinitionV0(array $definition): array
{
    foreach ($definition['sla_rules'] ?? [] as $index => $rule) {
        foreach (['behavior_version', 'sla_eligible', 'ticket_onsite', 'ticket_billable'] as $key) {
            unset($rule[$key]);
        }
        $definition['sla_rules'][$index] = $rule;
    }
    return $definition;
}

function agreementDbQuery(string $sql, string $message = 'Agreement database operation failed')
{
    global $mysqli;

    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        throw new RuntimeException($message . ': ' . mysqli_error($mysqli));
    }

    return $result;
}

/**
 * Serialize immutable agreement, SLA-decision, and service-review writers with
 * client hard deletion. The caller owns the surrounding transaction and must
 * hold this lock until its evidence write commits or rolls back.
 */
function agreementLockClientForAuditRetention(int $client_id): ?array
{
    $client_id = intval($client_id);
    if ($client_id <= 0) {
        return null;
    }

    $sql = agreementDbQuery("SELECT client_id, client_name, client_archived_at
        FROM clients WHERE client_id = $client_id LIMIT 1 FOR UPDATE",
        'Could not lock the client for agreement audit retention');
    return mysqli_num_rows($sql) ? mysqli_fetch_assoc($sql) : null;
}

/**
 * A ticket decision, clock interval, or snapshotted SLA term is immutable
 * evidence. Query failures throw so destructive callers retain the ticket.
 */
function agreementTicketHasAuditHistory(int $ticket_id, int $client_id = 0): bool
{
    $ticket_id = intval($ticket_id);
    $client_id = max(0, intval($client_id));
    if ($ticket_id <= 0) {
        return false;
    }

    $ticket_client_scope = $client_id > 0 ? " AND ticket_client_id = $client_id" : '';
    $row = mysqli_fetch_row(agreementDbQuery("SELECT
        EXISTS (SELECT 1 FROM ticket_agreement_decisions
            WHERE ticket_agreement_decision_ticket_id = $ticket_id
            LIMIT 1)
        OR EXISTS (SELECT 1 FROM sla_history
            WHERE sla_history_ticket_id = $ticket_id LIMIT 1)
        OR EXISTS (SELECT 1 FROM tickets
            WHERE ticket_id = $ticket_id $ticket_client_scope
            AND (ticket_sla_id > 0
                OR ticket_sla_response_minutes_snapshot IS NOT NULL
                OR ticket_sla_resolution_minutes_snapshot IS NOT NULL
                OR ticket_sla_calendar_mode IS NOT NULL
                OR ticket_sla_business_days IS NOT NULL
                OR ticket_sla_business_hours_start IS NOT NULL
                OR ticket_sla_business_hours_end IS NOT NULL
                OR ticket_sla_timezone IS NOT NULL
                OR ticket_response_due_at_utc IS NOT NULL
                OR ticket_resolution_due_at_utc IS NOT NULL
                OR ticket_response_sla_met IS NOT NULL
                OR ticket_resolution_sla_met IS NOT NULL)
            LIMIT 1)", 'Could not inspect ticket agreement or SLA audit history'));
    return intval($row[0] ?? 0) === 1;
}

/**
 * Retain every client-scoped immutable agreement, ticket-SLA, and review row,
 * including child events whose parent row may already be inconsistent.
 * Query failures throw so client deletion fails closed.
 */
function agreementClientHasAuditHistory(int $client_id): bool
{
    $client_id = intval($client_id);
    if ($client_id <= 0) {
        return false;
    }

    $row = mysqli_fetch_row(agreementDbQuery("SELECT
        EXISTS (SELECT 1 FROM contracts
            WHERE contract_client_id = $client_id LIMIT 1)
        OR EXISTS (SELECT 1 FROM agreement_versions
            INNER JOIN contracts ON contract_id = agreement_version_contract_id
            WHERE contract_client_id = $client_id
            AND (agreement_version_status IN ('Published', 'Superseded')
                OR agreement_version_definition_hash IS NOT NULL
                OR agreement_version_published_at IS NOT NULL
                OR agreement_version_superseded_at IS NOT NULL)
            LIMIT 1)
        OR EXISTS (SELECT 1 FROM agreement_version_events
            INNER JOIN contracts ON contract_id = agreement_version_event_contract_id
            WHERE contract_client_id = $client_id LIMIT 1)
        OR EXISTS (SELECT 1 FROM ticket_agreement_decisions
            WHERE ticket_agreement_decision_client_id = $client_id LIMIT 1)
        OR EXISTS (SELECT 1 FROM ticket_agreement_decisions
            INNER JOIN tickets ON ticket_id = ticket_agreement_decision_ticket_id
            WHERE ticket_client_id = $client_id LIMIT 1)
        OR EXISTS (SELECT 1 FROM service_reviews
            WHERE service_review_client_id = $client_id LIMIT 1)
        OR EXISTS (SELECT 1 FROM service_review_events
            WHERE service_review_event_client_id = $client_id LIMIT 1)
        OR EXISTS (SELECT 1 FROM sla_history
            INNER JOIN tickets ON ticket_id = sla_history_ticket_id
            WHERE ticket_client_id = $client_id LIMIT 1)
        OR EXISTS (SELECT 1 FROM tickets
            WHERE ticket_client_id = $client_id
            AND (ticket_sla_id > 0
                OR ticket_sla_response_minutes_snapshot IS NOT NULL
                OR ticket_sla_resolution_minutes_snapshot IS NOT NULL
                OR ticket_sla_calendar_mode IS NOT NULL
                OR ticket_sla_business_days IS NOT NULL
                OR ticket_sla_business_hours_start IS NOT NULL
                OR ticket_sla_business_hours_end IS NOT NULL
                OR ticket_sla_timezone IS NOT NULL
                OR ticket_response_due_at_utc IS NOT NULL
                OR ticket_resolution_due_at_utc IS NOT NULL
                OR ticket_response_sla_met IS NOT NULL
                OR ticket_resolution_sla_met IS NOT NULL)
            LIMIT 1)", 'Could not inspect client agreement, SLA, or service-review audit history'));
    return intval($row[0] ?? 0) === 1;
}

function agreementVersionContext(int $version_id, bool $for_update = false): ?array
{
    $version_id = intval($version_id);
    $lock = $for_update ? ' FOR UPDATE' : '';
    $sql = agreementDbQuery("SELECT agreement_versions.*, contracts.contract_client_id,
        contracts.contract_name, contracts.contract_status, contracts.contract_archived_at,
        contracts.contract_published_version_id
        FROM agreement_versions
        JOIN contracts ON contract_id = agreement_version_contract_id
        WHERE agreement_version_id = $version_id
        LIMIT 1$lock", 'Could not load agreement version');

    return mysqli_num_rows($sql) ? mysqli_fetch_assoc($sql) : null;
}

function agreementGetVersionDefinition(int $version_id): ?array
{
    $version = agreementVersionContext($version_id);
    if (!$version) {
        return null;
    }

    $definition = [
        'contract_id' => intval($version['agreement_version_contract_id']),
        'version_number' => intval($version['agreement_version_number']),
        'name' => (string) $version['agreement_version_name'],
        'type' => (string) $version['agreement_version_type'],
        'effective_from' => $version['agreement_version_effective_from'],
        'effective_until' => $version['agreement_version_effective_until'],
        'support_hours' => $version['agreement_version_support_hours'],
        'review_cadence_months' => intval($version['agreement_version_review_cadence_months']),
        'renewal_notice_days' => intval($version['agreement_version_renewal_notice_days']),
        'details' => $version['agreement_version_details'],
        'entitlements' => [],
        'sla_rules' => [],
    ];

    $entitlements = agreementDbQuery("SELECT agreement_entitlement_scope_type,
        agreement_entitlement_scope_id, agreement_entitlement_scope_key,
        agreement_entitlement_scope_label, agreement_entitlement_quantity_limit,
        agreement_entitlement_classification, agreement_entitlement_notes
        FROM agreement_entitlements
        WHERE agreement_entitlement_version_id = $version_id
        ORDER BY agreement_entitlement_scope_type, agreement_entitlement_scope_id,
            agreement_entitlement_scope_key, agreement_entitlement_classification,
            agreement_entitlement_id", 'Could not load agreement entitlements');
    while ($row = mysqli_fetch_assoc($entitlements)) {
        $definition['entitlements'][] = [
            'scope_type' => $row['agreement_entitlement_scope_type'],
            'scope_id' => intval($row['agreement_entitlement_scope_id']),
            'scope_key' => $row['agreement_entitlement_scope_key'],
            'scope_label' => $row['agreement_entitlement_scope_label'],
            'quantity_limit' => is_null($row['agreement_entitlement_quantity_limit'])
                ? null : floatval($row['agreement_entitlement_quantity_limit']),
            'classification' => $row['agreement_entitlement_classification'],
            'notes' => $row['agreement_entitlement_notes'],
        ];
    }

    $rules = agreementDbQuery("SELECT agreement_sla_rule_id,
        agreement_sla_rule_request_type_key, agreement_sla_rule_priority,
        agreement_sla_rule_sla_id, agreement_sla_rule_sla_name,
        agreement_sla_rule_response_minutes, agreement_sla_rule_resolution_minutes,
        agreement_sla_rule_calendar_mode, agreement_sla_rule_business_days,
        agreement_sla_rule_business_hours_start, agreement_sla_rule_business_hours_end,
        agreement_sla_rule_timezone, agreement_sla_rule_classification,
        agreement_sla_rule_classification_basis,
        agreement_sla_rule_behavior_version, agreement_sla_rule_sla_eligible,
        agreement_sla_rule_ticket_onsite, agreement_sla_rule_ticket_billable,
        agreement_sla_rule_order
        FROM agreement_sla_rules
        WHERE agreement_sla_rule_version_id = $version_id
        ORDER BY agreement_sla_rule_order, agreement_sla_rule_id", 'Could not load agreement SLA rules');
    while ($row = mysqli_fetch_assoc($rules)) {
        $definition['sla_rules'][] = [
            'request_type_key' => $row['agreement_sla_rule_request_type_key'],
            'priority' => $row['agreement_sla_rule_priority'],
            'sla_id' => intval($row['agreement_sla_rule_sla_id']),
            'sla_name' => $row['agreement_sla_rule_sla_name'],
            'response_minutes' => is_null($row['agreement_sla_rule_response_minutes'])
                ? null : intval($row['agreement_sla_rule_response_minutes']),
            'resolution_minutes' => is_null($row['agreement_sla_rule_resolution_minutes'])
                ? null : intval($row['agreement_sla_rule_resolution_minutes']),
            'calendar_mode' => $row['agreement_sla_rule_calendar_mode'],
            'business_days' => $row['agreement_sla_rule_business_days'],
            'business_hours_start' => $row['agreement_sla_rule_business_hours_start'],
            'business_hours_end' => $row['agreement_sla_rule_business_hours_end'],
            'timezone' => $row['agreement_sla_rule_timezone'],
            'classification' => $row['agreement_sla_rule_classification'],
            'classification_basis' => $row['agreement_sla_rule_classification_basis'],
            'behavior_version' => intval($row['agreement_sla_rule_behavior_version']),
            'sla_eligible' => intval($row['agreement_sla_rule_sla_eligible']),
            'ticket_onsite' => intval($row['agreement_sla_rule_ticket_onsite']),
            'ticket_billable' => intval($row['agreement_sla_rule_ticket_billable']),
            'rule_order' => intval($row['agreement_sla_rule_order']),
        ];
    }

    return $definition;
}

function agreementAssertVersionIntegrity(array $version): void
{
    $status = (string) ($version['agreement_version_status'] ?? '');
    if (!in_array($status, ['Published', 'Superseded'], true)) {
        return;
    }

    $version_id = intval($version['agreement_version_id'] ?? 0);
    $stored_hash = (string) ($version['agreement_version_definition_hash'] ?? '');
    $definition = agreementGetVersionDefinition($version_id);
    if (!$definition || strlen($stored_hash) !== 64) {
        throw new RuntimeException("Published agreement version $version_id failed its definition integrity check");
    }
    $current_hash = agreementDefinitionHash($definition);
    if (hash_equals($stored_hash, $current_hash)) {
        return;
    }

    // Compatibility for versions published by the pre-release 2.8.1 build.
    // Its hash predates the explicit behavior-matrix columns. The classification
    // itself was already hashed, so accepting that exact legacy projection does
    // not permit any commercial term to change.
    $legacy_hash = agreementDefinitionHash(agreementLegacyDefinitionV0($definition));
    if (!hash_equals($stored_hash, $legacy_hash)) {
        throw new RuntimeException("Published agreement version $version_id failed its definition integrity check");
    }
}

function agreementResolveRequestTypeKey(array $ticket): string
{
    $stored_key = agreementNormalizeRequestTypeKey($ticket['ticket_request_type_key'] ?? '*');
    if ($stored_key !== '*') {
        return $stored_key;
    }

    if (function_exists('requestCatalogAgreementKeyForTicket')) {
        try {
            $catalog_key = requestCatalogAgreementKeyForTicket($ticket);
            if (is_string($catalog_key) && trim($catalog_key) !== '') {
                return agreementNormalizeRequestTypeKey($catalog_key);
            }
        } catch (Throwable $e) {
            $ticket_id = intval($ticket['ticket_id'] ?? 0);
            error_log("Request-catalog agreement adapter failed for ticket $ticket_id: " . $e->getMessage());
        }
    }

    $category_name = trim((string) ($ticket['ticket_category_name'] ?? ''));
    if ($category_name !== '') {
        return agreementNormalizeRequestTypeKey($category_name);
    }

    // ticket_category is historically a mutable numeric foreign key. It is
    // never a semantic agreement key. Callers resolve its current name once,
    // then persist the normalized result on the ticket.
    return 'uncategorized';
}

/**
 * Resolve published entitlement clauses against a locked ticket context.
 *
 * Context entries are keyed by agreement scope type and carry ids, semantic
 * keys and the active population used by broad quantity caps. Within a scope,
 * exact record/key clauses replace wildcard clauses. Every configured scope
 * must match; a missing match fails closed as excluded/billable. Classification
 * precedence is excluded, after-hours, billable, onsite, included. This keeps
 * the v1 result mutually exclusive and therefore exactly reproducible.
 */
function agreementResolveEntitlementApplicability(
    array $entitlements,
    array $context,
    string $rule_classification
): array {
    $precedence = [
        'included' => 1,
        'onsite' => 2,
        'billable' => 3,
        'after_hours' => 4,
        'excluded' => 5,
    ];
    if (!isset($precedence[$rule_classification])) {
        throw new RuntimeException('Unsupported rule classification for entitlement resolution');
    }

    $by_scope = [];
    foreach ($entitlements as $entitlement) {
        $scope = (string) ($entitlement['scope_type']
            ?? $entitlement['agreement_entitlement_scope_type'] ?? '');
        $classification = (string) ($entitlement['classification']
            ?? $entitlement['agreement_entitlement_classification'] ?? '');
        if (!isset(agreementScopeTypes()[$scope]) || !isset($precedence[$classification])) {
            throw new RuntimeException('Published agreement contains unsupported entitlement semantics');
        }
        $by_scope[$scope][] = $entitlement;
    }

    $resolved_classification = $rule_classification;
    $scope_decisions = [];
    foreach ($by_scope as $scope => $rows) {
        $scope_context = $context[$scope] ?? [];
        $ids = array_values(array_unique(array_map('intval', $scope_context['ids'] ?? [])));
        $keys = array_values(array_unique(array_map('agreementNormalizeRequestTypeKey', $scope_context['keys'] ?? [])));
        $population = max(0, intval($scope_context['population'] ?? count($ids)));
        $matches = [];
        foreach ($rows as $row) {
            $scope_id = intval($row['scope_id'] ?? $row['agreement_entitlement_scope_id'] ?? 0);
            $scope_key = agreementNormalizeRequestTypeKey($row['scope_key']
                ?? $row['agreement_entitlement_scope_key'] ?? '*');
            $specificity = 0;
            $quantity = 0;
            $basis = '';
            if ($scope_id > 0 && in_array($scope_id, $ids, true)) {
                $specificity = 3;
                $quantity = 1;
                $basis = "exact-$scope-record";
            } elseif ($scope_id === 0 && $scope_key !== '*' && in_array($scope_key, $keys, true)) {
                $specificity = 2;
                $quantity = 1;
                $basis = "exact-$scope-key";
            } elseif ($scope_id === 0 && $scope_key === '*') {
                $specificity = 1;
                $quantity = $population;
                $basis = "wildcard-$scope-population";
            }
            if (!$specificity) {
                continue;
            }
            $limit_raw = $row['quantity_limit']
                ?? $row['agreement_entitlement_quantity_limit'] ?? null;
            $limit = is_null($limit_raw) ? null : floatval($limit_raw);
            $classification = (string) ($row['classification']
                ?? $row['agreement_entitlement_classification']);
            $matches[] = [
                'entitlement_id' => intval($row['entitlement_id']
                    ?? $row['agreement_entitlement_id'] ?? 0),
                'specificity' => $specificity,
                'basis' => $basis,
                'measured_quantity' => $quantity,
                'quantity_limit' => $limit,
                'over_limit' => !is_null($limit) && $quantity > $limit,
                'classification' => $classification,
            ];
        }

        if (!$matches) {
            $resolved_classification = 'excluded';
            $scope_decisions[$scope] = [
                'matched_entitlement_ids' => [],
                'basis' => 'no-applicable-entitlement-fail-closed',
                'measured_quantity' => $population,
                'quantity_limit' => null,
                'classification' => 'excluded',
            ];
            continue;
        }

        $max_specificity = max(array_column($matches, 'specificity'));
        $matches = array_values(array_filter($matches, static function (array $match) use ($max_specificity): bool {
            return $match['specificity'] === $max_specificity;
        }));
        usort($matches, static function (array $left, array $right) use ($precedence): int {
            return ($precedence[$right['classification']] <=> $precedence[$left['classification']])
                ?: ($left['entitlement_id'] <=> $right['entitlement_id']);
        });
        $scope_classification = $matches[0]['classification'];
        $over_limit = false;
        foreach ($matches as $match) {
            $over_limit = $over_limit || $match['over_limit'];
        }
        if ($scope_classification !== 'excluded' && $over_limit) {
            $scope_classification = 'billable';
        }
        if ($precedence[$scope_classification] > $precedence[$resolved_classification]) {
            $resolved_classification = $scope_classification;
        }
        $scope_decisions[$scope] = [
            'matched_entitlement_ids' => array_column($matches, 'entitlement_id'),
            'basis' => $matches[0]['basis'],
            'measured_quantity' => max(array_column($matches, 'measured_quantity')),
            'quantity_limit' => $matches[0]['quantity_limit'],
            'over_limit' => $over_limit,
            'classification' => $scope_classification,
        ];
    }

    $behavior = agreementClassificationBehavior($resolved_classification);
    return [
        'schema_version' => 1,
        'rule_classification' => $rule_classification,
        'resolved_classification' => $resolved_classification,
        'scopes' => $scope_decisions,
        'behavior' => $behavior,
    ];
}

function agreementTicketHourScopeKeys(string $ticket_created_at, array $calendar): array
{
    $keys = ['all-hours'];
    if (function_exists('slaNormalizeCalendarSnapshot')) {
        $calendar = slaNormalizeCalendarSnapshot($calendar);
    }
    $mode = (string) ($calendar['calendar_mode'] ?? 'none');
    if ($mode === '24x7') {
        return ['all-hours', '24x7'];
    }
    if ($mode !== 'business_hours') {
        return $keys;
    }

    $app_timezone = new DateTimeZone(date_default_timezone_get());
    $created = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $ticket_created_at, $app_timezone);
    if (!$created || $created->format('Y-m-d H:i:s') !== $ticket_created_at) {
        throw new RuntimeException('Ticket creation time is invalid for entitlement resolution');
    }
    $local = $created->setTimezone(new DateTimeZone((string) $calendar['timezone']));
    $weekday = intval($local->format('N'));
    $time = $local->format('H:i:s');
    $inside = in_array($weekday, $calendar['business_days'] ?? [], true)
        && $time >= (string) ($calendar['business_hours_start'] ?? '')
        && $time < (string) ($calendar['business_hours_end'] ?? '');
    $keys[] = $inside ? 'business-hours' : 'after-hours';
    return $keys;
}

/**
 * Build a tenant-scoped, locked context for operational entitlement matching.
 * Broad quantity limits use the complete active client population; exact rows
 * use only records actually linked to the ticket. Service records are linked
 * through the ticket's contacts/assets, while a semantic service key may match
 * the immutable request-type key.
 */
function agreementTicketEntitlementContext(
    int $client_id,
    array $ticket,
    string $request_type_key,
    array $sla_snapshot,
    bool $for_update = false
): array {
    $client_id = intval($client_id);
    if ($client_id <= 0) {
        throw new RuntimeException('A client is required for agreement entitlement resolution');
    }
    $lock = $for_update ? ' FOR UPDATE' : '';

    $active_contacts = [];
    $contact_sql = agreementDbQuery("SELECT contact_id FROM contacts
        WHERE contact_client_id = $client_id AND contact_archived_at IS NULL
        ORDER BY contact_id$lock", 'Could not load agreement user population');
    while ($row = mysqli_fetch_assoc($contact_sql)) {
        $active_contacts[intval($row['contact_id'])] = true;
    }
    $ticket_contact_id = intval($ticket['ticket_contact_id'] ?? 0);
    $ticket_contact_ids = isset($active_contacts[$ticket_contact_id]) ? [$ticket_contact_id] : [];

    $active_assets = [];
    $asset_sql = agreementDbQuery("SELECT asset_id FROM assets
        WHERE asset_client_id = $client_id AND asset_archived_at IS NULL
        ORDER BY asset_id$lock", 'Could not load agreement device population');
    while ($row = mysqli_fetch_assoc($asset_sql)) {
        $active_assets[intval($row['asset_id'])] = true;
    }
    $ticket_asset_ids = [];
    $primary_asset_id = intval($ticket['ticket_asset_id'] ?? 0);
    if (isset($active_assets[$primary_asset_id])) {
        $ticket_asset_ids[$primary_asset_id] = $primary_asset_id;
    }
    $ticket_id = intval($ticket['ticket_id'] ?? 0);
    if ($ticket_id > 0) {
        $additional_assets = agreementDbQuery("SELECT asset_id FROM ticket_assets
            WHERE ticket_id = $ticket_id ORDER BY asset_id$lock",
            'Could not load additional ticket assets for entitlement resolution');
        while ($row = mysqli_fetch_assoc($additional_assets)) {
            $asset_id = intval($row['asset_id']);
            if (isset($active_assets[$asset_id])) {
                $ticket_asset_ids[$asset_id] = $asset_id;
            }
        }
    }
    $ticket_asset_ids = array_values($ticket_asset_ids);

    $active_locations = [];
    $location_sql = agreementDbQuery("SELECT location_id FROM locations
        WHERE location_client_id = $client_id AND location_archived_at IS NULL
        ORDER BY location_id$lock", 'Could not load agreement location population');
    while ($row = mysqli_fetch_assoc($location_sql)) {
        $active_locations[intval($row['location_id'])] = true;
    }
    $ticket_location_id = intval($ticket['ticket_location_id'] ?? 0);
    $ticket_location_ids = isset($active_locations[$ticket_location_id]) ? [$ticket_location_id] : [];

    $services = [];
    $service_sql = agreementDbQuery("SELECT service_id, service_name, service_category FROM services
        WHERE service_client_id = $client_id ORDER BY service_id$lock",
        'Could not load agreement service population');
    while ($row = mysqli_fetch_assoc($service_sql)) {
        $services[intval($row['service_id'])] = $row;
    }
    $linked_service_ids = [];
    if ($ticket_contact_ids) {
        $contact_list = implode(',', array_map('intval', $ticket_contact_ids));
        $linked = agreementDbQuery("SELECT service_id FROM service_contacts
            WHERE contact_id IN ($contact_list) ORDER BY service_id$lock",
            'Could not load ticket contact services for entitlement resolution');
        while ($row = mysqli_fetch_assoc($linked)) {
            $service_id = intval($row['service_id']);
            if (isset($services[$service_id])) {
                $linked_service_ids[$service_id] = $service_id;
            }
        }
    }
    if ($ticket_asset_ids) {
        $asset_list = implode(',', array_map('intval', $ticket_asset_ids));
        $linked = agreementDbQuery("SELECT service_id FROM service_assets
            WHERE asset_id IN ($asset_list) ORDER BY service_id$lock",
            'Could not load ticket asset services for entitlement resolution');
        while ($row = mysqli_fetch_assoc($linked)) {
            $service_id = intval($row['service_id']);
            if (isset($services[$service_id])) {
                $linked_service_ids[$service_id] = $service_id;
            }
        }
    }
    $service_keys = [agreementNormalizeRequestTypeKey($request_type_key)];
    foreach ($linked_service_ids as $service_id) {
        $service_keys[] = agreementNormalizeRequestTypeKey($services[$service_id]['service_name'] ?? '');
        $service_keys[] = agreementNormalizeRequestTypeKey($services[$service_id]['service_category'] ?? '');
    }
    $service_keys = array_values(array_unique(array_filter($service_keys, static fn (string $key): bool => $key !== '*')));

    return [
        'users' => [
            'ids' => $ticket_contact_ids,
            'keys' => [],
            'population' => count($active_contacts),
        ],
        'devices' => [
            'ids' => $ticket_asset_ids,
            'keys' => [],
            'population' => count($active_assets),
        ],
        'services' => [
            'ids' => array_values($linked_service_ids),
            'keys' => $service_keys,
            'population' => count($services),
        ],
        'locations' => [
            'ids' => $ticket_location_ids,
            'keys' => [],
            'population' => count($active_locations),
        ],
        'hours' => [
            'ids' => [],
            'keys' => agreementTicketHourScopeKeys((string) ($ticket['ticket_created_at'] ?? ''), $sla_snapshot),
            'population' => 1,
        ],
    ];
}

function agreementVersionAppliesAt(array $version, string $on_date, string $as_of): bool
{
    $status = (string) ($version['agreement_version_status'] ?? '');
    $published_at = (string) ($version['agreement_version_published_at'] ?? '');
    $superseded_at = (string) ($version['agreement_version_superseded_at'] ?? '');
    $effective_from = (string) ($version['agreement_version_effective_from'] ?? '');
    $effective_until = (string) ($version['agreement_version_effective_until'] ?? '');

    $lifecycle_is_consistent = ($status === 'Published' && $superseded_at === '')
        || ($status === 'Superseded' && $superseded_at !== '');

    return $lifecycle_is_consistent
        && $published_at !== '' && $published_at <= $as_of
        && ($superseded_at === '' || $superseded_at > $as_of)
        && ($effective_from === '' || $effective_from <= $on_date)
        && ($effective_until === '' || $effective_until >= $on_date);
}

function agreementGetVersionForClientAt(
    int $client_id,
    string $on_date,
    string $as_of,
    int $contract_id = 0,
    bool $for_update = false
): ?array
{
    global $mysqli;

    $client_id = intval($client_id);
    if ($client_id <= 0) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $on_date);
    $instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $as_of);
    if (!$date || $date->format('Y-m-d') !== $on_date
        || !$instant || $instant->format('Y-m-d H:i:s') !== $as_of) {
        throw new InvalidArgumentException('Agreement resolution date or instant is invalid');
    }
    $on_date_sql = mysqli_real_escape_string($mysqli, $on_date);
    $as_of_sql = mysqli_real_escape_string($mysqli, $as_of);
    $contract_filter = $contract_id > 0 ? ' AND contract_id = ' . intval($contract_id) : '';
    $lock = $for_update ? ' FOR UPDATE' : '';
    $sql = agreementDbQuery("SELECT agreement_versions.*, contracts.contract_client_id,
        contracts.contract_name, contracts.contract_status
        FROM contracts
        JOIN clients ON client_id = contract_client_id AND client_archived_at IS NULL
        JOIN agreement_versions ON agreement_version_contract_id = contract_id
        WHERE contract_client_id = $client_id
        AND contract_archived_at IS NULL
        AND contract_status = 'Active'
        AND ((agreement_version_status = 'Published' AND agreement_version_superseded_at IS NULL)
            OR (agreement_version_status = 'Superseded' AND agreement_version_superseded_at IS NOT NULL))
        AND agreement_version_published_at IS NOT NULL
        AND agreement_version_published_at <= '$as_of_sql'
        AND (agreement_version_superseded_at IS NULL OR agreement_version_superseded_at > '$as_of_sql')
        AND (agreement_version_effective_from IS NULL OR agreement_version_effective_from <= '$on_date_sql')
        AND (agreement_version_effective_until IS NULL OR agreement_version_effective_until >= '$on_date_sql')
        $contract_filter
        ORDER BY COALESCE(agreement_version_effective_from, '1000-01-01') DESC,
            agreement_version_number DESC, contract_id DESC
        LIMIT 1$lock", 'Could not resolve active client agreement');

    if (!mysqli_num_rows($sql)) {
        return null;
    }
    $version = mysqli_fetch_assoc($sql);
    if (!agreementVersionAppliesAt($version, $on_date, $as_of)) {
        throw new RuntimeException('Resolved agreement version falls outside its publication/effective interval');
    }
    agreementAssertVersionIntegrity($version);
    return $version;
}

function agreementGetActiveVersionForClient(
    int $client_id,
    ?string $on_date = null,
    bool $for_update = false
): ?array
{
    $on_date = $on_date ?: date('Y-m-d');
    $as_of = $on_date === date('Y-m-d') ? date('Y-m-d H:i:s') : $on_date . ' 23:59:59';
    return agreementGetVersionForClientAt($client_id, $on_date, $as_of, 0, $for_update);
}

function agreementResolveTicketSlaDecision(
    int $client_id,
    $priority,
    $request_type_key = '*',
    bool $for_update = false,
    array $ticket = []
): ?array
{
    $version = agreementGetActiveVersionForClient($client_id, null, $for_update);
    if (!$version) {
        return null;
    }

    $version_id = intval($version['agreement_version_id']);
    $rules = [];
    $sql = agreementDbQuery("SELECT agreement_sla_rule_id, agreement_sla_rule_request_type_key,
        agreement_sla_rule_priority, agreement_sla_rule_sla_id, agreement_sla_rule_sla_name,
        agreement_sla_rule_response_minutes, agreement_sla_rule_resolution_minutes,
        agreement_sla_rule_calendar_mode, agreement_sla_rule_business_days,
        agreement_sla_rule_business_hours_start, agreement_sla_rule_business_hours_end,
        agreement_sla_rule_timezone, agreement_sla_rule_classification,
        agreement_sla_rule_classification_basis, agreement_sla_rule_behavior_version,
        agreement_sla_rule_sla_eligible, agreement_sla_rule_ticket_onsite,
        agreement_sla_rule_ticket_billable, agreement_sla_rule_order
        FROM agreement_sla_rules
        WHERE agreement_sla_rule_version_id = $version_id"
        . ($for_update ? ' FOR UPDATE' : ''), 'Could not resolve agreement SLA rules');
    while ($row = mysqli_fetch_assoc($sql)) {
        $rules[] = $row;
    }

    $request_type_key = agreementNormalizeRequestTypeKey($request_type_key);
    $priority = agreementNormalizePriority($priority);
    $rule = agreementSelectSlaRule($rules, $request_type_key, $priority);
    if (!$rule) {
        return [
            'matched' => false,
            'client_id' => $client_id,
            'contract_id' => intval($version['agreement_version_contract_id']),
            'version_id' => $version_id,
            'rule_id' => 0,
            'request_type_key' => $request_type_key,
            'priority' => $priority,
            'classification' => null,
            'reason' => "Published agreement {$version['contract_name']}"
                . " (contract {$version['agreement_version_contract_id']}, v{$version['agreement_version_number']})"
                . ' has no matching request-type/priority SLA rule',
        ];
    }

    $specificity_labels = [
        0 => 'default request type and default priority',
        1 => 'default request type and exact priority',
        2 => 'exact request type and default priority',
        3 => 'exact request type and exact priority',
    ];
    $specificity = intval($rule['_agreement_specificity']);
    $rule_classification = agreementRuleClassification($rule);
    $stored_rule_behavior = agreementRuleOperationalBehavior($rule);
    $selected_rule_sla = agreementRuleSlaSnapshot($rule);
    if (!$stored_rule_behavior['sla_eligible'] && intval($selected_rule_sla['sla_id']) !== 0) {
        throw new RuntimeException('An excluded agreement rule cannot carry an SLA target');
    }
    if (!$ticket) {
        throw new RuntimeException('Ticket context is required to enforce published agreement entitlements');
    }

    $entitlements = [];
    $entitlement_sql = agreementDbQuery("SELECT * FROM agreement_entitlements
        WHERE agreement_entitlement_version_id = $version_id
        ORDER BY agreement_entitlement_scope_type, agreement_entitlement_scope_id,
            agreement_entitlement_scope_key, agreement_entitlement_classification,
            agreement_entitlement_id" . ($for_update ? ' FOR UPDATE' : ''),
        'Could not resolve published agreement entitlements');
    while ($row = mysqli_fetch_assoc($entitlement_sql)) {
        $entitlements[] = $row;
    }
    if (!$entitlements) {
        throw new RuntimeException('Published agreement has no entitlement definition');
    }
    $entitlement_context = agreementTicketEntitlementContext(
        $client_id,
        $ticket,
        $request_type_key,
        $selected_rule_sla,
        $for_update
    );
    $applicability = agreementResolveEntitlementApplicability(
        $entitlements,
        $entitlement_context,
        $rule_classification['classification']
    );
    $classification = (string) $applicability['resolved_classification'];
    $behavior = $applicability['behavior'];
    $sla_snapshot = $behavior['sla_eligible']
        ? $selected_rule_sla
        : (function_exists('slaSnapshotFromRecord')
            ? slaSnapshotFromRecord(['sla_id' => 0])
            : [
                'sla_id' => 0,
                'sla_name' => 'None',
                'response_minutes' => null,
                'resolution_minutes' => null,
                'calendar_mode' => 'none',
                'business_days' => [],
                'business_hours_start' => null,
                'business_hours_end' => null,
                'timezone' => 'UTC',
            ]);
    $entitlement_snapshot = [
        'schema_version' => 1,
        'applicable' => true,
        'context' => $entitlement_context,
        'resolution' => $applicability,
        'selected_rule_sla' => $selected_rule_sla,
    ];

    return [
        'matched' => true,
        'client_id' => $client_id,
        'contract_id' => intval($version['agreement_version_contract_id']),
        'version_id' => $version_id,
        'rule_id' => intval($rule['agreement_sla_rule_id']),
        'request_type_key' => $request_type_key,
        'priority' => $priority,
        'sla_id' => intval($sla_snapshot['sla_id']),
        'sla_snapshot' => $sla_snapshot,
        'classification' => $classification,
        'classification_basis' => 'explicit_rule_and_entitlements',
        'behavior_version' => $behavior['behavior_version'],
        'sla_eligible' => $behavior['sla_eligible'],
        'ticket_onsite' => $behavior['ticket_onsite'],
        'ticket_billable' => $behavior['ticket_billable'],
        'entitlement_snapshot' => $entitlement_snapshot,
        'source' => 'agreement_rule',
        'reason' => 'Selected published agreement ' . $version['contract_name']
            . ' (contract ' . intval($version['agreement_version_contract_id'])
            . ', v' . intval($version['agreement_version_number']) . ') using '
            . $specificity_labels[$specificity]
            . '; rule classification ' . $rule_classification['classification']
            . ' resolved through published entitlements to ' . $classification
            . ' applies behavior matrix v' . $behavior['behavior_version']
            . ' (SLA ' . ($behavior['sla_eligible'] ? 'eligible' : 'ineligible')
            . ', onsite ' . $behavior['ticket_onsite'] . ', billable ' . $behavior['ticket_billable']
            . '; hour scope uses the ticket creation instant and immutable SLA calendar, while support-hours text remains descriptive)',
    ];
}

function agreementTicketDecisionBaseRecord(int $ticket_id, array $decision): array
{
    $snapshot = $decision['sla_snapshot'] ?? [
        'sla_id' => intval($decision['sla_id'] ?? 0),
        'sla_name' => (string) ($decision['sla_name'] ?? 'None'),
        'response_minutes' => $decision['response_minutes'] ?? null,
        'resolution_minutes' => $decision['resolution_minutes'] ?? null,
        'calendar_mode' => (string) ($decision['calendar_mode'] ?? 'none'),
        'business_days' => $decision['business_days'] ?? '',
        'business_hours_start' => $decision['business_hours_start'] ?? null,
        'business_hours_end' => $decision['business_hours_end'] ?? null,
        'timezone' => (string) ($decision['timezone'] ?? 'UTC'),
    ];
    if (function_exists('slaNormalizeCalendarSnapshot')) {
        $calendar = slaNormalizeCalendarSnapshot($snapshot);
    } else {
        $calendar = $snapshot;
        if (!is_array($calendar['business_days'])) {
            $calendar['business_days'] = array_values(array_filter(explode(',', (string) $calendar['business_days'])));
        }
    }

    return [
        'ticket_id' => intval($ticket_id),
        'client_id' => intval($decision['client_id'] ?? 0),
        'contract_id' => intval($decision['contract_id'] ?? 0),
        'version_id' => intval($decision['version_id'] ?? 0),
        'rule_id' => intval($decision['rule_id'] ?? 0),
        'request_type_key' => agreementNormalizeRequestTypeKey($decision['request_type_key'] ?? '*'),
        'priority' => agreementNormalizePriority($decision['priority'] ?? '*'),
        'sla_id' => intval($snapshot['sla_id'] ?? $decision['sla_id'] ?? 0),
        'sla_name' => substr((string) ($snapshot['sla_name'] ?? 'None'), 0, 200),
        'response_minutes' => is_null($snapshot['response_minutes'] ?? null)
            ? null : intval($snapshot['response_minutes']),
        'resolution_minutes' => is_null($snapshot['resolution_minutes'] ?? null)
            ? null : intval($snapshot['resolution_minutes']),
        'calendar_mode' => (string) ($calendar['calendar_mode'] ?? 'none'),
        'business_days' => implode(',', $calendar['business_days'] ?? []),
        'business_hours_start' => $calendar['business_hours_start'] ?? null,
        'business_hours_end' => $calendar['business_hours_end'] ?? null,
        'timezone' => substr((string) ($calendar['timezone'] ?? 'UTC'), 0, 64),
        'classification' => is_null($decision['classification'] ?? null)
            ? null : (string) $decision['classification'],
        'classification_basis' => is_null($decision['classification_basis'] ?? null)
            ? null : (string) $decision['classification_basis'],
        'source' => substr((string) ($decision['source'] ?? 'legacy_assignment'), 0, 30),
        'reason' => substr((string) ($decision['reason'] ?? 'SLA selection recorded'), 0, 500),
    ];
}

function agreementTicketDecisionRecord(int $ticket_id, array $decision): array
{
    $record = agreementTicketDecisionBaseRecord($ticket_id, $decision);
    $classification = $record['classification'];
    $behavior_version = intval($decision['behavior_version'] ?? ($classification ? 1 : 0));
    if ($classification) {
        $behavior = agreementClassificationBehavior($classification, $behavior_version);
        foreach (['sla_eligible', 'ticket_onsite', 'ticket_billable'] as $key) {
            if (isset($decision[$key]) && intval($decision[$key]) !== $behavior[$key]) {
                throw new RuntimeException('Ticket agreement decision behavior conflicts with its classification matrix');
            }
        }
    } else {
        $behavior = [
            'behavior_version' => 0,
            'sla_eligible' => intval($decision['sla_eligible'] ?? intval($record['sla_id']) > 0),
            'ticket_onsite' => intval($decision['ticket_onsite'] ?? 0),
            'ticket_billable' => intval($decision['ticket_billable'] ?? 0),
        ];
    }
    if (!$behavior['sla_eligible'] && intval($record['sla_id']) > 0) {
        throw new RuntimeException('An SLA-ineligible ticket decision cannot carry an SLA target');
    }

    $entitlement_snapshot = $decision['entitlement_snapshot'] ?? [
        'schema_version' => 1,
        'applicable' => false,
        'basis' => $record['source'],
    ];
    if (is_string($entitlement_snapshot)) {
        $entitlement_snapshot = json_decode($entitlement_snapshot, true, 512, JSON_THROW_ON_ERROR);
    }
    if (!is_array($entitlement_snapshot)) {
        throw new RuntimeException('Ticket agreement entitlement evidence is invalid');
    }

    return ['schema_version' => 1] + $record + [
        'behavior_version' => $behavior['behavior_version'],
        'sla_eligible' => $behavior['sla_eligible'],
        'ticket_onsite' => $behavior['ticket_onsite'],
        'ticket_billable' => $behavior['ticket_billable'],
        'entitlement_snapshot' => agreementCanonicalize($entitlement_snapshot),
    ];
}

function agreementVerifyTicketDecision(array $decision_row): bool
{
    try {
        $ticket_id = intval($decision_row['ticket_agreement_decision_ticket_id'] ?? 0);
        $decision = [
            'client_id' => $decision_row['ticket_agreement_decision_client_id'] ?? 0,
            'contract_id' => $decision_row['ticket_agreement_decision_contract_id'] ?? 0,
            'version_id' => $decision_row['ticket_agreement_decision_version_id'] ?? 0,
            'rule_id' => $decision_row['ticket_agreement_decision_rule_id'] ?? 0,
            'request_type_key' => $decision_row['ticket_agreement_decision_request_type_key'] ?? '*',
            'priority' => $decision_row['ticket_agreement_decision_priority'] ?? '*',
            'sla_snapshot' => [
                'sla_id' => $decision_row['ticket_agreement_decision_sla_id'] ?? 0,
                'sla_name' => $decision_row['ticket_agreement_decision_sla_name'] ?? 'None',
                'response_minutes' => $decision_row['ticket_agreement_decision_response_minutes'] ?? null,
                'resolution_minutes' => $decision_row['ticket_agreement_decision_resolution_minutes'] ?? null,
                'calendar_mode' => $decision_row['ticket_agreement_decision_calendar_mode'] ?? 'none',
                'business_days' => $decision_row['ticket_agreement_decision_business_days'] ?? '',
                'business_hours_start' => $decision_row['ticket_agreement_decision_business_hours_start'] ?? null,
                'business_hours_end' => $decision_row['ticket_agreement_decision_business_hours_end'] ?? null,
                'timezone' => $decision_row['ticket_agreement_decision_timezone'] ?? 'UTC',
            ],
            'classification' => $decision_row['ticket_agreement_decision_classification'] ?? null,
            'classification_basis' => $decision_row['ticket_agreement_decision_classification_basis'] ?? null,
            'behavior_version' => $decision_row['ticket_agreement_decision_behavior_version'] ?? 0,
            'sla_eligible' => $decision_row['ticket_agreement_decision_sla_eligible'] ?? 0,
            'ticket_onsite' => $decision_row['ticket_agreement_decision_ticket_onsite'] ?? 0,
            'ticket_billable' => $decision_row['ticket_agreement_decision_ticket_billable'] ?? 0,
            'entitlement_snapshot' => $decision_row['ticket_agreement_decision_entitlement_snapshot'] ?? '',
            'source' => $decision_row['ticket_agreement_decision_source'] ?? '',
            'reason' => $decision_row['ticket_agreement_decision_reason'] ?? '',
        ];
        $schema_version = intval($decision_row['ticket_agreement_decision_schema_version'] ?? 0);
        if ($schema_version === 0) {
            $record = agreementTicketDecisionBaseRecord($ticket_id, $decision);
        } elseif ($schema_version === 1) {
            $record = agreementTicketDecisionRecord($ticket_id, $decision);
        } else {
            return false;
        }
        $stored_hash = (string) ($decision_row['ticket_agreement_decision_hash'] ?? '');
        return $ticket_id > 0 && strlen($stored_hash) === 64
            && hash_equals($stored_hash, agreementDefinitionHash($record));
    } catch (Throwable $e) {
        return false;
    }
}

function agreementRecordTicketDecision(int $ticket_id, array $decision): int
{
    global $mysqli;

    $ticket_id = intval($ticket_id);
    if ($ticket_id <= 0) {
        throw new InvalidArgumentException('Ticket ID is required for an agreement decision');
    }

    // Client -> ticket is the shared evidence/deletion lock order. The caller
    // owns the SLA transaction and keeps both locks through the decision insert.
    $decision_client_id = intval($decision['client_id'] ?? 0);
    if ($decision_client_id > 0 && !agreementLockClientForAuditRetention($decision_client_id)) {
        throw new RuntimeException('Ticket client not found while recording its agreement decision');
    }

    $ticket_sql = agreementDbQuery("SELECT ticket_client_id FROM tickets
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1 FOR UPDATE", 'Could not validate the ticket agreement decision');
    if (!mysqli_num_rows($ticket_sql)) {
        throw new RuntimeException('Ticket not found while recording its agreement decision');
    }
    $ticket_client_id = intval(mysqli_fetch_assoc($ticket_sql)['ticket_client_id']);
    if ($decision_client_id !== $ticket_client_id) {
        throw new RuntimeException('Ticket agreement decision client does not match the ticket client');
    }

    $decision['client_id'] = $ticket_client_id;
    $record = agreementTicketDecisionRecord($ticket_id, $decision);
    if (!is_null($record['classification'])
        && (!isset(agreementClassifications()[$record['classification']])
            || !in_array($record['classification_basis'], [
                'explicit_rule', 'explicit_rule_and_entitlements',
            ], true))) {
        throw new RuntimeException('Ticket agreement decision has unsupported classification semantics');
    }
    if ($record['contract_id'] > 0 || $record['version_id'] > 0) {
        $context_sql = agreementDbQuery("SELECT agreement_versions.*, contracts.contract_client_id,
            contracts.contract_name, contracts.contract_status, contracts.contract_archived_at,
            contracts.contract_published_version_id
            FROM agreement_versions
            JOIN contracts ON contract_id = agreement_version_contract_id
            LEFT JOIN agreement_sla_rules ON agreement_sla_rule_id = {$record['rule_id']}
                AND agreement_sla_rule_version_id = agreement_version_id
            WHERE contract_id = {$record['contract_id']}
            AND agreement_version_id = {$record['version_id']}
            AND contract_client_id = $ticket_client_id
            AND ({$record['rule_id']} = 0 OR agreement_sla_rule_id IS NOT NULL) LIMIT 1",
            'Could not validate the agreement decision scope');
        if (!mysqli_num_rows($context_sql)) {
            throw new RuntimeException('Ticket agreement decision references another client or an invalid version');
        }
        $decision_version = mysqli_fetch_assoc($context_sql);
        if (!in_array($decision_version['agreement_version_status'], ['Published', 'Superseded'], true)) {
            throw new RuntimeException('Ticket agreement decision references an unpublished agreement version');
        }
        agreementAssertVersionIntegrity($decision_version);
    }
    $hash = agreementDefinitionHash($record);

    $request_type = mysqli_real_escape_string($mysqli, $record['request_type_key']);
    $priority = mysqli_real_escape_string($mysqli, $record['priority']);
    $sla_name = mysqli_real_escape_string($mysqli, $record['sla_name']);
    $response_minutes_sql = is_null($record['response_minutes']) ? 'NULL' : intval($record['response_minutes']);
    $resolution_minutes_sql = is_null($record['resolution_minutes']) ? 'NULL' : intval($record['resolution_minutes']);
    $calendar_mode = mysqli_real_escape_string($mysqli, $record['calendar_mode']);
    $business_days_sql = $record['business_days'] === ''
        ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $record['business_days']) . "'";
    $business_start_sql = is_null($record['business_hours_start'])
        ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $record['business_hours_start']) . "'";
    $business_end_sql = is_null($record['business_hours_end'])
        ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $record['business_hours_end']) . "'";
    $timezone = mysqli_real_escape_string($mysqli, $record['timezone']);
    $classification_sql = is_null($record['classification'])
        ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, (string) $record['classification']) . "'";
    $classification_basis_sql = is_null($record['classification_basis'])
        ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, (string) $record['classification_basis']) . "'";
    $behavior_version = intval($record['behavior_version']);
    $sla_eligible = intval($record['sla_eligible']);
    $ticket_onsite = intval($record['ticket_onsite']);
    $ticket_billable = intval($record['ticket_billable']);
    $entitlement_snapshot = mysqli_real_escape_string(
        $mysqli,
        agreementCanonicalJson($record['entitlement_snapshot'])
    );
    $source = mysqli_real_escape_string($mysqli, $record['source']);
    $reason = mysqli_real_escape_string($mysqli, $record['reason']);

    agreementDbQuery("INSERT INTO ticket_agreement_decisions SET
        ticket_agreement_decision_schema_version = 1,
        ticket_agreement_decision_ticket_id = $ticket_id,
        ticket_agreement_decision_client_id = {$record['client_id']},
        ticket_agreement_decision_contract_id = {$record['contract_id']},
        ticket_agreement_decision_version_id = {$record['version_id']},
        ticket_agreement_decision_rule_id = {$record['rule_id']},
        ticket_agreement_decision_request_type_key = '$request_type',
        ticket_agreement_decision_priority = '$priority',
        ticket_agreement_decision_sla_id = {$record['sla_id']},
        ticket_agreement_decision_sla_name = '$sla_name',
        ticket_agreement_decision_response_minutes = $response_minutes_sql,
        ticket_agreement_decision_resolution_minutes = $resolution_minutes_sql,
        ticket_agreement_decision_calendar_mode = '$calendar_mode',
        ticket_agreement_decision_business_days = $business_days_sql,
        ticket_agreement_decision_business_hours_start = $business_start_sql,
        ticket_agreement_decision_business_hours_end = $business_end_sql,
        ticket_agreement_decision_timezone = '$timezone',
        ticket_agreement_decision_classification = $classification_sql,
        ticket_agreement_decision_classification_basis = $classification_basis_sql,
        ticket_agreement_decision_behavior_version = $behavior_version,
        ticket_agreement_decision_sla_eligible = $sla_eligible,
        ticket_agreement_decision_ticket_onsite = $ticket_onsite,
        ticket_agreement_decision_ticket_billable = $ticket_billable,
        ticket_agreement_decision_entitlement_snapshot = '$entitlement_snapshot',
        ticket_agreement_decision_source = '$source',
        ticket_agreement_decision_reason = '$reason',
        ticket_agreement_decision_hash = '$hash'", 'Could not record the ticket agreement decision');
    return intval(mysqli_insert_id($mysqli));
}

function agreementValidateEntitlementScope(
    int $client_id,
    string $scope_type,
    int $scope_id,
    bool $for_update = false
): bool
{
    if ($scope_id === 0) {
        return true;
    }

    $maps = [
        'users' => ['contacts', 'contact_id', 'contact_client_id', 'contact_archived_at'],
        'devices' => ['assets', 'asset_id', 'asset_client_id', 'asset_archived_at'],
        'services' => ['services', 'service_id', 'service_client_id', null],
        'locations' => ['locations', 'location_id', 'location_client_id', 'location_archived_at'],
    ];
    if (!isset($maps[$scope_type])) {
        return false;
    }

    [$table, $id_column, $client_column, $archive_column] = $maps[$scope_type];
    $lock = $for_update ? ' FOR UPDATE' : '';
    $active = is_null($archive_column) ? '' : " AND `$archive_column` IS NULL";
    $row = agreementDbQuery("SELECT `$id_column` FROM `$table`
        WHERE `$id_column` = $scope_id AND `$client_column` = $client_id$active LIMIT 1$lock",
        'Could not validate agreement entitlement scope');
    return mysqli_num_rows($row) === 1;
}

function agreementEntitlementScopeLabel(
    int $client_id,
    string $scope_type,
    int $scope_id,
    bool $for_update = false
): ?string
{
    $maps = [
        'users' => ['contacts', 'contact_id', 'contact_client_id', 'contact_name'],
        'devices' => ['assets', 'asset_id', 'asset_client_id', 'asset_name'],
        'services' => ['services', 'service_id', 'service_client_id', 'service_name'],
        'locations' => ['locations', 'location_id', 'location_client_id', 'location_name'],
    ];
    if ($scope_id <= 0 || !isset($maps[$scope_type])) {
        return null;
    }

    [$table, $id_column, $client_column, $label_column] = $maps[$scope_type];
    $lock = $for_update ? ' FOR UPDATE' : '';
    $row = agreementDbQuery("SELECT `$label_column` FROM `$table`
        WHERE `$id_column` = $scope_id AND `$client_column` = $client_id LIMIT 1$lock",
        'Could not load the agreement entitlement scope label');
    if (!mysqli_num_rows($row)) {
        return null;
    }
    $record = mysqli_fetch_assoc($row);
    return (string) $record[$label_column];
}

/**
 * Move a date by whole calendar months while clamping to the target month's
 * last day. PHP's relative month parser rolls dates such as January 31 into
 * March; review schedules instead need a stable monthly boundary.
 */
function agreementShiftCalendarMonths(string $date, int $months): string
{
    $source = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$source || $source->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Agreement schedule date is invalid');
    }

    $month_start = $source->modify('first day of this month');
    $target_month = $month_start->modify(($months < 0 ? '' : '+') . $months . ' months');
    $source_day = intval($source->format('d'));
    $source_is_month_end = $source_day === intval($source->format('t'));
    $target_day = $source_is_month_end
        ? intval($target_month->format('t'))
        : min($source_day, intval($target_month->format('t')));
    return $target_month->setDate(
        intval($target_month->format('Y')),
        intval($target_month->format('m')),
        $target_day
    )->format('Y-m-d');
}

function agreementPublishVersion(int $version_id, int $actor_id, string $reason = ''): array
{
    global $mysqli;

    $actor_id = intval($actor_id);
    $reason = trim($reason);
    if ($actor_id <= 0 || $reason === '') {
        throw new RuntimeException('Agreement publication requires an approving technician and reason');
    }
    mysqli_begin_transaction($mysqli);
    try {
        $pre_version = agreementVersionContext($version_id);
        if (!$pre_version) {
            throw new RuntimeException('Agreement version not found');
        }
        $contract_id = intval($pre_version['agreement_version_contract_id']);
        $client_id = intval($pre_version['contract_client_id']);
        $locked_client = agreementLockClientForAuditRetention($client_id);
        if (!$locked_client || !empty($locked_client['client_archived_at'])) {
            throw new RuntimeException('An agreement must belong to an active client before publication');
        }
        agreementDbQuery("SELECT contract_id FROM contracts WHERE contract_id = $contract_id LIMIT 1 FOR UPDATE",
            'Could not lock the agreement for publication');
        $version = agreementVersionContext($version_id, true);
        if (!$version || intval($version['contract_client_id']) !== $client_id) {
            throw new RuntimeException('Agreement version not found');
        }
        if ($version['agreement_version_status'] !== 'Draft') {
            throw new RuntimeException('Only a draft agreement version can be published');
        }
        if (!empty($version['contract_archived_at'])) {
            throw new RuntimeException('An archived agreement cannot be published');
        }
        if (trim((string) $version['agreement_version_name']) === ''
            || trim((string) $version['agreement_version_type']) === '') {
            throw new RuntimeException('Agreement name and type are required before publication');
        }
        $definition_cadence = intval($version['agreement_version_review_cadence_months']);
        $renewal_notice = intval($version['agreement_version_renewal_notice_days']);
        if ($definition_cadence < 1 || $definition_cadence > 24
            || $renewal_notice < 0 || $renewal_notice > 365) {
            throw new RuntimeException('Agreement review cadence or renewal notice is outside the supported range');
        }
        if (!empty($version['agreement_version_effective_from'])
            && !empty($version['agreement_version_effective_until'])
            && $version['agreement_version_effective_until'] < $version['agreement_version_effective_from']) {
            throw new RuntimeException('The agreement effective-until date precedes its effective-from date');
        }
        $today = date('Y-m-d');
        if (!empty($version['agreement_version_effective_from'])
            && $version['agreement_version_effective_from'] > $today) {
            throw new RuntimeException('A future agreement version must remain a draft until its effective date');
        }
        if (!empty($version['agreement_version_effective_until'])
            && $version['agreement_version_effective_until'] < $today) {
            throw new RuntimeException('An expired agreement version cannot be activated');
        }

        $version_id = intval($version['agreement_version_id']);
        $contract_id = intval($version['agreement_version_contract_id']);
        $counts = mysqli_fetch_assoc(agreementDbQuery("SELECT
            (SELECT COUNT(*) FROM agreement_entitlements WHERE agreement_entitlement_version_id = $version_id) AS entitlements,
            (SELECT COUNT(*) FROM agreement_sla_rules WHERE agreement_sla_rule_version_id = $version_id) AS sla_rules",
            'Could not validate the agreement definition'));
        if (intval($counts['entitlements']) < 1) {
            throw new RuntimeException('Add at least one entitlement or exclusion before publishing');
        }
        if (intval($counts['sla_rules']) < 1) {
            throw new RuntimeException('Add at least one SLA rule (SLA may be None) before publishing');
        }

        $entitlement_rows = agreementDbQuery("SELECT agreement_entitlement_scope_type,
            agreement_entitlement_scope_id, agreement_entitlement_scope_key,
            agreement_entitlement_scope_label, agreement_entitlement_quantity_limit,
            agreement_entitlement_classification
            FROM agreement_entitlements WHERE agreement_entitlement_version_id = $version_id
            ORDER BY agreement_entitlement_id", 'Could not validate agreement entitlement scope');
        while ($entitlement = mysqli_fetch_assoc($entitlement_rows)) {
            $scope_type = (string) $entitlement['agreement_entitlement_scope_type'];
            $scope_id = intval($entitlement['agreement_entitlement_scope_id']);
            $scope_key = (string) $entitlement['agreement_entitlement_scope_key'];
            $scope_label = trim((string) $entitlement['agreement_entitlement_scope_label']);
            $quantity_limit = $entitlement['agreement_entitlement_quantity_limit'];
            $classification = (string) $entitlement['agreement_entitlement_classification'];
            if (!isset(agreementScopeTypes()[$scope_type])
                || !isset(agreementClassifications()[$classification])
                || $scope_key !== agreementNormalizeRequestTypeKey($scope_key)
                || ($scope_id > 0 && $scope_key !== '*')
                || ($scope_id === 0 && !in_array($scope_type, ['services', 'hours'], true)
                    && $scope_key !== '*')
                || ($scope_type === 'hours' && !in_array(
                    $scope_key,
                    ['*', 'all-hours', '24x7', 'business-hours', 'after-hours'],
                    true
                ))
                || $scope_label === ''
                || (!is_null($quantity_limit) && floatval($quantity_limit) < 0)
                || !agreementValidateEntitlementScope($client_id, $scope_type, $scope_id, true)) {
                throw new RuntimeException('Replace agreement entitlements with invalid or cross-client scope before publishing');
            }
        }

        $rule_rows = agreementDbQuery("SELECT agreement_sla_rules.* FROM agreement_sla_rules
            WHERE agreement_sla_rule_version_id = $version_id
            ORDER BY agreement_sla_rule_id", 'Could not validate agreement SLA rule values');
        while ($rule = mysqli_fetch_assoc($rule_rows)) {
            $request_key = (string) $rule['agreement_sla_rule_request_type_key'];
            $priority = (string) $rule['agreement_sla_rule_priority'];
            $classification = (string) $rule['agreement_sla_rule_classification'];
            $classification_basis = (string) $rule['agreement_sla_rule_classification_basis'];
            $snapshot = agreementRuleSlaSnapshot($rule);
            $behavior = agreementRuleOperationalBehavior($rule);
            if ($request_key !== agreementNormalizeRequestTypeKey($request_key)
                || ($priority !== '*' && !in_array($priority, ['Low', 'Medium', 'High', 'Urgent'], true))
                || intval($rule['agreement_sla_rule_sla_id']) < 0
                || !isset(agreementClassifications()[$classification])
                || $classification_basis !== 'explicit_rule'
                || ($snapshot['sla_id'] > 0 && (is_null($snapshot['response_minutes'])
                    || $snapshot['response_minutes'] < 0
                    || (!is_null($snapshot['resolution_minutes']) && $snapshot['resolution_minutes'] < 0)))
                || ($snapshot['sla_id'] === 0 && $snapshot['calendar_mode'] !== 'none')
                || (!$behavior['sla_eligible'] && $snapshot['sla_id'] > 0)) {
                throw new RuntimeException('Replace agreement SLA rules with non-canonical values before publishing');
            }
        }

        $invalid_slas = mysqli_fetch_row(agreementDbQuery("SELECT COUNT(*)
            FROM agreement_sla_rules LEFT JOIN slas ON sla_id = agreement_sla_rule_sla_id
            WHERE agreement_sla_rule_version_id = $version_id
            AND agreement_sla_rule_sla_id > 0
            AND (sla_id IS NULL OR sla_archived_at IS NOT NULL)",
            'Could not validate agreement SLA references'));
        if (intval($invalid_slas[0] ?? 0) > 0) {
            throw new RuntimeException('Replace agreement rules that reference archived or unavailable SLAs before publishing');
        }

        $definition = agreementGetVersionDefinition($version_id);
        $hash = agreementDefinitionHash($definition);
        $hash_sql = mysqli_real_escape_string($mysqli, $hash);
        $reason_sql = mysqli_real_escape_string($mysqli, substr($reason, 0, 255));

        // The pointer and status set are one invariant. Validate every current
        // published definition before changing either side so corruption is
        // surfaced rather than silently hidden by a new publication.
        $published_versions = [];
        $published_sql = agreementDbQuery("SELECT agreement_versions.*, contracts.contract_client_id,
            contracts.contract_name, contracts.contract_status, contracts.contract_archived_at,
            contracts.contract_published_version_id
            FROM agreement_versions JOIN contracts ON contract_id = agreement_version_contract_id
            WHERE agreement_version_contract_id = $contract_id
            AND agreement_version_status = 'Published'
            ORDER BY agreement_version_id FOR UPDATE", 'Could not validate current agreement publication');
        while ($published = mysqli_fetch_assoc($published_sql)) {
            agreementAssertVersionIntegrity($published);
            $published_versions[] = $published;
        }
        $published_pointer = intval($version['contract_published_version_id']);
        if (count($published_versions) > 1
            || ($published_pointer === 0 && count($published_versions) !== 0)
            || ($published_pointer > 0 && (count($published_versions) !== 1
                || intval($published_versions[0]['agreement_version_id']) !== $published_pointer))) {
            throw new RuntimeException('The agreement current-version pointer failed its integrity check');
        }

        // One explicit instant closes the prior publication interval and opens
        // the replacement. Separate NOW() calls can straddle a second boundary
        // and leave historical ticket/review resolution with a gap.
        $publication_at = mysqli_real_escape_string($mysqli, date('Y-m-d H:i:s'));
        agreementDbQuery("UPDATE agreement_versions SET agreement_version_status = 'Superseded',
            agreement_version_superseded_at = '$publication_at'
            WHERE agreement_version_contract_id = $contract_id
            AND agreement_version_status = 'Published'", 'Could not supersede the previous agreement version');
        foreach ($published_versions as $published) {
            $published_id = intval($published['agreement_version_id']);
            $published_hash = mysqli_real_escape_string(
                $mysqli,
                (string) $published['agreement_version_definition_hash']
            );
            $superseded_reason = mysqli_real_escape_string(
                $mysqli,
                "Superseded by agreement version $version_id"
            );
            agreementDbQuery("INSERT INTO agreement_version_events SET
                agreement_version_event_contract_id = $contract_id,
                agreement_version_event_version_id = $published_id,
                agreement_version_event_action = 'Superseded',
                agreement_version_event_actor_id = $actor_id,
                agreement_version_event_reason = '$superseded_reason',
                agreement_version_event_definition_hash = '$published_hash',
                agreement_version_event_created_at = '$publication_at'",
                'Could not record agreement supersession');
        }
        agreementDbQuery("UPDATE agreement_versions SET
            agreement_version_status = 'Published',
            agreement_version_definition_hash = '$hash_sql',
            agreement_version_published_by = $actor_id,
            agreement_version_published_at = '$publication_at',
            agreement_version_superseded_at = NULL
            WHERE agreement_version_id = $version_id AND agreement_version_status = 'Draft'",
            'Could not publish the agreement version');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The agreement draft changed while it was being published');
        }

        $name = mysqli_real_escape_string($mysqli, $version['agreement_version_name']);
        $type = mysqli_real_escape_string($mysqli, $version['agreement_version_type']);
        $support_hours = mysqli_real_escape_string($mysqli, (string) $version['agreement_version_support_hours']);
        $start_sql = empty($version['agreement_version_effective_from'])
            ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $version['agreement_version_effective_from']) . "'";
        $end_sql = empty($version['agreement_version_effective_until'])
            ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $version['agreement_version_effective_until']) . "'";
        $cadence = $definition_cadence;
        $review_anchor = $today;
        $next_review = agreementShiftCalendarMonths($review_anchor, $cadence);

        agreementDbQuery("UPDATE contracts SET
            contract_name = '$name', contract_type = '$type', contract_status = 'Active',
            contract_support_hours = '$support_hours', contract_start_date = $start_sql,
            contract_end_date = $end_sql, contract_published_version_id = $version_id,
            contract_review_cadence_months = $cadence,
            contract_next_review_at = '$next_review'
            WHERE contract_id = $contract_id AND contract_client_id = $client_id
            AND contract_archived_at IS NULL", 'Could not activate the published agreement');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The agreement changed while its publication pointer was being activated');
        }
        agreementDbQuery("INSERT INTO agreement_version_events SET
            agreement_version_event_contract_id = $contract_id,
            agreement_version_event_version_id = $version_id,
            agreement_version_event_action = 'Published',
            agreement_version_event_actor_id = $actor_id,
            agreement_version_event_reason = '$reason_sql',
            agreement_version_event_definition_hash = '$hash_sql',
            agreement_version_event_created_at = '$publication_at'",
            'Could not record agreement publication');

        mysqli_commit($mysqli);
        return ['contract_id' => $contract_id, 'version_id' => $version_id, 'definition_hash' => $hash];
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function agreementCreateDraftFromPublished(int $contract_id, int $actor_id): int
{
    global $mysqli;

    $contract_id = intval($contract_id);
    $actor_id = intval($actor_id);
    $pre_contract_sql = agreementDbQuery("SELECT contract_client_id FROM contracts
        WHERE contract_id = $contract_id LIMIT 1", 'Could not locate agreement client');
    if (!mysqli_num_rows($pre_contract_sql)) {
        throw new RuntimeException('Agreement not found');
    }
    $client_id = intval(mysqli_fetch_assoc($pre_contract_sql)['contract_client_id']);
    mysqli_begin_transaction($mysqli);
    try {
        if (!agreementLockClientForAuditRetention($client_id)) {
            throw new RuntimeException('The agreement client no longer exists');
        }
        $contract_sql = agreementDbQuery("SELECT * FROM contracts WHERE contract_id = $contract_id
            AND contract_archived_at IS NULL LIMIT 1 FOR UPDATE",
            'Could not lock agreement');
        if (!mysqli_num_rows($contract_sql)) {
            throw new RuntimeException('Agreement not found');
        }
        $contract = mysqli_fetch_assoc($contract_sql);
        if (intval($contract['contract_client_id']) !== $client_id) {
            throw new RuntimeException('The agreement client changed; refresh and try again');
        }

        $draft_sql = agreementDbQuery("SELECT agreement_version_id FROM agreement_versions
            WHERE agreement_version_contract_id = $contract_id AND agreement_version_status = 'Draft'
            ORDER BY agreement_version_number DESC LIMIT 1", 'Could not inspect agreement drafts');
        if (mysqli_num_rows($draft_sql)) {
            $draft_id = intval(mysqli_fetch_assoc($draft_sql)['agreement_version_id']);
            mysqli_commit($mysqli);
            return $draft_id;
        }

        $source_id = intval($contract['contract_published_version_id']);
        if ($source_id > 0) {
            $source = agreementVersionContext($source_id, true);
            if (!$source
                || intval($source['agreement_version_contract_id']) !== $contract_id
                || $source['agreement_version_status'] !== 'Published') {
                throw new RuntimeException('The agreement current-version pointer failed its integrity check');
            }
            agreementAssertVersionIntegrity($source);
        } else {
            $source = null;
        }
        $number_row = mysqli_fetch_row(agreementDbQuery("SELECT COALESCE(MAX(agreement_version_number), 0) + 1
            FROM agreement_versions WHERE agreement_version_contract_id = $contract_id",
            'Could not allocate the agreement version number'));
        $version_number = intval($number_row[0] ?? 1);

        $name = mysqli_real_escape_string($mysqli, $source['agreement_version_name'] ?? $contract['contract_name']);
        $type = mysqli_real_escape_string($mysqli, $source['agreement_version_type'] ?? $contract['contract_type']);
        $support_hours = mysqli_real_escape_string($mysqli, (string) ($source['agreement_version_support_hours'] ?? $contract['contract_support_hours']));
        $details = mysqli_real_escape_string($mysqli, (string) ($source['agreement_version_details'] ?? $contract['contract_details']));
        $from = $source['agreement_version_effective_from'] ?? $contract['contract_start_date'];
        $until = $source['agreement_version_effective_until'] ?? $contract['contract_end_date'];
        $from_sql = empty($from) ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $from) . "'";
        $until_sql = empty($until) ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $until) . "'";
        $cadence = max(1, intval($source['agreement_version_review_cadence_months'] ?? $contract['contract_review_cadence_months'] ?? 3));
        $notice = max(0, intval($source['agreement_version_renewal_notice_days'] ?? 90));

        agreementDbQuery("INSERT INTO agreement_versions SET
            agreement_version_contract_id = $contract_id,
            agreement_version_number = $version_number,
            agreement_version_name = '$name', agreement_version_type = '$type',
            agreement_version_effective_from = $from_sql,
            agreement_version_effective_until = $until_sql,
            agreement_version_support_hours = '$support_hours',
            agreement_version_review_cadence_months = $cadence,
            agreement_version_renewal_notice_days = $notice,
            agreement_version_details = '$details',
            agreement_version_created_by = $actor_id", 'Could not create an agreement draft');
        $draft_id = intval(mysqli_insert_id($mysqli));

        if ($source_id > 0) {
            agreementDbQuery("INSERT INTO agreement_entitlements
                (agreement_entitlement_version_id, agreement_entitlement_scope_type,
                 agreement_entitlement_scope_id, agreement_entitlement_scope_key,
                 agreement_entitlement_scope_label, agreement_entitlement_quantity_limit,
                 agreement_entitlement_classification, agreement_entitlement_notes)
                SELECT $draft_id, agreement_entitlement_scope_type,
                    agreement_entitlement_scope_id, agreement_entitlement_scope_key,
                    agreement_entitlement_scope_label, agreement_entitlement_quantity_limit,
                    agreement_entitlement_classification, agreement_entitlement_notes
                FROM agreement_entitlements WHERE agreement_entitlement_version_id = $source_id",
                'Could not clone agreement entitlements');
            agreementDbQuery("INSERT INTO agreement_sla_rules
                (agreement_sla_rule_version_id, agreement_sla_rule_request_type_key,
                 agreement_sla_rule_priority, agreement_sla_rule_sla_id,
                 agreement_sla_rule_sla_name, agreement_sla_rule_response_minutes,
                 agreement_sla_rule_resolution_minutes, agreement_sla_rule_calendar_mode,
                 agreement_sla_rule_business_days, agreement_sla_rule_business_hours_start,
                 agreement_sla_rule_business_hours_end, agreement_sla_rule_timezone,
                 agreement_sla_rule_classification, agreement_sla_rule_classification_basis,
                 agreement_sla_rule_behavior_version, agreement_sla_rule_sla_eligible,
                 agreement_sla_rule_ticket_onsite, agreement_sla_rule_ticket_billable,
                 agreement_sla_rule_order)
                SELECT $draft_id, agreement_sla_rule_request_type_key,
                    agreement_sla_rule_priority, agreement_sla_rule_sla_id,
                    agreement_sla_rule_sla_name, agreement_sla_rule_response_minutes,
                    agreement_sla_rule_resolution_minutes, agreement_sla_rule_calendar_mode,
                    agreement_sla_rule_business_days, agreement_sla_rule_business_hours_start,
                    agreement_sla_rule_business_hours_end, agreement_sla_rule_timezone,
                    agreement_sla_rule_classification, agreement_sla_rule_classification_basis,
                    agreement_sla_rule_behavior_version, agreement_sla_rule_sla_eligible,
                    agreement_sla_rule_ticket_onsite, agreement_sla_rule_ticket_billable,
                    agreement_sla_rule_order
                FROM agreement_sla_rules WHERE agreement_sla_rule_version_id = $source_id",
                'Could not clone agreement SLA rules');
        }

        $draft_reason = $source_id > 0
            ? 'Drafted from the current published version'
            : 'Initial version drafted from the legacy contract';
        agreementDbQuery("INSERT INTO agreement_version_events SET
            agreement_version_event_contract_id = $contract_id,
            agreement_version_event_version_id = $draft_id,
            agreement_version_event_action = 'Drafted',
            agreement_version_event_actor_id = $actor_id,
            agreement_version_event_reason = '$draft_reason'",
            'Could not record agreement draft creation');
        mysqli_commit($mysqli);
        return $draft_id;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function agreementPercent(?int $numerator, ?int $denominator): ?float
{
    if (is_null($denominator) || $denominator <= 0) {
        return null;
    }
    return round((intval($numerator) / $denominator) * 100, 1);
}

function agreementBuildRecommendations(array $snapshot): array
{
    $recommendations = [];
    $tickets = $snapshot['tickets'] ?? [];
    $coverage = $snapshot['coverage'] ?? [];
    $backup = $snapshot['backup'] ?? [];
    $documentation = $snapshot['documentation'] ?? [];
    $renewals = $snapshot['renewals'] ?? [];

    if (($tickets['response_compliance_percent'] ?? 100) < 95) {
        $recommendations[] = 'Review response-SLA misses and agree a corrective action for the next review period.';
    }
    if (($tickets['resolution_compliance_percent'] ?? 100) < 90) {
        $recommendations[] = 'Analyze resolution-SLA misses for staffing, escalation, or scope changes.';
    }
    if (intval($tickets['recurring_issue_groups'] ?? 0) > 0) {
        $recommendations[] = 'Assign root-cause work for the recurring issue groups listed in this review.';
    }
    if (($coverage['endpoint_coverage_percent'] ?? 100) < 100) {
        $recommendations[] = 'Reconcile active devices that are not mapped to endpoint management.';
    }
    if (($coverage['security_coverage_percent'] ?? 100) < 100) {
        $recommendations[] = 'Validate endpoint-protection coverage for every active device.';
    }
    if (intval($backup['open_incidents'] ?? 0) > 0) {
        $recommendations[] = 'Resolve open backup-health incidents and record a successful recovery check.';
    }
    if (($backup['in_scope'] ?? false) && !($backup['available'] ?? false)) {
        $recommendations[] = 'Connect or validate backup-health signals for the backup services recorded in scope.';
    }
    if (($documentation['available'] ?? false) && ($documentation['readiness_percent'] ?? 100) < 90) {
        $recommendations[] = 'Close the highest-impact documentation readiness gaps before the next review.';
    }
    if (!($documentation['available'] ?? false)) {
        $recommendations[] = 'Enable the documentation-readiness integration so future reviews can include verified obligation status.';
    }
    if (intval($renewals['within_notice_window'] ?? 0) > 0) {
        $recommendations[] = 'Confirm renewal decisions for items already inside their notice window.';
    }
    if (!$recommendations) {
        $recommendations[] = 'Maintain the current controls and re-evaluate at the next scheduled service review.';
    }

    return $recommendations;
}

function agreementReviewNonnegativeInteger($value, string $label): int
{
    if ((is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value)))
        && intval($value) >= 0) {
        return intval($value);
    }
    throw new RuntimeException("Service-review $label must be a non-negative integer");
}

function agreementReviewPercentValue($value, string $label, bool $nullable = false): ?float
{
    if (is_null($value) && $nullable) {
        return null;
    }
    if (!is_numeric($value) || !is_finite(floatval($value))
        || floatval($value) < 0 || floatval($value) > 100) {
        throw new RuntimeException("Service-review $label must be between 0 and 100");
    }
    return round(floatval($value), 2);
}

function agreementReviewTextValue($value, string $label, int $limit = 500): string
{
    if (!is_string($value) || trim($value) === '' || strlen($value) > $limit) {
        throw new RuntimeException("Service-review $label is invalid");
    }
    return trim($value);
}

function agreementNormalizeCoverageAdapter(array $adapter): array
{
    $active = agreementReviewNonnegativeInteger($adapter['active_devices'] ?? null, 'active-device count');
    $managed = agreementReviewNonnegativeInteger(
        $adapter['endpoint_managed_devices'] ?? null,
        'endpoint-managed count'
    );
    $security = agreementReviewNonnegativeInteger(
        $adapter['security_mapped_devices'] ?? null,
        'endpoint-security count'
    );
    if ($managed > $active || $security > $active) {
        throw new RuntimeException('Service-review endpoint coverage exceeds its active-device population');
    }
    $endpoint_percent = agreementReviewPercentValue(
        $adapter['endpoint_coverage_percent'] ?? null,
        'endpoint coverage'
    );
    $security_percent = agreementReviewPercentValue(
        $adapter['security_coverage_percent'] ?? null,
        'endpoint-security coverage'
    );
    $expected_endpoint = $active ? round(($managed / $active) * 100, 2) : 0.0;
    $expected_security = $active ? round(($security / $active) * 100, 2) : 0.0;
    if (abs($endpoint_percent - $expected_endpoint) > 0.01
        || abs($security_percent - $expected_security) > 0.01) {
        throw new RuntimeException('Service-review endpoint coverage percentages do not match their counts');
    }
    return [
        'active_devices' => $active,
        'endpoint_managed_devices' => $managed,
        'endpoint_coverage_percent' => $endpoint_percent,
        'security_mapped_devices' => $security,
        'security_coverage_percent' => $security_percent,
        'source' => agreementReviewTextValue($adapter['source'] ?? null, 'endpoint source', 200),
    ];
}

function agreementNormalizeDocumentationAdapter(array $adapter): array
{
    $normalized = [
        'available' => filter_var($adapter['available'] ?? null, FILTER_VALIDATE_BOOLEAN),
        'readiness_percent' => agreementReviewPercentValue(
            $adapter['readiness_percent'] ?? null,
            'documentation readiness'
        ),
        'source' => agreementReviewTextValue($adapter['source'] ?? null, 'documentation source', 200),
        'note' => agreementReviewTextValue($adapter['note'] ?? null, 'documentation note', 500),
    ];
    if (!$normalized['available']) {
        throw new RuntimeException('Documentation readiness adapter did not mark its evidence available');
    }
    foreach (['current', 'due_soon', 'stale', 'missing', 'exceptions'] as $metric) {
        $normalized[$metric] = agreementReviewNonnegativeInteger(
            $adapter[$metric] ?? null,
            "documentation $metric count"
        );
    }
    return $normalized;
}

function agreementServiceReviewSnapshot(int $client_id, string $period_start, string $period_end): array
{
    global $mysqli;

    $client_id = intval($client_id);
    $period_start_sql = mysqli_real_escape_string($mysqli, $period_start);
    $period_end_sql = mysqli_real_escape_string($mysqli, $period_end);
    $client_sql = agreementDbQuery("SELECT client_name FROM clients
        WHERE client_id = $client_id AND client_archived_at IS NULL LIMIT 1",
        'Could not load the review client');
    if (!mysqli_num_rows($client_sql)) {
        throw new RuntimeException('Client not found');
    }
    $client = mysqli_fetch_assoc($client_sql);

    $ticket = mysqli_fetch_assoc(agreementDbQuery("SELECT
        COUNT(*) AS total,
        SUM(ticket_resolved_at IS NOT NULL OR ticket_closed_at IS NOT NULL) AS resolved,
        SUM(ticket_resolved_at IS NULL AND ticket_closed_at IS NULL) AS open,
        SUM(ticket_recurring_ticket_id > 0) AS recurring,
        SUM(ticket_response_sla_met = 1) AS response_met,
        SUM(ticket_response_sla_met = 0) AS response_missed,
        SUM(ticket_resolution_sla_met = 1) AS resolution_met,
        SUM(ticket_resolution_sla_met = 0) AS resolution_missed
        FROM tickets WHERE ticket_client_id = $client_id AND ticket_deleted_at IS NULL
        AND DATE(ticket_created_at) BETWEEN '$period_start_sql' AND '$period_end_sql'",
        'Could not calculate ticket review metrics'));

    $recurring_issues = [];
    $recurring_sql = agreementDbQuery("SELECT ticket_subject, COUNT(*) AS occurrences
        FROM tickets WHERE ticket_client_id = $client_id AND ticket_deleted_at IS NULL
        AND DATE(ticket_created_at) BETWEEN '$period_start_sql' AND '$period_end_sql'
        GROUP BY ticket_subject HAVING COUNT(*) > 1
        ORDER BY occurrences DESC, ticket_subject ASC LIMIT 10", 'Could not calculate recurring issues');
    while ($row = mysqli_fetch_assoc($recurring_sql)) {
        $recurring_issues[] = [
            'subject' => (string) $row['ticket_subject'],
            'occurrences' => intval($row['occurrences']),
        ];
    }

    $active_assets = intval(mysqli_fetch_row(agreementDbQuery("SELECT COUNT(*) FROM assets
        WHERE asset_client_id = $client_id AND asset_archived_at IS NULL",
        'Could not count active client devices'))[0] ?? 0);
    $level_mapped = intval(mysqli_fetch_row(agreementDbQuery("SELECT COUNT(DISTINCT level_asset_id)
        FROM level_asset_links JOIN assets ON asset_id = level_asset_id
        WHERE asset_client_id = $client_id AND asset_archived_at IS NULL
        AND level_device_deleted_at IS NULL", 'Could not calculate endpoint coverage'))[0] ?? 0);
    $security_mapped = intval(mysqli_fetch_row(agreementDbQuery("SELECT COUNT(DISTINCT automation_mapping_asset_id)
        FROM automation_entity_mappings JOIN assets ON asset_id = automation_mapping_asset_id
        WHERE automation_mapping_client_id = $client_id
        AND automation_mapping_source = 'sentinelone'
        AND automation_mapping_state IN ('automatic', 'confirmed')
        AND automation_mapping_deleted_at IS NULL
        AND asset_archived_at IS NULL", 'Could not calculate security coverage'))[0] ?? 0);

    $coverage = [
        'available' => true,
        'active_devices' => $active_assets,
        'endpoint_managed_devices' => $level_mapped,
        'endpoint_coverage_percent' => agreementPercent($level_mapped, $active_assets),
        'security_mapped_devices' => $security_mapped,
        'security_coverage_percent' => agreementPercent($security_mapped, $active_assets),
        'source' => 'level_asset_links + source-neutral automation mappings',
    ];
    if (function_exists('unifiedDeviceServiceReviewSnapshot')) {
        try {
            $adapter_coverage = unifiedDeviceServiceReviewSnapshot($client_id);
            if (is_array($adapter_coverage)) {
                $coverage = agreementNormalizeCoverageAdapter($adapter_coverage) + ['available' => true];
            }
        } catch (Throwable $e) {
            error_log("Unified endpoint service-review adapter failed for client $client_id: " . $e->getMessage());
        }
    }

    $backup = mysqli_fetch_assoc(agreementDbQuery("SELECT
        COUNT(*) AS incidents,
        SUM(automation_incident_status <> 'Resolved') AS open_incidents,
        COALESCE(SUM(automation_incident_repeat_count), 0) AS repeat_events,
        MAX(automation_incident_last_event_at) AS last_signal_at
        FROM automation_incidents WHERE automation_incident_client_id = $client_id
        AND automation_incident_source = 'backup'
        AND DATE(COALESCE(automation_incident_last_event_at, automation_incident_created_at))
            BETWEEN '$period_start_sql' AND '$period_end_sql'", 'Could not calculate backup-health metrics'));
    $backup_services = intval(mysqli_fetch_row(agreementDbQuery("SELECT COUNT(*) FROM services
        WHERE service_client_id = $client_id
        AND (LOCATE('backup', LOWER(COALESCE(service_name, ''))) > 0
            OR LOCATE('backup', LOWER(COALESCE(service_category, ''))) > 0
            OR (service_backup IS NOT NULL AND service_backup <> ''))",
        'Could not calculate backup services in scope'))[0] ?? 0);

    $document = mysqli_fetch_assoc(agreementDbQuery("SELECT COUNT(*) AS total,
        SUM(COALESCE(document_updated_at, document_created_at) >= DATE_SUB('$period_end_sql', INTERVAL 90 DAY)) AS recently_updated,
        MAX(COALESCE(document_updated_at, document_created_at)) AS latest_at
        FROM documents WHERE document_client_id = $client_id AND document_archived_at IS NULL",
        'Could not calculate document metrics'));
    $documentation = [
        'available' => false,
        'source' => 'basic-document-inventory',
        'document_count' => intval($document['total']),
        'recently_updated' => intval($document['recently_updated']),
        'latest_at' => $document['latest_at'],
        'note' => 'Goal 4 readiness provider was not available when this snapshot was generated.',
    ];
    if (function_exists('documentationServiceReviewReadiness')) {
        try {
            $adapter_documentation = documentationServiceReviewReadiness($client_id);
            if (is_array($adapter_documentation)) {
                $documentation = agreementNormalizeDocumentationAdapter($adapter_documentation);
            }
        } catch (Throwable $e) {
            error_log("Documentation service-review adapter failed for client $client_id: " . $e->getMessage());
        }
    }

    $renewal_items = [];
    $review_period_end = DateTimeImmutable::createFromFormat('!Y-m-d', $period_end);
    if (!$review_period_end || $review_period_end->format('Y-m-d') !== $period_end) {
        throw new RuntimeException('Service-review period end is invalid');
    }
    $renewal_sql = agreementDbQuery("SELECT 'agreement' AS item_type, contract_name AS item_name,
        contract_end_date AS renewal_date,
        COALESCE(agreement_version_renewal_notice_days, 90) AS notice_days
        FROM contracts LEFT JOIN agreement_versions ON agreement_version_id = contract_published_version_id
        WHERE contract_client_id = $client_id AND contract_archived_at IS NULL
        AND contract_end_date BETWEEN '$period_end_sql' AND DATE_ADD('$period_end_sql', INTERVAL 365 DAY)
        UNION ALL
        SELECT 'domain', domain_name, domain_expire, 45 FROM domains
        WHERE domain_client_id = $client_id AND domain_archived_at IS NULL
        AND domain_expire BETWEEN '$period_end_sql' AND DATE_ADD('$period_end_sql', INTERVAL 365 DAY)
        UNION ALL
        SELECT 'software', software_name, software_expire, 45 FROM software
        WHERE software_client_id = $client_id AND software_archived_at IS NULL
        AND software_expire BETWEEN '$period_end_sql' AND DATE_ADD('$period_end_sql', INTERVAL 365 DAY)
        ORDER BY renewal_date, item_type, item_name", 'Could not calculate renewal metrics');
    $within_notice = 0;
    while ($row = mysqli_fetch_assoc($renewal_sql)) {
        $renewal_date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['renewal_date']);
        if (!$renewal_date || $renewal_date->format('Y-m-d') !== (string) $row['renewal_date']) {
            throw new RuntimeException('A service-review renewal date is invalid');
        }
        $days_until = intval($review_period_end->diff($renewal_date)->format('%r%a'));
        $inside = $days_until <= intval($row['notice_days']);
        if ($inside) {
            $within_notice++;
        }
        $renewal_items[] = [
            'type' => $row['item_type'],
            'name' => $row['item_name'],
            'date' => $row['renewal_date'],
            'notice_days' => intval($row['notice_days']),
            'within_notice_window' => $inside,
        ];
    }

    $response_judged = intval($ticket['response_met']) + intval($ticket['response_missed']);
    $resolution_judged = intval($ticket['resolution_met']) + intval($ticket['resolution_missed']);
    $snapshot = [
        'schema_version' => 1,
        'client' => ['id' => $client_id, 'name' => $client['client_name']],
        'period' => ['start' => $period_start, 'end' => $period_end],
        'tickets' => [
            'total' => intval($ticket['total']),
            'resolved' => intval($ticket['resolved']),
            'open' => intval($ticket['open']),
            'recurring' => intval($ticket['recurring']),
            'response_met' => intval($ticket['response_met']),
            'response_missed' => intval($ticket['response_missed']),
            'response_compliance_percent' => agreementPercent(intval($ticket['response_met']), $response_judged),
            'resolution_met' => intval($ticket['resolution_met']),
            'resolution_missed' => intval($ticket['resolution_missed']),
            'resolution_compliance_percent' => agreementPercent(intval($ticket['resolution_met']), $resolution_judged),
            'recurring_issue_groups' => count($recurring_issues),
            'recurring_issues' => $recurring_issues,
        ],
        'coverage' => $coverage,
        'backup' => [
            'available' => intval($backup['incidents']) > 0,
            'in_scope' => $backup_services > 0,
            'services_in_scope' => $backup_services,
            'incidents' => intval($backup['incidents']),
            'open_incidents' => intval($backup['open_incidents']),
            'repeat_events' => intval($backup['repeat_events']),
            'last_signal_at' => $backup['last_signal_at'],
            'source' => 'source-neutral automation incidents',
        ],
        'documentation' => $documentation,
        'renewals' => [
            'next_365_days' => count($renewal_items),
            'within_notice_window' => $within_notice,
            'items' => $renewal_items,
        ],
    ];
    $snapshot['recommendations'] = agreementBuildRecommendations($snapshot);

    return $snapshot;
}

function agreementGenerateServiceReview(
    int $client_id,
    string $period_start,
    string $period_end,
    int $actor_id = 0,
    int $contract_id = 0
): int {
    global $mysqli;

    $start_date = DateTimeImmutable::createFromFormat('!Y-m-d', $period_start);
    $end_date = DateTimeImmutable::createFromFormat('!Y-m-d', $period_end);
    if (!$start_date || $start_date->format('Y-m-d') !== $period_start
        || !$end_date || $end_date->format('Y-m-d') !== $period_end
        || $period_end < $period_start
        || $period_end > date('Y-m-d')) {
        throw new RuntimeException('The service-review period is invalid or extends into the future');
    }

    mysqli_begin_transaction($mysqli, MYSQLI_TRANS_START_READ_WRITE | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    try {
        $locked_client = agreementLockClientForAuditRetention($client_id);
        if (!$locked_client || !empty($locked_client['client_archived_at'])) {
            throw new RuntimeException('An active client is required to generate a service review');
        }
        $review_as_of = $period_end === date('Y-m-d')
            ? date('Y-m-d H:i:s') : $period_end . ' 23:59:59';
        $version = agreementGetVersionForClientAt(
            $client_id,
            $period_end,
            $review_as_of,
            intval($contract_id)
        );
        if (!$version) {
            throw new RuntimeException('A published or superseded agreement effective at the review-period boundary is required');
        }
        $contract_id = intval($version['agreement_version_contract_id']);
        $version_id = intval($version['agreement_version_id']);
        $snapshot = agreementServiceReviewSnapshot($client_id, $period_start, $period_end);
        $snapshot['agreement'] = [
            'contract_id' => $contract_id,
            'version_id' => $version_id,
            'version_number' => intval($version['agreement_version_number'] ?? 0),
            'name' => $version['agreement_version_name'] ?? null,
            'definition_hash' => $version['agreement_version_definition_hash'] ?? null,
            'published_at' => $version['agreement_version_published_at'] ?? null,
            'superseded_at' => $version['agreement_version_superseded_at'] ?? null,
            'resolution_as_of' => $review_as_of,
        ];
        $summary = sprintf(
            '%d tickets; response SLA %s; resolution SLA %s; %d recurring issue group(s).',
            intval($snapshot['tickets']['total']),
            is_null($snapshot['tickets']['response_compliance_percent']) ? 'not judged' : $snapshot['tickets']['response_compliance_percent'] . '%',
            is_null($snapshot['tickets']['resolution_compliance_percent']) ? 'not judged' : $snapshot['tickets']['resolution_compliance_percent'] . '%',
            intval($snapshot['tickets']['recurring_issue_groups'])
        );
        $snapshot['summary'] = $summary;
        $snapshot_json = agreementCanonicalJson($snapshot);
        $hash = hash('sha256', $snapshot_json);
        agreementValidateServiceReviewSnapshot([
            'service_review_client_id' => $client_id,
            'service_review_contract_id' => $contract_id,
            'service_review_agreement_version_id' => $version_id,
            'service_review_period_start' => $period_start,
            'service_review_period_end' => $period_end,
            'service_review_source_snapshot' => $snapshot_json,
            'service_review_summary' => $summary,
            'service_review_recommendations' => implode("\n", $snapshot['recommendations']),
            'service_review_snapshot_hash' => $hash,
        ]);
        $snapshot_sql = mysqli_real_escape_string($mysqli, $snapshot_json);
        $recommendations = implode("\n", $snapshot['recommendations']);
        $recommendations_sql = mysqli_real_escape_string($mysqli, $recommendations);
        $summary_sql = mysqli_real_escape_string($mysqli, $summary);
        $actor_id = intval($actor_id);

        $existing = agreementDbQuery("SELECT service_review_id FROM service_reviews
            WHERE service_review_client_id = $client_id
            AND service_review_period_start = '$period_start'
            AND service_review_period_end = '$period_end'
            AND service_review_snapshot_hash = '$hash' LIMIT 1 FOR UPDATE",
            'Could not inspect existing service reviews');
        if (mysqli_num_rows($existing)) {
            $review_id = intval(mysqli_fetch_assoc($existing)['service_review_id']);
            mysqli_commit($mysqli);
            return $review_id;
        }

        agreementDbQuery("INSERT INTO service_reviews SET
            service_review_client_id = $client_id,
            service_review_contract_id = $contract_id,
            service_review_agreement_version_id = $version_id,
            service_review_period_start = '$period_start',
            service_review_period_end = '$period_end',
            service_review_source_snapshot = '$snapshot_sql',
            service_review_summary = '$summary_sql',
            service_review_recommendations = '$recommendations_sql',
            service_review_snapshot_hash = '$hash',
            service_review_generated_by = $actor_id", 'Could not create the service review');
        $review_id = intval(mysqli_insert_id($mysqli));
        agreementDbQuery("INSERT INTO service_review_events SET
            service_review_event_review_id = $review_id,
            service_review_event_client_id = $client_id,
            service_review_event_action = 'Generated',
            service_review_event_actor_id = $actor_id,
            service_review_event_reason = 'Generated from a consistent source snapshot',
            service_review_event_snapshot_hash = '$hash'", 'Could not record service-review generation');
        mysqli_commit($mysqli);
        return $review_id;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

/**
 * Verify the immutable review payload and every database binding that makes it
 * tenant/period/agreement specific. Callers receive the decoded snapshot only
 * after all presentation fields and required evidence sections agree.
 */
function agreementValidateServiceReviewSnapshot(array $review): array
{
    $snapshot_json = (string) ($review['service_review_source_snapshot'] ?? '');
    $stored_hash = strtolower((string) ($review['service_review_snapshot_hash'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $stored_hash)
        || !hash_equals($stored_hash, hash('sha256', $snapshot_json))) {
        throw new RuntimeException('Service-review snapshot integrity check failed');
    }
    try {
        $snapshot = json_decode($snapshot_json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new RuntimeException('Service-review snapshot is not valid JSON');
    }
    if (!is_array($snapshot) || intval($snapshot['schema_version'] ?? 0) !== 1) {
        throw new RuntimeException('Service-review snapshot schema is unsupported');
    }

    $client_id = intval($review['service_review_client_id'] ?? 0);
    $contract_id = intval($review['service_review_contract_id'] ?? 0);
    $version_id = intval($review['service_review_agreement_version_id'] ?? 0);
    $period_start = (string) ($review['service_review_period_start'] ?? '');
    $period_end = (string) ($review['service_review_period_end'] ?? '');
    $agreement = $snapshot['agreement'] ?? [];
    $required_sections = [
        'client', 'period', 'agreement', 'tickets', 'coverage', 'backup',
        'documentation', 'renewals', 'recommendations',
    ];
    foreach ($required_sections as $section) {
        if (!array_key_exists($section, $snapshot) || !is_array($snapshot[$section])) {
            throw new RuntimeException("Service-review snapshot is missing $section evidence");
        }
    }
    if ($client_id <= 0 || $contract_id <= 0 || $version_id <= 0
        || intval($snapshot['client']['id'] ?? 0) !== $client_id
        || trim((string) ($snapshot['client']['name'] ?? '')) === ''
        || (string) ($snapshot['period']['start'] ?? '') !== $period_start
        || (string) ($snapshot['period']['end'] ?? '') !== $period_end
        || intval($agreement['contract_id'] ?? 0) !== $contract_id
        || intval($agreement['version_id'] ?? 0) !== $version_id
        || intval($agreement['version_number'] ?? 0) <= 0
        || trim((string) ($agreement['name'] ?? '')) === ''
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($agreement['definition_hash'] ?? ''))) {
        throw new RuntimeException('Service-review snapshot database bindings do not match');
    }

    $tickets = $snapshot['tickets'];
    foreach ([
        'total', 'resolved', 'open', 'recurring', 'response_met', 'response_missed',
        'resolution_met', 'resolution_missed', 'recurring_issue_groups',
    ] as $metric) {
        agreementReviewNonnegativeInteger($tickets[$metric] ?? null, "ticket $metric");
    }
    agreementReviewPercentValue(
        $tickets['response_compliance_percent'] ?? null,
        'response-SLA compliance',
        true
    );
    agreementReviewPercentValue(
        $tickets['resolution_compliance_percent'] ?? null,
        'resolution-SLA compliance',
        true
    );
    if (!is_array($tickets['recurring_issues'] ?? null)
        || count($tickets['recurring_issues']) !== intval($tickets['recurring_issue_groups'])) {
        throw new RuntimeException('Service-review recurring-issue evidence is inconsistent');
    }
    foreach ($tickets['recurring_issues'] as $issue) {
        agreementReviewTextValue($issue['subject'] ?? null, 'recurring-issue subject', 500);
        agreementReviewNonnegativeInteger($issue['occurrences'] ?? null, 'recurring-issue occurrences');
    }

    $coverage = $snapshot['coverage'];
    $active_devices = agreementReviewNonnegativeInteger(
        $coverage['active_devices'] ?? null,
        'active-device count'
    );
    foreach (['endpoint_managed_devices', 'security_mapped_devices'] as $metric) {
        if (agreementReviewNonnegativeInteger($coverage[$metric] ?? null, $metric) > $active_devices) {
            throw new RuntimeException('Service-review device coverage exceeds its active-device population');
        }
    }
    agreementReviewPercentValue($coverage['endpoint_coverage_percent'] ?? null, 'endpoint coverage', true);
    agreementReviewPercentValue($coverage['security_coverage_percent'] ?? null, 'endpoint-security coverage', true);
    agreementReviewTextValue($coverage['source'] ?? null, 'coverage source', 200);

    foreach (['services_in_scope', 'incidents', 'open_incidents', 'repeat_events'] as $metric) {
        agreementReviewNonnegativeInteger($snapshot['backup'][$metric] ?? null, "backup $metric");
    }
    if (intval($snapshot['backup']['open_incidents']) > intval($snapshot['backup']['incidents'])) {
        throw new RuntimeException('Service-review open backup incidents exceed total incidents');
    }
    agreementReviewTextValue($snapshot['backup']['source'] ?? null, 'backup source', 200);
    $documentation = $snapshot['documentation'];
    agreementReviewTextValue($documentation['source'] ?? null, 'documentation source', 200);
    agreementReviewTextValue($documentation['note'] ?? null, 'documentation note', 500);
    if (!empty($documentation['available'])) {
        agreementReviewPercentValue(
            $documentation['readiness_percent'] ?? null,
            'documentation readiness'
        );
        foreach (['current', 'due_soon', 'stale', 'missing', 'exceptions'] as $metric) {
            agreementReviewNonnegativeInteger($documentation[$metric] ?? null, "documentation $metric");
        }
    } else {
        agreementReviewNonnegativeInteger($documentation['document_count'] ?? null, 'document count');
        agreementReviewNonnegativeInteger($documentation['recently_updated'] ?? null, 'recent document count');
    }
    agreementReviewNonnegativeInteger(
        $snapshot['renewals']['next_365_days'] ?? null,
        'renewal item count'
    );
    agreementReviewNonnegativeInteger(
        $snapshot['renewals']['within_notice_window'] ?? null,
        'renewal notice count'
    );
    if (!is_array($snapshot['renewals']['items'] ?? null)
        || count($snapshot['renewals']['items']) !== intval($snapshot['renewals']['next_365_days'])) {
        throw new RuntimeException('Service-review renewal evidence is inconsistent');
    }
    $notice_count = 0;
    foreach ($snapshot['renewals']['items'] as $item) {
        agreementReviewTextValue($item['type'] ?? null, 'renewal type', 30);
        agreementReviewTextValue($item['name'] ?? null, 'renewal name', 500);
        $renewal_date = (string) ($item['date'] ?? '');
        $renewal_instant = DateTimeImmutable::createFromFormat('!Y-m-d', $renewal_date);
        if (!$renewal_instant || $renewal_instant->format('Y-m-d') !== $renewal_date) {
            throw new RuntimeException('Service-review renewal date is invalid');
        }
        agreementReviewNonnegativeInteger($item['notice_days'] ?? null, 'renewal notice days');
        $notice_count += !empty($item['within_notice_window']) ? 1 : 0;
    }
    if ($notice_count !== intval($snapshot['renewals']['within_notice_window'])) {
        throw new RuntimeException('Service-review renewal notice evidence is inconsistent');
    }

    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $period_start);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $period_end);
    if (!$start || $start->format('Y-m-d') !== $period_start
        || !$end || $end->format('Y-m-d') !== $period_end || $end < $start) {
        throw new RuntimeException('Service-review snapshot period is invalid');
    }
    if (trim((string) ($snapshot['summary'] ?? '')) === ''
        || (string) $snapshot['summary'] !== (string) ($review['service_review_summary'] ?? '')
        || implode("\n", $snapshot['recommendations'])
            !== (string) ($review['service_review_recommendations'] ?? '')) {
        throw new RuntimeException('Service-review presentation fields do not match the immutable snapshot');
    }
    foreach ($snapshot['recommendations'] as $recommendation) {
        if (!is_string($recommendation) || trim($recommendation) === '') {
            throw new RuntimeException('Service-review recommendations are invalid');
        }
    }

    return $snapshot;
}

function agreementServiceReviewEvents(int $review_id, int $client_id): array
{
    $review_id = intval($review_id);
    $client_id = intval($client_id);
    if ($review_id <= 0 || $client_id <= 0) {
        return [];
    }
    $events = [];
    $sql = agreementDbQuery("SELECT service_review_events.*, user_name
        FROM service_review_events
        LEFT JOIN users ON user_id = service_review_event_actor_id
        WHERE service_review_event_review_id = $review_id
        AND service_review_event_client_id = $client_id
        ORDER BY service_review_event_id", 'Could not load service-review approval evidence');
    while ($event = mysqli_fetch_assoc($sql)) {
        $events[] = $event;
    }
    return $events;
}

function agreementValidateServiceReviewApproval(array $review, array $events): ?array
{
    $review_id = intval($review['service_review_id'] ?? 0);
    $client_id = intval($review['service_review_client_id'] ?? 0);
    $hash = (string) ($review['service_review_snapshot_hash'] ?? '');
    $generated = 0;
    $published = [];
    foreach ($events as $event) {
        if (intval($event['service_review_event_review_id'] ?? 0) !== $review_id
            || intval($event['service_review_event_client_id'] ?? 0) !== $client_id
            || !hash_equals($hash, (string) ($event['service_review_event_snapshot_hash'] ?? ''))) {
            throw new RuntimeException('Service-review event evidence does not match its review binding');
        }
        if (($event['service_review_event_action'] ?? '') === 'Generated') {
            $generated++;
        } elseif (($event['service_review_event_action'] ?? '') === 'Published') {
            $published[] = $event;
        }
    }
    if ($generated < 1) {
        throw new RuntimeException('Service-review generation evidence is missing');
    }

    $status = (string) ($review['service_review_status'] ?? '');
    if ($status === 'Draft') {
        if ($published || intval($review['service_review_published_by'] ?? 0) !== 0
            || !empty($review['service_review_published_at'])) {
            throw new RuntimeException('Draft service-review approval state is inconsistent');
        }
        return null;
    }
    if ($status !== 'Published' || count($published) !== 1) {
        throw new RuntimeException('Published service-review approval evidence is missing or ambiguous');
    }

    $approval = $published[0];
    if (intval($review['service_review_published_by'] ?? 0) <= 0
        || intval($approval['service_review_event_actor_id'] ?? 0)
            !== intval($review['service_review_published_by'])
        || (string) ($approval['service_review_event_created_at'] ?? '')
            !== (string) ($review['service_review_published_at'] ?? '')
        || trim((string) ($approval['service_review_event_reason'] ?? '')) === '') {
        throw new RuntimeException('Published service-review approval binding is inconsistent');
    }
    return $approval;
}

function agreementPublishServiceReview(int $review_id, int $actor_id, string $reason = ''): void
{
    global $mysqli;

    $review_id = intval($review_id);
    $actor_id = intval($actor_id);
    $reason = trim($reason);
    if ($review_id <= 0 || $actor_id <= 0 || $reason === '') {
        throw new RuntimeException('Service-review publication requires an approving technician and reason');
    }
    $pre_review_sql = agreementDbQuery("SELECT service_review_client_id FROM service_reviews
        WHERE service_review_id = $review_id LIMIT 1", 'Could not locate the service-review client');
    if (!mysqli_num_rows($pre_review_sql)) {
        throw new RuntimeException('Service review not found');
    }
    $client_id = intval(mysqli_fetch_assoc($pre_review_sql)['service_review_client_id']);
    mysqli_begin_transaction($mysqli);
    try {
        if (!agreementLockClientForAuditRetention($client_id)) {
            throw new RuntimeException('The service-review client no longer exists');
        }
        $sql = agreementDbQuery("SELECT * FROM service_reviews WHERE service_review_id = $review_id LIMIT 1 FOR UPDATE",
            'Could not lock the service review');
        if (!mysqli_num_rows($sql)) {
            throw new RuntimeException('Service review not found');
        }
        $review = mysqli_fetch_assoc($sql);
        if (intval($review['service_review_client_id']) !== $client_id) {
            throw new RuntimeException('The service-review client changed; refresh and try again');
        }
        if ($review['service_review_status'] !== 'Draft') {
            throw new RuntimeException('Only a draft service review can be published');
        }
        $snapshot = agreementValidateServiceReviewSnapshot($review);
        $contract_id = intval($review['service_review_contract_id']);
        $version_id = intval($review['service_review_agreement_version_id']);
        $version_sql = agreementDbQuery("SELECT agreement_versions.*, contracts.contract_client_id,
            contracts.contract_name, contracts.contract_status, contracts.contract_archived_at,
            contracts.contract_published_version_id
            FROM agreement_versions JOIN contracts ON contract_id = agreement_version_contract_id
            WHERE agreement_version_id = $version_id
            AND agreement_version_contract_id = $contract_id
            AND contract_client_id = $client_id LIMIT 1 FOR UPDATE",
            'Could not validate the service-review agreement evidence');
        if (!mysqli_num_rows($version_sql)) {
            throw new RuntimeException('Service-review agreement evidence belongs to another tenant or no longer exists');
        }
        $version = mysqli_fetch_assoc($version_sql);
        agreementAssertVersionIntegrity($version);
        if (!hash_equals(
            (string) $snapshot['agreement']['definition_hash'],
            (string) $version['agreement_version_definition_hash']
        )) {
            throw new RuntimeException('Service-review agreement definition binding does not match');
        }

        $published_at = mysqli_real_escape_string($mysqli, date('Y-m-d H:i:s'));
        agreementDbQuery("UPDATE service_reviews SET service_review_status = 'Published',
            service_review_published_by = $actor_id, service_review_published_at = '$published_at'
            WHERE service_review_id = $review_id AND service_review_status = 'Draft'",
            'Could not publish the service review');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The service review changed while it was being published');
        }
        $reason_sql = mysqli_real_escape_string($mysqli, substr($reason, 0, 255));
        agreementDbQuery("INSERT INTO service_review_events SET
            service_review_event_review_id = $review_id,
            service_review_event_client_id = " . intval($review['service_review_client_id']) . ",
            service_review_event_action = 'Published',
            service_review_event_actor_id = $actor_id,
            service_review_event_reason = '$reason_sql',
            service_review_event_snapshot_hash = '" . mysqli_real_escape_string($mysqli, $review['service_review_snapshot_hash']) . "',
            service_review_event_created_at = '$published_at'",
            'Could not record service-review publication');
        $published_review_sql = agreementDbQuery("SELECT * FROM service_reviews
            WHERE service_review_id = $review_id AND service_review_client_id = $client_id LIMIT 1",
            'Could not verify the published service review');
        $published_review = mysqli_fetch_assoc($published_review_sql);
        agreementValidateServiceReviewSnapshot($published_review ?: []);
        agreementValidateServiceReviewApproval(
            $published_review ?: [],
            agreementServiceReviewEvents($review_id, $client_id)
        );
        mysqli_commit($mysqli);
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function agreementGenerateDueServiceReviews(int $limit = 25): array
{
    global $mysqli;

    $limit = max(1, min(100, intval($limit)));
    $generated = [];
    $sql = agreementDbQuery("SELECT contract_id, contract_client_id,
        GREATEST(1, contract_review_cadence_months) AS cadence,
        contract_next_review_at AS review_due_at
        FROM contracts
        JOIN clients ON client_id = contract_client_id AND client_archived_at IS NULL
        JOIN agreement_versions
            ON agreement_version_id = contract_published_version_id
            AND agreement_version_contract_id = contract_id
        WHERE contract_status = 'Active' AND contract_archived_at IS NULL
        AND agreement_version_status = 'Published'
        AND agreement_version_published_at IS NOT NULL
        AND agreement_version_superseded_at IS NULL
        AND (contract_end_date IS NULL
            OR contract_end_date >= DATE_SUB(contract_next_review_at, INTERVAL 1 DAY))
        AND contract_next_review_at IS NOT NULL AND contract_next_review_at <= CURDATE()
        ORDER BY contract_next_review_at, contract_id LIMIT $limit",
        'Could not find due service reviews');
    while ($contract = mysqli_fetch_assoc($sql)) {
        $contract_id = intval($contract['contract_id']);
        $client_id = intval($contract['contract_client_id']);
        $cadence = max(1, intval($contract['cadence']));
        $review_due_at = (string) $contract['review_due_at'];
        $review_due = DateTimeImmutable::createFromFormat('!Y-m-d', $review_due_at);
        if (!$review_due || $review_due->format('Y-m-d') !== $review_due_at) {
            error_log("Service-review schedule is invalid for contract $contract_id");
            continue;
        }
        $period_start = agreementShiftCalendarMonths($review_due_at, -$cadence);
        $period_end = $review_due->modify('-1 day')->format('Y-m-d');
        try {
            $review_id = agreementGenerateServiceReview(
                $client_id,
                $period_start,
                $period_end,
                0,
                $contract_id
            );
            $next_review = agreementShiftCalendarMonths($review_due_at, $cadence);
            $review_due_sql = mysqli_real_escape_string($mysqli, $review_due_at);
            agreementDbQuery("UPDATE contracts SET contract_next_review_at = '$next_review'
                WHERE contract_id = $contract_id AND contract_client_id = $client_id
                AND contract_status = 'Active' AND contract_archived_at IS NULL
                AND contract_next_review_at = '$review_due_sql'",
                'Could not advance the service-review schedule');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The service-review schedule changed before it could be advanced');
            }
            $generated[] = $review_id;
        } catch (Throwable $e) {
            $message = "Service-review draft failed for contract $contract_id/client $client_id: "
                . $e->getMessage();
            error_log($message);
            if (function_exists('logApp')) {
                logApp('Cron-Service-Reviews', 'error', $message);
            }
        }
    }

    return $generated;
}

function agreementMarkdownEscape($value): string
{
    $value = str_replace(["\r", "\n"], ' ', trim((string) $value));
    return str_replace(
        ['\\', '`', '*', '_', '[', ']', '|', '<', '>'],
        ['\\\\', '\\`', '\\*', '\\_', '\\[', '\\]', '\\|', '&lt;', '&gt;'],
        $value
    );
}

function agreementServiceReviewMarkdown(array $review): string
{
    $snapshot = agreementValidateServiceReviewSnapshot($review);

    $client = agreementMarkdownEscape($snapshot['client']['name'] ?? 'Client');
    $period = agreementMarkdownEscape(($snapshot['period']['start'] ?? '') . ' through ' . ($snapshot['period']['end'] ?? ''));
    $agreement = $snapshot['agreement'] ?? [];
    $agreement_name = agreementMarkdownEscape($agreement['name'] ?? 'Unknown agreement');
    $agreement_version = intval($agreement['version_number'] ?? 0);
    $agreement_hash = agreementMarkdownEscape($agreement['definition_hash'] ?? 'missing');
    $tickets = $snapshot['tickets'] ?? [];
    $coverage = $snapshot['coverage'] ?? [];
    $backup = $snapshot['backup'] ?? [];
    $documentation = $snapshot['documentation'] ?? [];
    $renewals = $snapshot['renewals'] ?? [];
    $percent = static function ($value): string {
        return is_null($value) ? 'Not judged' : number_format(floatval($value), 1) . '%';
    };

    $lines = [
        "# Service Review — $client",
        '',
        "Period: $period",
        '',
        "Agreement: $agreement_name — version $agreement_version",
        '',
        "Agreement definition SHA-256: `$agreement_hash`",
        '',
        '## Executive summary',
        '',
        agreementMarkdownEscape($snapshot['summary'] ?? ''),
        '',
        '## Service performance',
        '',
        '| Metric | Result |',
        '| --- | ---: |',
        '| Tickets opened | ' . intval($tickets['total'] ?? 0) . ' |',
        '| Tickets resolved | ' . intval($tickets['resolved'] ?? 0) . ' |',
        '| Response SLA | ' . $percent($tickets['response_compliance_percent'] ?? null) . ' |',
        '| Resolution SLA | ' . $percent($tickets['resolution_compliance_percent'] ?? null) . ' |',
        '| Recurring issue groups | ' . intval($tickets['recurring_issue_groups'] ?? 0) . ' |',
        '',
        '## Coverage and readiness',
        '',
        '| Area | Result |',
        '| --- | ---: |',
        '| Endpoint management | ' . $percent($coverage['endpoint_coverage_percent'] ?? null) . ' |',
        '| Endpoint security | ' . $percent($coverage['security_coverage_percent'] ?? null) . ' |',
        '| Backup health | ' . (($backup['available'] ?? false)
            ? intval($backup['open_incidents'] ?? 0) . ' open incident(s)'
            : (($backup['in_scope'] ?? false) ? 'No source signals' : 'Not in recorded scope')) . ' |',
        '| Documentation readiness | ' . (($documentation['available'] ?? false)
            ? $percent($documentation['readiness_percent'] ?? null) : 'Provider unavailable') . ' |',
        '| Renewals in notice window | ' . intval($renewals['within_notice_window'] ?? 0) . ' |',
        '',
        '## Recommendations',
        '',
    ];
    foreach (($snapshot['recommendations'] ?? []) as $recommendation) {
        $lines[] = '- ' . agreementMarkdownEscape($recommendation);
    }

    $lines[] = '';
    $lines[] = 'Snapshot SHA-256: `' . agreementMarkdownEscape($review['service_review_snapshot_hash'] ?? '') . '`';
    $review_events = array_key_exists('_service_review_events', $review)
        ? (array) $review['_service_review_events']
        : agreementServiceReviewEvents(
            intval($review['service_review_id'] ?? 0),
            intval($review['service_review_client_id'] ?? 0)
        );
    $approval = agreementValidateServiceReviewApproval($review, $review_events);
    if ($approval) {
            $actor = agreementMarkdownEscape($approval['user_name']
                ?? ('User ' . intval($approval['service_review_event_actor_id'])));
            $approved_at = agreementMarkdownEscape($approval['service_review_event_created_at'] ?? '');
            $approval_reason = agreementMarkdownEscape($approval['service_review_event_reason'] ?? '');
            $lines[] = '';
            $lines[] = "Published approval: $actor at $approved_at — $approval_reason";
    }
    return implode("\n", $lines) . "\n";
}
