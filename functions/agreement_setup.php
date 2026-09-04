<?php

/* Agreement setup uses the existing versioned definition, not client defaults. */
function agreementSetupScopes(): array
{
    return ['users' => 'Users', 'devices' => 'Devices', 'services' => 'Services', 'locations' => 'Locations'];
}

function agreementSetupText($value, string $label, int $limit, bool $required = false): string
{
    if (!is_string($value) && !is_numeric($value)) {
        throw new InvalidArgumentException("$label is invalid");
    }
    $value = trim((string) $value);
    if (($required && $value === '') || strlen($value) > $limit) {
        throw new InvalidArgumentException("Enter $label (up to $limit characters)");
    }
    return $value;
}

function agreementSetupInteger($value, string $label, int $min, int $max): int
{
    $value = agreementSetupText($value, $label, 12, true);
    if (!preg_match('/^\d+$/D', $value) || (float) $value < $min || (float) $value > $max) {
        throw new InvalidArgumentException("$label must be a whole number from $min to $max");
    }
    return intval($value);
}

function agreementSetupRememberInput(array $input): array
{
    $keys = ['client_id', 'name', 'type', 'effective_from', 'effective_until',
        'review_cadence_months', 'renewal_notice_days', 'scope_notes',
        'responsibilities', 'escalation', 'review_notes', 'calendar', 'coverage', 'sla', 'exceptions'];
    $saved = array_intersect_key($input, array_flip($keys));
    // Never retain a CSRF token or an unbounded request in the session.
    $encoded = json_encode($saved);
    return $encoded !== false && strlen($encoded) <= 65536 ? $saved : [];
}

function agreementSetupCalendar(array $input): array
{
    $mode = agreementSetupText($input['mode'] ?? '', 'support calendar', 20, true);
    if (!in_array($mode, ['24x7', 'business_hours'], true)) {
        throw new InvalidArgumentException('Choose business hours or 24/7 support');
    }
    $timezone = agreementSetupText($input['timezone'] ?? '', 'support timezone', 64, true);
    if (!in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
        throw new InvalidArgumentException('Choose a named support timezone, such as America/New_York');
    }
    $days = [];
    $start = $end = '';
    if ($mode === 'business_hours') {
        if (!is_array($input['days'] ?? null) || count($input['days']) < 1 || count($input['days']) > 7) {
            throw new InvalidArgumentException('Choose at least one support day');
        }
        foreach ($input['days'] as $day) {
            $days[] = agreementSetupInteger($day, 'Support day', 1, 7);
        }
        foreach (['start', 'end'] as $key) {
            $time = agreementSetupText($input[$key] ?? '', "support $key time", 5, true);
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
                throw new InvalidArgumentException('Enter support times in HH:MM format');
            }
            if ($key === 'start') {
                $start = $time . ':00';
            } else {
                $end = $time . ':00';
            }
        }
        if ($start >= $end) {
            throw new InvalidArgumentException('Support must end after it starts on the same day');
        }
    }
    return slaNormalizeCalendarSnapshot(['calendar_mode' => $mode, 'business_days' => $days,
        'business_hours_start' => $start, 'business_hours_end' => $end, 'timezone' => $timezone]);
}

