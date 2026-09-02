<?php

defined('FROM_POST_HANDLER') || die('Direct file access is not allowed');

if (isset($_POST['install_portal_request_starters'])) {
    validateCSRFToken();
    try {
        $installed = portalRequestInstallStarters($session_user_id);
        logAudit('Portal Request Catalog', 'Create', "$session_name installed $installed starter portal requests");
        flashAlert($installed ? "$installed starter requests installed as drafts" : 'Starter requests are already installed');
    } catch (Throwable $exception) {
        flashAlert(escapeHtml($exception->getMessage()), 'error');
    }
    redirect('portal_request_catalog.php');
}

if (isset($_POST['add_portal_request_catalog_item'])) {
    validateCSRFToken();
    $name = trim((string) ($_POST['name'] ?? ''));
    $key = portalRequestNormalizeKey($_POST['key'] ?? $name, 'request');
    $type = array_key_exists($_POST['type'] ?? '', portalRequestTypes()) ? $_POST['type'] : 'other';
    if ($name === '' || strlen($name) > 200) {
        flashAlert('A request name up to 200 characters is required', 'error');
        redirect('portal_request_catalog.php');
    }
    $name_sql = escapeSql($name);
    $key_sql = escapeSql($key);
    $type_sql = escapeSql($type);
    try {
        portalRequestDbQuery("INSERT INTO portal_request_catalog_items SET
            portal_request_catalog_item_key = '$key_sql',
            portal_request_catalog_item_type = '$type_sql',
            portal_request_catalog_item_name = '$name_sql',
            portal_request_catalog_item_created_by = $session_user_id,
            portal_request_catalog_item_updated_by = $session_user_id",
            'Could not create the request catalog draft');
        $item_id = intval(mysqli_insert_id($mysqli));
        logAudit('Portal Request Catalog', 'Create',
            "$session_name created request catalog draft $name_sql", 0, $item_id);
        flashAlert("Request draft <strong>" . escapeHtml($name) . '</strong> created');
        redirect("portal_request_catalog_item.php?item_id=$item_id");
    } catch (Throwable $exception) {
        flashAlert('The request key is already in use or the draft could not be created', 'error');
        redirect('portal_request_catalog.php');
    }
}

