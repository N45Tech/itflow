<?php

/*
 * Versioned documentation requirements, client obligations and operational
 * evidence. Published definitions and event rows are append-only. Mutable
 * projections use row locks plus revision compare-and-swap writes.
 */

function documentationRequirementRecordTypes() {
    return ['identity', 'network', 'backup', 'security', 'endpoint', 'vendor', 'agreement', 'portal', 'recovery', 'general'];
}

function documentationSelectorDimensions() {
    return ['always', 'active_contract', 'plan', 'service', 'service_category', 'asset_class', 'integration', 'client_type'];
}

function documentationOwnerRoles() {
    return ['documentation_owner', 'service_owner', 'account_manager', 'security_lead', 'support_lead', 'unassigned'];
}

function documentationEvidencePolicies() {
    // Automated verification is intentionally disabled until an authenticated
    // production adapter can create attributable evidence occurrences.
    return ['none', 'note', 'file', 'reference'];
}

function documentationEvidenceReferenceTypes() {
    return ['policy', 'note', 'document', 'document-version', 'file', 'ticket', 'url', 'automation'];
}

function documentationEntityEvidenceReferenceTypes() {
    return ['document', 'document-version', 'file', 'ticket', 'automation'];
}

function documentationExceptionApprovalPolicies() {
    return ['support3', 'administrator'];
}

function documentationPromiseReasonCodes() {
    return ['client-input', 'evidence-follow-up', 'technical-validation', 'documentation-refresh'];
}

function documentationBaseStatuses() {
    return ['Missing', 'Draft', 'Current', 'Due Soon', 'Stale', 'Not Applicable'];
}

function documentationNormalizeKey($value, $fallback = 'requirement', $limit = 100) {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string) $value, '-');
    if ($value === '') {
        $value = $fallback;
    }
    return substr($value, 0, max(1, intval($limit)));
}

function documentationNormalizeChoiceToken($value, $fallback = '', $limit = 40) {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9_]+/', '_', $value);
    $value = trim((string) preg_replace('/_+/', '_', (string) $value), '_');
    if ($value === '') {
        $value = $fallback;
    }
    return substr($value, 0, max(1, intval($limit)));
}

function documentationNormalizeSelectorValue($dimension, $value) {
    $dimension = (string) $dimension;
    $value = documentationNormalizeKey($value, 'any');

    if ($dimension === 'asset_class') {
        $aliases = [
            'desktop' => 'workstation',
            'laptop' => 'workstation',
            'notebook' => 'workstation',
            'pc' => 'workstation',
            'firewall' => 'network-device',
            'router' => 'network-device',
            'switch' => 'network-device',
            'access-point' => 'network-device',
            'wireless-access-point' => 'network-device',
        ];
        return $aliases[$value] ?? $value;
    }

    if ($dimension === 'integration') {
        $aliases = [
            'level-io' => 'level',
            'sentinel-one' => 'sentinelone',
            'microsoft-intune' => 'intune',
            'microsoft-entra' => 'entra',
        ];
        return $aliases[$value] ?? $value;
    }

    return $value;
}

function documentationCanonicalRequirementDefinition($definition) {
    $definition = is_array($definition) ? $definition : [];
    $selectors = [];
    foreach ((array) ($definition['selectors'] ?? []) as $selector) {
        if (!is_array($selector)) {
            continue;
        }
        $dimension = documentationNormalizeChoiceToken($selector['dimension'] ?? '', '', 40);
        $value = documentationNormalizeSelectorValue($dimension, $selector['value'] ?? '');
        $identity = $dimension . ':' . $value;
        $selectors[$identity] = ['dimension' => $dimension, 'value' => $value];
    }
    ksort($selectors, SORT_STRING);

    $cadence_days = max(1, min(3650, intval($definition['review_cadence_days'] ?? 365)));
    $warning_days = max(0, min($cadence_days - 1, intval($definition['warning_window_days'] ?? 30)));
    $record_type = documentationNormalizeChoiceToken($definition['record_type'] ?? 'general', 'general', 40);
    $owner_role = documentationNormalizeChoiceToken($definition['default_owner_role'] ?? 'documentation_owner', 'documentation_owner', 40);
    $reviewer_role = documentationNormalizeChoiceToken($definition['default_reviewer_role'] ?? 'support_lead', 'support_lead', 40);
    $evidence_policy = documentationNormalizeChoiceToken($definition['evidence_policy'] ?? 'reference', 'reference', 40);
    $exception_policy = documentationNormalizeChoiceToken($definition['exception_approval_policy'] ?? 'support3', 'support3', 40);
    $mode = strtolower(trim((string) ($definition['applicability_mode'] ?? 'any')));

    return [
        'key' => documentationNormalizeKey($definition['key'] ?? ''),
        'name' => substr(trim((string) ($definition['name'] ?? '')), 0, 200),
        'description' => trim((string) ($definition['description'] ?? '')),
        'record_type' => $record_type,
        'default_owner_role' => $owner_role,
        'default_owner_user_id' => max(0, intval($definition['default_owner_user_id'] ?? 0)),
        'default_reviewer_role' => $reviewer_role,
        'default_reviewer_user_id' => max(0, intval($definition['default_reviewer_user_id'] ?? 0)),
        'review_cadence_days' => $cadence_days,
        'warning_window_days' => $warning_days,
        'blocks_readiness' => empty($definition['blocks_readiness']) ? 0 : 1,
        'blocks_ticket_resolution' => empty($definition['blocks_ticket_resolution']) ? 0 : 1,
        'evidence_policy' => $evidence_policy,
        'exception_approval_policy' => $exception_policy,
        'applicability_mode' => in_array($mode, ['any', 'all'], true) ? $mode : 'any',
        'selectors' => array_values($selectors),
    ];
}

function documentationValidateRequirementDefinition($definition) {
    $definition = documentationCanonicalRequirementDefinition($definition);
    $errors = [];
    if ($definition['key'] === '' || $definition['key'] === 'requirement') {
        $errors[] = 'A stable requirement key is required.';
    }
    if ($definition['name'] === '') {
        $errors[] = 'A requirement name is required.';
    }
    if (!in_array($definition['record_type'], documentationRequirementRecordTypes(), true)) {
        $errors[] = 'The required record type is not supported.';
    }
    if (!in_array($definition['default_owner_role'], documentationOwnerRoles(), true)) {
        $errors[] = 'The default owner role is not supported.';
    }
    if (!in_array($definition['default_reviewer_role'], documentationOwnerRoles(), true)) {
        $errors[] = 'The default reviewer role is not supported.';
    }
    if (!in_array($definition['evidence_policy'], documentationEvidencePolicies(), true)) {
        $errors[] = 'The verification evidence policy is not supported.';
    }
    if (!in_array($definition['exception_approval_policy'], documentationExceptionApprovalPolicies(), true)) {
        $errors[] = 'The exception approval policy is not supported.';
    }
    if (!$definition['selectors']) {
        $errors[] = 'At least one applicability selector is required.';
    }
    foreach ($definition['selectors'] as $selector) {
        if (!in_array($selector['dimension'], documentationSelectorDimensions(), true)) {
            $errors[] = 'Unsupported applicability selector: ' . $selector['dimension'];
        }
        if ($selector['value'] === '' || ($selector['dimension'] === 'always' && $selector['value'] !== 'any')) {
            $errors[] = 'Invalid applicability selector value for ' . $selector['dimension'];
        }
    }
    return array_values(array_unique($errors));
}

function documentationRequirementDefinitionHash($definition) {
    $json = json_encode(
        documentationCanonicalRequirementDefinition($definition),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
    if ($json === false) {
        throw new RuntimeException('Could not serialize the documentation requirement definition');
    }
    return hash('sha256', $json);
}

function documentationBaselineRequirementCatalog() {
    return [
        [
            'key' => 'identity-access-record',
            'name' => 'Identity and Access Record',
            'description' => 'Authoritative tenant, privileged access, identity lifecycle, and emergency-access documentation.',
            'record_type' => 'identity',
            'review_cadence_days' => 90,
            'warning_window_days' => 14,
            'blocks_readiness' => 1,
            'blocks_ticket_resolution' => 1,
            'evidence_policy' => 'reference',
            'selectors' => [
                ['dimension' => 'service_category', 'value' => 'identity'],
                ['dimension' => 'service_category', 'value' => 'microsoft-365'],
                ['dimension' => 'integration', 'value' => 'cipp'],
                ['dimension' => 'integration', 'value' => 'entra'],
                ['dimension' => 'integration', 'value' => 'intune'],
            ],
        ],
        [
            'key' => 'network-topology-access',
            'name' => 'Network Topology and Access Record',
            'description' => 'Current sites, subnets, edge devices, wireless, circuits, management paths, and recovery access.',
            'record_type' => 'network',
            'review_cadence_days' => 180,
            'warning_window_days' => 30,
            'blocks_readiness' => 1,
            'blocks_ticket_resolution' => 1,
            'evidence_policy' => 'reference',
            'selectors' => [
                ['dimension' => 'asset_class', 'value' => 'network-device'],
                ['dimension' => 'service_category', 'value' => 'network'],
            ],
        ],
        [
            'key' => 'backup-coverage-record',
            'name' => 'Backup Coverage Record',
            'description' => 'Protected workloads, products, retention, exclusions, monitoring, and restore ownership.',
            'record_type' => 'backup',
            'review_cadence_days' => 90,
            'warning_window_days' => 14,
            'blocks_readiness' => 1,
            'blocks_ticket_resolution' => 1,
            'evidence_policy' => 'reference',
            'selectors' => [
                ['dimension' => 'service_category', 'value' => 'backup'],
                ['dimension' => 'service', 'value' => 'managed-backup'],
            ],
        ],
        [
            'key' => 'security-controls-response',
            'name' => 'Security Controls and Response Record',
            'description' => 'Deployed controls, coverage exceptions, alert ownership, containment authority, and response contacts.',
            'record_type' => 'security',
            'review_cadence_days' => 90,
            'warning_window_days' => 14,
            'blocks_readiness' => 1,
            'blocks_ticket_resolution' => 1,
            'evidence_policy' => 'reference',
            'selectors' => [
                ['dimension' => 'service_category', 'value' => 'security'],
                ['dimension' => 'integration', 'value' => 'sentinelone'],
            ],
        ],
        [
            'key' => 'endpoint-management-standard',
            'name' => 'Endpoint Management Standard',
            'description' => 'Enrollment, patching, encryption, compliance, monitoring, supported builds, and exclusions.',
            'record_type' => 'endpoint',
            'review_cadence_days' => 180,
            'warning_window_days' => 30,
            'blocks_readiness' => 1,
            'blocks_ticket_resolution' => 1,
            'evidence_policy' => 'reference',
            'selectors' => [
                ['dimension' => 'asset_class', 'value' => 'workstation'],
                ['dimension' => 'asset_class', 'value' => 'server'],
                ['dimension' => 'integration', 'value' => 'intune'],
                ['dimension' => 'integration', 'value' => 'level'],
            ],
        ],
        [
            'key' => 'vendor-escalation-matrix',
            'name' => 'Vendor Escalation Matrix',
            'description' => 'Support vendors, account identifiers, authorized contacts, escalation paths, and renewal ownership.',
            'record_type' => 'vendor',
            'review_cadence_days' => 365,
            'warning_window_days' => 45,
            'blocks_readiness' => 1,
            'blocks_ticket_resolution' => 0,
            'evidence_policy' => 'reference',
            'selectors' => [['dimension' => 'active_contract', 'value' => 'any']],
        ],
        [
            'key' => 'active-service-agreement',
            'name' => 'Active Service Agreement and Scope',
            'description' => 'Executed service terms, accepted scope, covered systems, rates, exclusions, and renewal dates.',
            'record_type' => 'agreement',
            'review_cadence_days' => 365,
            'warning_window_days' => 60,
            'blocks_readiness' => 1,
            'blocks_ticket_resolution' => 0,
            'evidence_policy' => 'reference',
            'selectors' => [['dimension' => 'active_contract', 'value' => 'any']],
        ],
        [
            'key' => 'portal-authorized-contacts',
            'name' => 'Portal and Authorized Contacts Record',
            'description' => 'Authorized requestors, approval contacts, portal access, notification routes, and emergency contacts.',
            'record_type' => 'portal',
            'review_cadence_days' => 180,
            'warning_window_days' => 30,
            'blocks_readiness' => 1,
            'blocks_ticket_resolution' => 1,
            'evidence_policy' => 'reference',
            'selectors' => [['dimension' => 'active_contract', 'value' => 'any']],
        ],
        [
            'key' => 'recovery-runbook',
            'name' => 'Recovery Runbook',
            'description' => 'Recovery priorities, dependencies, restore sequence, access, decision authority, and validation procedure.',
            'record_type' => 'recovery',
            'review_cadence_days' => 180,
            'warning_window_days' => 30,
            'blocks_readiness' => 1,
            'blocks_ticket_resolution' => 1,
            'evidence_policy' => 'reference',
            'selectors' => [
                ['dimension' => 'service_category', 'value' => 'backup'],
                ['dimension' => 'service_category', 'value' => 'business-continuity'],
                ['dimension' => 'active_contract', 'value' => 'any'],
            ],
        ],
    ];
}

function documentationRedactAuditText($value) {
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim((string) $value));
    $value = preg_replace('/\b(bearer|token|secret|password|api[_ -]?key)\s*[:=]\s*[^\s,;]+/iu', '$1=[REDACTED]', (string) $value);
    $value = preg_replace('/([?&](?:token|key|secret|password)=)[^&\s]+/iu', '$1[REDACTED]', (string) $value);
    $value = preg_replace('/\s+/u', ' ', (string) $value);
    return substr(trim((string) $value), 0, 255);
}

function documentationAuditContextHash($context) {
    if (!is_array($context)) {
        $context = ['value' => documentationRedactAuditText($context)];
    }
    ksort($context, SORT_STRING);
    foreach ($context as $key => $value) {
        $context[$key] = is_scalar($value) || $value === null
            ? documentationRedactAuditText((string) $value)
            : hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    return hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function documentationEvidenceReferenceHash($reference_type, $reference_id, $opaque_locator = '') {
    return hash('sha256', documentationNormalizeKey($reference_type, 'reference', 40)
        . ':' . max(0, intval($reference_id)) . ':' . trim((string) $opaque_locator));
}

function documentationFreshnessProjection($state, $now = null) {
    $state = is_array($state) ? $state : [];
    $now_timestamp = $now === null ? time() : (is_numeric($now) ? intval($now) : strtotime((string) $now));
    $now_timestamp = $now_timestamp ?: time();
    if (empty($state['applicable'])) {
        return ['base_status' => 'Not Applicable', 'reason_code' => 'not_applicable', 'next_review_at' => null, 'stale_at' => null];
    }
    if (empty($state['document_exists'])) {
        return ['base_status' => 'Missing', 'reason_code' => 'required_record_missing', 'next_review_at' => null, 'stale_at' => null];
    }
    if (array_key_exists('verification_context_valid', $state) && empty($state['verification_context_valid'])) {
        return ['base_status' => 'Draft', 'reason_code' => 'verification_context_changed', 'next_review_at' => null, 'stale_at' => null];
    }
    if (empty($state['last_verified_at'])) {
        return ['base_status' => 'Draft', 'reason_code' => 'awaiting_verification', 'next_review_at' => null, 'stale_at' => null];
    }
    $verified_hash = (string) ($state['verified_document_hash'] ?? '');
    $current_hash = (string) ($state['current_document_hash'] ?? '');
    if ($verified_hash !== '' && $current_hash !== '' && !hash_equals($verified_hash, $current_hash)) {
        return ['base_status' => 'Draft', 'reason_code' => 'document_changed_since_verification', 'next_review_at' => null, 'stale_at' => null];
    }
    $verified_timestamp = strtotime((string) $state['last_verified_at']);
    if (!$verified_timestamp) {
        return ['base_status' => 'Draft', 'reason_code' => 'invalid_verification_timestamp', 'next_review_at' => null, 'stale_at' => null];
    }
    $cadence_days = max(1, min(3650, intval($state['review_cadence_days'] ?? 365)));
    $warning_days = max(0, min($cadence_days - 1, intval($state['warning_window_days'] ?? 30)));
    $stale_timestamp = strtotime('+' . $cadence_days . ' days', $verified_timestamp);
    $warning_timestamp = strtotime('-' . $warning_days . ' days', $stale_timestamp);
    $dates = [
        'next_review_at' => date('Y-m-d H:i:s', $stale_timestamp),
        'stale_at' => date('Y-m-d H:i:s', $stale_timestamp),
    ];
    if ($now_timestamp >= $stale_timestamp) {
        return ['base_status' => 'Stale', 'reason_code' => 'review_overdue'] + $dates;
    }
    if ($now_timestamp >= $warning_timestamp) {
        return ['base_status' => 'Due Soon', 'reason_code' => 'review_due_soon'] + $dates;
    }
    return ['base_status' => 'Current', 'reason_code' => 'verified_current'] + $dates;
}

function documentationObligationEffectiveStatus($obligation, $now = null) {
    $obligation = is_array($obligation) ? $obligation : [];
    $base_status = documentationObligationProjectedBaseStatus($obligation, $now);
    if ($base_status === 'Not Applicable') {
        return $base_status;
    }
    $exception_status = (string) ($obligation['documentation_obligation_exception_status'] ?? $obligation['exception_status'] ?? '');
    $exception_expires = (string) ($obligation['documentation_obligation_exception_expires_at'] ?? $obligation['exception_expires_at'] ?? '');
    $exception_record_valid = !array_key_exists('documentation_exception_record_valid', $obligation)
        || !empty($obligation['documentation_exception_record_valid']);
    $now_timestamp = $now === null ? time() : (is_numeric($now) ? intval($now) : strtotime((string) $now));
    if (!in_array($base_status, ['Current', 'Due Soon'], true)
        && $exception_record_valid
        && $exception_status === 'Approved' && $exception_expires !== ''
        && ($expires_timestamp = strtotime($exception_expires)) && $expires_timestamp > ($now_timestamp ?: time())) {
        return 'Exception';
    }
    return in_array($base_status, documentationBaseStatuses(), true) ? $base_status : 'Missing';
}

function documentationObligationProjectedBaseStatus($obligation, $now = null) {
    $obligation = is_array($obligation) ? $obligation : [];
    $stored = (string) ($obligation['documentation_obligation_base_status'] ?? $obligation['base_status'] ?? 'Missing');
    if (array_key_exists('documentation_requirement_current_lifecycle', $obligation)) {
        $lifecycle = (string) $obligation['documentation_requirement_current_lifecycle'];
        if (in_array($lifecycle, ['Archived', 'Draft'], true)) {
            return 'Not Applicable';
        }
        if ($lifecycle !== 'Active'
            || empty($obligation['documentation_requirement_projection_valid'])) {
            return 'Draft';
        }
    }
    $applicable = !empty($obligation['documentation_obligation_applicable'] ?? $obligation['applicable'] ?? false);
    if (!$applicable || $stored === 'Not Applicable') {
        return 'Not Applicable';
    }
    if (array_key_exists('documentation_verification_context_valid', $obligation)
        && empty($obligation['documentation_verification_context_valid'])
        && !empty($obligation['documentation_obligation_last_verified_at'] ?? $obligation['last_verified_at'] ?? null)) {
        return 'Draft';
    }
    $has_live_document_state = array_key_exists('current_document_exists', $obligation);
    if ($has_live_document_state) {
        $current_hash = (string) ($obligation['current_document_hash'] ?? '');
        if ($current_hash === '' && !empty($obligation['current_document_exists'])) {
            $content = (string) ($obligation['current_document_content_raw'] ?? '');
            if ($content === '') {
                $content = (string) ($obligation['current_document_content'] ?? '');
            }
            $current_hash = hash('sha256', $content);
        }
        $projection = documentationFreshnessProjection([
            'applicable' => true,
            'document_exists' => !empty($obligation['current_document_exists']),
            'verification_context_valid' => !array_key_exists('documentation_verification_context_valid', $obligation)
                || !empty($obligation['documentation_verification_context_valid']),
            'last_verified_at' => $obligation['documentation_obligation_last_verified_at'] ?? $obligation['last_verified_at'] ?? null,
            'review_cadence_days' => intval($obligation['documentation_requirement_version_review_cadence_days'] ?? $obligation['review_cadence_days'] ?? 365),
            'warning_window_days' => intval($obligation['documentation_requirement_version_warning_window_days'] ?? $obligation['warning_window_days'] ?? 30),
            'verified_document_hash' => $obligation['documentation_obligation_verification_document_hash'] ?? $obligation['verified_document_hash'] ?? '',
            'current_document_hash' => $current_hash,
        ], $now);
        return $projection['base_status'];
    }
    if (in_array($stored, ['Missing', 'Draft'], true)) {
        return $stored;
    }
    $now_timestamp = $now === null ? time() : (is_numeric($now) ? intval($now) : strtotime((string) $now));
    $now_timestamp = $now_timestamp ?: time();
    $stale_at = (string) ($obligation['documentation_obligation_stale_at'] ?? $obligation['stale_at'] ?? '');
    if ($stale_at !== '' && ($stale_timestamp = strtotime($stale_at))) {
        if ($now_timestamp >= $stale_timestamp) {
            return 'Stale';
        }
        $warning_days = max(0, intval($obligation['documentation_requirement_version_warning_window_days'] ?? $obligation['warning_window_days'] ?? 0));
        if ($warning_days > 0 && $now_timestamp >= strtotime('-' . $warning_days . ' days', $stale_timestamp)) {
            return 'Due Soon';
        }
        return 'Current';
    }
    return in_array($stored, documentationBaseStatuses(), true) ? $stored : 'Missing';
}

function documentationReadinessReduce($obligations, $now = null) {
    $counts = array_fill_keys(array_merge(documentationBaseStatuses(), ['Exception']), 0);
    $numerator = 0;
    $denominator = 0;
    $contributions = [];
    foreach ((array) $obligations as $obligation) {
        $projection_pending = (string) ($obligation['documentation_requirement_current_lifecycle'] ?? '') === 'Active'
            && array_key_exists('documentation_requirement_projection_valid', $obligation)
            && empty($obligation['documentation_requirement_projection_valid']);
        $applicable = !empty($obligation['documentation_obligation_applicable'] ?? $obligation['applicable'] ?? false)
            || $projection_pending;
        $blocks = !empty($obligation['documentation_requirement_version_blocks_readiness'] ?? $obligation['blocks_readiness'] ?? false);
        $status = documentationObligationEffectiveStatus($obligation, $now);
        if (!isset($counts[$status])) {
            $counts[$status] = 0;
        }
        $counts[$status]++;
        $included = $applicable && $blocks && $status !== 'Not Applicable';
        $points = $included && in_array($status, ['Current', 'Due Soon'], true) ? 1 : 0;
        if ($included) {
            $denominator++;
            $numerator += $points;
        }
        $contributions[] = [
            'obligation_id' => intval($obligation['documentation_obligation_id'] ?? $obligation['obligation_id'] ?? 0),
            'requirement_key' => (string) ($obligation['documentation_requirement_version_key'] ?? $obligation['requirement_key'] ?? ''),
            'requirement_name' => (string) ($obligation['documentation_requirement_version_name'] ?? $obligation['requirement_name'] ?? ''),
            'status' => $status,
            'included' => $included,
            'points' => $points,
            'reason' => !$applicable ? 'not_applicable'
                : (!$blocks ? 'does_not_block_readiness'
                    : ($status === 'Exception' ? 'exception_does_not_earn_readiness_credit'
                        : ($points ? 'freshness_credit' : 'freshness_gap'))),
        ];
    }
    return [
        'score_percent' => $denominator > 0 ? (int) round(($numerator / $denominator) * 100) : null,
        'numerator' => $numerator,
        'denominator' => $denominator,
        'counts' => $counts,
        'contributions' => $contributions,
    ];
}

function documentationObligationValiditySql($obligation_alias = 'o') {
    $obligation_alias = (string) $obligation_alias;
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $obligation_alias)) {
        throw new InvalidArgumentException('Invalid documentation obligation SQL alias');
    }
    $requirement_alias = 'documentation_validity_requirement';
    $version_alias = 'documentation_validity_version';
    $evidence_alias = 'documentation_validity_evidence';
    $exception_alias = 'documentation_validity_exception';
    $document_alias = 'documentation_validity_document';
    $requirement_current = "$requirement_alias.documentation_requirement_lifecycle = 'Active'
        AND $requirement_alias.documentation_requirement_archived_at IS NULL
        AND $version_alias.documentation_requirement_version_id IS NOT NULL
        AND $version_alias.documentation_requirement_version_requirement_id = $obligation_alias.documentation_obligation_requirement_id
        AND $version_alias.documentation_requirement_version_id = $obligation_alias.documentation_obligation_requirement_version_id";

    return [
        'select' => "$requirement_alias.documentation_requirement_lifecycle AS documentation_requirement_current_lifecycle,
            $requirement_alias.documentation_requirement_published_version_id AS documentation_requirement_current_version_id,
            $version_alias.documentation_requirement_version_number AS documentation_current_requirement_version_number,
            $version_alias.documentation_requirement_version_key AS documentation_current_requirement_version_key,
            $version_alias.documentation_requirement_version_name AS documentation_current_requirement_version_name,
            $version_alias.documentation_requirement_version_description AS documentation_current_requirement_version_description,
            $version_alias.documentation_requirement_version_record_type AS documentation_current_requirement_version_record_type,
            $version_alias.documentation_requirement_version_blocks_readiness AS documentation_current_requirement_version_blocks_readiness,
            $version_alias.documentation_requirement_version_blocks_ticket_resolution AS documentation_current_requirement_version_blocks_ticket_resolution,
            $version_alias.documentation_requirement_version_review_cadence_days AS documentation_current_requirement_version_review_cadence_days,
            $version_alias.documentation_requirement_version_warning_window_days AS documentation_current_requirement_version_warning_window_days,
            $version_alias.documentation_requirement_version_evidence_policy AS documentation_current_requirement_version_evidence_policy,
            $version_alias.documentation_requirement_version_exception_approval_policy AS documentation_current_requirement_version_exception_approval_policy,
            CASE WHEN $document_alias.document_id IS NOT NULL THEN 1 ELSE 0 END AS current_document_exists,
            CASE WHEN $document_alias.document_id IS NULL THEN ''
                ELSE SHA2(CASE
                    WHEN $document_alias.document_content_raw IS NOT NULL
                        AND $document_alias.document_content_raw <> '' THEN $document_alias.document_content_raw
                    ELSE COALESCE($document_alias.document_content, '')
                END, 256)
            END AS current_document_hash,
            CASE WHEN $requirement_current THEN 1 ELSE 0 END AS documentation_requirement_projection_valid,
            CASE
                WHEN NOT ($requirement_current) THEN 0
                WHEN $obligation_alias.documentation_obligation_last_verified_at IS NULL THEN
                    CASE WHEN $obligation_alias.documentation_obligation_verification_evidence_id = 0
                        AND $obligation_alias.documentation_obligation_verification_document_hash IS NULL
                        THEN 1 ELSE 0 END
                WHEN $evidence_alias.documentation_evidence_id IS NOT NULL
                    AND $evidence_alias.documentation_evidence_client_id = $obligation_alias.documentation_obligation_client_id
                    AND $evidence_alias.documentation_evidence_obligation_id = $obligation_alias.documentation_obligation_id
                    AND $evidence_alias.documentation_evidence_requirement_version_id = $version_alias.documentation_requirement_version_id
                    AND $evidence_alias.documentation_evidence_policy_result = 'accepted'
                    AND $obligation_alias.documentation_obligation_verification_document_hash IS NOT NULL
                    AND $obligation_alias.documentation_obligation_verification_document_hash <> ''
                    THEN 1 ELSE 0
            END AS documentation_verification_context_valid,
            CASE
                WHEN NOT ($requirement_current) THEN 0
                WHEN $obligation_alias.documentation_obligation_exception_id = 0
                    AND $obligation_alias.documentation_obligation_exception_status IS NULL THEN 1
                WHEN $exception_alias.documentation_obligation_exception_id IS NOT NULL
                    AND $exception_alias.documentation_obligation_exception_client_id = $obligation_alias.documentation_obligation_client_id
                    AND $exception_alias.documentation_obligation_exception_obligation_id = $obligation_alias.documentation_obligation_id
                    AND $exception_alias.documentation_obligation_exception_requirement_version_id = $version_alias.documentation_requirement_version_id
                    AND $exception_alias.documentation_obligation_exception_status = $obligation_alias.documentation_obligation_exception_status
                    THEN 1 ELSE 0
            END AS documentation_exception_record_valid",
        'joins' => "LEFT JOIN documentation_requirements $requirement_alias
                ON $requirement_alias.documentation_requirement_id = $obligation_alias.documentation_obligation_requirement_id
            LEFT JOIN documentation_requirement_versions $version_alias
                ON $version_alias.documentation_requirement_version_id = $requirement_alias.documentation_requirement_published_version_id
                AND $version_alias.documentation_requirement_version_requirement_id = $requirement_alias.documentation_requirement_id
            LEFT JOIN documentation_evidence_locker $evidence_alias
                ON $evidence_alias.documentation_evidence_id = $obligation_alias.documentation_obligation_verification_evidence_id
            LEFT JOIN documentation_obligation_exceptions $exception_alias
                ON $exception_alias.documentation_obligation_exception_id = $obligation_alias.documentation_obligation_exception_id
            LEFT JOIN documents $document_alias
                ON $document_alias.document_id = $obligation_alias.documentation_obligation_document_id
                AND $document_alias.document_client_id = $obligation_alias.documentation_obligation_client_id
                AND $document_alias.document_archived_at IS NULL",
    ];
}