function agreementSetupCalendarLabel(array $calendar): string
{
    if ($calendar['calendar_mode'] === '24x7') {
        return '24/7 (' . $calendar['timezone'] . ')';
    }
    $names = [1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $days = array_map(static fn($day) => $names[$day], $calendar['business_days']);
    return implode(',', $days) . ' ' . substr($calendar['business_hours_start'], 0, 5)
        . '-' . substr($calendar['business_hours_end'], 0, 5) . ' ' . $calendar['timezone'];
}

/* Only fixed table/column names supplied below reach this internal writer. */
function agreementSetupInsert(string $table, array $values): int
{
    global $mysqli;
    $parts = [];
    foreach ($values as $column => $value) {
        $parts[] = "`$column` = " . (is_null($value) ? 'NULL'
            : "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'");
    }
    agreementDbQuery("INSERT INTO `$table` SET " . implode(', ', $parts), 'Could not save agreement setup');
    return intval(mysqli_insert_id($mysqli));
}

function agreementCreateFromSetup(array $input, int $client_id, int $actor_id): array
{
    global $mysqli;
    if ($client_id <= 0 || $actor_id <= 0 || ($input['setup_version'] ?? '') !== '1') {
        throw new InvalidArgumentException('Use the complete agreement setup form to create an agreement');
    }
    $name = agreementSetupText($input['name'] ?? '', 'agreement name', 255, true);
    $type = agreementSetupText($input['type'] ?? '', 'agreement type', 50, true);
    if (!in_array($type, ['Fully Managed', 'Partially Managed', 'Project', 'Break/Fix', 'Other'], true)) {
        throw new InvalidArgumentException('Choose an agreement type');
    }
    $dates = [];
    foreach (['effective_from', 'effective_until'] as $key) {
        $value = agreementSetupText($input[$key] ?? '', str_replace('_', ' ', $key), 10, $key === 'effective_from');
        $date = $value === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($value !== '' && (!$date || $date->format('Y-m-d') !== $value)) {
            throw new InvalidArgumentException('Enter valid agreement dates');
        }
        $dates[$key] = $value === '' ? null : $value;
    }
    if ($dates['effective_until'] && $dates['effective_until'] < $dates['effective_from']) {
        throw new InvalidArgumentException('The agreement end date cannot precede its start date');
    }
    $cadence = agreementSetupInteger($input['review_cadence_months'] ?? '', 'Review cadence', 1, 24);
    $notice = agreementSetupInteger($input['renewal_notice_days'] ?? '', 'Renewal notice', 0, 365);
    foreach (['calendar', 'coverage', 'sla'] as $key) {
        if (!is_array($input[$key] ?? null)) {
            throw new InvalidArgumentException('Complete coverage, support hours and SLA targets before saving');
        }
    }
    $calendar = agreementSetupCalendar($input['calendar']);
    $support_hours = agreementSetupText(agreementSetupCalendarLabel($calendar), 'support hours', 100, true);
    $details = [];
    foreach (['scope_notes' => 'Service scope and exclusions', 'responsibilities' => 'Client responsibilities',
        'escalation' => 'Escalation and after-hours process', 'review_notes' => 'Onboarding and business reviews'] as $key => $label) {
        $value = agreementSetupText($input[$key] ?? '', strtolower($label), 4000);
        if ($value !== '') {
            $details[] = $label . "\n" . $value;
        }
    }

    $entitlements = [];
    foreach (agreementSetupScopes() as $scope => $label) {
        $row = $input['coverage'][$scope] ?? null;
        if (!is_array($row) || !in_array($row['classification'] ?? '', ['included', 'billable', 'excluded'], true)) {
            throw new InvalidArgumentException("Choose coverage for $label");
        }
        $limit = agreementSetupText($row['limit'] ?? '', "$label limit", 13);
        if ($limit !== '' && (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $limit) || (float) $limit > 9999999999.99)) {
            throw new InvalidArgumentException("Enter a valid $label quantity limit, or leave it blank");
        }
        $entitlements[] = ['scope' => $scope, 'id' => 0, 'label' => 'All active ' . strtolower($label),
            'classification' => $row['classification'], 'limit' => $limit === '' ? null : $limit, 'notes' => ''];
    }
    $exceptions = $input['exceptions'] ?? [];
    if (!is_array($exceptions) || count($exceptions) > 20) {
        throw new InvalidArgumentException('Use no more than 20 specific coverage exceptions during setup');
    }

    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not start agreement setup');
    }
    try {
        $client = agreementLockClientForAuditRetention($client_id);
        if (!$client || !empty($client['client_archived_at'])) {
            throw new InvalidArgumentException('Choose an active client');
        }
        $seen = [];
        foreach ($exceptions as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('A coverage exception is invalid');
            }
            $record = agreementSetupText($row['record'] ?? '', 'exception record', 40);
            $notes = agreementSetupText($row['notes'] ?? '', 'exception notes', 1000);
            if ($record === '' && $notes === '') {
                continue;
            }
            if (!preg_match('/^(users|devices|services|locations):([1-9]\d{0,9})$/D', $record, $match)
                || isset($seen[$record]) || !in_array($row['classification'] ?? '', ['included', 'billable', 'excluded'], true)) {
                throw new InvalidArgumentException('Choose a distinct client record and coverage for each exception');
            }
            $scope = $match[1];
            $id = intval($match[2]);
            if (!agreementValidateEntitlementScope($client_id, $scope, $id, true)) {
                throw new InvalidArgumentException('A selected coverage record is unavailable or belongs to another client');
            }
            $seen[$record] = true;
            $entitlements[] = ['scope' => $scope, 'id' => $id,
                'label' => agreementEntitlementScopeLabel($client_id, $scope, $id, true),
                'classification' => $row['classification'], 'limit' => null, 'notes' => $notes];
        }

        $rules = [];
        foreach (array_keys(ticketPriorityDefinitions()) as $priority) {
            $row = $input['sla'][$priority] ?? null;
            if (!is_array($row)) {
                throw new InvalidArgumentException("Choose SLA terms for $priority priority");
            }
            $profile_id = agreementSetupInteger($row['profile_id'] ?? '', "$priority SLA profile", 0, 2147483647);
            if ($profile_id === 0) {
                $rules[$priority] = slaSnapshotFromRecord(['sla_id' => 0]);
                continue;
            }
            $profile_sql = agreementDbQuery("SELECT sla_id, sla_name FROM slas
                WHERE sla_id = $profile_id AND sla_archived_at IS NULL LIMIT 1 FOR UPDATE",
                'Could not load the SLA profile');
            $profile = mysqli_fetch_assoc($profile_sql);
            if (!$profile) {
                throw new InvalidArgumentException("The $priority SLA profile is no longer available; choose another");
            }
            $response = agreementSetupInteger($row['response'] ?? '', "$priority response target", 0, 1051200);
            $resolution_value = agreementSetupText($row['resolution'] ?? '', "$priority resolution target", 12);
            $resolution = $resolution_value === '' ? null
                : agreementSetupInteger($resolution_value, "$priority resolution target", 0, 1051200);
            if ($response > 0 && $resolution > 0 && $resolution < $response) {
                throw new InvalidArgumentException("$priority resolution target cannot be shorter than its response target");
            }
            $rules[$priority] = ['sla_id' => $profile_id, 'sla_name' => $profile['sla_name'],
                'response_minutes' => $response, 'resolution_minutes' => $resolution] + $calendar;
        }

        $contract_id = agreementSetupInsert('contracts', [
            'contract_name' => $name, 'contract_status' => 'Draft', 'contract_type' => $type,
            'contract_details' => implode("\n\n", $details), 'contract_client_id' => $client_id,
            'contract_client_name' => $client['client_name'], 'contract_support_hours' => $support_hours,
            'contract_start_date' => $dates['effective_from'], 'contract_end_date' => $dates['effective_until'],
            'contract_review_cadence_months' => $cadence]);
        $version_id = agreementSetupInsert('agreement_versions', [
            'agreement_version_contract_id' => $contract_id, 'agreement_version_number' => 1,
            'agreement_version_name' => $name, 'agreement_version_type' => $type,
            'agreement_version_effective_from' => $dates['effective_from'],
            'agreement_version_effective_until' => $dates['effective_until'],
            'agreement_version_support_hours' => $support_hours, 'agreement_version_review_cadence_months' => $cadence,
            'agreement_version_renewal_notice_days' => $notice, 'agreement_version_details' => implode("\n\n", $details),
            'agreement_version_created_by' => $actor_id]);
        foreach ($entitlements as $row) {
            agreementSetupInsert('agreement_entitlements', [
                'agreement_entitlement_version_id' => $version_id, 'agreement_entitlement_scope_type' => $row['scope'],
                'agreement_entitlement_scope_id' => $row['id'], 'agreement_entitlement_scope_key' => '*',
                'agreement_entitlement_scope_label' => $row['label'],
                'agreement_entitlement_classification' => $row['classification'],
                'agreement_entitlement_quantity_limit' => $row['limit'], 'agreement_entitlement_notes' => $row['notes']]);
        }
        foreach ($rules as $priority => $rule) {
            agreementSetupInsert('agreement_sla_rules', [
                'agreement_sla_rule_version_id' => $version_id, 'agreement_sla_rule_request_type_key' => '*',
                'agreement_sla_rule_priority' => $priority, 'agreement_sla_rule_sla_id' => $rule['sla_id'],
                'agreement_sla_rule_sla_name' => $rule['sla_name'],
                'agreement_sla_rule_response_minutes' => $rule['response_minutes'],
                'agreement_sla_rule_resolution_minutes' => $rule['resolution_minutes'],
                'agreement_sla_rule_calendar_mode' => $rule['calendar_mode'],
                'agreement_sla_rule_business_days' => $rule['business_days'] ? implode(',', $rule['business_days']) : null,
                'agreement_sla_rule_business_hours_start' => $rule['business_hours_start'],
                'agreement_sla_rule_business_hours_end' => $rule['business_hours_end'],
                'agreement_sla_rule_timezone' => $rule['timezone'],
                'agreement_sla_rule_classification' => 'included', 'agreement_sla_rule_classification_basis' => 'explicit_rule',
                'agreement_sla_rule_behavior_version' => 1, 'agreement_sla_rule_sla_eligible' => 1,
                'agreement_sla_rule_ticket_onsite' => 0, 'agreement_sla_rule_ticket_billable' => 0,
                'agreement_sla_rule_order' => 0]);
        }
        agreementSetupInsert('agreement_version_events', [
            'agreement_version_event_contract_id' => $contract_id, 'agreement_version_event_version_id' => $version_id,
            'agreement_version_event_action' => 'Drafted', 'agreement_version_event_actor_id' => $actor_id,
            'agreement_version_event_reason' => 'Agreement setup saved with coverage, SLA targets, support calendar and review cadence']);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit agreement setup');
        }
        return ['contract_id' => $contract_id, 'version_id' => $version_id, 'name' => $name];
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}
