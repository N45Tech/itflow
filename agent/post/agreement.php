<?php

/*
 * ITFlow - Agreement entitlement and service-review request handler
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$agreement_actions = [
    'add_agreement',
    'edit_agreement_draft',
    'add_agreement_entitlement',
    'delete_agreement_entitlement',
    'add_agreement_sla_rule',
    'delete_agreement_sla_rule',
    'publish_agreement_version',
    'create_agreement_draft',
    'generate_service_review',
    'publish_service_review',
];

$agreement_action = null;
foreach ($agreement_actions as $candidate) {
    if (isset($_POST[$candidate])) {
        $agreement_action = $candidate;
        break;
    }
}

if ($agreement_action !== null) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $agreement_date = static function ($value, string $label, bool $allow_empty = true): ?string {
        $value = trim((string) $value);
        if ($value === '' && $allow_empty) {
            return null;
        }
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException("$label is not a valid date");
        }
        return $value;
    };

    // Every child-row mutation takes the client, contract, then definition
    // lock. Evidence writers and hard deletion use that same order.
    $agreement_mutate_draft = static function (int $version_id, callable $mutation) use ($mysqli): array {
        $pre_version = agreementVersionContext($version_id);
        if (!$pre_version) {
            throw new RuntimeException('Agreement version not found');
        }
        $contract_id = intval($pre_version['agreement_version_contract_id']);
        $client_id = intval($pre_version['contract_client_id']);
        mysqli_begin_transaction($mysqli);
        try {
            if (!agreementLockClientForAuditRetention($client_id)) {
                throw new RuntimeException('The agreement client no longer exists');
            }
            agreementDbQuery("SELECT contract_id FROM contracts
                WHERE contract_id = $contract_id LIMIT 1 FOR UPDATE",
                'Could not lock the agreement for draft mutation');
            $locked_version = agreementVersionContext($version_id, true);
            if (!$locked_version
                || intval($locked_version['agreement_version_contract_id']) !== $contract_id
                || intval($locked_version['contract_client_id']) !== $client_id
                || $locked_version['agreement_version_status'] !== 'Draft') {
                throw new RuntimeException('Published agreement versions are immutable; create a new draft instead');
            }
            $mutation($locked_version);
            mysqli_commit($mysqli);
            return $locked_version;
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            throw $e;
        }
    };

    $return_url = 'agreements.php';

    try {
        if ($agreement_action === 'add_agreement') {
            require_once __DIR__ . '/../../functions/agreement_setup.php';
            $client_id = intval($_POST['client_id'] ?? 0);
            enforceClientAccess($client_id);
            $return_url = "agreement_create.php?client_id=$client_id";
            $_SESSION['agreement_setup_input'] = agreementSetupRememberInput($_POST);
            $created = agreementCreateFromSetup($_POST, $client_id, intval($session_user_id));
            $contract_id = $created['contract_id'];
            $version_id = $created['version_id'];
            unset($_SESSION['agreement_setup_input'], $_SESSION['agreement_setup_error']);
            logAudit('Agreement', 'Create', "$session_name created agreement " . $created['name'], $client_id, $contract_id);
            flashAlert('Complete agreement draft saved with coverage, SLA targets, support hours and review cadence. Review it below, then publish when approved.');
            redirect("agreement.php?agreement_id=$contract_id&version_id=$version_id");
        }

        if ($agreement_action === 'edit_agreement_draft') {
            $version_id = intval($_POST['version_id'] ?? 0);
            $version = agreementVersionContext($version_id);
            if (!$version) {
                throw new RuntimeException('Agreement draft not found');
            }
            $contract_id = intval($version['agreement_version_contract_id']);
            $client_id = intval($version['contract_client_id']);
            $return_url = "agreement.php?agreement_id=$contract_id&version_id=$version_id";
            enforceClientAccess($client_id);
            if ($version['agreement_version_status'] !== 'Draft') {
                throw new RuntimeException('Published agreement versions are immutable; create a new draft instead');
            }

            $name_value = trim((string) ($_POST['name'] ?? ''));
            $type_value = trim((string) ($_POST['type'] ?? ''));
            if ($name_value === '' || $type_value === '') {
                throw new RuntimeException('Agreement name and type are required');
            }
            $effective_from = $agreement_date($_POST['effective_from'] ?? '', 'Effective-from date');
            $effective_until = $agreement_date($_POST['effective_until'] ?? '', 'Effective-until date');
            if ($effective_from && $effective_until && $effective_until < $effective_from) {
                throw new RuntimeException('Effective-until date must not precede effective-from date');
            }
            $cadence = max(1, min(24, intval($_POST['review_cadence_months'] ?? 3)));
            $notice = max(0, min(365, intval($_POST['renewal_notice_days'] ?? 90)));
            $name = mysqli_real_escape_string($mysqli, substr($name_value, 0, 255));
            $type = mysqli_real_escape_string($mysqli, substr($type_value, 0, 50));
            $support_hours = mysqli_real_escape_string($mysqli, substr(trim((string) ($_POST['support_hours'] ?? '')), 0, 100));
            $details = mysqli_real_escape_string($mysqli, trim((string) ($_POST['details'] ?? '')));
            $from_sql = is_null($effective_from) ? 'NULL' : "'$effective_from'";
            $until_sql = is_null($effective_until) ? 'NULL' : "'$effective_until'";

            $agreement_mutate_draft($version_id, static function () use (
                $version_id,
                $name,
                $type,
                $from_sql,
                $until_sql,
                $support_hours,
                $cadence,
                $notice,
                $details
            ): void {
                agreementDbQuery("UPDATE agreement_versions SET
                    agreement_version_name = '$name', agreement_version_type = '$type',
                    agreement_version_effective_from = $from_sql,
                    agreement_version_effective_until = $until_sql,
                    agreement_version_support_hours = '$support_hours',
                    agreement_version_review_cadence_months = $cadence,
                    agreement_version_renewal_notice_days = $notice,
                    agreement_version_details = '$details'
                    WHERE agreement_version_id = $version_id AND agreement_version_status = 'Draft'",
                    'Could not update the agreement draft');
            });
            logAudit('Agreement', 'Edit', "$session_name edited agreement draft $name_value", $client_id, $contract_id);
            flashAlert('Agreement draft updated');
            redirect($return_url);
        }

        if ($agreement_action === 'add_agreement_entitlement') {
            $version_id = intval($_POST['version_id'] ?? 0);
            $version = agreementVersionContext($version_id);
            if (!$version || $version['agreement_version_status'] !== 'Draft') {
                throw new RuntimeException('Entitlements can only be changed on an agreement draft');
            }
            $contract_id = intval($version['agreement_version_contract_id']);
            $client_id = intval($version['contract_client_id']);
            $return_url = "agreement.php?agreement_id=$contract_id&version_id=$version_id";
            enforceClientAccess($client_id);

            $scope_type = trim((string) ($_POST['scope_type'] ?? ''));
            $classification = trim((string) ($_POST['classification'] ?? ''));
            if (!isset(agreementScopeTypes()[$scope_type]) || !isset(agreementClassifications()[$classification])) {
                throw new RuntimeException('Select a valid entitlement scope and classification');
            }
            $scope_id = intval($_POST['scope_id'] ?? 0);
            if (!agreementValidateEntitlementScope($client_id, $scope_type, $scope_id)) {
                throw new RuntimeException('The selected entitlement record does not belong to this client');
            }
            $scope_key_value = agreementNormalizeRequestTypeKey($_POST['scope_key'] ?? '*');
            if (!in_array($scope_type, ['services', 'hours'], true) || $scope_id > 0) {
                $scope_key_value = '*';
            }
            if ($scope_type === 'hours' && !in_array(
                $scope_key_value,
                ['*', 'all-hours', '24x7', 'business-hours', 'after-hours'],
                true
            )) {
                throw new RuntimeException('Hours entitlements require a supported immutable hours key');
            }
            $label_value = trim((string) ($_POST['scope_label'] ?? ''));
            if ($scope_id > 0) {
                $label_value = agreementEntitlementScopeLabel($client_id, $scope_type, $scope_id) ?? '';
            }
            if ($label_value === '') {
                throw new RuntimeException('Entitlement label is required');
            }
            $quantity_raw = trim((string) ($_POST['quantity_limit'] ?? ''));
            if ($quantity_raw !== '' && (!is_numeric($quantity_raw)
                || floatval($quantity_raw) < 0 || floatval($quantity_raw) > 9999999999.99)) {
                throw new RuntimeException('Quantity limit must be between 0 and 9,999,999,999.99');
            }
            $quantity_sql = $quantity_raw === '' ? 'NULL' : number_format(floatval($quantity_raw), 2, '.', '');
            $scope_key = mysqli_real_escape_string($mysqli, $scope_key_value);
            $notes = mysqli_real_escape_string($mysqli, trim((string) ($_POST['notes'] ?? '')));
            $scope_type_sql = mysqli_real_escape_string($mysqli, $scope_type);
            $classification_sql = mysqli_real_escape_string($mysqli, $classification);
            $agreement_mutate_draft($version_id, static function () use (
                $version_id,
                $scope_type_sql,
                $scope_id,
                $scope_key,
                $label_value,
                $quantity_sql,
                $classification_sql,
                $notes,
                $client_id,
                $scope_type,
                $mysqli
            ): void {
                if (!agreementValidateEntitlementScope($client_id, $scope_type, $scope_id, true)) {
                    throw new RuntimeException('The selected entitlement record no longer belongs to this client');
                }
                $locked_label_value = $scope_id > 0
                    ? agreementEntitlementScopeLabel($client_id, $scope_type, $scope_id, true)
                    : $label_value;
                if (is_null($locked_label_value) || trim($locked_label_value) === '') {
                    throw new RuntimeException('The selected entitlement label is no longer available');
                }
                $label = mysqli_real_escape_string($mysqli, substr($locked_label_value, 0, 255));
                agreementDbQuery("INSERT INTO agreement_entitlements SET
                    agreement_entitlement_version_id = $version_id,
                    agreement_entitlement_scope_type = '$scope_type_sql',
                    agreement_entitlement_scope_id = $scope_id,
                    agreement_entitlement_scope_key = '$scope_key',
                    agreement_entitlement_scope_label = '$label',
                    agreement_entitlement_quantity_limit = $quantity_sql,
                    agreement_entitlement_classification = '$classification_sql',
                    agreement_entitlement_notes = '$notes'", 'Could not add the agreement entitlement');
            });
            flashAlert('Agreement entitlement added');
            redirect($return_url);
        }

        if ($agreement_action === 'delete_agreement_entitlement') {
            $entitlement_id = intval($_POST['entitlement_id'] ?? 0);
            $sql = agreementDbQuery("SELECT agreement_entitlement_version_id FROM agreement_entitlements
                WHERE agreement_entitlement_id = $entitlement_id LIMIT 1", 'Could not load the entitlement');
            if (!mysqli_num_rows($sql)) {
                throw new RuntimeException('Agreement entitlement not found');
            }
            $version_id = intval(mysqli_fetch_assoc($sql)['agreement_entitlement_version_id']);
            $version = agreementVersionContext($version_id);
            $contract_id = intval($version['agreement_version_contract_id'] ?? 0);
            $return_url = "agreement.php?agreement_id=$contract_id&version_id=$version_id";
            if (!$version || $version['agreement_version_status'] !== 'Draft') {
                throw new RuntimeException('Published agreement entitlements are immutable');
            }
            enforceClientAccess(intval($version['contract_client_id']));
            $agreement_mutate_draft($version_id, static function () use ($entitlement_id, $version_id): void {
                agreementDbQuery("DELETE FROM agreement_entitlements
                    WHERE agreement_entitlement_id = $entitlement_id
                    AND agreement_entitlement_version_id = $version_id LIMIT 1",
                    'Could not remove the agreement entitlement');
            });
            flashAlert('Agreement entitlement removed', 'error');
            redirect($return_url);
        }

        if ($agreement_action === 'add_agreement_sla_rule') {
            $version_id = intval($_POST['version_id'] ?? 0);
            $version = agreementVersionContext($version_id);
            if (!$version || $version['agreement_version_status'] !== 'Draft') {
                throw new RuntimeException('SLA rules can only be changed on an agreement draft');
            }
            $contract_id = intval($version['agreement_version_contract_id']);
            $client_id = intval($version['contract_client_id']);
            $return_url = "agreement.php?agreement_id=$contract_id&version_id=$version_id";
            enforceClientAccess($client_id);

            $request_type_key = agreementNormalizeRequestTypeKey($_POST['request_type_key'] ?? '*');
            $priority = agreementNormalizePriority($_POST['priority'] ?? '*');
            if ($priority !== '*' && !array_key_exists($priority, ticketPriorityDefinitions())) {
                throw new RuntimeException('Select a valid SLA rule priority');
            }
            $classification = trim((string) ($_POST['classification'] ?? 'included'));
            if (!isset(agreementClassifications()[$classification])) {
                throw new RuntimeException('Select a valid request classification');
            }
            $behavior = agreementClassificationBehavior($classification);
            $sla_id = max(0, intval($_POST['sla_id'] ?? 0));
            if (!$behavior['sla_eligible'] && $sla_id > 0) {
                throw new RuntimeException('Excluded requests are SLA-ineligible; select SLA None');
            }
            if ($sla_id > 0) {
                $sla_sql = agreementDbQuery("SELECT sla_id, sla_name, sla_response_minutes,
                    sla_resolution_minutes FROM slas
                    WHERE sla_id = $sla_id AND sla_archived_at IS NULL LIMIT 1",
                    'Could not validate the SLA');
                if (!mysqli_num_rows($sla_sql)) {
                    throw new RuntimeException('The selected SLA is unavailable');
                }
                $sla_snapshot = slaSnapshotFromRecord(mysqli_fetch_assoc($sla_sql));
            } else {
                $sla_snapshot = slaSnapshotFromRecord(['sla_id' => 0]);
            }
            $order = max(-2147483648, min(2147483647, intval($_POST['rule_order'] ?? 0)));
            $request_type_sql = mysqli_real_escape_string($mysqli, $request_type_key);
            $priority_sql = mysqli_real_escape_string($mysqli, $priority);
            $classification_sql = mysqli_real_escape_string($mysqli, $classification);
            $sla_name_sql = mysqli_real_escape_string($mysqli, substr($sla_snapshot['sla_name'], 0, 200));
            $response_minutes_sql = is_null($sla_snapshot['response_minutes'])
                ? 'NULL' : intval($sla_snapshot['response_minutes']);
            $resolution_minutes_sql = is_null($sla_snapshot['resolution_minutes'])
                ? 'NULL' : intval($sla_snapshot['resolution_minutes']);
            $calendar_mode_sql = mysqli_real_escape_string($mysqli, $sla_snapshot['calendar_mode']);
            $business_days_value = implode(',', $sla_snapshot['business_days']);
            $business_days_sql = $business_days_value === ''
                ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $business_days_value) . "'";
            $business_start_sql = empty($sla_snapshot['business_hours_start'])
                ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $sla_snapshot['business_hours_start']) . "'";
            $business_end_sql = empty($sla_snapshot['business_hours_end'])
                ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $sla_snapshot['business_hours_end']) . "'";
            $timezone_sql = mysqli_real_escape_string($mysqli, substr($sla_snapshot['timezone'], 0, 64));
            $behavior_version = intval($behavior['behavior_version']);
            $sla_eligible = intval($behavior['sla_eligible']);
            $ticket_onsite = intval($behavior['ticket_onsite']);
            $ticket_billable = intval($behavior['ticket_billable']);
            $agreement_mutate_draft($version_id, static function () use (
                $version_id,
                $request_type_sql,
                $priority_sql,
                $sla_id,
                $sla_name_sql,
                $response_minutes_sql,
                $resolution_minutes_sql,
                $calendar_mode_sql,
                $business_days_sql,
                $business_start_sql,
                $business_end_sql,
                $timezone_sql,
                $classification_sql,
                $behavior_version,
                $sla_eligible,
                $ticket_onsite,
                $ticket_billable,
                $order
            ): void {
                agreementDbQuery("INSERT INTO agreement_sla_rules SET
                    agreement_sla_rule_version_id = $version_id,
                    agreement_sla_rule_request_type_key = '$request_type_sql',
                    agreement_sla_rule_priority = '$priority_sql',
                    agreement_sla_rule_sla_id = $sla_id,
                    agreement_sla_rule_sla_name = '$sla_name_sql',
                    agreement_sla_rule_response_minutes = $response_minutes_sql,
                    agreement_sla_rule_resolution_minutes = $resolution_minutes_sql,
                    agreement_sla_rule_calendar_mode = '$calendar_mode_sql',
                    agreement_sla_rule_business_days = $business_days_sql,
                    agreement_sla_rule_business_hours_start = $business_start_sql,
                    agreement_sla_rule_business_hours_end = $business_end_sql,
                    agreement_sla_rule_timezone = '$timezone_sql',
                    agreement_sla_rule_classification = '$classification_sql',
                    agreement_sla_rule_classification_basis = 'explicit_rule',
                    agreement_sla_rule_behavior_version = $behavior_version,
                    agreement_sla_rule_sla_eligible = $sla_eligible,
                    agreement_sla_rule_ticket_onsite = $ticket_onsite,
                    agreement_sla_rule_ticket_billable = $ticket_billable,
                    agreement_sla_rule_order = $order", 'Could not add the agreement SLA rule');
            });
            flashAlert('Agreement SLA rule added');
            redirect($return_url);
        }

        if ($agreement_action === 'delete_agreement_sla_rule') {
            $rule_id = intval($_POST['rule_id'] ?? 0);
            $sql = agreementDbQuery("SELECT agreement_sla_rule_version_id FROM agreement_sla_rules
                WHERE agreement_sla_rule_id = $rule_id LIMIT 1", 'Could not load the SLA rule');
            if (!mysqli_num_rows($sql)) {
                throw new RuntimeException('Agreement SLA rule not found');
            }
            $version_id = intval(mysqli_fetch_assoc($sql)['agreement_sla_rule_version_id']);
            $version = agreementVersionContext($version_id);
            $contract_id = intval($version['agreement_version_contract_id'] ?? 0);
            $return_url = "agreement.php?agreement_id=$contract_id&version_id=$version_id";
            if (!$version || $version['agreement_version_status'] !== 'Draft') {
                throw new RuntimeException('Published agreement SLA rules are immutable');
            }
            enforceClientAccess(intval($version['contract_client_id']));
            $agreement_mutate_draft($version_id, static function () use ($rule_id, $version_id): void {
                agreementDbQuery("DELETE FROM agreement_sla_rules WHERE agreement_sla_rule_id = $rule_id
                    AND agreement_sla_rule_version_id = $version_id LIMIT 1",
                    'Could not remove the agreement SLA rule');
            });
            flashAlert('Agreement SLA rule removed', 'error');
            redirect($return_url);
        }

        if ($agreement_action === 'publish_agreement_version') {
            $version_id = intval($_POST['version_id'] ?? 0);
            $version = agreementVersionContext($version_id);
            if (!$version) {
                throw new RuntimeException('Agreement version not found');
            }
            $contract_id = intval($version['agreement_version_contract_id']);
            $client_id = intval($version['contract_client_id']);
            $return_url = "agreement.php?agreement_id=$contract_id&version_id=$version_id";
            enforceClientAccess($client_id);
            agreementPublishVersion($version_id, $session_user_id, trim((string) ($_POST['reason'] ?? '')));
            logAudit('Agreement', 'Publish', "$session_name published agreement version $version_id", $client_id, $contract_id);
            flashAlert('Agreement version published; its definition is now immutable');
            redirect($return_url);
        }

        if ($agreement_action === 'create_agreement_draft') {
            $contract_id = intval($_POST['contract_id'] ?? 0);
            $contract_sql = agreementDbQuery("SELECT contract_client_id FROM contracts
                WHERE contract_id = $contract_id LIMIT 1", 'Could not load the agreement');
            if (!mysqli_num_rows($contract_sql)) {
                throw new RuntimeException('Agreement not found');
            }
            $client_id = intval(mysqli_fetch_assoc($contract_sql)['contract_client_id']);
            $return_url = "agreement.php?agreement_id=$contract_id";
            enforceClientAccess($client_id);
            $version_id = agreementCreateDraftFromPublished($contract_id, $session_user_id);
            flashAlert('A new editable agreement draft is ready');
            redirect("agreement.php?agreement_id=$contract_id&version_id=$version_id");
        }

        if ($agreement_action === 'generate_service_review') {
            $client_id = intval($_POST['client_id'] ?? 0);
            $contract_id = intval($_POST['contract_id'] ?? 0);
            enforceClientAccess($client_id);
            $return_url = $contract_id > 0
                ? "agreement.php?agreement_id=$contract_id" : "agreements.php?client_id=$client_id";
            $period_start = $agreement_date($_POST['period_start'] ?? '', 'Period start', false);
            $period_end = $agreement_date($_POST['period_end'] ?? '', 'Period end', false);
            $review_id = agreementGenerateServiceReview(
                $client_id,
                $period_start,
                $period_end,
                $session_user_id,
                $contract_id
            );
            logAudit('Service Review', 'Create', "$session_name generated service review $review_id", $client_id, $review_id);
            flashAlert('Service review generated from a traceable snapshot');
            redirect("service_review.php?review_id=$review_id");
        }

        if ($agreement_action === 'publish_service_review') {
            $review_id = intval($_POST['review_id'] ?? 0);
            $review_sql = agreementDbQuery("SELECT service_review_client_id FROM service_reviews
                WHERE service_review_id = $review_id LIMIT 1", 'Could not load the service review');
            if (!mysqli_num_rows($review_sql)) {
                throw new RuntimeException('Service review not found');
            }
            $client_id = intval(mysqli_fetch_assoc($review_sql)['service_review_client_id']);
            $return_url = "service_review.php?review_id=$review_id";
            enforceClientAccess($client_id);
            agreementPublishServiceReview($review_id, $session_user_id, trim((string) ($_POST['reason'] ?? '')));
            logAudit('Service Review', 'Publish', "$session_name published service review $review_id", $client_id, $review_id);
            flashAlert('Service review published; its source snapshot is now immutable');
            redirect($return_url);
        }
    } catch (Throwable $e) {
        if ($agreement_action === 'add_agreement') {
            $_SESSION['agreement_setup_error'] = $e->getMessage();
        }
        logApp('Agreement', 'error', $agreement_action . ' failed: ' . $e->getMessage());
        flashAlert(escapeHtml($e->getMessage()), 'error');
        redirect($return_url);
    }
}