/**
 * Present the active published definition under the established version field
 * names used by queue/detail templates. Stored obligation-version metadata must
 * never overwrite these values after a vNext publication.
 */
function documentationApplyCurrentRequirementMetadata(array $row) {
    $fields = [
        'number', 'key', 'name', 'description', 'record_type',
        'blocks_readiness', 'blocks_ticket_resolution',
        'review_cadence_days', 'warning_window_days',
        'evidence_policy', 'exception_approval_policy',
    ];
    foreach ($fields as $field) {
        $current = 'documentation_current_requirement_version_' . $field;
        if (array_key_exists($current, $row)) {
            $row['documentation_requirement_version_' . $field] = $row[$current];
        }
    }
    return $row;
}

function documentationProjectObligationValidity(array $row, $now = null) {
    $lifecycle_present = array_key_exists('documentation_requirement_current_lifecycle', $row);
    $requirement_active = !$lifecycle_present
        || (string) $row['documentation_requirement_current_lifecycle'] === 'Active';
    $requirement_current = !$lifecycle_present
        || !empty($row['documentation_requirement_projection_valid']);
    $verification_current = !array_key_exists('documentation_verification_context_valid', $row)
        || !empty($row['documentation_verification_context_valid']);
    $exception_current = !array_key_exists('documentation_exception_record_valid', $row)
        || !empty($row['documentation_exception_record_valid']);
    return [
        'requirement_active' => $requirement_active,
        'requirement_current' => $requirement_current,
        'verification_current' => $verification_current,
        'exception_current' => $exception_current,
        'base_status' => documentationObligationProjectedBaseStatus($row, $now),
        'effective_status' => documentationObligationEffectiveStatus($row, $now),
    ];
}

function documentationDbQuery($sql, $context) {
    global $mysqli;
    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        throw new RuntimeException($context . ': ' . mysqli_error($mysqli));
    }
    return $result;
}

function documentationSqlValue($value) {
    global $mysqli;
    if ($value === null || $value === '') {
        return 'NULL';
    }
    return "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
}

function documentationBeginMutation($caller_transaction) {
    global $mysqli;
    if (!$caller_transaction && !mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not start the documentation transaction');
    }
}

function documentationCommitMutation($caller_transaction) {
    global $mysqli;
    if (!$caller_transaction && !mysqli_commit($mysqli)) {
        throw new RuntimeException('Could not commit the documentation transaction');
    }
}

function documentationRollbackMutation($caller_transaction) {
    global $mysqli;
    if (!$caller_transaction) {
        mysqli_rollback($mysqli);
    }
}

