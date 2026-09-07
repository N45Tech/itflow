<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/functions.php';
require_once dirname(__DIR__, 2) . '/functions/field_workspace.php';
require_once dirname(__DIR__, 2) . '/includes/session_init.php';
if (empty($_SESSION['logged'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Sign in again to continue.']));
}
require_once dirname(__DIR__, 2) . '/includes/check_login.php';

try {
    if (lookupUserPermission('module_support') < 1) {
        http_response_code(403);
        throw new DomainException('Support access is required for Field Mode.');
    }
    $user_id = (int) $session_user_id;
    $action = (string) ($_GET['action'] ?? 'boot');
    $ticket_id = (int) ($_GET['ticket_id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
            ? json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR) : $_POST;
        if (!is_array($input) || !is_string($input['csrf_token'] ?? null)
            || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
            http_response_code(403);
            throw new DomainException('Your form expired. Refresh Field Mode and try again.');
        }
        $action = (string) ($input['action'] ?? '');
        if ($action === 'match') {
            $result = ['candidates' => fieldArrivalCandidates(fieldToday($user_id), fieldPosition($input), time())];
        } elseif ($action === 'credential_reveal') {
            $result = fieldRevealCredential($input, $user_id);
        } elseif ($action === 'followup_plan') {
            $result = assistanceWrite($action, $input, $user_id);
        } else {
            if (lookupUserPermission('module_support') < 2) {
                http_response_code(403);
                throw new DomainException('Support write access is required to save field work.');
            }
            if ($action !== 'create_job' && (int) ($input['ticket_id'] ?? 0) < 1) {
                throw new DomainException('Choose a ticket for this update.');
            }
            $operations = ['visit' => 'fieldVisitTransition', 'note' => 'fieldAddNote', 'task' => 'fieldCompleteTask',
                'time' => 'fieldSubmitTime', 'issue' => 'fieldAddBlocker', 'issue_update' => 'fieldUpdateBlocker',
                'photo' => 'fieldAddPhoto', 'pin' => 'fieldSavePin', 'position' => 'fieldUpdatePosition',
                'reply' => 'fieldWorkspaceReply', 'plan' => 'fieldWorkspacePlan', 'transition' => 'fieldWorkspaceTransition',
                'create_job' => 'fieldWorkspaceCreate', 'task_update' => 'fieldWorkspaceTask',
                'promise' => 'fieldWorkspacePromise', 'asset_update' => 'fieldWorkspaceAsset',
                'file' => 'fieldWorkspaceFile', 'document_update' => 'fieldWorkspaceDocument',
                'documentation_action' => 'fieldWorkspaceDocumentationAction', 'approval' => 'fieldWorkspaceApproval'];
            if (!isset($operations[$action])) {
                throw new DomainException('Choose a valid field action.');
            }
            $result = fieldRequest($action, $input, $user_id,
                static fn (&$batch) => $operations[$action]($input, $user_id, $batch));
        }
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        throw new DomainException('Use GET or POST for Field Mode.');
    } elseif ($action === 'boot') {
        $result = ['user' => ['id' => $user_id, 'name' => mysqli_fetch_row(fieldDb("SELECT user_name FROM users WHERE user_id = $user_id"))[0],
            'write' => lookupUserPermission('module_support') >= 2, 'admin' => lookupUserPermission('module_support') >= 3,
            'client_write' => lookupUserPermission('module_client') >= 2, 'credentials' => lookupUserPermission('module_credential') >= 1,
            'verify_site' => lookupUserPermission('module_client') >= 2, 'documents' => lookupUserPermission('module_client') >= 1],
            'csrf_token' => $_SESSION['csrf_token'], 'theme' => $user_config_theme_dark ? 'dark' : 'light',
            'jobs' => fieldToday($user_id), 'projects' => fieldProjects($user_id), 'visits' => fieldMyVisits($user_id),
            'issues' => fieldBlockers("b.blocker_owner_id = $user_id AND b.blocker_status <> 'resolved'"),
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'), 'options' => fieldWorkspaceOptions()];
    } elseif ($action === 'ticket') {
        $result = array_merge(fieldTicketDetail($ticket_id, $user_id), fieldWorkspaceDetail($ticket_id));
        $result['recipients_hash'] = hash('sha256', json_encode($result['recipients']));
        $result['canned_responses'] = cannedResponseChoices($ticket_id);
        $result['followups'] = followupQueue(['ticket_id' => $ticket_id, 'scope' => 'all', 'due' => 'all'], $user_id);
    } elseif ($action === 'followups') {
        $result = followupQueue($_GET, $user_id);
    } elseif ($action === 'followup') {
        $result = followupDetail($ticket_id, (string) ($_GET['key'] ?? ''));
    } elseif ($action === 'canned_response') {
        $result = cannedResponseForTicket($ticket_id, (int) ($_GET['response_id'] ?? 0));
    } elseif ($action === 'jobs') {
        $result = fieldSearchTickets($_GET, $user_id);
    } elseif ($action === 'clients') {
        $result = fieldClientChoices((string) ($_GET['q'] ?? ''));
    } elseif ($action === 'client_context') {
        $result = fieldClientContext((int) ($_GET['client_id'] ?? 0));
    } elseif ($action === 'history') {
        $result = fieldHistory($ticket_id, max(0, (int) ($_GET['before'] ?? 0)));
    } elseif ($action === 'asset') {
        $result = fieldAsset($ticket_id, (int) ($_GET['asset_id'] ?? 0));
    } elseif ($action === 'project') {
        $result = fieldProjectDetail((int) ($_GET['project_id'] ?? 0));
    } elseif (in_array($action, ['documents', 'document', 'document_view', 'assets', 'attachment', 'files'], true)) {
        $ticket = fieldTicket($ticket_id);
        $client_id = (int) $ticket['ticket_client_id'];
        if (!in_array($action, ['attachment', 'files'], true) && lookupUserPermission('module_client') < 1) {
            http_response_code(403);
            throw new DomainException('Client documentation access is required.');
        }
        if ($action === 'documents') {
            $result = fieldDocumentation($client_id, (int) $ticket['ticket_asset_id'], fieldText($_GET['q'] ?? '', 'search text', 200, false));
        } elseif ($action === 'document' || $action === 'document_view') {
            $id = (int) ($_GET['document_id'] ?? 0);
            $doc = mysqli_fetch_assoc(fieldDb("SELECT document_name, document_content, document_updated_at FROM documents
                WHERE document_id = $id AND document_client_id = $client_id AND document_archived_at IS NULL"));
            if (!$doc) {
                throw new DomainException('This document is unavailable.');
            }
            if ($action === 'document_view') {
                fieldRenderDocument($doc);
            }
            $result = ['name' => $doc['document_name'], 'content' => fieldPlainText($doc['document_content']), 'id' => $id];
        } elseif ($action === 'files') {
            $before = max(0, (int) ($_GET['before'] ?? 0));
            $cursor = $before ? " AND ticket_attachment_id < $before" : '';
            $files = fieldRows("SELECT ticket_attachment_id, ticket_attachment_name, ticket_attachment_created_at
                FROM ticket_attachments WHERE ticket_attachment_ticket_id = $ticket_id $cursor ORDER BY ticket_attachment_id DESC LIMIT 41");
            $more = count($files) > 40; $files = array_slice($files, 0, 40);
            $result = ['files' => $files, 'next' => $more ? (int) end($files)['ticket_attachment_id'] : null];
        } elseif ($action === 'assets') {
            $search = fieldSql('%' . fieldText($_GET['q'] ?? '', 'an asset name or serial number', 200) . '%');
            $result = fieldRows("SELECT asset_id, asset_name, asset_type, asset_make, asset_model, asset_serial
                FROM assets WHERE asset_client_id = $client_id AND asset_archived_at IS NULL
                AND (asset_name LIKE $search OR asset_serial LIKE $search) ORDER BY asset_name LIMIT 50");
        } else {
            $id = (int) ($_GET['attachment_id'] ?? 0);
            $attachment = mysqli_fetch_assoc(fieldDb("SELECT * FROM ticket_attachments WHERE ticket_attachment_id = $id
                AND ticket_attachment_ticket_id = $ticket_id"));
            $reference = $attachment['ticket_attachment_reference_name'] ?? '';
            if (!$reference || basename($reference) !== $reference || str_contains($reference, '\\')) {
                throw new DomainException('This attachment is unavailable.');
            }
            $path = dirname(__DIR__, 2) . '/uploads/tickets/' . $ticket_id . '/' . $reference;
            if (!is_file($path) || is_link($path)) {
                throw new DomainException('The attachment is still being prepared. Try again shortly.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            $inline = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);
            header('Content-Security-Policy: sandbox');
            header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($attachment['ticket_attachment_name']));
            session_write_close();
            readfile($path);
            exit;
        }
    } else {
        http_response_code(404);
        throw new DomainException('This Field Mode view is unavailable.');
    }
    echo json_encode(['data' => $result], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (DomainException | JsonException $exception) {
    if (http_response_code() < 400) {
        http_response_code(422);
    }
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Field Mode: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The update could not be confirmed. Keep this form and retry; a repeated submission will not duplicate it.']);
}