if (isset($_POST['update_portal_request_catalog_item'])) {
    validateCSRFToken();
    $item_id = intval($_POST['item_id'] ?? 0);
    $name = substr(trim((string) ($_POST['name'] ?? '')), 0, 200);
    $description = substr(trim((string) ($_POST['description'] ?? '')), 0, 5000);
    $instructions = substr(trim((string) ($_POST['instructions'] ?? '')), 0, 5000);
    $icon = trim((string) ($_POST['icon'] ?? 'far fa-list-alt'));
    $type = (string) ($_POST['type'] ?? 'other');
    $permission = (string) ($_POST['permission_rule'] ?? 'any');
    $applicability = (string) ($_POST['applicability_rule'] ?? 'all');
    $applicability_value = substr(trim((string) ($_POST['applicability_value'] ?? '')), 0, 255);
    $approval = (string) ($_POST['approval_rule'] ?? 'none');
    $category_id = intval($_POST['category_id'] ?? 0);
    $ticket_template_id = intval($_POST['ticket_template_id'] ?? 0);
    $order = intval($_POST['sort_order'] ?? 0);
    if ($name === '' || !array_key_exists($type, portalRequestTypes())
        || !array_key_exists($permission, portalRequestPermissionRules())
        || !array_key_exists($applicability, portalRequestApplicabilityRules())
        || !array_key_exists($approval, portalRequestApprovalRules())
        || !preg_match('/^[a-z0-9 -]{1,60}$/i', $icon)) {
        flashAlert('The request draft contains an invalid value', 'error');
        redirect("portal_request_catalog_item.php?item_id=$item_id");
    }
    if ($applicability !== 'all' && $applicability_value === '') {
        flashAlert('The selected applicability rule needs a value', 'error');
        redirect("portal_request_catalog_item.php?item_id=$item_id");
    }
    $values = array_map('escapeSql', [
        $name, $description, $instructions, $icon, $type, $permission,
        $applicability, $applicability_value, $approval,
    ]);
    [$name_sql, $description_sql, $instructions_sql, $icon_sql, $type_sql,
        $permission_sql, $applicability_sql, $applicability_value_sql, $approval_sql] = $values;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the request draft transaction');
        }
        $item = mysqli_fetch_assoc(portalRequestDbQuery("SELECT portal_request_catalog_item_id
            FROM portal_request_catalog_items WHERE portal_request_catalog_item_id = $item_id
            AND portal_request_catalog_item_archived_at IS NULL LIMIT 1 FOR UPDATE",
            'Could not lock the request catalog draft'));
        if (!$item) {
            throw new RuntimeException('The request catalog draft is unavailable or archived');
        }
        // Keep the publication lock order: catalog item, runbook template, then
        // ticket category. Draft edits therefore cannot deadlock publication.
        if ($ticket_template_id) {
            $template = mysqli_fetch_assoc(portalRequestDbQuery("SELECT ticket_template_id
                FROM ticket_templates WHERE ticket_template_id = $ticket_template_id
                AND ticket_template_archived_at IS NULL LIMIT 1 FOR UPDATE",
                'Could not lock the request runbook template'));
            if (!$template) {
                throw new RuntimeException('The selected runbook template is unavailable or archived');
            }
        }
        if ($category_id) {
            $category = mysqli_fetch_assoc(portalRequestDbQuery("SELECT category_id FROM categories
                WHERE category_id = $category_id AND category_type = 'Ticket'
                AND category_archived_at IS NULL LIMIT 1 FOR UPDATE",
                'Could not lock the request ticket category'));
            if (!$category) {
                throw new RuntimeException('The selected ticket category is unavailable or archived');
            }
        }
        portalRequestDbQuery("UPDATE portal_request_catalog_items SET
            portal_request_catalog_item_type = '$type_sql',
            portal_request_catalog_item_name = '$name_sql',
            portal_request_catalog_item_description = '$description_sql',
            portal_request_catalog_item_instructions = '$instructions_sql',
            portal_request_catalog_item_icon = '$icon_sql',
            portal_request_catalog_item_category_id = $category_id,
            portal_request_catalog_item_ticket_template_id = $ticket_template_id,
            portal_request_catalog_item_permission_rule = '$permission_sql',
            portal_request_catalog_item_applicability_rule = '$applicability_sql',
            portal_request_catalog_item_applicability_value = '$applicability_value_sql',
            portal_request_catalog_item_approval_rule = '$approval_sql',
            portal_request_catalog_item_order = $order,
            portal_request_catalog_item_updated_by = $session_user_id
            WHERE portal_request_catalog_item_id = $item_id
            AND portal_request_catalog_item_archived_at IS NULL LIMIT 1",
            'Could not update the request catalog draft');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the request catalog draft');
        }
        logAudit('Portal Request Catalog', 'Modify',
            "$session_name updated request catalog draft $name_sql", 0, $item_id);
        flashAlert('Request catalog draft updated');
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        error_log("Portal request catalog draft $item_id update failed: " . $exception->getMessage());
        flashAlert('The request catalog draft could not be updated safely', 'error');
    }
    redirect("portal_request_catalog_item.php?item_id=$item_id");
}

