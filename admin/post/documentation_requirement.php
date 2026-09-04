<?php

defined('FROM_POST_HANDLER') || die('Direct file access is not allowed');

if (isset($_POST['save_documentation_requirement'])) {
    validateCSRFToken();
    enforceAdminPermission();

    $requirement_id = intval($_POST['requirement_id'] ?? 0);
    $expected_revision = intval($_POST['expected_revision'] ?? 0);
    $key = strtolower(trim((string) ($_POST['key'] ?? '')));
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,99}$/', $key) || $name === '' || mb_strlen($name) > 200) {
        flashAlert('A stable requirement key and name are required.', 'error');
        redirect('documentation_requirements.php');
    }

    $allowed_record_types = ['identity', 'network', 'backup', 'security', 'endpoint', 'vendor', 'agreement', 'portal', 'recovery', 'general'];
    $allowed_roles = ['documentation_owner', 'service_owner', 'account_manager', 'security_lead', 'support_lead', 'unassigned'];
    $allowed_evidence = ['none', 'note', 'file', 'reference'];
    $allowed_exception_policies = ['support3', 'administrator'];
    $allowed_dimensions = ['always', 'active_contract', 'plan', 'service', 'service_category', 'asset_class', 'integration', 'client_type'];

    $record_type = (string) ($_POST['record_type'] ?? 'general');
    $owner_role = (string) ($_POST['default_owner_role'] ?? 'documentation_owner');
    $reviewer_role = (string) ($_POST['default_reviewer_role'] ?? 'support_lead');
    $evidence_policy = (string) ($_POST['evidence_policy'] ?? 'reference');
    $exception_policy = (string) ($_POST['exception_approval_policy'] ?? 'support3');
    $applicability_mode = (string) ($_POST['applicability_mode'] ?? 'any');
    if (!in_array($record_type, $allowed_record_types, true)
        || !in_array($owner_role, $allowed_roles, true)
        || !in_array($reviewer_role, $allowed_roles, true)
        || !in_array($evidence_policy, $allowed_evidence, true)
        || !in_array($exception_policy, $allowed_exception_policies, true)
        || !in_array($applicability_mode, ['any', 'all'], true)) {
        flashAlert('One or more requirement policy values are invalid.', 'error');
        redirect('documentation_requirements.php');
    }

    $cadence_days = intval($_POST['review_cadence_days'] ?? 0);
    $warning_days = intval($_POST['warning_window_days'] ?? 0);
    if ($cadence_days < 1 || $cadence_days > 3650 || $warning_days < 0 || $warning_days > min(365, $cadence_days - 1)) {
        flashAlert('Review cadence and warning window are invalid.', 'error');
        redirect('documentation_requirements.php');
    }

    $selectors = [];
    $seen_selectors = [];
    foreach (preg_split('/\R/', (string) ($_POST['selectors'] ?? '')) as $selector_line) {
        $selector_line = strtolower(trim($selector_line));
        if ($selector_line === '') {
            continue;
        }
        [$dimension, $selector_value] = array_pad(explode(':', $selector_line, 2), 2, '');
        $dimension = trim($dimension);
        $selector_value = trim($selector_value);
        if (!in_array($dimension, $allowed_dimensions, true)
            || !preg_match('/^[a-z0-9*][a-z0-9._* -]{0,99}$/', $selector_value)
            || ($dimension === 'always' && $selector_value !== 'any')) {
            flashAlert('An applicability selector is invalid. Use one supported dimension:value pair per line.', 'error');
            redirect('documentation_requirements.php');
        }
        $identity = "$dimension:$selector_value";
        if (!isset($seen_selectors[$identity])) {
            $selectors[] = ['dimension' => $dimension, 'value' => $selector_value];
            $seen_selectors[$identity] = true;
        }
    }
    if (!$selectors) {
        $selectors[] = ['dimension' => 'always', 'value' => 'any'];
    }

    $definition = [
        'key' => $key,
        'name' => $name,
        'description' => mb_substr($description, 0, 2000),
        'record_type' => $record_type,
        'default_owner_role' => $owner_role,
        'default_owner_user_id' => 0,
        'default_reviewer_role' => $reviewer_role,
        'default_reviewer_user_id' => 0,
        'review_cadence_days' => $cadence_days,
        'warning_window_days' => $warning_days,
        'blocks_readiness' => intval($_POST['blocks_readiness'] ?? 0) === 1,
        'blocks_ticket_resolution' => intval($_POST['blocks_ticket_resolution'] ?? 0) === 1,
        'evidence_policy' => $evidence_policy,
        'exception_approval_policy' => $exception_policy,
        'applicability_mode' => $applicability_mode,
        'selectors' => $selectors,
    ];

    try {
        $saved_requirement = documentationSaveRequirementDraft($requirement_id, $definition, $expected_revision, $session_user_id);
        $saved_requirement_id = intval($saved_requirement['requirement_id'] ?? $requirement_id);
        logAudit('Documentation Requirement', 'Draft', "$session_name saved documentation requirement draft $key", 0, $saved_requirement_id);
        flashAlert('Documentation requirement draft saved.');
    } catch (Throwable $e) {
        error_log("Documentation requirement $requirement_id draft save failed: " . $e->getMessage());
        flashAlert('The requirement draft could not be saved. Its key may already exist or the draft changed in another session.', 'error');
    }
    redirect('documentation_requirements.php');
}

if (isset($_POST['publish_documentation_requirement'])
    || isset($_POST['archive_documentation_requirement'])
    || isset($_POST['restore_documentation_requirement'])) {
    validateCSRFToken();
    enforceAdminPermission();

    $requirement_id = intval($_POST['requirement_id'] ?? 0);
    $expected_revision = intval($_POST['expected_revision'] ?? 0);
    try {
        if (isset($_POST['publish_documentation_requirement'])) {
            documentationPublishRequirement($requirement_id, $expected_revision, $session_user_id);
            $verb = 'published';
        } elseif (isset($_POST['archive_documentation_requirement'])) {
            documentationArchiveRequirement($requirement_id, $expected_revision, $session_user_id);
            $verb = 'archived';
        } else {
            documentationRestoreRequirement($requirement_id, $expected_revision, $session_user_id);
            $verb = 'restored';
        }
        logAudit('Documentation Requirement', ucfirst($verb), "$session_name $verb documentation requirement", 0, $requirement_id);
        flashAlert("Documentation requirement $verb.");
    } catch (Throwable $e) {
        error_log("Documentation requirement $requirement_id lifecycle change failed: " . $e->getMessage());
        flashAlert('The requirement lifecycle change could not be saved. Refresh and try again.', 'error');
    }
    redirect('documentation_requirements.php');
}
