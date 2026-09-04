<?php

/*
 * ITFlow - Operations identity review actions
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['review_identity_mappings'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $mapping_ids = is_array($_POST['mapping_ids'] ?? null)
        ? array_values(array_unique(array_filter(
            array_map('intval', $_POST['mapping_ids']),
            static fn ($mapping_id) => $mapping_id > 0
        ))) : [];
    $action = integrationIdentityReviewAction($_POST['identity_action'] ?? '');
    if (!$mapping_ids || count($mapping_ids) > 100) {
        flashAlert('Select between 1 and 100 endpoint identities.', 'error');
        redirect('/agent/operations.php#identity-review');
    }
    if ($action === 'remap') {
        enforceUserPermission('module_support', 3);
        if (count($mapping_ids) !== 1) {
            flashAlert('Remap exactly one endpoint identity at a time.', 'error');
            redirect('/agent/operations.php#identity-review');
        }
    }
    $reason = integrationIdentityLimitText($_POST['identity_reason'] ?? '', 1000);
    if ($reason === '') {
        flashAlert('A reason is required for every identity decision.', 'error');
        redirect('/agent/operations.php#identity-review');
    }

    $expected_client_ids = [];
    $audit_client_ids = [];
    foreach ($mapping_ids as $mapping_id) {
        $mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_mapping_client_id
            FROM automation_entity_mappings WHERE automation_mapping_id = $mapping_id LIMIT 1"));
        if (!$mapping) {
            flashAlert('One of the selected endpoint identities no longer exists.', 'error');
            redirect('/agent/operations.php#identity-review');
        }
        $mapping_client_id = intval($mapping['automation_mapping_client_id']);
        if ($mapping_client_id === 0 && !$session_is_admin) {
            flashAlert('Only an administrator can review an identity without a client binding.', 'error');
            redirect('/agent/operations.php#identity-review');
        }
        if ($mapping_client_id > 0) {
            enforceClientAccess($mapping_client_id);
            $audit_client_ids[$mapping_client_id] = true;
        }
        $expected_client_ids[$mapping_id] = $mapping_client_id;
    }

    $target_client_id = max(0, intval($_POST['target_client_id'] ?? 0));
    $target_asset_id = max(0, intval($_POST['target_asset_id'] ?? 0));
    if ($action === 'remap') {
        if ($target_client_id < 1 || $target_asset_id < 1) {
            flashAlert('Remap requires a target client ID and asset ID.', 'error');
            redirect('/agent/operations.php#identity-review');
        }
        enforceClientAccess($target_client_id);
        $audit_client_ids[$target_client_id] = true;
    }

    try {
        $summary = integrationIdentityReviewMappings($mapping_ids, $action, [
            'reason' => $reason,
            'actor_user_id' => intval($session_user_id),
            'expected_client_ids' => $expected_client_ids,
            'target_client_id' => $target_client_id,
            'target_asset_id' => $target_asset_id,
        ]);
        $audit_client_id = count($audit_client_ids) === 1
            ? intval(array_key_first($audit_client_ids)) : 0;
        logAudit(
            'Endpoint Identity',
            'Review',
            "$session_name applied $action to {$summary['succeeded']} endpoint identity mapping(s); "
                . "{$summary['failed']} failed",
            $audit_client_id
        );
        if ($summary['failed'] > 0) {
            $first_failure = '';
            foreach ($summary['results'] as $result) {
                if (empty($result['success'])) {
                    $first_failure = (string) ($result['error'] ?? 'The decision could not be applied');
                    break;
                }
            }
            flashAlert(
                "Applied the decision to <strong>{$summary['succeeded']}</strong> mapping(s); "
                    . "<strong>{$summary['failed']}</strong> failed. " . escapeHtml($first_failure),
                'error'
            );
        } else {
            flashAlert("Applied the identity decision to <strong>{$summary['succeeded']}</strong> mapping(s).");
        }
    } catch (Throwable $e) {
        flashAlert('The endpoint identity decision could not be applied: ' . escapeHtml($e->getMessage()), 'error');
    }
    redirect('/agent/operations.php#identity-review');
}