if (isset($_POST['save_portal_request_catalog_field'])) {
    validateCSRFToken();
    $item_id = intval($_POST['item_id'] ?? 0);
    $field_id = intval($_POST['field_id'] ?? 0);
    $key = str_replace('-', '_', portalRequestNormalizeKey($_POST['field_key'] ?? '', 'field'));
    $label = substr(trim((string) ($_POST['label'] ?? '')), 0, 200);
    $help = substr(trim((string) ($_POST['help'] ?? '')), 0, 500);
    $type = (string) ($_POST['field_type'] ?? 'text');
    $required = isset($_POST['required']) ? 1 : 0;
    $max_length = max(1, min(10000, intval($_POST['max_length'] ?? ($type === 'textarea' ? 4000 : 255))));
    $min_value = trim((string) ($_POST['min_value'] ?? ''));
    $max_value = trim((string) ($_POST['max_value'] ?? ''));
    $min_parsed = $min_value === '' ? null : portalRequestParseInteger($min_value);
    $max_parsed = $max_value === '' ? null : portalRequestParseInteger($max_value);
    if ($type !== 'integer') {
        $min_parsed = null;
        $max_parsed = null;
        $min_value = '';
        $max_value = '';
    }
    $min_sql = $min_parsed === null ? 'NULL' : (string) $min_parsed;
    $max_sql = $max_parsed === null ? 'NULL' : (string) $max_parsed;
    $order = intval($_POST['field_order'] ?? 0);
    $options = [];
    foreach (preg_split('/\R/', (string) ($_POST['options'] ?? '')) as $option) {
        $option = substr(trim($option), 0, 200);
        if ($option !== '' && !in_array($option, $options, true)) {
            $options[] = $option;
        }
    }
    if ($type !== 'select') {
        $options = [];
    }
    if (!$item_id || $label === '' || !preg_match('/^[a-z][a-z0-9_]{0,99}$/', $key)
        || !array_key_exists($type, portalRequestFieldTypes())
        || ($type === 'select' && !$options)
        || ($min_value !== '' && $min_parsed === null)
        || ($max_value !== '' && $max_parsed === null)
        || ($min_parsed !== null && $max_parsed !== null && $min_parsed > $max_parsed)) {
        flashAlert('The request field definition is invalid', 'error');
        redirect("portal_request_catalog_item.php?item_id=$item_id");
    }
    $key_sql = escapeSql($key);
    $label_sql = escapeSql($label);
    $help_sql = escapeSql($help);
    $type_sql = escapeSql($type);
    $options_sql = escapeSql(portalRequestCanonicalJson(array_slice($options, 0, 100)));
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the request field transaction');
        }
        $item = mysqli_fetch_assoc(portalRequestDbQuery("SELECT portal_request_catalog_item_id
            FROM portal_request_catalog_items WHERE portal_request_catalog_item_id = $item_id
            AND portal_request_catalog_item_archived_at IS NULL LIMIT 1 FOR UPDATE",
            'Could not lock the request catalog draft'));
        if (!$item) {
            throw new RuntimeException('The request catalog draft is unavailable');
        }
        if ($field_id) {
            $field = mysqli_fetch_assoc(portalRequestDbQuery("SELECT portal_request_catalog_field_id
                FROM portal_request_catalog_fields
                WHERE portal_request_catalog_field_id = $field_id
                AND portal_request_catalog_field_item_id = $item_id LIMIT 1 FOR UPDATE",
                'Could not lock the request field'));
            if (!$field) {
                throw new RuntimeException('The request field is unavailable for this draft');
            }
            portalRequestDbQuery("UPDATE portal_request_catalog_fields SET
                portal_request_catalog_field_key = '$key_sql',
                portal_request_catalog_field_label = '$label_sql',
                portal_request_catalog_field_help = '$help_sql',
                portal_request_catalog_field_type = '$type_sql',
                portal_request_catalog_field_required = $required,
                portal_request_catalog_field_options = '$options_sql',
                portal_request_catalog_field_max_length = $max_length,
                portal_request_catalog_field_min_value = $min_sql,
                portal_request_catalog_field_max_value = $max_sql,
                portal_request_catalog_field_order = $order
                WHERE portal_request_catalog_field_id = $field_id
                AND portal_request_catalog_field_item_id = $item_id LIMIT 1",
                'Could not update the request field');
        } else {
            portalRequestDbQuery("INSERT INTO portal_request_catalog_fields SET
                portal_request_catalog_field_item_id = $item_id,
                portal_request_catalog_field_key = '$key_sql',
                portal_request_catalog_field_label = '$label_sql',
                portal_request_catalog_field_help = '$help_sql',
                portal_request_catalog_field_type = '$type_sql',
                portal_request_catalog_field_required = $required,
                portal_request_catalog_field_options = '$options_sql',
                portal_request_catalog_field_max_length = $max_length,
                portal_request_catalog_field_min_value = $min_sql,
                portal_request_catalog_field_max_value = $max_sql,
                portal_request_catalog_field_order = $order",
                'Could not add the request field');
            $field_id = intval(mysqli_insert_id($mysqli));
            if (!$field_id) {
                throw new RuntimeException('The request field did not receive an ID');
            }
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the request field');
        }
        logAudit('Portal Request Catalog', 'Modify',
            "$session_name saved request field $key", 0, $item_id);
        flashAlert('Request field saved');
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('That field key is already used or the field could not be saved', 'error');
    }
    redirect("portal_request_catalog_item.php?item_id=$item_id");
}

