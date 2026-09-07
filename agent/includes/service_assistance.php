<?php

// Render helpers inherit the existing PSA theme. All record text is escaped.
function assistanceDate(?string $value): string
{
    return $value ? (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('M j, Y · g:i A T') : 'Not set';
}
function assistanceFields(int $ticket_id, string $action): void
{
    $key = $_POST['request_key'] ?? assistanceUuid();
    ?><input type="hidden" name="action" value="<?= escapeHtml($action) ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="request_key" value="<?= escapeHtml($key) ?>"><?php
}
function assistancePagePost(): ?string
{
    global $session_user_id;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return null; }
    validateCSRFToken();
    try {
        $result = assistanceWrite((string) ($_POST['action'] ?? ''), $_POST, (int) $session_user_id);
        flashAlert(escapeHtml($result['message']));
        redirect('/agent/ticket.php?ticket_id=' . (int) $_POST['ticket_id'] . '#followups');
    } catch (DomainException $e) { unset($_POST['request_key']); return $e->getMessage(); }
    catch (Throwable $e) {
        error_log('Service assistance: ' . $e->getMessage());
        return 'The save could not be confirmed. Keep this form and retry; the same submission will not duplicate the change.';
    }
    return null;
}
function assistanceHistoryMarkup(array $history): void
{
    if (!$history) { return; }
    ?><details class="sa-history"><summary>Activity history</summary><ol><?php foreach ($history as $event) { ?>
        <li><strong><?= escapeHtml(ucfirst($event['event_action'])) ?></strong> · <?= escapeHtml($event['user_name'] ?: 'System') ?>
        <p><?= escapeHtml($event['event_note']) ?></p><small><?= escapeHtml(assistanceDate(fieldUtc($event['event_created_at']))) ?></small></li>
    <?php } ?></ol></details><?php
}