function documentationLockClient($client_id) {
    $client_id = intval($client_id);
    if (!$client_id) {
        throw new RuntimeException('A client is required for the documentation mutation');
    }
    $client = mysqli_fetch_assoc(documentationDbQuery("SELECT client_id, client_type, client_archived_at
        FROM clients WHERE client_id = $client_id LIMIT 1 FOR UPDATE", 'Could not lock the documentation client'));
    if (!$client || !empty($client['client_archived_at'])) {
        throw new RuntimeException('The documentation client is unavailable');
    }
    return $client;
}

function documentationLockClientForExpiry($client_id) {
    $client_id = intval($client_id);
    if (!$client_id) {
        throw new RuntimeException('A client is required for documentation expiry');
    }
    $client = mysqli_fetch_assoc(documentationDbQuery("SELECT client_id, client_type, client_archived_at
        FROM clients WHERE client_id = $client_id LIMIT 1 FOR UPDATE", 'Could not lock the documentation expiry client'));
    if (!$client) {
        throw new RuntimeException('The documentation expiry client no longer exists');
    }
    return $client;
}

function documentationAgentHasSupportLevel($user_id, $minimum_level = 2) {
    $user_id = intval($user_id);
    $minimum_level = max(1, min(3, intval($minimum_level)));
    if (!$user_id) {
        return false;
    }
    $row = mysqli_fetch_row(documentationDbQuery("SELECT COUNT(*) FROM users
        WHERE user_id = $user_id AND user_type = 1 AND user_status = 1
        AND user_archived_at IS NULL AND (
            EXISTS (SELECT 1 FROM user_roles r
                WHERE r.role_id = users.user_role_id AND r.role_is_admin = 1)
            OR EXISTS (SELECT 1 FROM user_role_permissions p
                INNER JOIN modules m ON m.module_id = p.module_id
                WHERE p.user_role_id = users.user_role_id
                AND m.module_name = 'module_support'
                AND p.user_role_permission_level >= $minimum_level)
        )", 'Could not verify documentation authorization'));
    return intval($row[0] ?? 0) > 0;
}

function documentationRequireSupportLevel($user_id, $minimum_level = 2) {
    if (!documentationAgentHasSupportLevel($user_id, $minimum_level)) {
        throw new RuntimeException('The documentation action requires an active authorized internal user');
    }
}

function documentationAgentIsAdministrator($user_id) {
    $user_id = intval($user_id);
    if (!$user_id) {
        return false;
    }
    $row = mysqli_fetch_row(documentationDbQuery("SELECT COUNT(*) FROM users
        INNER JOIN user_roles ON role_id = user_role_id
        WHERE user_id = $user_id AND user_type = 1 AND user_status = 1
        AND user_archived_at IS NULL AND role_is_admin = 1", 'Could not verify documentation administrator authorization'));
    return intval($row[0] ?? 0) > 0;
}

function documentationRequireAuthoringActor($actor_id, $caller_transaction) {
    $actor_id = intval($actor_id);
    if ($actor_id > 0) {
        documentationRequireSupportLevel($actor_id, 3);
        return;
    }
    if (!$caller_transaction) {
        throw new RuntimeException('System requirement authoring is allowed only inside an explicit reconciliation transaction');
    }
}

function documentationSaveRequirementDraft(
    $requirement_id,
    array $definition,
    $expected_revision,
    $actor_id,
    $caller_transaction = false
) {
    global $mysqli;

    documentationRequireAuthoringActor($actor_id, $caller_transaction);

    $definition = documentationCanonicalRequirementDefinition($definition);
    $errors = documentationValidateRequirementDefinition($definition);
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', $errors));
    }
    $definition_json = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($definition_json === false) {
        throw new RuntimeException('Could not serialize the documentation requirement draft');
    }
    $requirement_id = max(0, intval($requirement_id));
    $actor_id = max(0, intval($actor_id));
    $key_sql = mysqli_real_escape_string($mysqli, $definition['key']);
    $definition_sql = mysqli_real_escape_string($mysqli, $definition_json);

    documentationBeginMutation($caller_transaction);
    try {
        if (!$requirement_id) {
            $duplicate = mysqli_fetch_assoc(documentationDbQuery("SELECT documentation_requirement_id,
                documentation_requirement_revision FROM documentation_requirements
                WHERE documentation_requirement_key = '$key_sql' LIMIT 1 FOR UPDATE", 'Could not check the requirement identity'));
            if ($duplicate) {
                throw new RuntimeException('A documentation requirement already owns this stable key');
            }
            documentationDbQuery("INSERT INTO documentation_requirements SET
                documentation_requirement_key = '$key_sql',
                documentation_requirement_draft_definition = '$definition_sql',
                documentation_requirement_lifecycle = 'Draft',
                documentation_requirement_revision = 1,
                documentation_requirement_created_by = $actor_id,
                documentation_requirement_updated_by = $actor_id", 'Could not create the documentation requirement draft');
            $requirement_id = intval(mysqli_insert_id($mysqli));
            documentationCommitMutation($caller_transaction);
            return ['requirement_id' => $requirement_id, 'revision' => 1, 'changed' => true];
        }

        $row = mysqli_fetch_assoc(documentationDbQuery("SELECT * FROM documentation_requirements
            WHERE documentation_requirement_id = $requirement_id LIMIT 1 FOR UPDATE", 'Could not lock the requirement draft'));
        if (!$row) {
            throw new RuntimeException('The documentation requirement no longer exists');
        }
        if ($row['documentation_requirement_lifecycle'] === 'Archived') {
            throw new RuntimeException('Restore the documentation requirement before editing its draft');
        }
        if (!hash_equals((string) $row['documentation_requirement_key'], $definition['key'])) {
            throw new RuntimeException('A published requirement stable key cannot be renamed');
        }
        $revision = intval($row['documentation_requirement_revision']);
        if ($expected_revision !== null && intval($expected_revision) !== $revision) {
            throw new RuntimeException('The requirement draft changed; refresh and try again');
        }
        if (hash_equals((string) $row['documentation_requirement_draft_definition'], $definition_json)) {
            documentationCommitMutation($caller_transaction);
            return ['requirement_id' => $requirement_id, 'revision' => $revision, 'changed' => false];
        }
        documentationDbQuery("UPDATE documentation_requirements SET
            documentation_requirement_draft_definition = '$definition_sql',
            documentation_requirement_updated_by = $actor_id,
            documentation_requirement_revision = documentation_requirement_revision + 1
            WHERE documentation_requirement_id = $requirement_id
            AND documentation_requirement_revision = $revision", 'Could not save the requirement draft');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The requirement draft changed; refresh and try again');
        }
        documentationCommitMutation($caller_transaction);
        return ['requirement_id' => $requirement_id, 'revision' => $revision + 1, 'changed' => true];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationPublishRequirement(
    $requirement_id,
    $expected_revision,
    $actor_id,
    $caller_transaction = false
) {
    global $mysqli;

    $requirement_id = intval($requirement_id);
    $actor_id = max(0, intval($actor_id));
    documentationRequireAuthoringActor($actor_id, $caller_transaction);
    documentationBeginMutation($caller_transaction);
    try {
        $row = mysqli_fetch_assoc(documentationDbQuery("SELECT * FROM documentation_requirements
            WHERE documentation_requirement_id = $requirement_id LIMIT 1 FOR UPDATE", 'Could not lock the requirement for publication'));
        if (!$row) {
            throw new RuntimeException('The documentation requirement no longer exists');
        }
        if ($row['documentation_requirement_lifecycle'] === 'Archived') {
            throw new RuntimeException('Archived documentation requirements cannot be published');
        }
        $revision = intval($row['documentation_requirement_revision']);
        if ($expected_revision !== null && intval($expected_revision) !== $revision) {
            throw new RuntimeException('The requirement draft changed; refresh and try again');
        }
        $draft = json_decode((string) $row['documentation_requirement_draft_definition'], true);
        if (!is_array($draft)) {
            throw new RuntimeException('The documentation requirement draft is invalid');
        }
        $draft = documentationCanonicalRequirementDefinition($draft);
        $errors = documentationValidateRequirementDefinition($draft);
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
        if (!hash_equals((string) $row['documentation_requirement_key'], $draft['key'])) {
            throw new RuntimeException('The requirement draft does not match its stable identity');
        }
        $hash = documentationRequirementDefinitionHash($draft);
        $hash_sql = mysqli_real_escape_string($mysqli, $hash);
        $existing = mysqli_fetch_assoc(documentationDbQuery("SELECT documentation_requirement_version_id,
            documentation_requirement_version_number FROM documentation_requirement_versions
            WHERE documentation_requirement_version_requirement_id = $requirement_id
            AND documentation_requirement_version_definition_hash = '$hash_sql'
            LIMIT 1", 'Could not find an existing published definition'));
        $created = false;
        if ($existing) {
            $version_id = intval($existing['documentation_requirement_version_id']);
            $version_number = intval($existing['documentation_requirement_version_number']);
        } else {
            $number_row = mysqli_fetch_row(documentationDbQuery("SELECT COALESCE(MAX(documentation_requirement_version_number), 0) + 1
                FROM documentation_requirement_versions
                WHERE documentation_requirement_version_requirement_id = $requirement_id", 'Could not allocate the requirement version number'));
            $version_number = max(1, intval($number_row[0] ?? 1));
            $columns = [
                'key', 'name', 'description', 'record_type', 'default_owner_role',
                'default_owner_user_id', 'default_reviewer_role', 'default_reviewer_user_id',
                'review_cadence_days', 'warning_window_days', 'blocks_readiness',
                'blocks_ticket_resolution', 'evidence_policy', 'exception_approval_policy',
                'applicability_mode',
            ];
            $values = [];
            foreach ($columns as $column) {
                $value = $draft[$column];
                $values[$column] = is_int($value)
                    ? (string) $value
                    : "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
            }
            documentationDbQuery("INSERT INTO documentation_requirement_versions SET
                documentation_requirement_version_requirement_id = $requirement_id,
                documentation_requirement_version_number = $version_number,
                documentation_requirement_version_definition_hash = '$hash_sql',
                documentation_requirement_version_key = {$values['key']},
                documentation_requirement_version_name = {$values['name']},
                documentation_requirement_version_description = {$values['description']},
                documentation_requirement_version_record_type = {$values['record_type']},
                documentation_requirement_version_default_owner_role = {$values['default_owner_role']},
                documentation_requirement_version_default_owner_user_id = {$values['default_owner_user_id']},
                documentation_requirement_version_default_reviewer_role = {$values['default_reviewer_role']},
                documentation_requirement_version_default_reviewer_user_id = {$values['default_reviewer_user_id']},
                documentation_requirement_version_review_cadence_days = {$values['review_cadence_days']},
                documentation_requirement_version_warning_window_days = {$values['warning_window_days']},
                documentation_requirement_version_blocks_readiness = {$values['blocks_readiness']},
                documentation_requirement_version_blocks_ticket_resolution = {$values['blocks_ticket_resolution']},
                documentation_requirement_version_evidence_policy = {$values['evidence_policy']},
                documentation_requirement_version_exception_approval_policy = {$values['exception_approval_policy']},
                documentation_requirement_version_applicability_mode = {$values['applicability_mode']},
                documentation_requirement_version_created_by = $actor_id", 'Could not publish the requirement version');
            $version_id = intval(mysqli_insert_id($mysqli));
            foreach ($draft['selectors'] as $order => $selector) {
                $dimension_sql = mysqli_real_escape_string($mysqli, $selector['dimension']);
                $value_sql = mysqli_real_escape_string($mysqli, $selector['value']);
                $selector_order = intval($order) + 1;
                documentationDbQuery("INSERT INTO documentation_requirement_version_selectors SET
                    documentation_selector_requirement_version_id = $version_id,
                    documentation_selector_dimension = '$dimension_sql',
                    documentation_selector_value = '$value_sql',
                    documentation_selector_order = $selector_order", 'Could not publish a requirement selector');
            }
            $created = true;
        }

        if (intval($row['documentation_requirement_published_version_id']) !== $version_id
            || $row['documentation_requirement_lifecycle'] !== 'Active') {
            documentationDbQuery("UPDATE documentation_requirements SET
                documentation_requirement_published_version_id = $version_id,
                documentation_requirement_lifecycle = 'Active',
                documentation_requirement_archived_at = NULL,
                documentation_requirement_updated_by = $actor_id,
                documentation_requirement_revision = documentation_requirement_revision + 1
                WHERE documentation_requirement_id = $requirement_id
                AND documentation_requirement_revision = $revision", 'Could not advance the published requirement pointer');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The requirement changed during publication; refresh and try again');
            }
            $revision++;
        }
        documentationCommitMutation($caller_transaction);
        return [
            'requirement_id' => $requirement_id,
            'version_id' => $version_id,
            'version_number' => $version_number,
            'revision' => $revision,
            'created' => $created,
        ];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationSetRequirementLifecycle($requirement_id, $expected_revision, $actor_id, $lifecycle, $caller_transaction = false) {
    global $mysqli;
    $requirement_id = intval($requirement_id);
    $actor_id = max(0, intval($actor_id));
    documentationRequireAuthoringActor($actor_id, $caller_transaction);
    if (!in_array($lifecycle, ['Archived', 'Restored'], true)) {
        throw new InvalidArgumentException('Invalid documentation requirement lifecycle transition');
    }
    documentationBeginMutation($caller_transaction);
    try {
        $row = mysqli_fetch_assoc(documentationDbQuery("SELECT * FROM documentation_requirements
            WHERE documentation_requirement_id = $requirement_id LIMIT 1 FOR UPDATE", 'Could not lock the requirement lifecycle'));
        if (!$row) {
            throw new RuntimeException('The documentation requirement no longer exists');
        }
        $revision = intval($row['documentation_requirement_revision']);
        if (intval($expected_revision) !== $revision) {
            throw new RuntimeException('The requirement changed; refresh and try again');
        }
        $next = $lifecycle === 'Archived'
            ? 'Archived'
            : (intval($row['documentation_requirement_published_version_id']) > 0 ? 'Active' : 'Draft');
        $archived = $lifecycle === 'Archived' ? 'NOW()' : 'NULL';
        if ($row['documentation_requirement_lifecycle'] !== $next) {
            documentationDbQuery("UPDATE documentation_requirements SET
                documentation_requirement_lifecycle = '$next',
                documentation_requirement_archived_at = $archived,
                documentation_requirement_updated_by = $actor_id,
                documentation_requirement_revision = documentation_requirement_revision + 1
                WHERE documentation_requirement_id = $requirement_id
                AND documentation_requirement_revision = $revision", 'Could not update the requirement lifecycle');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The requirement changed; refresh and try again');
            }
            $revision++;
        }
        documentationCommitMutation($caller_transaction);
        return ['requirement_id' => $requirement_id, 'lifecycle' => $next, 'revision' => $revision];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationArchiveRequirement($requirement_id, $expected_revision, $actor_id, $caller_transaction = false) {
    return documentationSetRequirementLifecycle($requirement_id, $expected_revision, $actor_id, 'Archived', $caller_transaction);
}

function documentationRestoreRequirement($requirement_id, $expected_revision, $actor_id, $caller_transaction = false) {
    return documentationSetRequirementLifecycle($requirement_id, $expected_revision, $actor_id, 'Restored', $caller_transaction);
}

function documentationClientApplicabilityFactsBatch(array $clients) {
    $normalized = [];
    foreach ($clients as $client) {
        $client_id = intval($client['client_id'] ?? 0);
        if ($client_id) {
            $normalized[$client_id] = $client;
        }
    }
    if (!$normalized) {
        return [];
    }
    ksort($normalized, SORT_NUMERIC);
    $facts = [];
    foreach ($normalized as $client_id => $client) {
        $facts[$client_id] = [
            'always' => ['any' => true],
            'active_contract' => [],
            'plan' => [],
            'service' => [],
            'service_category' => [],
            'asset_class' => [],
            'integration' => [],
            'client_type' => [],
        ];
        if (trim((string) ($client['client_type'] ?? '')) !== '') {
            $facts[$client_id]['client_type'][documentationNormalizeSelectorValue('client_type', $client['client_type'])] = true;
        }
    }
    $client_ids = implode(',', array_map('intval', array_keys($normalized)));
    $contracts = documentationDbQuery("SELECT contract_client_id, contract_name, contract_type FROM contracts
        WHERE contract_client_id IN ($client_ids) AND contract_archived_at IS NULL
        AND LOWER(TRIM(contract_status)) IN ('active','accepted','signed','executed','in force','in-force')
        AND (contract_start_date IS NULL OR contract_start_date <= CURRENT_DATE())
        AND (contract_end_date IS NULL OR contract_end_date >= CURRENT_DATE())", 'Could not evaluate client plans');
    while ($contract = mysqli_fetch_assoc($contracts)) {
        $client_id = intval($contract['contract_client_id']);
        $facts[$client_id]['active_contract']['any'] = true;
        foreach (['contract_name', 'contract_type'] as $field) {
            $value = documentationNormalizeSelectorValue('plan', $contract[$field] ?? '');
            if ($value !== 'any') {
                $facts[$client_id]['plan'][$value] = true;
            }
        }
    }
    $services = documentationDbQuery("SELECT service_client_id, service_name, service_category FROM services
        WHERE service_client_id IN ($client_ids)", 'Could not evaluate client services');
    while ($service = mysqli_fetch_assoc($services)) {
        $client_id = intval($service['service_client_id']);
        $name = documentationNormalizeSelectorValue('service', $service['service_name'] ?? '');
        $category = documentationNormalizeSelectorValue('service_category', $service['service_category'] ?? '');
        if ($name !== 'any') {
            $facts[$client_id]['service'][$name] = true;
        }
        if ($category !== 'any') {
            $facts[$client_id]['service_category'][$category] = true;
        }
    }
    $assets = documentationDbQuery("SELECT asset_client_id, asset_type FROM assets
        WHERE asset_client_id IN ($client_ids) AND asset_archived_at IS NULL", 'Could not evaluate client asset classes');
    while ($asset = mysqli_fetch_assoc($assets)) {
        $client_id = intval($asset['asset_client_id']);
        $value = documentationNormalizeSelectorValue('asset_class', $asset['asset_type'] ?? '');
        if ($value !== 'any') {
            $facts[$client_id]['asset_class'][$value] = true;
        }
    }
    $integrations = documentationDbQuery("SELECT DISTINCT automation_mapping_client_id, automation_mapping_source
        FROM automation_entity_mappings WHERE automation_mapping_client_id IN ($client_ids)
        AND automation_mapping_deleted_at IS NULL
        AND automation_mapping_state IN ('automatic','confirmed','mapped','active')", 'Could not evaluate client integration coverage');
    while ($integration = mysqli_fetch_assoc($integrations)) {
        $client_id = intval($integration['automation_mapping_client_id']);
        $value = documentationNormalizeSelectorValue('integration', $integration['automation_mapping_source'] ?? '');
        if ($value !== 'any') {
            $facts[$client_id]['integration'][$value] = true;
        }
    }
    return $facts;
}

function documentationClientApplicabilityFacts($client_id, $client = null) {
    $client_id = intval($client_id);
    $client = is_array($client) ? $client : ['client_id' => $client_id];
    $client['client_id'] = $client_id;
    $facts = documentationClientApplicabilityFactsBatch([$client]);
    return $facts[$client_id] ?? [];
}

function documentationRequirementApplies($selectors, $mode, $facts) {
    $matches = [];
    foreach ((array) $selectors as $selector) {
        $dimension = (string) ($selector['documentation_selector_dimension'] ?? $selector['dimension'] ?? '');
        $value = documentationNormalizeSelectorValue(
            $dimension,
            $selector['documentation_selector_value'] ?? $selector['value'] ?? ''
        );
        if (!in_array($dimension, documentationSelectorDimensions(), true)) {
            throw new RuntimeException('A published documentation selector is unsupported');
        }
        $matches[] = !empty($facts[$dimension][$value]);
    }
    if (!$matches) {
        return false;
    }
    return strtolower((string) $mode) === 'all'
        ? !in_array(false, $matches, true)
        : in_array(true, $matches, true);
}

function documentationBuildPendingObligationRow(array $client, array $requirement) {
    $client_id = intval($client['client_id'] ?? 0);
    $requirement_id = intval($requirement['documentation_requirement_id'] ?? 0);
    $version_id = intval($requirement['documentation_requirement_version_id'] ?? 0);
    if (!$client_id || !$requirement_id || !$version_id) {
        throw new InvalidArgumentException('A client and published requirement are required for a pending projection');
    }
    $row = [
        'documentation_obligation_id' => 0,
        'documentation_obligation_projection_pending' => 1,
        'documentation_obligation_client_id' => $client_id,
        'documentation_obligation_requirement_id' => $requirement_id,
        'documentation_obligation_requirement_version_id' => $version_id,
        'documentation_obligation_applicable' => 1,
        'documentation_obligation_base_status' => 'Missing',
        'documentation_obligation_owner_role' => $requirement['documentation_requirement_version_default_owner_role'],
        'documentation_obligation_owner_user_id' => intval($requirement['documentation_requirement_version_default_owner_user_id']),
        'documentation_obligation_reviewer_role' => $requirement['documentation_requirement_version_default_reviewer_role'],
        'documentation_obligation_reviewer_user_id' => intval($requirement['documentation_requirement_version_default_reviewer_user_id']),
        'documentation_obligation_document_id' => 0,
        'documentation_obligation_revision' => 0,
        'documentation_obligation_next_review_at' => null,
        'documentation_obligation_stale_at' => null,
        'documentation_obligation_evaluation_reason_code' => 'projection_pending',
        'documentation_obligation_exception_status' => null,
        'documentation_requirement_current_lifecycle' => 'Active',
        'documentation_requirement_current_version_id' => $version_id,
        'documentation_requirement_projection_valid' => 1,
        'documentation_verification_context_valid' => 1,
        'documentation_exception_record_valid' => 1,
        'current_document_exists' => 0,
        'current_document_hash' => '',
        'client_name' => (string) ($client['client_name'] ?? ''),
        'document_name' => null,
        'owner_name' => null,
        'reviewer_name' => null,
    ];
    foreach ([
        'number', 'key', 'name', 'description', 'record_type',
        'blocks_readiness', 'blocks_ticket_resolution',
        'review_cadence_days', 'warning_window_days',
        'evidence_policy', 'exception_approval_policy',
    ] as $field) {
        $row['documentation_current_requirement_version_' . $field]
            = $requirement['documentation_requirement_version_' . $field] ?? null;
    }
    return documentationApplyCurrentRequirementMetadata($row);
}

/**
 * Build read-only Missing projections for applicable published
 * requirements which do not yet have a durable client obligation. This closes
 * the publish-to-reconcile visibility gap without mutating state on a GET.
 * Pass zero for a complete result which the caller will paginate explicitly.
 */
function documentationPendingObligationRowsForClients(array $clients, $limit = 500) {
    static $cache = [];
    $limit = intval($limit);
    $bounded = $limit > 0;
    if ($bounded) {
        $limit = min(5000, $limit);
    }
    $normalized = [];
    foreach ($clients as $client) {
        $client_id = intval($client['client_id'] ?? 0);
        if ($client_id && empty($client['client_archived_at'])) {
            $normalized[$client_id] = $client;
        }
    }
    if (!$normalized) {
        return [];
    }
    ksort($normalized, SORT_NUMERIC);
    $cache_clients = [];
    foreach ($normalized as $client_id => $client) {
        $cache_clients[$client_id] = [
            'client_name' => (string) ($client['client_name'] ?? ''),
            'client_type' => (string) ($client['client_type'] ?? ''),
            'client_archived_at' => (string) ($client['client_archived_at'] ?? ''),
        ];
    }
    $cache_key = hash('sha256', serialize([$cache_clients, $limit]));
    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }
    $client_ids = implode(',', array_map('intval', array_keys($normalized)));
    $existing = [];
    $existing_rows = documentationDbQuery("SELECT documentation_obligation_client_id,
        documentation_obligation_requirement_id FROM client_documentation_obligations
        WHERE documentation_obligation_client_id IN ($client_ids)
        ORDER BY documentation_obligation_client_id, documentation_obligation_requirement_id", 'Could not load existing documentation projections');
    while ($existing_row = mysqli_fetch_assoc($existing_rows)) {
        $existing[intval($existing_row['documentation_obligation_client_id'])]
            [intval($existing_row['documentation_obligation_requirement_id'])] = true;
    }
    $requirements = documentationPublishedRequirementRows();
    $facts_by_client = documentationClientApplicabilityFactsBatch(array_values($normalized));
    $pending = [];
    foreach ($normalized as $client_id => $client) {
        foreach ($requirements as $requirement) {
            $requirement_id = intval($requirement['documentation_requirement_id']);
            if (isset($existing[$client_id][$requirement_id])
                || !documentationRequirementApplies(
                    $requirement['selectors'],
                    $requirement['documentation_requirement_version_applicability_mode'],
                    $facts_by_client[$client_id] ?? []
                )) {
                continue;
            }
            $pending[] = documentationBuildPendingObligationRow($client, $requirement);
            if ($bounded && count($pending) >= $limit) {
                $cache[$cache_key] = $pending;
                return $cache[$cache_key];
            }
        }
    }
    $cache[$cache_key] = $pending;
    return $cache[$cache_key];
}

function documentationDocumentState($document_id, $client_id, $for_update = false) {
    $document_id = intval($document_id);
    $client_id = intval($client_id);
    if (!$document_id) {
        return ['exists' => false, 'version_id' => 0, 'hash' => ''];
    }
    $lock_sql = $for_update ? ' FOR UPDATE' : '';
    $document = mysqli_fetch_assoc(documentationDbQuery("SELECT document_id, document_content_raw,
        document_content, document_archived_at,
        (SELECT COALESCE(MAX(document_version_id), 0) FROM document_versions
            WHERE document_version_document_id = documents.document_id) AS latest_version_id
        FROM documents WHERE document_id = $document_id
        AND document_client_id = $client_id LIMIT 1$lock_sql", 'Could not load the obligation document'));
    if (!$document || !empty($document['document_archived_at'])) {
        return ['exists' => false, 'version_id' => 0, 'hash' => ''];
    }
    $content = (string) ($document['document_content_raw'] ?? '');
    if ($content === '') {
        $content = (string) ($document['document_content'] ?? '');
    }
    return [
        'exists' => true,
        'version_id' => intval($document['latest_version_id'] ?? 0),
        'hash' => hash('sha256', $content),
    ];
}

function documentationRecordObligationEvent(
    $obligation,
    $action,
    $from_base_status,
    $to_base_status,
    $from_effective_status,
    $to_effective_status,
    $actor_type,
    $actor_id,
    $reason_code,
    $source_type = null,
    $source_id = 0,
    $context = []
) {
    global $mysqli;
    $obligation_id = intval($obligation['documentation_obligation_id'] ?? 0);
    $client_id = intval($obligation['documentation_obligation_client_id'] ?? 0);
    $version_id = intval($obligation['documentation_obligation_requirement_version_id'] ?? 0);
    $actions = [
        'created', 'evaluated', 'applicability_changed', 'freshness_changed', 'version_projected', 'ownership_changed',
        'document_linked', 'verified', 'verification_invalidated', 'exception_requested', 'exception_approved',
        'exception_rejected', 'exception_revoked', 'exception_expired', 'exception_superseded',
        'ticket_linked', 'ticket_link_updated',
        'promise_created', 'promise_fulfilled', 'promise_cancelled', 'promise_expired',
        'waiver_requested', 'waiver_approved', 'waiver_rejected', 'waiver_revoked', 'waiver_expired',
    ];
    if (!$obligation_id || !$client_id || !$version_id || !in_array($action, $actions, true)) {
        throw new RuntimeException('Invalid documentation obligation event');
    }
    $status_value = static function ($status) use ($mysqli) {
        return $status === null || $status === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($mysqli, substr((string) $status, 0, 20)) . "'";
    };
    $actor_type = in_array($actor_type, ['agent', 'automation', 'system'], true) ? $actor_type : 'system';
    $actor_type_sql = mysqli_real_escape_string($mysqli, $actor_type);
    $actor_id = max(0, intval($actor_id));
    $reason_code_sql = mysqli_real_escape_string($mysqli, documentationNormalizeKey($reason_code, 'unspecified', 60));
    $source_type_sql = $source_type === null || $source_type === ''
        ? 'NULL'
        : "'" . mysqli_real_escape_string($mysqli, documentationNormalizeKey($source_type, 'record', 40)) . "'";
    $source_id = max(0, intval($source_id));
    $context_hash = $context === [] || $context === null ? null : documentationAuditContextHash($context);
    $context_hash_sql = documentationSqlValue($context_hash);
    $action_sql = mysqli_real_escape_string($mysqli, $action);
    $from_base_sql = $status_value($from_base_status);
    $to_base_sql = $status_value($to_base_status);
    $from_effective_sql = $status_value($from_effective_status);
    $to_effective_sql = $status_value($to_effective_status);

    documentationDbQuery("INSERT INTO documentation_obligation_events SET
        documentation_obligation_event_obligation_id = $obligation_id,
        documentation_obligation_event_client_id = $client_id,
        documentation_obligation_event_requirement_version_id = $version_id,
        documentation_obligation_event_action = '$action_sql',
        documentation_obligation_event_from_base_status = $from_base_sql,
        documentation_obligation_event_to_base_status = $to_base_sql,
        documentation_obligation_event_from_effective_status = $from_effective_sql,
        documentation_obligation_event_to_effective_status = $to_effective_sql,
        documentation_obligation_event_actor_type = '$actor_type_sql',
        documentation_obligation_event_actor_id = $actor_id,
        documentation_obligation_event_reason_code = '$reason_code_sql',
        documentation_obligation_event_source_type = $source_type_sql,
        documentation_obligation_event_source_id = $source_id,
        documentation_obligation_event_context_hash = $context_hash_sql", 'Could not append the documentation obligation event');
}

function documentationRecordObligationExceptionEvent(
    $exception,
    $action,
    $from_status,
    $to_status,
    $actor_id,
    $reason_code,
    $context = []
) {
    global $mysqli;
    $exception_id = intval($exception['documentation_obligation_exception_id'] ?? 0);
    $obligation_id = intval($exception['documentation_obligation_exception_obligation_id'] ?? 0);
    $client_id = intval($exception['documentation_obligation_exception_client_id'] ?? 0);
    $version_id = intval($exception['documentation_obligation_exception_requirement_version_id'] ?? 0);
    if (!$exception_id || !$obligation_id || !$client_id || !$version_id
        || !in_array($action, ['requested', 'approved', 'rejected', 'revoked', 'expired', 'superseded'], true)) {
        throw new RuntimeException('Invalid documentation obligation exception event');
    }
    $status_sql = static function ($value) use ($mysqli) {
        return $value === null || $value === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($mysqli, substr((string) $value, 0, 20)) . "'";
    };
    $action_sql = mysqli_real_escape_string($mysqli, $action);
    $from_status_sql = $status_sql($from_status);
    $to_status_sql = $status_sql($to_status);
    $actor_id = max(0, intval($actor_id));
    $reason_code_sql = mysqli_real_escape_string($mysqli, documentationNormalizeKey($reason_code, 'unspecified', 60));
    $context_hash_sql = $context ? documentationSqlValue(documentationAuditContextHash($context)) : 'NULL';
    documentationDbQuery("INSERT INTO documentation_obligation_exception_events SET
        documentation_obligation_exception_event_exception_id = $exception_id,
        documentation_obligation_exception_event_obligation_id = $obligation_id,
        documentation_obligation_exception_event_client_id = $client_id,
        documentation_obligation_exception_event_requirement_version_id = $version_id,
        documentation_obligation_exception_event_action = '$action_sql',
        documentation_obligation_exception_event_from_status = $from_status_sql,
        documentation_obligation_exception_event_to_status = $to_status_sql,
        documentation_obligation_exception_event_actor_id = $actor_id,
        documentation_obligation_exception_event_reason_code = '$reason_code_sql',
        documentation_obligation_exception_event_context_hash = $context_hash_sql", 'Could not append the documentation obligation exception event');
}

function documentationLoadCurrentExceptionLocked($obligation, $required = false) {
    $exception_id = intval($obligation['documentation_obligation_exception_id'] ?? 0);
    if (!$exception_id) {
        if ($required) {
            throw new RuntimeException('The documentation obligation has no current exception record');
        }
        return null;
    }
    $obligation_id = intval($obligation['documentation_obligation_id'] ?? 0);
    $client_id = intval($obligation['documentation_obligation_client_id'] ?? 0);
    $exception = mysqli_fetch_assoc(documentationDbQuery("SELECT * FROM documentation_obligation_exceptions
        WHERE documentation_obligation_exception_id = $exception_id
        AND documentation_obligation_exception_obligation_id = $obligation_id
        AND documentation_obligation_exception_client_id = $client_id
        LIMIT 1 FOR UPDATE", 'Could not lock the current documentation obligation exception'));
    if (!$exception && $required) {
        throw new RuntimeException('The current documentation obligation exception record is unavailable');
    }
    return $exception ?: null;
}

function documentationObligationMutationIdentity($obligation_id) {
    $obligation_id = intval($obligation_id);
    $row = mysqli_fetch_assoc(documentationDbQuery("SELECT documentation_obligation_id,
        documentation_obligation_client_id, documentation_obligation_requirement_id,
        documentation_obligation_requirement_version_id
        FROM client_documentation_obligations WHERE documentation_obligation_id = $obligation_id LIMIT 1", 'Could not locate the documentation obligation'));
    if (!$row) {
        throw new RuntimeException('The documentation obligation no longer exists');
    }
    return $row;
}

function documentationObligationRequirementIsCurrent(array $obligation, array $requirement) {
    $requirement_id = intval($obligation['documentation_obligation_requirement_id'] ?? 0);
    $version_id = intval($obligation['documentation_obligation_requirement_version_id'] ?? 0);
    return $requirement_id > 0
        && $version_id > 0
        && intval($requirement['documentation_requirement_id'] ?? 0) === $requirement_id
        && (string) ($requirement['documentation_requirement_lifecycle'] ?? '') === 'Active'
        && empty($requirement['documentation_requirement_archived_at'])
        && intval($requirement['documentation_requirement_published_version_id'] ?? 0) === $version_id;
}

function documentationLoadObligationForMutation($obligation_id) {
    $obligation_id = intval($obligation_id);
    $identity = documentationObligationMutationIdentity($obligation_id);
    $client_id = intval($identity['documentation_obligation_client_id']);
    $requirement_id = intval($identity['documentation_obligation_requirement_id']);
    documentationLockClient($client_id);

    // Re-read after the client lock: the evaluator also takes this coarse lock,
    // so the root/version identity is now stable for the rest of the mutation.
    $locked_identity = documentationObligationMutationIdentity($obligation_id);
    if (intval($locked_identity['documentation_obligation_client_id']) !== $client_id
        || intval($locked_identity['documentation_obligation_requirement_id']) !== $requirement_id) {
        throw new RuntimeException('The documentation obligation changed; refresh and try again');
    }

    $requirement = mysqli_fetch_assoc(documentationDbQuery("SELECT * FROM documentation_requirements
        WHERE documentation_requirement_id = $requirement_id
        ORDER BY documentation_requirement_id FOR UPDATE", 'Could not lock the active documentation requirement'));
    if (!$requirement || !documentationObligationRequirementIsCurrent($locked_identity, $requirement)) {
        throw new RuntimeException('The published documentation requirement changed; refresh and reconcile this client before retrying');
    }

    $obligation = mysqli_fetch_assoc(documentationDbQuery("SELECT obligation.*, version.*
        FROM client_documentation_obligations obligation
        INNER JOIN documentation_requirement_versions version
            ON version.documentation_requirement_version_id = obligation.documentation_obligation_requirement_version_id
            AND version.documentation_requirement_version_requirement_id = obligation.documentation_obligation_requirement_id
        WHERE obligation.documentation_obligation_id = $obligation_id LIMIT 1 FOR UPDATE", 'Could not lock the documentation obligation'));
    if (!$obligation
        || intval($obligation['documentation_obligation_client_id']) !== $client_id
        || !documentationObligationRequirementIsCurrent($obligation, $requirement)) {
        throw new RuntimeException('The published documentation requirement changed; refresh and reconcile this client before retrying');
    }
    return $obligation;
}

function documentationObligationClientId($obligation_id) {
    $identity = documentationObligationMutationIdentity($obligation_id);
    return intval($identity['documentation_obligation_client_id']);
}

function documentationPublishedRequirementRows($for_update = false) {
    $lock_sql = $for_update ? ' FOR UPDATE' : '';
    $requirements = [];
    $rows = documentationDbQuery("SELECT requirement.documentation_requirement_id,
        requirement.documentation_requirement_lifecycle, version.*
        FROM documentation_requirements requirement
        INNER JOIN documentation_requirement_versions version
            ON version.documentation_requirement_version_id = requirement.documentation_requirement_published_version_id
            AND version.documentation_requirement_version_requirement_id = requirement.documentation_requirement_id
        WHERE requirement.documentation_requirement_lifecycle = 'Active'
        ORDER BY requirement.documentation_requirement_id$lock_sql", 'Could not load published documentation requirements');
    while ($row = mysqli_fetch_assoc($rows)) {
        $version_id = intval($row['documentation_requirement_version_id']);
        $row['selectors'] = [];
        $requirements[$version_id] = $row;
    }
    if (!$requirements) {
        return [];
    }
    $version_ids = implode(',', array_map('intval', array_keys($requirements)));
    $selectors = documentationDbQuery("SELECT * FROM documentation_requirement_version_selectors
        WHERE documentation_selector_requirement_version_id IN ($version_ids)
        ORDER BY documentation_selector_requirement_version_id, documentation_selector_order, documentation_selector_id", 'Could not load published documentation selectors');
    while ($selector = mysqli_fetch_assoc($selectors)) {
        $requirements[intval($selector['documentation_selector_requirement_version_id'])]['selectors'][] = $selector;
    }
    return array_values($requirements);
}

function documentationEvaluateClient($client_id, $actor_id = 0, $caller_transaction = false, $now = null) {
    global $mysqli;
    $client_id = intval($client_id);
    $actor_id = max(0, intval($actor_id));
    $now_timestamp = $now === null ? time() : (is_numeric($now) ? intval($now) : strtotime((string) $now));
    if (!$now_timestamp) {
        throw new InvalidArgumentException('The documentation evaluation timestamp is invalid');
    }
    $now_string = date('Y-m-d H:i:s', $now_timestamp);
    documentationBeginMutation($caller_transaction);
    try {
        $client = documentationLockClient($client_id);
        $facts = documentationClientApplicabilityFacts($client_id, $client);
        $requirements = documentationPublishedRequirementRows(true);
        $existing = [];
        $rows = documentationDbQuery("SELECT * FROM client_documentation_obligations
            WHERE documentation_obligation_client_id = $client_id
            ORDER BY documentation_obligation_id FOR UPDATE", 'Could not lock the client documentation projection');
        while ($row = mysqli_fetch_assoc($rows)) {
            $existing[intval($row['documentation_obligation_requirement_id'])] = $row;
        }
        $summary = ['created' => 0, 'changed' => 0, 'unchanged' => 0, 'expired_exceptions' => 0];
        $active_requirement_ids = [];

        foreach ($requirements as $requirement) {
            $requirement_id = intval($requirement['documentation_requirement_id']);
            $version_id = intval($requirement['documentation_requirement_version_id']);
            $active_requirement_ids[$requirement_id] = true;
            $applicable = documentationRequirementApplies(
                $requirement['selectors'],
                $requirement['documentation_requirement_version_applicability_mode'],
                $facts
            );
            $obligation = $existing[$requirement_id] ?? null;
            $version_changed = $obligation
                && intval($obligation['documentation_obligation_requirement_version_id']) !== $version_id;
            $document_id = intval($obligation['documentation_obligation_document_id'] ?? 0);
            $document_state = documentationDocumentState($document_id, $client_id);
            $projection = documentationFreshnessProjection([
                'applicable' => $applicable,
                'document_exists' => $document_state['exists'],
                'verification_context_valid' => !$version_changed,
                'last_verified_at' => $version_changed ? null : ($obligation['documentation_obligation_last_verified_at'] ?? null),
                'review_cadence_days' => intval($requirement['documentation_requirement_version_review_cadence_days']),
                'warning_window_days' => intval($requirement['documentation_requirement_version_warning_window_days']),
                'verified_document_hash' => $version_changed ? '' : ($obligation['documentation_obligation_verification_document_hash'] ?? ''),
                'current_document_hash' => $document_state['hash'],
            ], $now_string);

            if (!$obligation) {
                $status_sql = mysqli_real_escape_string($mysqli, $projection['base_status']);
                $reason_sql = mysqli_real_escape_string($mysqli, $projection['reason_code']);
                $owner_role_sql = mysqli_real_escape_string($mysqli, $requirement['documentation_requirement_version_default_owner_role']);
                $reviewer_role_sql = mysqli_real_escape_string($mysqli, $requirement['documentation_requirement_version_default_reviewer_role']);
                $owner_id = intval($requirement['documentation_requirement_version_default_owner_user_id']);
                $reviewer_id = intval($requirement['documentation_requirement_version_default_reviewer_user_id']);
                documentationDbQuery("INSERT INTO client_documentation_obligations SET
                    documentation_obligation_client_id = $client_id,
                    documentation_obligation_requirement_id = $requirement_id,
                    documentation_obligation_requirement_version_id = $version_id,
                    documentation_obligation_applicable = " . ($applicable ? '1' : '0') . ",
                    documentation_obligation_base_status = '$status_sql',
                    documentation_obligation_owner_role = '$owner_role_sql',
                    documentation_obligation_owner_user_id = $owner_id,
                    documentation_obligation_reviewer_role = '$reviewer_role_sql',
                    documentation_obligation_reviewer_user_id = $reviewer_id,
                    documentation_obligation_next_review_at = " . documentationSqlValue($projection['next_review_at']) . ",
                    documentation_obligation_stale_at = " . documentationSqlValue($projection['stale_at']) . ",
                    documentation_obligation_evaluation_reason_code = '$reason_sql',
                    documentation_obligation_evaluated_at = " . documentationSqlValue($now_string), 'Could not create the client documentation obligation');
                $obligation_id = intval(mysqli_insert_id($mysqli));
                $obligation = mysqli_fetch_assoc(documentationDbQuery("SELECT * FROM client_documentation_obligations
                    WHERE documentation_obligation_id = $obligation_id LIMIT 1", 'Could not reload the new documentation obligation'));
                documentationRecordObligationEvent(
                    $obligation,
                    'created',
                    null,
                    $projection['base_status'],
                    null,
                    documentationObligationEffectiveStatus($obligation, $now_string),
                    'system',
                    $actor_id,
                    $projection['reason_code']
                );
                $summary['created']++;
                continue;
            }

            $old_base = (string) $obligation['documentation_obligation_base_status'];
            $old_effective = documentationObligationEffectiveStatus($obligation, $now_string);
            $exception_transition = null;
            $current_exception = documentationLoadCurrentExceptionLocked(
                $obligation,
                intval($obligation['documentation_obligation_exception_id'] ?? 0) > 0
            );
            if ($current_exception) {
                $current_exception_status = (string) $current_exception['documentation_obligation_exception_status'];
                $exception_is_open = in_array($current_exception_status, ['Pending', 'Approved'], true);
                $exception_is_expired = $exception_is_open
                    && strtotime((string) $current_exception['documentation_obligation_exception_expires_at']) <= $now_timestamp;
                if ($exception_is_expired || ($version_changed && $exception_is_open)) {
                    $exception_transition = $exception_is_expired ? 'Expired' : 'Superseded';
                    $exception_id = intval($current_exception['documentation_obligation_exception_id']);
                    $exception_revision = intval($current_exception['documentation_obligation_exception_revision']);
                    $transition_sql = mysqli_real_escape_string($mysqli, $exception_transition);
                    $terminal_assignment = $exception_transition === 'Expired'
                        ? ', documentation_obligation_exception_expired_at = ' . documentationSqlValue($now_string)
                        : ', documentation_obligation_exception_decided_by = ' . $actor_id
                            . ', documentation_obligation_exception_decided_at = ' . documentationSqlValue($now_string);
                    documentationDbQuery("UPDATE documentation_obligation_exceptions SET
                        documentation_obligation_exception_status = '$transition_sql',
                        documentation_obligation_exception_revision = documentation_obligation_exception_revision + 1
                        $terminal_assignment
                        WHERE documentation_obligation_exception_id = $exception_id
                        AND documentation_obligation_exception_revision = $exception_revision
                        AND documentation_obligation_exception_status IN ('Pending','Approved')", 'Could not transition the documentation exception during evaluation');
                    if (mysqli_affected_rows($mysqli) !== 1) {
                        throw new RuntimeException('The documentation exception changed during evaluation');
                    }
                    documentationRecordObligationExceptionEvent(
                        $current_exception,
                        strtolower($exception_transition),
                        $current_exception_status,
                        $exception_transition,
                        $actor_id,
                        'exception_' . strtolower($exception_transition),
                        $version_changed ? ['next_requirement_version_id' => $version_id] : []
                    );
                }
            }
            $expired_exception = $exception_transition === 'Expired';
            $substantive_change = $version_changed
                || intval($obligation['documentation_obligation_applicable']) !== ($applicable ? 1 : 0)
                || $old_base !== $projection['base_status']
                || (string) $obligation['documentation_obligation_evaluation_reason_code'] !== $projection['reason_code']
                || (string) ($obligation['documentation_obligation_next_review_at'] ?? '') !== (string) ($projection['next_review_at'] ?? '')
                || (string) ($obligation['documentation_obligation_stale_at'] ?? '') !== (string) ($projection['stale_at'] ?? '')
                || $expired_exception;
            if (!$substantive_change) {
                documentationDbQuery("UPDATE client_documentation_obligations SET
                    documentation_obligation_evaluated_at = " . documentationSqlValue($now_string) . "
                    WHERE documentation_obligation_id = " . intval($obligation['documentation_obligation_id']), 'Could not timestamp the documentation evaluation');
                $summary['unchanged']++;
                continue;
            }

            $revision = intval($obligation['documentation_obligation_revision']);
            $status_sql = mysqli_real_escape_string($mysqli, $projection['base_status']);
            $reason_sql = mysqli_real_escape_string($mysqli, $projection['reason_code']);
            $exception_assignment = '';
            if ($version_changed) {
                $exception_assignment = ",
                    documentation_obligation_last_verified_at = NULL,
                    documentation_obligation_verification_source = NULL,
                    documentation_obligation_verification_evidence_id = 0,
                    documentation_obligation_verification_document_version_id = 0,
                    documentation_obligation_verification_document_hash = NULL,
                    documentation_obligation_verification_ticket_id = 0,
                    documentation_obligation_exception_id = 0,
                    documentation_obligation_exception_status = NULL,
                    documentation_obligation_exception_reason_redacted = NULL,
                    documentation_obligation_exception_reason_hash = NULL,
                    documentation_obligation_exception_requested_by = 0,
                    documentation_obligation_exception_requested_at = NULL,
                    documentation_obligation_exception_decided_by = 0,
                    documentation_obligation_exception_decided_at = NULL,
                    documentation_obligation_exception_expires_at = NULL,
                    documentation_obligation_exception_expired_event_at = NULL";
            } elseif ($expired_exception) {
                $exception_assignment = ",
                    documentation_obligation_exception_status = 'Expired',
                    documentation_obligation_exception_expired_event_at = " . documentationSqlValue($now_string);
            }
            documentationDbQuery("UPDATE client_documentation_obligations SET
                documentation_obligation_requirement_version_id = $version_id,
                documentation_obligation_applicable = " . ($applicable ? '1' : '0') . ",
                documentation_obligation_base_status = '$status_sql',
                documentation_obligation_next_review_at = " . documentationSqlValue($projection['next_review_at']) . ",
                documentation_obligation_stale_at = " . documentationSqlValue($projection['stale_at']) . ",
                documentation_obligation_evaluation_reason_code = '$reason_sql',
                documentation_obligation_evaluated_at = " . documentationSqlValue($now_string) . ",
                documentation_obligation_revision = documentation_obligation_revision + 1
                $exception_assignment
                WHERE documentation_obligation_id = " . intval($obligation['documentation_obligation_id']) . "
                AND documentation_obligation_revision = $revision", 'Could not update the client documentation obligation');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The documentation obligation changed during evaluation');
            }
            $obligation['documentation_obligation_requirement_version_id'] = $version_id;
            $obligation['documentation_obligation_applicable'] = $applicable ? 1 : 0;
            $obligation['documentation_obligation_base_status'] = $projection['base_status'];
            if ($version_changed) {
                $obligation['documentation_obligation_last_verified_at'] = null;
                $obligation['documentation_obligation_verification_evidence_id'] = 0;
                $obligation['documentation_obligation_verification_document_hash'] = null;
                $obligation['documentation_obligation_exception_id'] = 0;
                $obligation['documentation_obligation_exception_status'] = null;
                $obligation['documentation_obligation_exception_expires_at'] = null;
            }
            if ($expired_exception) {
                $obligation['documentation_obligation_exception_status'] = 'Expired';
                $obligation['documentation_obligation_exception_expired_event_at'] = $now_string;
                documentationRecordObligationEvent(
                    $obligation,
                    'exception_expired',
                    $old_base,
                    $projection['base_status'],
                    'Exception',
                    $projection['base_status'],
                    'system',
                    $actor_id,
                    'exception_expired'
                );
                $summary['expired_exceptions']++;
            }
            $action = $version_changed
                ? 'version_projected'
                : (intval($existing[$requirement_id]['documentation_obligation_applicable']) !== ($applicable ? 1 : 0)
                    ? 'applicability_changed' : 'freshness_changed');
            documentationRecordObligationEvent(
                $obligation,
                $action,
                $old_base,
                $projection['base_status'],
                $old_effective,
                documentationObligationEffectiveStatus($obligation, $now_string),
                'system',
                $actor_id,
                $projection['reason_code'],
                $version_changed ? 'requirement-version' : null,
                $version_changed ? intval($existing[$requirement_id]['documentation_obligation_requirement_version_id']) : 0,
                $version_changed ? [
                    'next_requirement_version_id' => $version_id,
                    'verification_cleared' => 1,
                    'exception_cleared' => 1,
                ] : []
            );
            $summary['changed']++;
        }

        foreach ($existing as $requirement_id => $obligation) {
            if (isset($active_requirement_ids[$requirement_id])) {
                continue;
            }
            if ($obligation['documentation_obligation_base_status'] === 'Not Applicable'
                && intval($obligation['documentation_obligation_applicable']) === 0
                && $obligation['documentation_obligation_evaluation_reason_code'] === 'requirement_inactive') {
                documentationDbQuery("UPDATE client_documentation_obligations SET
                    documentation_obligation_evaluated_at = " . documentationSqlValue($now_string) . "
                    WHERE documentation_obligation_id = " . intval($obligation['documentation_obligation_id']), 'Could not timestamp an inactive documentation obligation');
                $summary['unchanged']++;
                continue;
            }
            $revision = intval($obligation['documentation_obligation_revision']);
            $old_base = $obligation['documentation_obligation_base_status'];
            $old_effective = documentationObligationEffectiveStatus($obligation, $now_string);
            documentationDbQuery("UPDATE client_documentation_obligations SET
                documentation_obligation_applicable = 0,
                documentation_obligation_base_status = 'Not Applicable',
                documentation_obligation_next_review_at = NULL,
                documentation_obligation_stale_at = NULL,
                documentation_obligation_evaluation_reason_code = 'requirement_inactive',
                documentation_obligation_evaluated_at = " . documentationSqlValue($now_string) . ",
                documentation_obligation_revision = documentation_obligation_revision + 1
                WHERE documentation_obligation_id = " . intval($obligation['documentation_obligation_id']) . "
                AND documentation_obligation_revision = $revision", 'Could not retire an inactive documentation obligation');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The documentation obligation changed during retirement');
            }
            $obligation['documentation_obligation_applicable'] = 0;
            $obligation['documentation_obligation_base_status'] = 'Not Applicable';
            documentationRecordObligationEvent(
                $obligation,
                'applicability_changed',
                $old_base,
                'Not Applicable',
                $old_effective,
                'Not Applicable',
                'system',
                $actor_id,
                'requirement_inactive'
            );
            $summary['changed']++;
        }

        documentationCommitMutation($caller_transaction);
        return $summary;
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationEvaluateDueClients($limit = 100) {
    $limit = max(1, min(1000, intval($limit)));
    $client_ids = [];
    $rows = documentationDbQuery("SELECT client.client_id,
        MAX(obligation.documentation_obligation_evaluated_at) AS last_evaluation
        FROM clients client
        LEFT JOIN client_documentation_obligations obligation
            ON obligation.documentation_obligation_client_id = client.client_id
        WHERE client.client_archived_at IS NULL AND client.client_lead = 0
        GROUP BY client.client_id
        ORDER BY MAX(obligation.documentation_obligation_evaluated_at) IS NULL DESC,
            MAX(obligation.documentation_obligation_evaluated_at) ASC, client.client_id ASC
        LIMIT $limit", 'Could not select clients for documentation evaluation');
    while ($row = mysqli_fetch_assoc($rows)) {
        $client_ids[] = intval($row['client_id']);
    }
    $summary = ['clients' => 0, 'created' => 0, 'changed' => 0, 'unchanged' => 0,
        'expired_exceptions' => documentationExpireObligationExceptions($limit), 'failed' => 0];
    foreach ($client_ids as $client_id) {
        try {
            $client_summary = documentationEvaluateClient($client_id, 0, false);
            $summary['clients']++;
            foreach (['created', 'changed', 'unchanged', 'expired_exceptions'] as $key) {
                $summary[$key] += intval($client_summary[$key] ?? 0);
            }
        } catch (Throwable $e) {
            $summary['failed']++;
            error_log("Documentation evaluation failed for client $client_id: " . $e->getMessage());
        }
    }
    return $summary;
}

function documentationClientReadiness($client_id, $now = null) {
    $client_id = intval($client_id);
    $obligations = [];
    $client = mysqli_fetch_assoc(documentationDbQuery("SELECT client_id, client_type, client_archived_at
        FROM clients WHERE client_id = $client_id LIMIT 1", 'Could not load the documentation readiness client'));
    if (!$client || !empty($client['client_archived_at'])) {
        return documentationReadinessReduce([], $now);
    }
    $validity = documentationObligationValiditySql('obligation');
    $rows = documentationDbQuery("SELECT obligation.*, {$validity['select']}
        FROM client_documentation_obligations obligation
        {$validity['joins']}
        WHERE obligation.documentation_obligation_client_id = $client_id
        ORDER BY documentation_validity_version.documentation_requirement_version_name", 'Could not calculate client documentation readiness');
    while ($row = mysqli_fetch_assoc($rows)) {
        $obligations[] = documentationApplyCurrentRequirementMetadata($row);
    }
    $obligations = array_merge($obligations, documentationPendingObligationRowsForClients([$client], 0));
    return documentationReadinessReduce($obligations, $now);
}

function documentationReadinessForClient($client_id, $now = null) {
    return documentationClientReadiness($client_id, $now);
}

function documentationAssignObligationOwners(
    $obligation_id,
    $owner_role,
    $owner_user_id,
    $reviewer_role,
    $reviewer_user_id,
    $expected_revision,
    $actor_id,
    $caller_transaction = false
) {
    global $mysqli;
    $actor_id = intval($actor_id);
    documentationRequireSupportLevel($actor_id, 2);
    $owner_role = documentationNormalizeChoiceToken($owner_role, 'documentation_owner', 40);
    $reviewer_role = documentationNormalizeChoiceToken($reviewer_role, 'support_lead', 40);
    $owner_user_id = max(0, intval($owner_user_id));
    $reviewer_user_id = max(0, intval($reviewer_user_id));
    if (!in_array($owner_role, documentationOwnerRoles(), true)
        || !in_array($reviewer_role, documentationOwnerRoles(), true)) {
        throw new InvalidArgumentException('Unsupported documentation owner or reviewer role');
    }
    if (($owner_user_id && !documentationAgentHasSupportLevel($owner_user_id, 1))
        || ($reviewer_user_id && !documentationAgentHasSupportLevel($reviewer_user_id, 1))) {
        throw new RuntimeException('Documentation owners and reviewers must be active internal users');
    }
    documentationBeginMutation($caller_transaction);
    try {
        $obligation = documentationLoadObligationForMutation($obligation_id);
        $revision = intval($obligation['documentation_obligation_revision']);
        if (intval($expected_revision) !== $revision) {
            throw new RuntimeException('The documentation obligation changed; refresh and try again');
        }
        $unchanged = $obligation['documentation_obligation_owner_role'] === $owner_role
            && intval($obligation['documentation_obligation_owner_user_id']) === $owner_user_id
            && $obligation['documentation_obligation_reviewer_role'] === $reviewer_role
            && intval($obligation['documentation_obligation_reviewer_user_id']) === $reviewer_user_id;
        if ($unchanged) {
            documentationCommitMutation($caller_transaction);
            return ['obligation_id' => intval($obligation_id), 'revision' => $revision, 'changed' => false];
        }
        $owner_role_sql = mysqli_real_escape_string($mysqli, $owner_role);
        $reviewer_role_sql = mysqli_real_escape_string($mysqli, $reviewer_role);
        documentationDbQuery("UPDATE client_documentation_obligations SET
            documentation_obligation_owner_role = '$owner_role_sql',
            documentation_obligation_owner_user_id = $owner_user_id,
            documentation_obligation_reviewer_role = '$reviewer_role_sql',
            documentation_obligation_reviewer_user_id = $reviewer_user_id,
            documentation_obligation_revision = documentation_obligation_revision + 1
            WHERE documentation_obligation_id = " . intval($obligation_id) . "
            AND documentation_obligation_revision = $revision", 'Could not assign the documentation obligation owners');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The documentation obligation changed; refresh and try again');
        }
        documentationRecordObligationEvent(
            $obligation,
            'ownership_changed',
            $obligation['documentation_obligation_base_status'],
            $obligation['documentation_obligation_base_status'],
            documentationObligationEffectiveStatus($obligation),
            documentationObligationEffectiveStatus($obligation),
            'agent',
            $actor_id,
            'ownership_changed',
            'obligation',
            intval($obligation_id),
            [
                'owner_role' => $owner_role,
                'owner_user_id' => $owner_user_id,
                'reviewer_role' => $reviewer_role,
                'reviewer_user_id' => $reviewer_user_id,
            ]
        );
        documentationCommitMutation($caller_transaction);
        return ['obligation_id' => intval($obligation_id), 'revision' => $revision + 1, 'changed' => true];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationServiceReviewReadiness($client_id) {
    $readiness = documentationReadinessForClient($client_id);
    $counts = $readiness['counts'];
    return [
        'available' => true,
        'readiness_percent' => $readiness['score_percent'],
        'current' => intval($counts['Current'] ?? 0),
        'due_soon' => intval($counts['Due Soon'] ?? 0),
        'stale' => intval($counts['Stale'] ?? 0),
        'missing' => intval($counts['Missing'] ?? 0) + intval($counts['Draft'] ?? 0),
        'exceptions' => intval($counts['Exception'] ?? 0),
        'source' => 'documentation_obligations',
        'note' => $readiness['denominator'] > 0
            ? 'Derived from applicable readiness-blocking documentation obligations.'
            : 'No applicable readiness-blocking documentation obligations.',
    ];
}

function documentationLinkObligationDocument(
    $obligation_id,
    $document_id,
    $expected_revision,
    $actor_id,
    $ticket_id = 0,
    $caller_transaction = false
) {
    global $mysqli;
    $obligation_id = intval($obligation_id);
    $document_id = intval($document_id);
    $actor_id = intval($actor_id);
    $ticket_id = max(0, intval($ticket_id));
    documentationRequireSupportLevel($actor_id, 2);
    documentationBeginMutation($caller_transaction);
    try {
        $expected_client_id = documentationObligationClientId($obligation_id);
        $ticket = null;
        if ($ticket_id) {
            $ticket = documentationLockClientTicket($ticket_id, $expected_client_id);
        } else {
            documentationLockClient($expected_client_id);
        }
        $obligation = documentationLoadObligationForMutation($obligation_id);
        if ($ticket && intval($ticket['ticket_client_id']) !== intval($obligation['documentation_obligation_client_id'])) {
            throw new RuntimeException('The document-link ticket belongs to another client');
        }
        if ($ticket) {
            $ticket_client_id = intval($ticket['ticket_client_id']);
            $ticket_link = mysqli_fetch_assoc(documentationDbQuery("SELECT ticket_documentation_obligation_id
                FROM ticket_documentation_obligations
                WHERE ticket_documentation_obligation_ticket_id = $ticket_id
                AND ticket_documentation_obligation_obligation_id = $obligation_id
                AND ticket_documentation_obligation_client_id = $ticket_client_id
                LIMIT 1 FOR UPDATE", 'Could not lock the document-link ticket obligation'));
            if (!$ticket_link) {
                throw new RuntimeException('A ticket-scoped document link requires a linked obligation');
            }
        }
        $revision = intval($obligation['documentation_obligation_revision']);
        if (intval($expected_revision) !== $revision) {
            throw new RuntimeException('The documentation obligation changed; refresh and try again');
        }
        $document_state = documentationDocumentState($document_id, intval($obligation['documentation_obligation_client_id']), true);
        if (!$document_state['exists']) {
            throw new RuntimeException('The selected document is unavailable for this client');
        }
        if (intval($obligation['documentation_obligation_document_id']) === $document_id) {
            documentationCommitMutation($caller_transaction);
            return ['obligation_id' => $obligation_id, 'revision' => $revision, 'changed' => false];
        }
        $old_base = $obligation['documentation_obligation_base_status'];
        $old_effective = documentationObligationEffectiveStatus($obligation);
        documentationDbQuery("UPDATE client_documentation_obligations SET
            documentation_obligation_document_id = $document_id,
            documentation_obligation_base_status = 'Draft',
            documentation_obligation_last_verified_at = NULL,
            documentation_obligation_next_review_at = NULL,
            documentation_obligation_stale_at = NULL,
            documentation_obligation_verification_source = NULL,
            documentation_obligation_verification_evidence_id = 0,
            documentation_obligation_verification_document_version_id = 0,
            documentation_obligation_verification_document_hash = NULL,
            documentation_obligation_verification_ticket_id = 0,
            documentation_obligation_evaluation_reason_code = 'awaiting_verification',
            documentation_obligation_evaluated_at = NOW(),
            documentation_obligation_revision = documentation_obligation_revision + 1
            WHERE documentation_obligation_id = $obligation_id
            AND documentation_obligation_revision = $revision", 'Could not link the obligation document');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The documentation obligation changed; refresh and try again');
        }
        $obligation['documentation_obligation_document_id'] = $document_id;
        $obligation['documentation_obligation_base_status'] = 'Draft';
        documentationRecordObligationEvent(
            $obligation,
            'document_linked',
            $old_base,
            'Draft',
            $old_effective,
            documentationObligationEffectiveStatus($obligation),
            'agent',
            $actor_id,
            'document_linked',
            $ticket_id ? 'ticket' : 'document',
            $ticket_id ?: $document_id,
            ['document_id' => $document_id]
        );
        documentationCommitMutation($caller_transaction);
        return ['obligation_id' => $obligation_id, 'revision' => $revision + 1, 'changed' => true];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationInvalidateDocumentLocked(
    $document_id,
    $client_id,
    $actor_id = 0,
    $reason_code = 'document_changed'
) {
    global $mysqli;
    $document_id = intval($document_id);
    $client_id = intval($client_id);
    $actor_id = max(0, intval($actor_id));
    $reason_code = documentationNormalizeChoiceToken($reason_code, 'document_changed', 60);
    if (!$document_id || !$client_id
        || !in_array($reason_code, ['document_changed', 'document_archived', 'document_deleted'], true)) {
        throw new InvalidArgumentException('A valid document invalidation context is required');
    }
    documentationLockClient($client_id);
    $obligations = [];
    $rows = documentationDbQuery("SELECT obligation.*, version.*
        FROM client_documentation_obligations obligation
        INNER JOIN documentation_requirement_versions version
            ON version.documentation_requirement_version_id = obligation.documentation_obligation_requirement_version_id
        WHERE obligation.documentation_obligation_client_id = $client_id
        AND obligation.documentation_obligation_document_id = $document_id
        ORDER BY obligation.documentation_obligation_id FOR UPDATE", 'Could not lock documentation obligations for document invalidation');
    while ($row = mysqli_fetch_assoc($rows)) {
        $obligations[] = $row;
    }
    $document_state = documentationDocumentState($document_id, $client_id, true);
    if (!$document_state['exists']) {
        throw new RuntimeException('The document to invalidate is unavailable for this client');
    }
    $invalidated = 0;
    foreach ($obligations as $obligation) {
        $has_verification = !empty($obligation['documentation_obligation_last_verified_at'])
            || intval($obligation['documentation_obligation_verification_evidence_id']) > 0
            || trim((string) $obligation['documentation_obligation_verification_document_hash']) !== '';
        if (!$has_verification && in_array($obligation['documentation_obligation_base_status'], ['Draft', 'Not Applicable'], true)) {
            continue;
        }
        $obligation_id = intval($obligation['documentation_obligation_id']);
        $revision = intval($obligation['documentation_obligation_revision']);
        $old_base = (string) $obligation['documentation_obligation_base_status'];
        $old_effective = documentationObligationEffectiveStatus($obligation);
        $new_base = empty($obligation['documentation_obligation_applicable']) ? 'Not Applicable' : 'Draft';
        $new_base_sql = mysqli_real_escape_string($mysqli, $new_base);
        $reason_sql = mysqli_real_escape_string($mysqli, $reason_code);
        documentationDbQuery("UPDATE client_documentation_obligations SET
            documentation_obligation_base_status = '$new_base_sql',
            documentation_obligation_last_verified_at = NULL,
            documentation_obligation_next_review_at = NULL,
            documentation_obligation_stale_at = NULL,
            documentation_obligation_verification_source = NULL,
            documentation_obligation_verification_evidence_id = 0,
            documentation_obligation_verification_document_version_id = 0,
            documentation_obligation_verification_document_hash = NULL,
            documentation_obligation_verification_ticket_id = 0,
            documentation_obligation_evaluation_reason_code = '$reason_sql',
            documentation_obligation_evaluated_at = NOW(),
            documentation_obligation_revision = documentation_obligation_revision + 1
            WHERE documentation_obligation_id = $obligation_id
            AND documentation_obligation_revision = $revision", 'Could not invalidate the documentation verification');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The documentation obligation changed during document invalidation');
        }
        $obligation['documentation_obligation_base_status'] = $new_base;
        $obligation['documentation_obligation_last_verified_at'] = null;
        $obligation['documentation_obligation_verification_evidence_id'] = 0;
        $obligation['documentation_obligation_verification_document_hash'] = null;
        documentationRecordObligationEvent(
            $obligation,
            'verification_invalidated',
            $old_base,
            $new_base,
            $old_effective,
            documentationObligationEffectiveStatus($obligation),
            $actor_id ? 'agent' : 'system',
            $actor_id,
            $reason_code,
            'document',
            $document_id
        );
        $invalidated++;
    }
    return $invalidated;
}

function documentationEvidenceSatisfiesPolicy($policy, $evidence) {
    $policy = (string) $policy;
    $type = documentationNormalizeKey($evidence['type'] ?? '', '', 40);
    $reference_type = documentationNormalizeKey($evidence['reference_type'] ?? '', '', 40);
    $reference_id = max(0, intval($evidence['reference_id'] ?? 0));
    $locator = trim((string) ($evidence['locator'] ?? ''));
    if ($policy === 'none') {
        return $type === 'none' && $reference_type === 'policy' && $reference_id === 0;
    }
    if ($policy === 'note') {
        return $type === 'note' && $reference_type === 'note' && $reference_id === 0 && $locator !== '';
    }
    if ($policy === 'file') {
        return $reference_type === 'file' && $reference_id > 0;
    }
    if ($reference_type === 'url') {
        return $reference_id === 0 && $locator !== '';
    }
    return in_array($reference_type, ['document', 'document-version', 'file', 'ticket', 'automation'], true)
        && $reference_id > 0;
}

function documentationValidateEvidenceReference($client_id, $document_id, $evidence, $for_update = false) {
    global $mysqli;
    $client_id = intval($client_id);
    $document_id = intval($document_id);
    $reference_type = documentationNormalizeKey($evidence['reference_type'] ?? '', '', 40);
    $reference_id = max(0, intval($evidence['reference_id'] ?? 0));
    $automation_source = documentationNormalizeSelectorValue('integration', $evidence['automation_source'] ?? '');
    $automation_source_sql = mysqli_real_escape_string($mysqli, $automation_source);
    if (!in_array($reference_type, documentationEvidenceReferenceTypes(), true)) {
        throw new InvalidArgumentException('Unsupported documentation evidence reference type');
    }
    if (!in_array($reference_type, documentationEntityEvidenceReferenceTypes(), true)) {
        if ($reference_id !== 0) {
            throw new InvalidArgumentException('Opaque documentation evidence references cannot carry an entity ID');
        }
        return;
    }
    if (!$reference_id) {
        throw new InvalidArgumentException('Entity documentation evidence requires a reference ID');
    }
    if ($reference_type === 'automation' && ($automation_source === '' || $automation_source === 'any')) {
        throw new InvalidArgumentException('Automation evidence requires an allowlisted integration source');
    }
    if ($reference_type === 'document' && $reference_id !== $document_id) {
        throw new RuntimeException('Document evidence must reference the document being verified');
    }
    $lock_sql = $for_update ? ' FOR UPDATE' : '';
    $queries = [
        'document' => "SELECT document_id AS documentation_evidence_entity_id FROM documents
            WHERE document_id = $reference_id
            AND document_client_id = $client_id AND document_archived_at IS NULL LIMIT 1$lock_sql",
        'document-version' => "SELECT version.document_version_id AS documentation_evidence_entity_id
            FROM document_versions version
            INNER JOIN documents document ON document.document_id = version.document_version_document_id
            WHERE version.document_version_id = $reference_id
            AND version.document_version_document_id = $document_id
            AND document.document_client_id = $client_id AND document.document_archived_at IS NULL LIMIT 1$lock_sql",
        'file' => "SELECT file_id AS documentation_evidence_entity_id FROM files WHERE file_id = $reference_id
            AND file_client_id = $client_id AND file_archived_at IS NULL LIMIT 1$lock_sql",
        'ticket' => "SELECT ticket_id AS documentation_evidence_entity_id FROM tickets WHERE ticket_id = $reference_id
            AND ticket_client_id = $client_id AND ticket_archived_at IS NULL LIMIT 1$lock_sql",
        'automation' => "SELECT automation_mapping_id AS documentation_evidence_entity_id
            FROM automation_entity_mappings
            WHERE automation_mapping_id = $reference_id AND automation_mapping_client_id = $client_id
            AND automation_mapping_deleted_at IS NULL
            AND automation_mapping_source = '$automation_source_sql' LIMIT 1$lock_sql",
    ];
    $row = mysqli_fetch_assoc(documentationDbQuery($queries[$reference_type], 'Could not validate the verification evidence scope'));
    if (!$row || intval($row['documentation_evidence_entity_id'] ?? 0) !== $reference_id) {
        throw new RuntimeException('The verification evidence reference is unavailable for this client');
    }
    return $row;
}

function documentationLockEvidenceReference($client_id, $document_id, array $evidence) {
    $client_id = intval($client_id);
    documentationLockClient($client_id);
    return documentationValidateEvidenceReference($client_id, $document_id, $evidence, true);
}

function documentationEvidenceReferenceInUse($reference_type, $reference_id, $client_id = 0) {
    global $mysqli;
    $reference_type = documentationNormalizeKey($reference_type, '', 40);
    $reference_id = intval($reference_id);
    $client_id = max(0, intval($client_id));
    if (!in_array($reference_type, documentationEntityEvidenceReferenceTypes(), true) || $reference_id < 1) {
        throw new InvalidArgumentException('A known documentation evidence entity reference is required');
    }
    $reference_type_sql = mysqli_real_escape_string($mysqli, $reference_type);
    $client_filter = $client_id ? " AND documentation_evidence_client_id = $client_id" : '';
    $row = mysqli_fetch_row(documentationDbQuery("SELECT EXISTS (
        SELECT 1 FROM documentation_evidence_locker
        WHERE documentation_evidence_reference_type = '$reference_type_sql'
        AND documentation_evidence_reference_id = $reference_id
        $client_filter
    )", 'Could not inspect documentation evidence references'));
    return intval($row[0] ?? 0) === 1;
}

function documentationRecordEvidenceLocked($obligation, $evidence, $actor_id, $ticket_id, $document_id = 0) {
    global $mysqli;
    // One immutable Locker row represents one successful verification
    // occurrence. The caller's obligation lock/revision CAS prevents a stale
    // retry from appending a second occurrence with misleading provenance.
    $policy = (string) $obligation['documentation_requirement_version_evidence_policy'];
    if (!documentationEvidenceSatisfiesPolicy($policy, $evidence)) {
        throw new RuntimeException('The verification evidence does not satisfy the published requirement policy');
    }
    documentationLockEvidenceReference(
        intval($obligation['documentation_obligation_client_id']),
        intval($document_id) ?: intval($obligation['documentation_obligation_document_id']),
        $evidence
    );
    $type = documentationNormalizeKey($evidence['type'] ?? ($policy === 'none' ? 'none' : 'reference'), 'reference', 40);
    $reference_type = documentationNormalizeKey($evidence['reference_type'] ?? ($policy === 'none' ? 'policy' : 'reference'), 'reference', 40);
    $reference_id = max(0, intval($evidence['reference_id'] ?? 0));
    $reference_hash = documentationEvidenceReferenceHash($reference_type, $reference_id, $evidence['locator'] ?? '');
    $type_sql = mysqli_real_escape_string($mysqli, $type);
    $reference_type_sql = mysqli_real_escape_string($mysqli, $reference_type);
    $reference_hash_sql = mysqli_real_escape_string($mysqli, $reference_hash);
    $obligation_id = intval($obligation['documentation_obligation_id']);
    $client_id = intval($obligation['documentation_obligation_client_id']);
    $version_id = intval($obligation['documentation_obligation_requirement_version_id']);
    $actor_id = max(0, intval($actor_id));
    $ticket_id = max(0, intval($ticket_id));
    documentationDbQuery("INSERT INTO documentation_evidence_locker SET
        documentation_evidence_client_id = $client_id,
        documentation_evidence_obligation_id = $obligation_id,
        documentation_evidence_requirement_version_id = $version_id,
        documentation_evidence_type = '$type_sql',
        documentation_evidence_reference_type = '$reference_type_sql',
        documentation_evidence_reference_id = $reference_id,
        documentation_evidence_reference_hash = '$reference_hash_sql',
        documentation_evidence_policy_result = 'accepted',
        documentation_evidence_source_ticket_id = $ticket_id,
        documentation_evidence_recorded_by = $actor_id", 'Could not record verification evidence');
    if (mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('The immutable verification evidence occurrence was not recorded');
    }
    return intval(mysqli_insert_id($mysqli));
}

function documentationVerifyObligation(
    $obligation_id,
    $document_id,
    array $evidence,
    $expected_revision,
    $actor_id,
    $ticket_id = 0,
    $source = 'agent',
    $caller_transaction = false
) {
    global $mysqli;
    $obligation_id = intval($obligation_id);
    $document_id = intval($document_id);
    $actor_id = max(0, intval($actor_id));
    $ticket_id = max(0, intval($ticket_id));
    $source = documentationNormalizeChoiceToken($source, 'agent', 40);
    if ($source !== 'agent') {
        throw new InvalidArgumentException('Unsupported documentation verification source');
    }
    documentationRequireSupportLevel($actor_id, 2);
    documentationBeginMutation($caller_transaction);
    try {
        $expected_client_id = documentationObligationClientId($obligation_id);
        $ticket = null;
        if ($ticket_id) {
            $ticket = documentationLockClientTicket($ticket_id, $expected_client_id);
        } else {
            documentationLockClient($expected_client_id);
        }
        $obligation = documentationLoadObligationForMutation($obligation_id);
        $revision = intval($obligation['documentation_obligation_revision']);
        if (intval($expected_revision) !== $revision) {
            throw new RuntimeException('The documentation obligation changed; refresh and try again');
        }
        if (empty($obligation['documentation_obligation_applicable'])) {
            throw new RuntimeException('A not-applicable documentation obligation cannot be verified');
        }
        $client_id = intval($obligation['documentation_obligation_client_id']);
        if ($ticket && intval($ticket['ticket_client_id']) !== $client_id) {
            throw new RuntimeException('The verification ticket belongs to another client');
        }
        if ($ticket) {
            $ticket_link = mysqli_fetch_assoc(documentationDbQuery("SELECT ticket_documentation_obligation_id
                FROM ticket_documentation_obligations
                WHERE ticket_documentation_obligation_ticket_id = $ticket_id
                AND ticket_documentation_obligation_obligation_id = $obligation_id
                AND ticket_documentation_obligation_client_id = $client_id
                LIMIT 1 FOR UPDATE", 'Could not lock the verification ticket obligation'));
            if (!$ticket_link) {
                throw new RuntimeException('Ticket-scoped verification requires a linked documentation obligation');
            }
        }
        $document_state = documentationDocumentState($document_id, $client_id, true);
        if (!$document_state['exists']) {
            throw new RuntimeException('The selected document is unavailable for this client');
        }
        $evidence_id = documentationRecordEvidenceLocked($obligation, $evidence, $actor_id, $ticket_id, $document_id);
        $verified_at = date('Y-m-d H:i:s');
        $projection = documentationFreshnessProjection([
            'applicable' => true,
            'document_exists' => true,
            'last_verified_at' => $verified_at,
            'review_cadence_days' => intval($obligation['documentation_requirement_version_review_cadence_days']),
            'warning_window_days' => intval($obligation['documentation_requirement_version_warning_window_days']),
            'verified_document_hash' => $document_state['hash'],
            'current_document_hash' => $document_state['hash'],
        ], $verified_at);
        $old_base = $obligation['documentation_obligation_base_status'];
        $old_effective = documentationObligationEffectiveStatus($obligation, $verified_at);
        $source_sql = mysqli_real_escape_string($mysqli, $source);
        $hash_sql = mysqli_real_escape_string($mysqli, $document_state['hash']);
        documentationDbQuery("UPDATE client_documentation_obligations SET
            documentation_obligation_document_id = $document_id,
            documentation_obligation_base_status = 'Current',
            documentation_obligation_last_verified_at = " . documentationSqlValue($verified_at) . ",
            documentation_obligation_next_review_at = " . documentationSqlValue($projection['next_review_at']) . ",
            documentation_obligation_stale_at = " . documentationSqlValue($projection['stale_at']) . ",
            documentation_obligation_verification_source = '$source_sql',
            documentation_obligation_verification_evidence_id = $evidence_id,
            documentation_obligation_verification_document_version_id = " . intval($document_state['version_id']) . ",
            documentation_obligation_verification_document_hash = '$hash_sql',
            documentation_obligation_verification_ticket_id = $ticket_id,
            documentation_obligation_evaluation_reason_code = 'verified_current',
            documentation_obligation_evaluated_at = " . documentationSqlValue($verified_at) . ",
            documentation_obligation_revision = documentation_obligation_revision + 1
            WHERE documentation_obligation_id = $obligation_id
            AND documentation_obligation_revision = $revision", 'Could not verify the documentation obligation');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The documentation obligation changed; refresh and try again');
        }
        $obligation['documentation_obligation_document_id'] = $document_id;
        $obligation['documentation_obligation_base_status'] = 'Current';
        documentationRecordObligationEvent(
            $obligation,
            'verified',
            $old_base,
            'Current',
            $old_effective,
            documentationObligationEffectiveStatus($obligation, $verified_at),
            'agent',
            $actor_id,
            'verification_accepted',
            'evidence',
            $evidence_id,
            ['evidence_type' => $evidence['type'] ?? 'reference', 'reference_type' => $evidence['reference_type'] ?? 'reference']
        );
        documentationCommitMutation($caller_transaction);
        return [
            'obligation_id' => $obligation_id,
            'revision' => $revision + 1,
            'evidence_id' => $evidence_id,
            'base_status' => 'Current',
            'next_review_at' => $projection['next_review_at'],
        ];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationRequestObligationException(
    $obligation_id,
    $reason,
    $expires_at,
    $expected_revision,
    $actor_id,
    $caller_transaction = false
) {
    global $mysqli;
    $actor_id = intval($actor_id);
    documentationRequireSupportLevel($actor_id, 2);
    $reason_redacted = documentationRedactAuditText($reason);
    if ($reason_redacted === '') {
        throw new InvalidArgumentException('An exception reason is required');
    }
    $expires_timestamp = strtotime((string) $expires_at);
    if (!$expires_timestamp || $expires_timestamp <= time() || $expires_timestamp > strtotime('+1 year')) {
        throw new InvalidArgumentException('The exception expiry must be in the future and no more than one year away');
    }
    $expires_at = date('Y-m-d H:i:s', $expires_timestamp);
    documentationBeginMutation($caller_transaction);
    try {
        $obligation = documentationLoadObligationForMutation($obligation_id);
        $revision = intval($obligation['documentation_obligation_revision']);
        if (intval($expected_revision) !== $revision) {
            throw new RuntimeException('The documentation obligation changed; refresh and try again');
        }
        if (empty($obligation['documentation_obligation_applicable'])) {
            throw new RuntimeException('A not-applicable obligation cannot receive an exception');
        }
        $prior_exception = documentationLoadCurrentExceptionLocked($obligation);
        if ($prior_exception && in_array($prior_exception['documentation_obligation_exception_status'], ['Pending', 'Approved'], true)) {
            if (strtotime((string) $prior_exception['documentation_obligation_exception_expires_at']) > time()) {
                throw new RuntimeException('This documentation obligation already has a pending or active exception');
            }
            $prior_exception_id = intval($prior_exception['documentation_obligation_exception_id']);
            $prior_revision = intval($prior_exception['documentation_obligation_exception_revision']);
            $prior_status = (string) $prior_exception['documentation_obligation_exception_status'];
            documentationDbQuery("UPDATE documentation_obligation_exceptions SET
                documentation_obligation_exception_status = 'Expired',
                documentation_obligation_exception_expired_at = NOW(),
                documentation_obligation_exception_revision = documentation_obligation_exception_revision + 1
                WHERE documentation_obligation_exception_id = $prior_exception_id
                AND documentation_obligation_exception_revision = $prior_revision
                AND documentation_obligation_exception_status IN ('Pending','Approved')", 'Could not expire the prior documentation exception');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The prior documentation exception changed; refresh and try again');
            }
            documentationRecordObligationExceptionEvent(
                $prior_exception,
                'expired',
                $prior_status,
                'Expired',
                0,
                'exception_expired'
            );
        }
        $reason_sql = mysqli_real_escape_string($mysqli, $reason_redacted);
        $reason_hash = hash('sha256', trim((string) $reason));
        $reason_hash_sql = mysqli_real_escape_string($mysqli, $reason_hash);
        $client_id = intval($obligation['documentation_obligation_client_id']);
        $version_id = intval($obligation['documentation_obligation_requirement_version_id']);
        documentationDbQuery("INSERT INTO documentation_obligation_exceptions SET
            documentation_obligation_exception_client_id = $client_id,
            documentation_obligation_exception_obligation_id = " . intval($obligation_id) . ",
            documentation_obligation_exception_requirement_version_id = $version_id,
            documentation_obligation_exception_status = 'Pending',
            documentation_obligation_exception_reason_redacted = '$reason_sql',
            documentation_obligation_exception_reason_hash = '$reason_hash_sql',
            documentation_obligation_exception_requested_by = $actor_id,
            documentation_obligation_exception_expires_at = " . documentationSqlValue($expires_at), 'Could not append the documentation obligation exception');
        $exception_id = intval(mysqli_insert_id($mysqli));
        $exception = [
            'documentation_obligation_exception_id' => $exception_id,
            'documentation_obligation_exception_client_id' => $client_id,
            'documentation_obligation_exception_obligation_id' => intval($obligation_id),
            'documentation_obligation_exception_requirement_version_id' => $version_id,
            'documentation_obligation_exception_status' => 'Pending',
            'documentation_obligation_exception_revision' => 1,
        ];
        documentationDbQuery("UPDATE client_documentation_obligations SET
            documentation_obligation_exception_id = $exception_id,
            documentation_obligation_exception_status = 'Pending',
            documentation_obligation_exception_reason_redacted = '$reason_sql',
            documentation_obligation_exception_reason_hash = '$reason_hash_sql',
            documentation_obligation_exception_requested_by = $actor_id,
            documentation_obligation_exception_requested_at = NOW(),
            documentation_obligation_exception_decided_by = 0,
            documentation_obligation_exception_decided_at = NULL,
            documentation_obligation_exception_expires_at = " . documentationSqlValue($expires_at) . ",
            documentation_obligation_exception_expired_event_at = NULL,
            documentation_obligation_revision = documentation_obligation_revision + 1
            WHERE documentation_obligation_id = " . intval($obligation_id) . "
            AND documentation_obligation_revision = $revision", 'Could not request the documentation exception');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The documentation obligation changed; refresh and try again');
        }
        documentationRecordObligationExceptionEvent(
            $exception,
            'requested',
            null,
            'Pending',
            $actor_id,
            'exception_requested',
            ['reason_hash' => $reason_hash, 'expires_at' => $expires_at]
        );
        documentationRecordObligationEvent(
            $obligation,
            'exception_requested',
            $obligation['documentation_obligation_base_status'],
            $obligation['documentation_obligation_base_status'],
            documentationObligationEffectiveStatus($obligation),
            documentationObligationEffectiveStatus($obligation),
            'agent',
            $actor_id,
            'exception_requested',
            'exception',
            $exception_id,
            ['reason_hash' => $reason_hash, 'expires_at' => $expires_at]
        );
        documentationCommitMutation($caller_transaction);
        return [
            'obligation_id' => intval($obligation_id),
            'exception_id' => $exception_id,
            'revision' => $revision + 1,
            'exception_status' => 'Pending',
        ];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationDecideObligationException(
    $obligation_id,
    $decision,
    $expected_revision,
    $actor_id,
    $caller_transaction = false
) {
    global $mysqli;
    $decision = ucfirst(strtolower(trim((string) $decision)));
    if (!in_array($decision, ['Approved', 'Rejected', 'Revoked'], true)) {
        throw new InvalidArgumentException('Unsupported documentation exception decision');
    }
    $actor_id = intval($actor_id);
    documentationRequireSupportLevel($actor_id, 3);
    documentationBeginMutation($caller_transaction);
    try {
        $obligation = documentationLoadObligationForMutation($obligation_id);
        $revision = intval($obligation['documentation_obligation_revision']);
        if (intval($expected_revision) !== $revision) {
            throw new RuntimeException('The documentation obligation changed; refresh and try again');
        }
        $exception = documentationLoadCurrentExceptionLocked($obligation, true);
        if (intval($exception['documentation_obligation_exception_requirement_version_id'])
            !== intval($obligation['documentation_obligation_requirement_version_id'])) {
            throw new RuntimeException('The documentation exception belongs to a superseded requirement version');
        }
        if (intval($exception['documentation_obligation_exception_requested_by']) === $actor_id) {
            throw new RuntimeException('Exception requesters cannot approve or decide their own request');
        }
        if ($obligation['documentation_requirement_version_exception_approval_policy'] === 'administrator'
            && !documentationAgentIsAdministrator($actor_id)) {
            throw new RuntimeException('This documentation exception requires an administrator decision');
        }
        $current = (string) $exception['documentation_obligation_exception_status'];
        if (($decision === 'Revoked' && $current !== 'Approved')
            || ($decision !== 'Revoked' && $current !== 'Pending')) {
            throw new RuntimeException('The documentation exception is not awaiting this decision');
        }
        if ($decision === 'Approved'
            && strtotime((string) $exception['documentation_obligation_exception_expires_at']) <= time()) {
            throw new RuntimeException('An expired documentation exception cannot be approved');
        }
        $old_effective = documentationObligationEffectiveStatus($obligation);
        $decision_sql = mysqli_real_escape_string($mysqli, $decision);
        $exception_id = intval($exception['documentation_obligation_exception_id']);
        $exception_revision = intval($exception['documentation_obligation_exception_revision']);
        documentationDbQuery("UPDATE documentation_obligation_exceptions SET
            documentation_obligation_exception_status = '$decision_sql',
            documentation_obligation_exception_decided_by = $actor_id,
            documentation_obligation_exception_decided_at = NOW(),
            documentation_obligation_exception_revision = documentation_obligation_exception_revision + 1
            WHERE documentation_obligation_exception_id = $exception_id
            AND documentation_obligation_exception_revision = $exception_revision", 'Could not decide the durable documentation exception');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The documentation exception changed; refresh and try again');
        }
        documentationDbQuery("UPDATE client_documentation_obligations SET
            documentation_obligation_exception_status = '$decision_sql',
            documentation_obligation_exception_decided_by = $actor_id,
            documentation_obligation_exception_decided_at = NOW(),
            documentation_obligation_revision = documentation_obligation_revision + 1
            WHERE documentation_obligation_id = " . intval($obligation_id) . "
            AND documentation_obligation_revision = $revision", 'Could not decide the documentation exception');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The documentation obligation changed; refresh and try again');
        }
        $obligation['documentation_obligation_exception_status'] = $decision;
        $action = 'exception_' . strtolower($decision);
        documentationRecordObligationExceptionEvent(
            $exception,
            strtolower($decision),
            $current,
            $decision,
            $actor_id,
            $action
        );
        documentationRecordObligationEvent(
            $obligation,
            $action,
            $obligation['documentation_obligation_base_status'],
            $obligation['documentation_obligation_base_status'],
            $old_effective,
            documentationObligationEffectiveStatus($obligation),
            'agent',
            $actor_id,
            $action,
            'exception',
            $exception_id
        );
        documentationCommitMutation($caller_transaction);
        return [
            'obligation_id' => intval($obligation_id),
            'exception_id' => $exception_id,
            'revision' => $revision + 1,
            'exception_status' => $decision,
        ];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationLockTicket($ticket_id) {
    $ticket_id = intval($ticket_id);
    $ticket = mysqli_fetch_assoc(documentationDbQuery("SELECT ticket_id, ticket_client_id,
        ticket_configuration_change, ticket_documentation_impact, ticket_status,
        ticket_resolved_at, ticket_closed_at FROM tickets
        WHERE ticket_id = $ticket_id LIMIT 1 FOR UPDATE", 'Could not lock the documentation ticket'));
    if (!$ticket) {
        throw new RuntimeException('The documentation ticket no longer exists');
    }
    return $ticket;
}

function documentationLockClientTicket($ticket_id, $expected_client_id = 0) {
    $ticket_id = intval($ticket_id);
    $expected_client_id = max(0, intval($expected_client_id));
    $prelock = mysqli_fetch_assoc(documentationDbQuery("SELECT ticket_client_id FROM tickets
        WHERE ticket_id = $ticket_id LIMIT 1", 'Could not locate the documentation ticket client'));
    if (!$prelock) {
        throw new RuntimeException('The documentation ticket no longer exists');
    }
    $client_id = intval($prelock['ticket_client_id']);
    if ($expected_client_id && $expected_client_id !== $client_id) {
        throw new RuntimeException('The documentation ticket belongs to another client');
    }
    if ($client_id) {
        documentationLockClient($client_id);
    }
    $ticket = documentationLockTicket($ticket_id);
    if (intval($ticket['ticket_client_id']) !== $client_id) {
        throw new RuntimeException('The documentation ticket client changed; refresh and try again');
    }
    return $ticket;
}

function documentationLinkTicketObligation(
    $ticket_id,
    $obligation_id,
    $actor_id,
    $blocks_resolution = true,
    $caller_transaction = false,
    $task_id = 0
) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    $obligation_id = intval($obligation_id);
    $actor_id = intval($actor_id);
    $task_id = max(0, intval($task_id));
    documentationRequireSupportLevel($actor_id, 2);
    documentationBeginMutation($caller_transaction);
    try {
        $expected_client_id = documentationObligationClientId($obligation_id);
        $ticket = documentationLockClientTicket($ticket_id, $expected_client_id);
        $client_id = intval($ticket['ticket_client_id']);
        $obligation = documentationLoadObligationForMutation($obligation_id);
        if (!$client_id || $client_id !== intval($obligation['documentation_obligation_client_id'])) {
            throw new RuntimeException('The ticket and documentation obligation must belong to the same client');
        }
        $existing = mysqli_fetch_assoc(documentationDbQuery("SELECT * FROM ticket_documentation_obligations
            WHERE ticket_documentation_obligation_ticket_id = $ticket_id
            AND ticket_documentation_obligation_obligation_id = $obligation_id
            LIMIT 1 FOR UPDATE", 'Could not lock the ticket documentation link'));
        $task_to_validate = $task_id ?: intval($existing['ticket_documentation_obligation_task_id'] ?? 0);
        if ($task_to_validate) {
            $task = mysqli_fetch_assoc(documentationDbQuery("SELECT task_id FROM tasks
                WHERE task_id = $task_to_validate AND task_ticket_id = $ticket_id
                LIMIT 1 FOR UPDATE", 'Could not lock the ticket documentation task'));
            if (!$task) {
                throw new RuntimeException('The documentation task must belong to the linked ticket');
            }
        }
        $blocks = $blocks_resolution
            && !empty($obligation['documentation_requirement_version_blocks_ticket_resolution']) ? 1 : 0;
        $effective_task_id = $task_to_validate;
        $link_changed = false;
        if ($existing) {
            $link_id = intval($existing['ticket_documentation_obligation_id']);
            $link_revision = intval($existing['ticket_documentation_obligation_revision']);
            $link_changed = intval($existing['ticket_documentation_obligation_blocks_resolution']) !== $blocks
                || intval($existing['ticket_documentation_obligation_task_id']) !== $effective_task_id;
            if ($link_changed) {
                documentationDbQuery("UPDATE ticket_documentation_obligations SET
                    ticket_documentation_obligation_blocks_resolution = $blocks,
                    ticket_documentation_obligation_task_id = $effective_task_id,
                    ticket_documentation_obligation_revision = ticket_documentation_obligation_revision + 1
                    WHERE ticket_documentation_obligation_id = $link_id
                    AND ticket_documentation_obligation_revision = $link_revision", 'Could not update the ticket documentation link');
                if (mysqli_affected_rows($mysqli) !== 1) {
                    throw new RuntimeException('The ticket documentation link changed; refresh and try again');
                }
            }
        } else {
            documentationDbQuery("INSERT INTO ticket_documentation_obligations SET
                ticket_documentation_obligation_ticket_id = $ticket_id,
                ticket_documentation_obligation_obligation_id = $obligation_id,
                ticket_documentation_obligation_client_id = $client_id,
                ticket_documentation_obligation_task_id = $effective_task_id,
                ticket_documentation_obligation_blocks_resolution = $blocks,
                ticket_documentation_obligation_linked_by = $actor_id", 'Could not link the documentation obligation to the ticket');
            $link_id = intval(mysqli_insert_id($mysqli));
        }
        if ($ticket['ticket_documentation_impact'] !== 'Required') {
            $observed_impact_sql = mysqli_real_escape_string($mysqli, (string) $ticket['ticket_documentation_impact']);
            documentationDbQuery("UPDATE tickets SET
                ticket_documentation_impact = 'Required',
                ticket_documentation_assessed_by = $actor_id,
                ticket_documentation_assessed_at = NOW()
                WHERE ticket_id = $ticket_id
                AND ticket_documentation_impact = '$observed_impact_sql'", 'Could not mark the ticket documentation impact as linked');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The ticket documentation assessment changed; refresh and try again');
            }
        }
        if (!$existing || $link_changed) {
            $action = $existing ? 'ticket_link_updated' : 'ticket_linked';
            documentationRecordObligationEvent(
                $obligation,
                $action,
                $obligation['documentation_obligation_base_status'],
                $obligation['documentation_obligation_base_status'],
                documentationObligationEffectiveStatus($obligation),
                documentationObligationEffectiveStatus($obligation),
                'agent',
                $actor_id,
                $action,
                'ticket',
                $ticket_id,
                [
                    'from_blocks_resolution' => $existing
                        ? intval($existing['ticket_documentation_obligation_blocks_resolution']) : null,
                    'to_blocks_resolution' => $blocks,
                    'from_task_id' => $existing
                        ? intval($existing['ticket_documentation_obligation_task_id']) : null,
                    'to_task_id' => $effective_task_id,
                ]
            );
        }
        documentationCommitMutation($caller_transaction);
        return [
            'link_id' => $link_id,
            'ticket_id' => $ticket_id,
            'task_id' => $effective_task_id,
            'obligation_id' => $obligation_id,
            'blocks_resolution' => $blocks,
            'revision' => $existing ? $link_revision + ($link_changed ? 1 : 0) : 1,
        ];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationLinkTaskObligation(
    $ticket_id,
    $task_id,
    $obligation_id,
    $actor_id,
    $blocks_resolution = true,
    $caller_transaction = false
) {
    return documentationLinkTicketObligation(
        $ticket_id,
        $obligation_id,
        $actor_id,
        $blocks_resolution,
        $caller_transaction,
        $task_id
    );
}

function documentationRecordTicketWaiverEvent($waiver, $action, $from_status, $to_status, $actor_id, $reason_code, $context = []) {
    global $mysqli;
    $waiver_id = intval($waiver['ticket_documentation_waiver_id'] ?? 0);
    $link_id = intval($waiver['ticket_documentation_waiver_link_id'] ?? 0);
    if (!$waiver_id || !$link_id || !in_array($action, ['requested', 'approved', 'rejected', 'revoked', 'expired'], true)) {
        throw new RuntimeException('Invalid ticket documentation waiver event');
    }
    $status = static function ($value) use ($mysqli) {
        return $value === null || $value === '' ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
    };
    $action_sql = mysqli_real_escape_string($mysqli, $action);
    $reason_sql = mysqli_real_escape_string($mysqli, documentationNormalizeKey($reason_code, 'unspecified', 60));
    $context_hash_sql = $context ? documentationSqlValue(documentationAuditContextHash($context)) : 'NULL';
    $actor_id = max(0, intval($actor_id));
    $from_status_sql = $status($from_status);
    $to_status_sql = $status($to_status);
    documentationDbQuery("INSERT INTO ticket_documentation_waiver_events SET
        ticket_documentation_waiver_event_waiver_id = $waiver_id,
        ticket_documentation_waiver_event_link_id = $link_id,
        ticket_documentation_waiver_event_action = '$action_sql',
        ticket_documentation_waiver_event_from_status = $from_status_sql,
        ticket_documentation_waiver_event_to_status = $to_status_sql,
        ticket_documentation_waiver_event_actor_id = $actor_id,
        ticket_documentation_waiver_event_reason_code = '$reason_sql',
        ticket_documentation_waiver_event_context_hash = $context_hash_sql", 'Could not append the ticket documentation waiver event');
}

function documentationTicketWaiverRequestContextHash($waiver, $requirement_version_id) {
    $waiver = is_array($waiver) ? $waiver : [];
    return documentationAuditContextHash([
        'expires_at' => (string) ($waiver['ticket_documentation_waiver_expires_at'] ?? ''),
        'reason_hash' => (string) ($waiver['ticket_documentation_waiver_reason_hash'] ?? ''),
        'requirement_version_id' => max(0, intval($requirement_version_id)),
    ]);
}

function documentationTicketWaiverRequestContextHashes($waiver_ids) {
    $waiver_ids = array_values(array_unique(array_filter(array_map('intval', (array) $waiver_ids))));
    if (!$waiver_ids) {
        return [];
    }
    sort($waiver_ids, SORT_NUMERIC);
    $waiver_id_sql = implode(',', $waiver_ids);
    $hashes = [];
    $counts = [];
    $rows = documentationDbQuery("SELECT ticket_documentation_waiver_event_waiver_id,
        ticket_documentation_waiver_event_context_hash
        FROM ticket_documentation_waiver_events
        WHERE ticket_documentation_waiver_event_waiver_id IN ($waiver_id_sql)
        AND ticket_documentation_waiver_event_action = 'requested'
        ORDER BY ticket_documentation_waiver_event_waiver_id, ticket_documentation_waiver_event_id", 'Could not inspect ticket waiver request pins');
    while ($row = mysqli_fetch_assoc($rows)) {
        $waiver_id = intval($row['ticket_documentation_waiver_event_waiver_id']);
        $counts[$waiver_id] = intval($counts[$waiver_id] ?? 0) + 1;
        $hashes[$waiver_id] = $counts[$waiver_id] === 1
            ? (string) ($row['ticket_documentation_waiver_event_context_hash'] ?? '') : '';
    }
    return $hashes;
}

function documentationTicketWaiverPinsObligationVersion($waiver, $obligation) {
    $waiver = is_array($waiver) ? $waiver : [];
    $obligation = is_array($obligation) ? $obligation : [];
    $version_id = intval($obligation['documentation_obligation_requirement_version_id'] ?? 0);
    $stored_hash = trim((string) ($waiver['ticket_documentation_waiver_request_context_hash'] ?? ''));
    return $version_id > 0
        && preg_match('/^[a-f0-9]{64}$/', $stored_hash) === 1
        && hash_equals(documentationTicketWaiverRequestContextHash($waiver, $version_id), $stored_hash);
}

function documentationTicketWaiverIsActiveForObligation($waiver, $obligation, $now = null) {
    $waiver = is_array($waiver) ? $waiver : [];
    $now_timestamp = $now === null ? time() : (is_numeric($now) ? intval($now) : strtotime((string) $now));
    $expires_timestamp = strtotime((string) ($waiver['ticket_documentation_waiver_expires_at'] ?? ''));
    return (string) ($waiver['ticket_documentation_waiver_status'] ?? '') === 'Approved'
        && $now_timestamp !== false
        && $expires_timestamp !== false
        && $expires_timestamp > $now_timestamp
        && documentationTicketWaiverPinsObligationVersion($waiver, $obligation);
}

function documentationRequestTicketWaiver(
    $link_id,
    $reason,
    $expires_at,
    $actor_id,
    $caller_transaction = false
) {
    global $mysqli;
    $link_id = intval($link_id);
    $actor_id = intval($actor_id);
    documentationRequireSupportLevel($actor_id, 2);
    $reason_redacted = documentationRedactAuditText($reason);
    if ($reason_redacted === '') {
        throw new InvalidArgumentException('A ticket documentation waiver reason is required');
    }
    $expires_timestamp = strtotime((string) $expires_at);
    if (!$expires_timestamp || $expires_timestamp <= time() || $expires_timestamp > strtotime('+30 days')) {
        throw new InvalidArgumentException('A ticket waiver must expire within 30 days');
    }
    $expires_at = date('Y-m-d H:i:s', $expires_timestamp);
    $prelink = mysqli_fetch_assoc(documentationDbQuery("SELECT ticket_documentation_obligation_ticket_id,
        ticket_documentation_obligation_client_id, ticket_documentation_obligation_obligation_id
        FROM ticket_documentation_obligations
        WHERE ticket_documentation_obligation_id = $link_id LIMIT 1", 'Could not locate the ticket documentation link'));
    if (!$prelink) {
        throw new RuntimeException('The ticket documentation link no longer exists');
    }
    documentationBeginMutation($caller_transaction);
    try {
        $ticket_id = intval($prelink['ticket_documentation_obligation_ticket_id']);
        $client_id = intval($prelink['ticket_documentation_obligation_client_id']);
        documentationLockClientTicket($ticket_id, $client_id);
        $obligation_id = intval($prelink['ticket_documentation_obligation_obligation_id']);
        $obligation = documentationLoadObligationForMutation($obligation_id);
        $link = mysqli_fetch_assoc(documentationDbQuery("SELECT link.* FROM ticket_documentation_obligations link
            WHERE link.ticket_documentation_obligation_id = $link_id LIMIT 1 FOR UPDATE", 'Could not lock the ticket documentation link'));
        if (!$link) {
            throw new RuntimeException('The ticket documentation link changed; refresh and try again');
        }
        if (intval($link['ticket_documentation_obligation_ticket_id']) !== $ticket_id
            || intval($link['ticket_documentation_obligation_client_id']) !== $client_id
            || intval($link['ticket_documentation_obligation_obligation_id']) !== $obligation_id
            || intval($obligation['documentation_obligation_client_id']) !== $client_id) {
            throw new RuntimeException('The ticket documentation link client changed; refresh and try again');
        }
        $link = array_merge($obligation, $link);
        $requirement_version_id = intval($obligation['documentation_obligation_requirement_version_id']);
        $open_waivers = [];
        $open_waiver_ids = [];
        $open_rows = documentationDbQuery("SELECT *
            FROM ticket_documentation_waivers
            WHERE ticket_documentation_waiver_link_id = $link_id
            AND ticket_documentation_waiver_status IN ('Pending','Approved')
            AND ticket_documentation_waiver_expires_at > NOW()
            ORDER BY ticket_documentation_waiver_id FOR UPDATE", 'Could not check existing ticket waivers');
        while ($open_waiver = mysqli_fetch_assoc($open_rows)) {
            $open_waiver_id = intval($open_waiver['ticket_documentation_waiver_id']);
            $open_waiver_ids[] = $open_waiver_id;
            $open_waivers[$open_waiver_id] = $open_waiver;
        }
        $open_context_hashes = documentationTicketWaiverRequestContextHashes($open_waiver_ids);
        foreach ($open_waivers as $open_waiver_id => $open_waiver) {
            $open_waiver['ticket_documentation_waiver_request_context_hash'] = $open_context_hashes[$open_waiver_id] ?? '';
            if (documentationTicketWaiverPinsObligationVersion($open_waiver, $obligation)) {
                throw new RuntimeException('This ticket documentation link already has a pending or active waiver');
            }
        }
        $reason_sql = mysqli_real_escape_string($mysqli, $reason_redacted);
        $reason_hash = hash('sha256', trim((string) $reason));
        $reason_hash_sql = mysqli_real_escape_string($mysqli, $reason_hash);
        documentationDbQuery("INSERT INTO ticket_documentation_waivers SET
            ticket_documentation_waiver_link_id = $link_id,
            ticket_documentation_waiver_status = 'Pending',
            ticket_documentation_waiver_reason_redacted = '$reason_sql',
            ticket_documentation_waiver_reason_hash = '$reason_hash_sql',
            ticket_documentation_waiver_requested_by = $actor_id,
            ticket_documentation_waiver_expires_at = " . documentationSqlValue($expires_at), 'Could not request the ticket documentation waiver');
        $waiver_id = intval(mysqli_insert_id($mysqli));
        $waiver = [
            'ticket_documentation_waiver_id' => $waiver_id,
            'ticket_documentation_waiver_link_id' => $link_id,
            'ticket_documentation_waiver_reason_hash' => $reason_hash,
            'ticket_documentation_waiver_expires_at' => $expires_at,
        ];
        documentationRecordTicketWaiverEvent($waiver, 'requested', null, 'Pending', $actor_id, 'waiver_requested', [
            'reason_hash' => $reason_hash,
            'expires_at' => $expires_at,
            'requirement_version_id' => $requirement_version_id,
        ]);
        documentationRecordObligationEvent(
            $link,
            'waiver_requested',
            $link['documentation_obligation_base_status'],
            $link['documentation_obligation_base_status'],
            documentationObligationEffectiveStatus($link),
            documentationObligationEffectiveStatus($link),
            'agent',
            $actor_id,
            'waiver_requested',
            'ticket-waiver',
            $waiver_id
        );
        documentationCommitMutation($caller_transaction);
        return ['waiver_id' => $waiver_id, 'revision' => 1, 'status' => 'Pending'];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationDecideTicketWaiver(
    $waiver_id,
    $decision,
    $expected_revision,
    $actor_id,
    $caller_transaction = false
) {
    global $mysqli;
    $waiver_id = intval($waiver_id);
    $decision = ucfirst(strtolower(trim((string) $decision)));
    if (!in_array($decision, ['Approved', 'Rejected', 'Revoked'], true)) {
        throw new InvalidArgumentException('Unsupported ticket documentation waiver decision');
    }
    $actor_id = intval($actor_id);
    documentationRequireSupportLevel($actor_id, 3);
    $pre = mysqli_fetch_assoc(documentationDbQuery("SELECT link.ticket_documentation_obligation_ticket_id,
        link.ticket_documentation_obligation_client_id,
        link.ticket_documentation_obligation_obligation_id
        FROM ticket_documentation_waivers waiver
        INNER JOIN ticket_documentation_obligations link
            ON link.ticket_documentation_obligation_id = waiver.ticket_documentation_waiver_link_id
        WHERE waiver.ticket_documentation_waiver_id = $waiver_id LIMIT 1", 'Could not locate the ticket waiver'));
    if (!$pre) {
        throw new RuntimeException('The ticket documentation waiver no longer exists');
    }
    documentationBeginMutation($caller_transaction);
    try {
        $ticket_id = intval($pre['ticket_documentation_obligation_ticket_id']);
        $client_id = intval($pre['ticket_documentation_obligation_client_id']);
        documentationLockClientTicket($ticket_id, $client_id);
        $obligation_id = intval($pre['ticket_documentation_obligation_obligation_id']);
        $obligation = documentationLoadObligationForMutation($obligation_id);
        $waiver = mysqli_fetch_assoc(documentationDbQuery("SELECT waiver.*, link.*
            FROM ticket_documentation_waivers waiver
            INNER JOIN ticket_documentation_obligations link
                ON link.ticket_documentation_obligation_id = waiver.ticket_documentation_waiver_link_id
            WHERE waiver.ticket_documentation_waiver_id = $waiver_id LIMIT 1 FOR UPDATE", 'Could not lock the ticket documentation waiver'));
        if (!$waiver) {
            throw new RuntimeException('The ticket documentation waiver changed; refresh and try again');
        }
        if (intval($waiver['ticket_documentation_obligation_ticket_id']) !== $ticket_id
            || intval($waiver['ticket_documentation_obligation_client_id']) !== $client_id
            || intval($waiver['ticket_documentation_obligation_obligation_id']) !== $obligation_id
            || intval($obligation['documentation_obligation_client_id']) !== $client_id) {
            throw new RuntimeException('The ticket documentation waiver client changed; refresh and try again');
        }
        $waiver = array_merge($obligation, $waiver);
        $request_context_hashes = documentationTicketWaiverRequestContextHashes([$waiver_id]);
        $waiver['ticket_documentation_waiver_request_context_hash'] = $request_context_hashes[$waiver_id] ?? '';
        $revision = intval($waiver['ticket_documentation_waiver_revision']);
        if (intval($expected_revision) !== $revision) {
            throw new RuntimeException('The ticket documentation waiver changed; refresh and try again');
        }
        if (intval($waiver['ticket_documentation_waiver_requested_by']) === $actor_id) {
            throw new RuntimeException('Waiver requesters cannot decide their own request');
        }
        if ($waiver['documentation_requirement_version_exception_approval_policy'] === 'administrator'
            && !documentationAgentIsAdministrator($actor_id)) {
            throw new RuntimeException('This ticket documentation waiver requires an administrator decision');
        }
        $current = (string) $waiver['ticket_documentation_waiver_status'];
        if (($decision === 'Revoked' && $current !== 'Approved')
            || ($decision !== 'Revoked' && $current !== 'Pending')) {
            throw new RuntimeException('The ticket documentation waiver is not awaiting this decision');
        }
        if ($decision === 'Approved' && strtotime((string) $waiver['ticket_documentation_waiver_expires_at']) <= time()) {
            throw new RuntimeException('An expired ticket documentation waiver cannot be approved');
        }
        if ($decision === 'Approved' && !documentationTicketWaiverPinsObligationVersion($waiver, $obligation)) {
            throw new RuntimeException('A waiver for a superseded documentation requirement version cannot be approved');
        }
        $decision_sql = mysqli_real_escape_string($mysqli, $decision);
        documentationDbQuery("UPDATE ticket_documentation_waivers SET
            ticket_documentation_waiver_status = '$decision_sql',
            ticket_documentation_waiver_decided_by = $actor_id,
            ticket_documentation_waiver_decided_at = NOW(),
            ticket_documentation_waiver_revision = ticket_documentation_waiver_revision + 1
            WHERE ticket_documentation_waiver_id = $waiver_id
            AND ticket_documentation_waiver_revision = $revision", 'Could not decide the ticket documentation waiver');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket documentation waiver changed; refresh and try again');
        }
        $action = strtolower($decision);
        documentationRecordTicketWaiverEvent($waiver, $action, $current, $decision, $actor_id, 'waiver_' . $action);
        documentationRecordObligationEvent(
            $waiver,
            'waiver_' . $action,
            $waiver['documentation_obligation_base_status'],
            $waiver['documentation_obligation_base_status'],
            documentationObligationEffectiveStatus($waiver),
            documentationObligationEffectiveStatus($waiver),
            'agent',
            $actor_id,
            'waiver_' . $action,
            'ticket-waiver',
            $waiver_id
        );
        documentationCommitMutation($caller_transaction);
        return ['waiver_id' => $waiver_id, 'revision' => $revision + 1, 'status' => $decision];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationLockTicketDocumentationGraph($ticket_id) {
    $ticket_id = intval($ticket_id);
    $ticket = documentationLockClientTicket($ticket_id);
    $client_id = intval($ticket['ticket_client_id']);
    if (!$client_id) {
        return ['ticket' => $ticket, 'links' => []];
    }
    $requirements = [];
    $pre_requirement_ids = [];
    $pre_rows = documentationDbQuery("SELECT DISTINCT obligation.documentation_obligation_requirement_id
        FROM ticket_documentation_obligations link
        INNER JOIN client_documentation_obligations obligation
            ON obligation.documentation_obligation_id = link.ticket_documentation_obligation_obligation_id
        WHERE link.ticket_documentation_obligation_ticket_id = $ticket_id
        ORDER BY obligation.documentation_obligation_requirement_id", 'Could not discover ticket documentation requirements');
    while ($pre_requirement = mysqli_fetch_assoc($pre_rows)) {
        $requirement_id = intval($pre_requirement['documentation_obligation_requirement_id']);
        if ($requirement_id) {
            $pre_requirement_ids[$requirement_id] = $requirement_id;
        }
    }
    if ($pre_requirement_ids) {
        sort($pre_requirement_ids, SORT_NUMERIC);
        $pre_requirement_id_sql = implode(',', array_map('intval', $pre_requirement_ids));
        $rows = documentationDbQuery("SELECT documentation_requirement_id,
            documentation_requirement_published_version_id, documentation_requirement_lifecycle
            FROM documentation_requirements WHERE documentation_requirement_id IN ($pre_requirement_id_sql)
            ORDER BY documentation_requirement_id FOR UPDATE", 'Could not lock active documentation requirements');
        while ($requirement = mysqli_fetch_assoc($rows)) {
            $requirements[intval($requirement['documentation_requirement_id'])] = $requirement;
        }
    }
    $links = [];
    $link_ids = [];
    $obligation_ids = [];
    $task_ids = [];
    $rows = documentationDbQuery("SELECT * FROM ticket_documentation_obligations
        WHERE ticket_documentation_obligation_ticket_id = $ticket_id
        ORDER BY ticket_documentation_obligation_id FOR UPDATE", 'Could not lock the ticket documentation links');
    while ($link = mysqli_fetch_assoc($rows)) {
        $link_id = intval($link['ticket_documentation_obligation_id']);
        $links[$link_id] = $link;
        $link_ids[$link_id] = $link_id;
        $obligation_id = intval($link['ticket_documentation_obligation_obligation_id']);
        if ($obligation_id) {
            $obligation_ids[$obligation_id] = $obligation_id;
        }
        $task_id = intval($link['ticket_documentation_obligation_task_id'] ?? 0);
        if ($task_id) {
            $task_ids[$task_id] = $task_id;
        }
    }

    $tasks = [];
    if ($task_ids) {
        sort($task_ids, SORT_NUMERIC);
        $task_id_sql = implode(',', array_map('intval', $task_ids));
        $rows = documentationDbQuery("SELECT task_id, task_ticket_id FROM tasks
            WHERE task_id IN ($task_id_sql) ORDER BY task_id FOR UPDATE", 'Could not lock ticket documentation tasks');
        while ($task = mysqli_fetch_assoc($rows)) {
            $tasks[intval($task['task_id'])] = $task;
        }
    }

    $obligations = [];
    $requirement_ids = [];
    $document_ids = [];
    $exception_ids = [];
    $evidence_ids = [];
    if ($obligation_ids) {
        sort($obligation_ids, SORT_NUMERIC);
        $obligation_id_sql = implode(',', array_map('intval', $obligation_ids));
        $rows = documentationDbQuery("SELECT obligation.*, version.*
            FROM client_documentation_obligations obligation
            INNER JOIN documentation_requirement_versions version
                ON version.documentation_requirement_version_id = obligation.documentation_obligation_requirement_version_id
            WHERE obligation.documentation_obligation_id IN ($obligation_id_sql)
            ORDER BY obligation.documentation_obligation_id FOR UPDATE", 'Could not lock ticket documentation obligations');
        while ($obligation = mysqli_fetch_assoc($rows)) {
            $obligation_id = intval($obligation['documentation_obligation_id']);
            $obligations[$obligation_id] = $obligation;
            $requirement_id = intval($obligation['documentation_obligation_requirement_id']);
            $document_id = intval($obligation['documentation_obligation_document_id']);
            $exception_id = intval($obligation['documentation_obligation_exception_id'] ?? 0);
            $evidence_id = intval($obligation['documentation_obligation_verification_evidence_id']);
            if ($requirement_id) {
                $requirement_ids[$requirement_id] = $requirement_id;
            }
            if ($document_id) {
                $document_ids[$document_id] = $document_id;
            }
            if ($exception_id) {
                $exception_ids[$exception_id] = $exception_id;
            }
            if ($evidence_id) {
                $evidence_ids[$evidence_id] = $evidence_id;
            }
        }
    }

    $exceptions = [];
    if ($exception_ids) {
        sort($exception_ids, SORT_NUMERIC);
        $exception_id_sql = implode(',', array_map('intval', $exception_ids));
        $rows = documentationDbQuery("SELECT * FROM documentation_obligation_exceptions
            WHERE documentation_obligation_exception_id IN ($exception_id_sql)
            ORDER BY documentation_obligation_exception_id FOR UPDATE", 'Could not lock ticket documentation exceptions');
        while ($exception = mysqli_fetch_assoc($rows)) {
            $exceptions[intval($exception['documentation_obligation_exception_id'])] = $exception;
        }
    }

    $waivers_by_link = [];
    $waiver_rows = [];
    $waiver_ids = [];
    if ($link_ids) {
        sort($link_ids, SORT_NUMERIC);
        $link_id_sql = implode(',', array_map('intval', $link_ids));
        $rows = documentationDbQuery("SELECT * FROM ticket_documentation_waivers
            WHERE ticket_documentation_waiver_link_id IN ($link_id_sql)
            ORDER BY ticket_documentation_waiver_id FOR UPDATE", 'Could not lock ticket documentation waivers');
        while ($waiver = mysqli_fetch_assoc($rows)) {
            $waiver_id = intval($waiver['ticket_documentation_waiver_id']);
            $waiver_ids[] = $waiver_id;
            $waiver_rows[$waiver_id] = $waiver;
        }
    }
    $waiver_request_context_hashes = documentationTicketWaiverRequestContextHashes($waiver_ids);
    foreach ($waiver_rows as $waiver_id => $waiver) {
        $waiver['ticket_documentation_waiver_request_context_hash'] = $waiver_request_context_hashes[$waiver_id] ?? '';
        $link_id = intval($waiver['ticket_documentation_waiver_link_id']);
        $waivers_by_link[$link_id][] = $waiver;
    }

    $documents = [];
    if ($document_ids) {
        sort($document_ids, SORT_NUMERIC);
        $document_id_sql = implode(',', array_map('intval', $document_ids));
        $rows = documentationDbQuery("SELECT document_id, document_client_id, document_content_raw,
            document_content, document_archived_at FROM documents
            WHERE document_id IN ($document_id_sql) ORDER BY document_id FOR UPDATE", 'Could not lock ticket documentation documents');
        while ($document = mysqli_fetch_assoc($rows)) {
            $documents[intval($document['document_id'])] = $document;
        }
    }

    $evidence = [];
    if ($evidence_ids) {
        sort($evidence_ids, SORT_NUMERIC);
        $evidence_id_sql = implode(',', array_map('intval', $evidence_ids));
        $rows = documentationDbQuery("SELECT * FROM documentation_evidence_locker
            WHERE documentation_evidence_id IN ($evidence_id_sql)
            ORDER BY documentation_evidence_id", 'Could not inspect ticket documentation evidence pins');
        while ($evidence_row = mysqli_fetch_assoc($rows)) {
            $evidence[intval($evidence_row['documentation_evidence_id'])] = $evidence_row;
        }
    }

    $graph_rows = [];
    $now_timestamp = time();
    foreach ($links as $link_id => $link) {
        $obligation_id = intval($link['ticket_documentation_obligation_obligation_id']);
        $row = $link;
        $obligation = $obligations[$obligation_id] ?? null;
        if ($obligation) {
            $row = array_merge($row, $obligation);
        }
        $requirement_id = intval($obligation['documentation_obligation_requirement_id'] ?? 0);
        $requirement = $requirements[$requirement_id] ?? null;
        $row['active_requirement_id'] = intval($requirement['documentation_requirement_id'] ?? 0);
        $row['active_requirement_version_id'] = intval($requirement['documentation_requirement_published_version_id'] ?? 0);
        $row['active_requirement_lifecycle'] = (string) ($requirement['documentation_requirement_lifecycle'] ?? '');

        $task_id = intval($link['ticket_documentation_obligation_task_id'] ?? 0);
        $task_record_valid = !$task_id
            || (isset($tasks[$task_id]) && intval($tasks[$task_id]['task_ticket_id']) === $ticket_id);
        $row['documentation_task_record_valid'] = $task_record_valid ? 1 : 0;

        $document_id = intval($obligation['documentation_obligation_document_id'] ?? 0);
        $document = $documents[$document_id] ?? null;
        $document_valid = $document
            && intval($document['document_client_id']) === $client_id
            && empty($document['document_archived_at']);
        $row['current_document_exists'] = $document_valid ? 1 : 0;
        $row['current_document_content_raw'] = $document_valid ? $document['document_content_raw'] : '';
        $row['current_document_content'] = $document_valid ? $document['document_content'] : '';

        $evidence_id = intval($obligation['documentation_obligation_verification_evidence_id'] ?? 0);
        $evidence_row = $evidence[$evidence_id] ?? null;
        $has_verification = !empty($obligation['documentation_obligation_last_verified_at']);
        $verification_valid = !$has_verification || ($evidence_row
            && intval($evidence_row['documentation_evidence_obligation_id']) === $obligation_id
            && intval($evidence_row['documentation_evidence_client_id']) === $client_id
            && intval($evidence_row['documentation_evidence_requirement_version_id'])
                === intval($obligation['documentation_obligation_requirement_version_id'])
            && trim((string) ($obligation['documentation_obligation_verification_document_hash'] ?? '')) !== '');
        $row['documentation_verification_context_valid'] = $verification_valid ? 1 : 0;

        $exception_id = intval($obligation['documentation_obligation_exception_id'] ?? 0);
        $exception = $exceptions[$exception_id] ?? null;
        $exception_valid = !$exception_id || ($exception
            && intval($exception['documentation_obligation_exception_obligation_id']) === $obligation_id
            && intval($exception['documentation_obligation_exception_client_id']) === $client_id
            && intval($exception['documentation_obligation_exception_requirement_version_id'])
                === intval($obligation['documentation_obligation_requirement_version_id'])
            && (string) $exception['documentation_obligation_exception_status']
                === (string) ($obligation['documentation_obligation_exception_status'] ?? ''));
        $row['documentation_exception_record_valid'] = $exception_valid ? 1 : 0;

        $active_waiver_id = 0;
        foreach ($waivers_by_link[$link_id] ?? [] as $waiver) {
            if (documentationTicketWaiverIsActiveForObligation($waiver, $obligation ?: [], $now_timestamp)) {
                $active_waiver_id = max($active_waiver_id, intval($waiver['ticket_documentation_waiver_id']));
            }
        }
        $row['active_waiver_id'] = $active_waiver_id;
        $row['has_active_waiver'] = $active_waiver_id ? 1 : 0;
        $graph_rows[] = $row;
    }
    return ['ticket' => $ticket, 'links' => $graph_rows];
}

function documentationTicketCanResolve($ticket_id) {
    try {
        $graph = documentationLockTicketDocumentationGraph($ticket_id);
        return documentationTicketGateFromRows($graph['ticket'], $graph['links']);
    } catch (Throwable $e) {
        error_log('Documentation ticket gate failed closed: ' . $e->getMessage());
        return [false, 'The ticket documentation state could not be locked for validation.'];
    }
}

function documentationTicketGateFromRows($ticket, $links, $now = null) {
    $ticket = is_array($ticket) ? $ticket : [];
    $impact = (string) $ticket['ticket_documentation_impact'];
    if ($impact === 'Legacy Exempt') {
        return [true, ''];
    }
    if ($impact === 'Unassessed' || $impact === '') {
        return [false, 'Assess whether this ticket changed client configuration before resolving it.'];
    }
    if (empty($ticket['ticket_configuration_change'])) {
        if ($impact === 'None') {
            return [true, ''];
        }
        if ($impact !== 'Required') {
            return [false, 'Record the ticket documentation impact before resolving it.'];
        }
    } elseif ($impact !== 'Required') {
        return [false, 'Configuration-changing work must link its affected documentation obligations.'];
    }
    if ($impact !== 'Required') {
        return empty($ticket['ticket_configuration_change'])
            ? [true, '']
            : [false, 'Configuration-changing work must link its affected documentation obligations.'];
    }
    $valid_affected_count = 0;
    $blocked = [];
    $ticket_client_id = intval($ticket['ticket_client_id'] ?? 0);
    foreach ((array) $links as $link) {
        $obligation_id = intval($link['documentation_obligation_id'] ?? 0);
        $requirement_id = intval($link['documentation_obligation_requirement_id'] ?? 0);
        $version_id = intval($link['documentation_obligation_requirement_version_id'] ?? 0);
        $version_requirement_id = intval($link['documentation_requirement_version_requirement_id'] ?? 0);
        $link_client_id = intval($link['ticket_documentation_obligation_client_id'] ?? 0);
        $obligation_client_id = intval($link['documentation_obligation_client_id'] ?? 0);
        $active_requirement_id = intval($link['active_requirement_id'] ?? 0);
        $active_version_id = intval($link['active_requirement_version_id'] ?? 0);
        if (!$ticket_client_id || !$obligation_id || !$requirement_id || !$version_id
            || $link_client_id !== $ticket_client_id || $obligation_client_id !== $ticket_client_id
            || $version_requirement_id !== $requirement_id
            || $active_requirement_id !== $requirement_id
            || (string) ($link['active_requirement_lifecycle'] ?? '') !== 'Active'
            || $active_version_id !== $version_id
            || empty($link['documentation_task_record_valid'] ?? 1)
            || (intval($link['documentation_obligation_exception_id'] ?? 0) > 0
                && empty($link['documentation_exception_record_valid']))) {
            return [false, 'A linked documentation obligation is no longer valid for this ticket; refresh its documentation impact.'];
        }
        if (empty($link['documentation_obligation_applicable'])) {
            return [false, 'A linked documentation obligation is no longer applicable; refresh the ticket documentation impact.'];
        }
        if (empty($link['ticket_documentation_obligation_blocks_resolution'])
            || empty($link['documentation_requirement_version_blocks_ticket_resolution'])) {
            continue;
        }
        $valid_affected_count++;
        $status = documentationObligationEffectiveStatus($link, $now);
        if (in_array($status, ['Missing', 'Draft', 'Stale'], true) && empty($link['has_active_waiver'])) {
            $blocked[] = (string) $link['documentation_requirement_version_name'];
        }
    }
    if (!$valid_affected_count) {
        return [false, 'Documentation-required work must link at least one current, applicable, resolution-gating obligation.'];
    }
    if ($blocked) {
        return [false, 'Resolve or obtain an approved waiver for: ' . implode(', ', array_slice($blocked, 0, 5)) . '.'];
    }
    return [true, ''];
}

function documentationRecordChangePassport(
    $ticket_id,
    $ticket_status,
    $actor_id,
    $caller_transaction = false
) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    $ticket_status = intval($ticket_status);
    $actor_id = max(0, intval($actor_id));
    if (!in_array($ticket_status, [4, 5], true)) {
        throw new InvalidArgumentException('Change Passports are recorded only for resolved or closed tickets');
    }
    documentationBeginMutation($caller_transaction);
    try {
        $graph = documentationLockTicketDocumentationGraph($ticket_id);
        $ticket = $graph['ticket'];
        if (empty($ticket['ticket_configuration_change'])) {
            documentationCommitMutation($caller_transaction);
            return ['created' => false, 'reason' => 'not_a_configuration_change'];
        }
        if (intval($ticket['ticket_status']) !== $ticket_status) {
            throw new RuntimeException('The ticket status changed before its Change Passport was recorded');
        }
        $client_id = intval($ticket['ticket_client_id']);
        [$can_record, $gate_reason] = documentationTicketGateFromRows($ticket, $graph['links']);
        if (!$can_record) {
            throw new RuntimeException($gate_reason ?: 'The ticket documentation gate is not satisfied');
        }
        $entries = [];
        $has_exception = false;
        foreach ($graph['links'] as $link) {
            if (intval($link['ticket_documentation_obligation_client_id']) !== $client_id) {
                throw new RuntimeException('A ticket documentation link crossed client scope');
            }
            $base_status = documentationObligationProjectedBaseStatus($link);
            $status = documentationObligationEffectiveStatus($link);
            $waiver_id = intval($link['active_waiver_id']);
            $waived = $waiver_id > 0;
            $exception_id = $status === 'Exception'
                && !empty($link['documentation_exception_record_valid'])
                ? intval($link['documentation_obligation_exception_id']) : 0;
            $has_exception = $has_exception || $status === 'Exception' || $waived;
            $entries[] = [
                'link_id' => intval($link['ticket_documentation_obligation_id']),
                'obligation_id' => intval($link['documentation_obligation_id']),
                'task_id' => intval($link['ticket_documentation_obligation_task_id'] ?? 0),
                'obligation_revision' => intval($link['documentation_obligation_revision']),
                'requirement_version_id' => intval($link['documentation_obligation_requirement_version_id']),
                'base_status' => $base_status,
                'effective_status' => $status,
                'evidence_id' => intval($link['documentation_obligation_verification_evidence_id']),
                'exception_id' => $exception_id,
                'waiver_id' => $waiver_id,
            ];
        }
        if (!$entries) {
            throw new RuntimeException('A configuration-changing ticket cannot create an empty Change Passport');
        }
        $set_json = json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($set_json === false) {
            throw new RuntimeException('Could not serialize the Change Passport obligation set');
        }
        $set_hash = hash('sha256', $set_json);
        $last = mysqli_fetch_assoc(documentationDbQuery("SELECT documentation_change_passport_resolution_sequence
            FROM documentation_change_passports
            WHERE documentation_change_passport_ticket_id = $ticket_id
            ORDER BY documentation_change_passport_resolution_sequence DESC
            LIMIT 1 FOR UPDATE", 'Could not allocate the Change Passport sequence'));
        $sequence = intval($last['documentation_change_passport_resolution_sequence'] ?? 0) + 1;
        $change_key = hash('sha256', $ticket_id . ':' . $sequence . ':' . $ticket_status . ':' . $set_hash);
        $outcome = $has_exception ? 'documented-with-exception' : 'documented';
        $change_key_sql = mysqli_real_escape_string($mysqli, $change_key);
        $set_hash_sql = mysqli_real_escape_string($mysqli, $set_hash);
        $outcome_sql = mysqli_real_escape_string($mysqli, $outcome);
        documentationDbQuery("INSERT INTO documentation_change_passports SET
            documentation_change_passport_client_id = $client_id,
            documentation_change_passport_ticket_id = $ticket_id,
            documentation_change_passport_resolution_sequence = $sequence,
            documentation_change_passport_ticket_status = $ticket_status,
            documentation_change_passport_change_key = '$change_key_sql',
            documentation_change_passport_obligation_set_hash = '$set_hash_sql',
            documentation_change_passport_outcome_code = '$outcome_sql',
            documentation_change_passport_committed_by = $actor_id", 'Could not append the Change Passport');
        $passport_id = intval(mysqli_insert_id($mysqli));
        foreach ($entries as $entry) {
            $link_id = intval($entry['link_id']);
            $obligation_id = intval($entry['obligation_id']);
            $task_id = intval($entry['task_id']);
            $version_id = intval($entry['requirement_version_id']);
            $obligation_revision = intval($entry['obligation_revision']);
            $base_status_sql = mysqli_real_escape_string($mysqli, $entry['base_status']);
            $effective_status_sql = mysqli_real_escape_string($mysqli, $entry['effective_status']);
            $evidence_id = intval($entry['evidence_id']);
            $exception_id = intval($entry['exception_id']);
            $waiver_id = intval($entry['waiver_id']);
            documentationDbQuery("INSERT INTO documentation_change_passport_obligations SET
                documentation_change_passport_obligation_passport_id = $passport_id,
                documentation_change_passport_obligation_link_id = $link_id,
                documentation_change_passport_obligation_obligation_id = $obligation_id,
                documentation_change_passport_obligation_task_id = $task_id,
                documentation_change_passport_obligation_requirement_version_id = $version_id,
                documentation_change_passport_obligation_revision = $obligation_revision,
                documentation_change_passport_obligation_base_status = '$base_status_sql',
                documentation_change_passport_obligation_effective_status = '$effective_status_sql',
                documentation_change_passport_obligation_evidence_id = $evidence_id,
                documentation_change_passport_obligation_exception_id = $exception_id,
                documentation_change_passport_obligation_waiver_id = $waiver_id", 'Could not append the Change Passport obligation snapshot');
        }
        documentationCommitMutation($caller_transaction);
        return [
            'created' => true,
            'passport_id' => $passport_id,
            'sequence' => $sequence,
            'change_key' => $change_key,
            'obligation_set_hash' => $set_hash,
            'outcome' => $outcome,
        ];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationRecordPromiseEvent($promise, $action, $from_status, $to_status, $actor_id, $reason_code, $context = []) {
    global $mysqli;
    $promise_id = intval($promise['documentation_promise_id'] ?? 0);
    $obligation_id = intval($promise['documentation_promise_obligation_id'] ?? 0);
    $client_id = intval($promise['documentation_promise_client_id'] ?? 0);
    $ticket_id = intval($promise['documentation_promise_ticket_id'] ?? 0);
    if (!$promise_id || !$obligation_id || !$client_id
        || !in_array($action, ['created', 'fulfilled', 'cancelled', 'expired'], true)) {
        throw new RuntimeException('Invalid documentation Promise Ledger event');
    }
    $status = static function ($value) use ($mysqli) {
        return $value === null || $value === '' ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
    };
    $action_sql = mysqli_real_escape_string($mysqli, $action);
    $reason_sql = mysqli_real_escape_string($mysqli, documentationNormalizeKey($reason_code, 'unspecified', 60));
    $context_hash_sql = $context ? documentationSqlValue(documentationAuditContextHash($context)) : 'NULL';
    $actor_id = max(0, intval($actor_id));
    $from_status_sql = $status($from_status);
    $to_status_sql = $status($to_status);
    documentationDbQuery("INSERT INTO documentation_promise_events SET
        documentation_promise_event_promise_id = $promise_id,
        documentation_promise_event_obligation_id = $obligation_id,
        documentation_promise_event_client_id = $client_id,
        documentation_promise_event_ticket_id = $ticket_id,
        documentation_promise_event_action = '$action_sql',
        documentation_promise_event_from_status = $from_status_sql,
        documentation_promise_event_to_status = $to_status_sql,
        documentation_promise_event_actor_id = $actor_id,
        documentation_promise_event_reason_code = '$reason_sql',
        documentation_promise_event_context_hash = $context_hash_sql", 'Could not append the Promise Ledger event');
}

function documentationCreatePromise(
    $obligation_id,
    $ticket_id,
    $reason_code,
    $reason,
    $due_at,
    $actor_id,
    $caller_transaction = false
) {
    global $mysqli;
    $obligation_id = intval($obligation_id);
    $ticket_id = max(0, intval($ticket_id));
    $actor_id = intval($actor_id);
    documentationRequireSupportLevel($actor_id, 2);
    $reason_code = documentationNormalizeKey($reason_code, '', 60);
    if (!in_array($reason_code, documentationPromiseReasonCodes(), true)) {
        throw new InvalidArgumentException('Unsupported documentation promise reason code');
    }
    $reason_redacted = documentationRedactAuditText($reason);
    if ($reason_redacted === '') {
        throw new InvalidArgumentException('A documentation promise reason is required');
    }
    $due_timestamp = strtotime((string) $due_at);
    if (!$due_timestamp || $due_timestamp <= time() || $due_timestamp > strtotime('+1 year')) {
        throw new InvalidArgumentException('The documentation promise due date must be within one year');
    }
    $due_at = date('Y-m-d H:i:s', $due_timestamp);
    documentationBeginMutation($caller_transaction);
    try {
        $expected_client_id = documentationObligationClientId($obligation_id);
        $ticket = $ticket_id
            ? documentationLockClientTicket($ticket_id, $expected_client_id)
            : null;
        if (!$ticket) {
            documentationLockClient($expected_client_id);
        }
        $obligation = documentationLoadObligationForMutation($obligation_id);
        $client_id = intval($obligation['documentation_obligation_client_id']);
        if ($ticket && intval($ticket['ticket_client_id']) !== $client_id) {
            throw new RuntimeException('The documentation promise ticket belongs to another client');
        }
        if ($ticket) {
            $link = mysqli_fetch_assoc(documentationDbQuery("SELECT ticket_documentation_obligation_id
                FROM ticket_documentation_obligations
                WHERE ticket_documentation_obligation_ticket_id = $ticket_id
                AND ticket_documentation_obligation_obligation_id = $obligation_id
                AND ticket_documentation_obligation_client_id = $client_id
                LIMIT 1 FOR UPDATE", 'Could not lock the documentation promise ticket link'));
            if (!$link) {
                throw new RuntimeException('A ticket documentation promise requires a linked obligation');
            }
        }
        $reason_code_sql = mysqli_real_escape_string($mysqli, $reason_code);
        $reason_redacted_sql = mysqli_real_escape_string($mysqli, $reason_redacted);
        $reason_hash = hash('sha256', trim((string) $reason));
        $reason_hash_sql = mysqli_real_escape_string($mysqli, $reason_hash);
        documentationDbQuery("INSERT INTO documentation_promise_ledger SET
            documentation_promise_client_id = $client_id,
            documentation_promise_obligation_id = $obligation_id,
            documentation_promise_ticket_id = $ticket_id,
            documentation_promise_status = 'Open',
            documentation_promise_reason_code = '$reason_code_sql',
            documentation_promise_reason_redacted = '$reason_redacted_sql',
            documentation_promise_reason_hash = '$reason_hash_sql',
            documentation_promise_due_at = " . documentationSqlValue($due_at) . ",
            documentation_promise_promised_by = $actor_id", 'Could not create the documentation promise');
        $promise_id = intval(mysqli_insert_id($mysqli));
        $promise = [
            'documentation_promise_id' => $promise_id,
            'documentation_promise_obligation_id' => $obligation_id,
            'documentation_promise_client_id' => $client_id,
            'documentation_promise_ticket_id' => $ticket_id,
        ];
        documentationRecordPromiseEvent($promise, 'created', null, 'Open', $actor_id, $reason_code, [
            'reason_hash' => $reason_hash,
            'due_at' => $due_at,
        ]);
        documentationRecordObligationEvent(
            $obligation,
            'promise_created',
            $obligation['documentation_obligation_base_status'],
            $obligation['documentation_obligation_base_status'],
            documentationObligationEffectiveStatus($obligation),
            documentationObligationEffectiveStatus($obligation),
            'agent',
            $actor_id,
            $reason_code,
            'promise',
            $promise_id
        );
        documentationCommitMutation($caller_transaction);
        return ['promise_id' => $promise_id, 'revision' => 1, 'status' => 'Open', 'due_at' => $due_at];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationCompletePromise(
    $promise_id,
    $status,
    $expected_revision,
    $actor_id,
    $caller_transaction = false
) {
    global $mysqli;
    $promise_id = intval($promise_id);
    $status = ucfirst(strtolower(trim((string) $status)));
    if (!in_array($status, ['Fulfilled', 'Cancelled'], true)) {
        throw new InvalidArgumentException('Unsupported documentation promise outcome');
    }
    $actor_id = intval($actor_id);
    documentationRequireSupportLevel($actor_id, 2);
    $pre = mysqli_fetch_assoc(documentationDbQuery("SELECT documentation_promise_ticket_id,
        documentation_promise_client_id, documentation_promise_obligation_id
        FROM documentation_promise_ledger
        WHERE documentation_promise_id = $promise_id LIMIT 1", 'Could not locate the documentation promise'));
    if (!$pre) {
        throw new RuntimeException('The documentation promise no longer exists');
    }
    documentationBeginMutation($caller_transaction);
    try {
        $ticket_id = intval($pre['documentation_promise_ticket_id']);
        $client_id = intval($pre['documentation_promise_client_id']);
        if ($ticket_id) {
            documentationLockClientTicket($ticket_id, $client_id);
        } else {
            documentationLockClient($client_id);
        }
        $obligation_id = intval($pre['documentation_promise_obligation_id']);
        $obligation = documentationLoadObligationForMutation($obligation_id);
        $promise = mysqli_fetch_assoc(documentationDbQuery("SELECT promise.*
            FROM documentation_promise_ledger promise
            WHERE promise.documentation_promise_id = $promise_id LIMIT 1 FOR UPDATE", 'Could not lock the documentation promise'));
        if (!$promise || $promise['documentation_promise_status'] !== 'Open') {
            throw new RuntimeException('The documentation promise is no longer open');
        }
        if (intval($promise['documentation_promise_ticket_id']) !== $ticket_id
            || intval($promise['documentation_promise_client_id']) !== $client_id
            || intval($promise['documentation_promise_obligation_id']) !== $obligation_id
            || intval($obligation['documentation_obligation_client_id']) !== $client_id) {
            throw new RuntimeException('The documentation promise client changed; refresh and try again');
        }
        $promise = array_merge($obligation, $promise);
        $revision = intval($promise['documentation_promise_revision']);
        if (intval($expected_revision) !== $revision) {
            throw new RuntimeException('The documentation promise changed; refresh and try again');
        }
        $status_sql = mysqli_real_escape_string($mysqli, $status);
        documentationDbQuery("UPDATE documentation_promise_ledger SET
            documentation_promise_status = '$status_sql',
            documentation_promise_fulfilled_by = $actor_id,
            documentation_promise_fulfilled_at = NOW(),
            documentation_promise_revision = documentation_promise_revision + 1
            WHERE documentation_promise_id = $promise_id
            AND documentation_promise_revision = $revision
            AND documentation_promise_status = 'Open'", 'Could not complete the documentation promise');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The documentation promise changed; refresh and try again');
        }
        $action = strtolower($status);
        documentationRecordPromiseEvent($promise, $action, 'Open', $status, $actor_id, 'promise_' . $action);
        documentationRecordObligationEvent(
            $promise,
            'promise_' . $action,
            $promise['documentation_obligation_base_status'],
            $promise['documentation_obligation_base_status'],
            documentationObligationEffectiveStatus($promise),
            documentationObligationEffectiveStatus($promise),
            'agent',
            $actor_id,
            'promise_' . $action,
            'promise',
            $promise_id
        );
        documentationCommitMutation($caller_transaction);
        return ['promise_id' => $promise_id, 'revision' => $revision + 1, 'status' => $status];
    } catch (Throwable $e) {
        documentationRollbackMutation($caller_transaction);
        throw $e;
    }
}

function documentationExpireObligationExceptions($limit = 100) {
    global $mysqli;
    $limit = max(1, min(1000, intval($limit)));
    $ids = [];
    $rows = documentationDbQuery("SELECT documentation_obligation_exception_id
        FROM documentation_obligation_exceptions
        WHERE documentation_obligation_exception_status IN ('Pending','Approved')
        AND documentation_obligation_exception_expires_at <= NOW()
        ORDER BY documentation_obligation_exception_expires_at, documentation_obligation_exception_id
        LIMIT $limit", 'Could not select expired documentation obligation exceptions');
    while ($row = mysqli_fetch_assoc($rows)) {
        $ids[] = intval($row['documentation_obligation_exception_id']);
    }
    $expired = 0;
    foreach ($ids as $exception_id) {
        if (!mysqli_begin_transaction($mysqli)) {
            error_log("Documentation exception $exception_id expiry could not start a transaction");
            continue;
        }
        try {
            $pre = mysqli_fetch_assoc(documentationDbQuery("SELECT
                documentation_obligation_exception_client_id,
                documentation_obligation_exception_obligation_id
                FROM documentation_obligation_exceptions
                WHERE documentation_obligation_exception_id = $exception_id LIMIT 1", 'Could not locate an expired documentation exception'));
            if (!$pre) {
                mysqli_rollback($mysqli);
                continue;
            }
            documentationLockClientForExpiry(intval($pre['documentation_obligation_exception_client_id']));
            $obligation_id = intval($pre['documentation_obligation_exception_obligation_id']);
            $obligation = mysqli_fetch_assoc(documentationDbQuery("SELECT obligation.*, version.*
                FROM client_documentation_obligations obligation
                INNER JOIN documentation_requirement_versions version
                    ON version.documentation_requirement_version_id = obligation.documentation_obligation_requirement_version_id
                WHERE obligation.documentation_obligation_id = $obligation_id
                LIMIT 1 FOR UPDATE", 'Could not lock the expired-exception obligation'));
            $exception = mysqli_fetch_assoc(documentationDbQuery("SELECT *
                FROM documentation_obligation_exceptions
                WHERE documentation_obligation_exception_id = $exception_id
                LIMIT 1 FOR UPDATE", 'Could not lock the expired documentation exception'));
            if (!$exception
                || !in_array($exception['documentation_obligation_exception_status'], ['Pending', 'Approved'], true)
                || strtotime((string) $exception['documentation_obligation_exception_expires_at']) > time()) {
                mysqli_rollback($mysqli);
                continue;
            }
            $from = (string) $exception['documentation_obligation_exception_status'];
            $exception_revision = intval($exception['documentation_obligation_exception_revision']);
            documentationDbQuery("UPDATE documentation_obligation_exceptions SET
                documentation_obligation_exception_status = 'Expired',
                documentation_obligation_exception_expired_at = NOW(),
                documentation_obligation_exception_revision = documentation_obligation_exception_revision + 1
                WHERE documentation_obligation_exception_id = $exception_id
                AND documentation_obligation_exception_revision = $exception_revision
                AND documentation_obligation_exception_status IN ('Pending','Approved')", 'Could not expire the documentation obligation exception');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The documentation obligation exception changed during expiry');
            }
            documentationRecordObligationExceptionEvent(
                $exception,
                'expired',
                $from,
                'Expired',
                0,
                'exception_expired'
            );
            if ($obligation
                && intval($obligation['documentation_obligation_client_id'])
                    === intval($exception['documentation_obligation_exception_client_id'])
                && intval($obligation['documentation_obligation_exception_id']) === $exception_id) {
                $obligation_revision = intval($obligation['documentation_obligation_revision']);
                $base_status = (string) $obligation['documentation_obligation_base_status'];
                documentationDbQuery("UPDATE client_documentation_obligations SET
                    documentation_obligation_exception_status = 'Expired',
                    documentation_obligation_exception_expired_event_at = NOW(),
                    documentation_obligation_revision = documentation_obligation_revision + 1
                    WHERE documentation_obligation_id = $obligation_id
                    AND documentation_obligation_revision = $obligation_revision
                    AND documentation_obligation_exception_id = $exception_id", 'Could not expire the current documentation exception projection');
                if (mysqli_affected_rows($mysqli) !== 1) {
                    throw new RuntimeException('The current documentation exception projection changed during expiry');
                }
                $obligation['documentation_obligation_exception_status'] = 'Expired';
                documentationRecordObligationEvent(
                    $obligation,
                    'exception_expired',
                    $base_status,
                    $base_status,
                    $from === 'Approved' ? 'Exception' : $base_status,
                    documentationObligationEffectiveStatus($obligation),
                    'system',
                    0,
                    'exception_expired',
                    'exception',
                    $exception_id
                );
            }
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the documentation exception expiry');
            }
            $expired++;
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            error_log("Documentation exception $exception_id expiry failed: " . $e->getMessage());
        }
    }
    return $expired;
}

function documentationExpirePromises($limit = 100) {
    global $mysqli;
    $limit = max(1, min(1000, intval($limit)));
    $ids = [];
    $rows = documentationDbQuery("SELECT documentation_promise_id FROM documentation_promise_ledger
        WHERE documentation_promise_status = 'Open' AND documentation_promise_due_at <= NOW()
        ORDER BY documentation_promise_due_at, documentation_promise_id LIMIT $limit", 'Could not select expired documentation promises');
    while ($row = mysqli_fetch_assoc($rows)) {
        $ids[] = intval($row['documentation_promise_id']);
    }
    $expired = 0;
    foreach ($ids as $promise_id) {
        if (!mysqli_begin_transaction($mysqli)) {
            error_log("Documentation promise $promise_id expiry could not start a transaction");
            continue;
        }
        try {
            $pre = mysqli_fetch_assoc(documentationDbQuery("SELECT documentation_promise_client_id
                FROM documentation_promise_ledger WHERE documentation_promise_id = $promise_id LIMIT 1", 'Could not locate an expired promise'));
            if (!$pre) {
                mysqli_rollback($mysqli);
                continue;
            }
            documentationLockClientForExpiry(intval($pre['documentation_promise_client_id']));
            $promise = mysqli_fetch_assoc(documentationDbQuery("SELECT promise.*, obligation.*
                FROM documentation_promise_ledger promise
                INNER JOIN client_documentation_obligations obligation
                    ON obligation.documentation_obligation_id = promise.documentation_promise_obligation_id
                WHERE promise.documentation_promise_id = $promise_id LIMIT 1 FOR UPDATE", 'Could not lock an expired promise'));
            if (!$promise || $promise['documentation_promise_status'] !== 'Open'
                || strtotime((string) $promise['documentation_promise_due_at']) > time()) {
                mysqli_rollback($mysqli);
                continue;
            }
            $revision = intval($promise['documentation_promise_revision']);
            documentationDbQuery("UPDATE documentation_promise_ledger SET
                documentation_promise_status = 'Expired',
                documentation_promise_revision = documentation_promise_revision + 1
                WHERE documentation_promise_id = $promise_id
                AND documentation_promise_revision = $revision
                AND documentation_promise_status = 'Open'", 'Could not expire the documentation promise');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The documentation promise changed during expiry');
            }
            documentationRecordPromiseEvent($promise, 'expired', 'Open', 'Expired', 0, 'promise_expired');
            documentationRecordObligationEvent(
                $promise,
                'promise_expired',
                $promise['documentation_obligation_base_status'],
                $promise['documentation_obligation_base_status'],
                documentationObligationEffectiveStatus($promise),
                documentationObligationEffectiveStatus($promise),
                'system',
                0,
                'promise_expired',
                'promise',
                $promise_id
            );
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the documentation promise expiry');
            }
            $expired++;
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            error_log("Documentation promise $promise_id expiry failed: " . $e->getMessage());
        }
    }
    return $expired;
}

function documentationExpireTicketWaivers($limit = 100) {
    global $mysqli;
    $limit = max(1, min(1000, intval($limit)));
    $ids = [];
    $rows = documentationDbQuery("SELECT ticket_documentation_waiver_id FROM ticket_documentation_waivers
        WHERE ticket_documentation_waiver_status IN ('Pending','Approved')
        AND ticket_documentation_waiver_expires_at <= NOW()
        ORDER BY ticket_documentation_waiver_expires_at, ticket_documentation_waiver_id LIMIT $limit", 'Could not select expired ticket documentation waivers');
    while ($row = mysqli_fetch_assoc($rows)) {
        $ids[] = intval($row['ticket_documentation_waiver_id']);
    }
    $expired = 0;
    foreach ($ids as $waiver_id) {
        if (!mysqli_begin_transaction($mysqli)) {
            error_log("Ticket documentation waiver $waiver_id expiry could not start a transaction");
            continue;
        }
        try {
            $pre = mysqli_fetch_assoc(documentationDbQuery("SELECT link.ticket_documentation_obligation_client_id
                FROM ticket_documentation_waivers waiver
                INNER JOIN ticket_documentation_obligations link
                    ON link.ticket_documentation_obligation_id = waiver.ticket_documentation_waiver_link_id
                WHERE waiver.ticket_documentation_waiver_id = $waiver_id LIMIT 1", 'Could not locate an expired ticket documentation waiver'));
            if (!$pre) {
                mysqli_rollback($mysqli);
                continue;
            }
            documentationLockClientForExpiry(intval($pre['ticket_documentation_obligation_client_id']));
            $waiver = mysqli_fetch_assoc(documentationDbQuery("SELECT waiver.*, link.*,
                obligation.*, version.* FROM ticket_documentation_waivers waiver
                INNER JOIN ticket_documentation_obligations link
                    ON link.ticket_documentation_obligation_id = waiver.ticket_documentation_waiver_link_id
                INNER JOIN client_documentation_obligations obligation
                    ON obligation.documentation_obligation_id = link.ticket_documentation_obligation_obligation_id
                INNER JOIN documentation_requirement_versions version
                    ON version.documentation_requirement_version_id = obligation.documentation_obligation_requirement_version_id
                WHERE waiver.ticket_documentation_waiver_id = $waiver_id LIMIT 1 FOR UPDATE", 'Could not lock an expired ticket documentation waiver'));
            if (!$waiver || !in_array($waiver['ticket_documentation_waiver_status'], ['Pending', 'Approved'], true)
                || strtotime((string) $waiver['ticket_documentation_waiver_expires_at']) > time()) {
                mysqli_rollback($mysqli);
                continue;
            }
            $from = $waiver['ticket_documentation_waiver_status'];
            $revision = intval($waiver['ticket_documentation_waiver_revision']);
            documentationDbQuery("UPDATE ticket_documentation_waivers SET
                ticket_documentation_waiver_status = 'Expired',
                ticket_documentation_waiver_revision = ticket_documentation_waiver_revision + 1
                WHERE ticket_documentation_waiver_id = $waiver_id
                AND ticket_documentation_waiver_revision = $revision", 'Could not expire the ticket documentation waiver');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The ticket documentation waiver changed during expiry');
            }
            documentationRecordTicketWaiverEvent($waiver, 'expired', $from, 'Expired', 0, 'waiver_expired');
            documentationRecordObligationEvent(
                $waiver,
                'waiver_expired',
                $waiver['documentation_obligation_base_status'],
                $waiver['documentation_obligation_base_status'],
                documentationObligationEffectiveStatus($waiver),
                documentationObligationEffectiveStatus($waiver),
                'system',
                0,
                'waiver_expired',
                'ticket-waiver',
                $waiver_id
            );
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the ticket documentation waiver expiry');
            }
            $expired++;
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            error_log("Ticket documentation waiver $waiver_id expiry failed: " . $e->getMessage());
        }
    }
    return $expired;
}