if (isset($_POST['delete_portal_request_catalog_field'])) {
    validateCSRFToken();
    $item_id = intval($_POST['item_id'] ?? 0);
    $field_id = intval($_POST['field_id'] ?? 0);
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the request field transaction');
        }
        $item = mysqli_fetch_assoc(portalRequestDbQuery("SELECT portal_request_catalog_item_id
            FROM portal_request_catalog_items WHERE portal_request_catalog_item_id = $item_id
            AND portal_request_catalog_item_archived_at IS NULL
            LIMIT 1 FOR UPDATE", 'Could not lock the request catalog draft'));
        if (!$item) {
            throw new RuntimeException('The request catalog draft is unavailable or archived');
        }
        portalRequestDbQuery("DELETE FROM portal_request_catalog_fields
            WHERE portal_request_catalog_field_id = $field_id
            AND portal_request_catalog_field_item_id = $item_id LIMIT 1",
            'Could not delete the request field');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The request field is unavailable for this draft');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the request field deletion');
        }
        logAudit('Portal Request Catalog', 'Modify',
            "$session_name removed a request field", 0, $item_id);
        flashAlert('Request field removed', 'warning');
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert(escapeHtml($exception->getMessage()), 'error');
    }
    redirect("portal_request_catalog_item.php?item_id=$item_id");
}

if (isset($_POST['publish_portal_request_catalog_item'])) {
    validateCSRFToken();
    $item_id = intval($_POST['item_id'] ?? 0);
    try {
        $version_id = portalRequestPublish($item_id, $session_user_id, $_POST['version_notes'] ?? '');
        logAudit('Portal Request Catalog', 'Publish',
            "$session_name published portal request catalog version $version_id", 0, $item_id);
        flashAlert('Request catalog release published');
    } catch (Throwable $exception) {
        flashAlert(escapeHtml($exception->getMessage()), 'error');
    }
    redirect("portal_request_catalog_item.php?item_id=$item_id");
}

if (isset($_POST['archive_portal_request_catalog_item']) || isset($_POST['restore_portal_request_catalog_item'])) {
    validateCSRFToken();
    $item_id = intval($_POST['item_id'] ?? 0);
    $restore = isset($_POST['restore_portal_request_catalog_item']);
    $archive_sql = $restore ? 'NULL' : 'NOW()';
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the request archive transaction');
        }
        $item = mysqli_fetch_assoc(portalRequestDbQuery("SELECT portal_request_catalog_item_id,
            portal_request_catalog_item_archived_at FROM portal_request_catalog_items
            WHERE portal_request_catalog_item_id = $item_id LIMIT 1 FOR UPDATE",
            'Could not lock the request catalog item'));
        if (!$item || ($restore && empty($item['portal_request_catalog_item_archived_at']))
            || (!$restore && !empty($item['portal_request_catalog_item_archived_at']))) {
            throw new RuntimeException('The request catalog archive state already changed');
        }
        portalRequestDbQuery("UPDATE portal_request_catalog_items
            SET portal_request_catalog_item_archived_at = $archive_sql,
                portal_request_catalog_item_updated_by = $session_user_id
            WHERE portal_request_catalog_item_id = $item_id LIMIT 1",
            'Could not change the request catalog archive state');
        if (mysqli_affected_rows($mysqli) !== 1 || !mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the request catalog archive state');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        error_log("Portal request catalog item $item_id archive change failed: " . $exception->getMessage());
        flashAlert('The request catalog archive state could not be changed safely', 'error');
        redirect('portal_request_catalog.php');
    }
    logAudit('Portal Request Catalog', $restore ? 'Restore' : 'Archive',
        "$session_name " . ($restore ? 'restored' : 'archived') . ' a portal request catalog item',
        0, $item_id);
    flashAlert($restore ? 'Request catalog item restored' : 'Request catalog item archived', $restore ? 'success' : 'warning');
    redirect('portal_request_catalog.php');
}
