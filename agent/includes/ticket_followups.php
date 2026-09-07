<?php
if (!empty($ticket['ticket_archived_at']) || $client_id < 1) { return; }
require_once __DIR__ . '/service_assistance.php';
$followups = followupQueue(['ticket_id' => $ticket_id, 'scope' => 'all', 'due' => 'all', 'offset' => $_GET['followup_offset'] ?? 0], (int) $session_user_id);
if (!$followups['items'] && empty($followup_error)) { return; }
$owners = followupOwners($client_id);
?>
<link rel="stylesheet" href="/agent/service_assistance.css">
<section class="card" id="followups">
    <details class="card-body" <?= $followup_error ? 'open' : '' ?>>
        <summary class="fw-bold">Follow-ups <span class="text-muted">(<?= $followups['total'] ?>)</span></summary>
        <?php if ($followup_error) { ?><p class="alert alert-danger mt-3" role="alert"><?= escapeHtml($followup_error) ?></p><?php } ?>
        <?php foreach ($followups['items'] as $f) {
            $posted = $followup_error && ($_POST['key'] ?? '') === $f['key'] ? $_POST : [];
            $due = fieldUtc(gmdate('Y-m-d H:i:s', max(time() + 3600, strtotime($f['due_at']))));
            $escalation = fieldUtc(gmdate('Y-m-d H:i:s', max(strtotime($due) + 86400, strtotime($f['escalate_at'])))); ?>
        <div class="py-3 border-bottom">
            <p class="mb-1"><strong><?= escapeHtml($f['summary']) ?></strong></p>
            <p class="small text-muted mb-2"><?= escapeHtml($f['owner_name']) ?> · <?= escapeHtml(assistanceDate($f['due_at'])) ?><?= $f['overdue'] ? ' · Due now' : '' ?></p>
            <?php if ($f['note']) { ?><p><?= escapeHtml($f['note']) ?></p><?php } ?>
            <?php if (lookupUserPermission('module_support') >= 2) { ?>
            <details <?= $posted ? 'open' : '' ?>><summary>Plan next follow-up</summary>
                <form method="post" action="/agent/ticket.php?ticket_id=<?= $ticket_id ?>#followups" class="sa-form mt-3">
                    <?php assistanceFields($ticket_id, 'followup_plan'); ?>
                    <input type="hidden" name="key" value="<?= escapeHtml($f['key']) ?>">
                    <input type="hidden" name="expected_version" value="<?= escapeHtml($posted['expected_version'] ?? $f['version']) ?>">
                    <label>Follow-up owner<select class="form-select" name="owner_id" required>
                        <?php if (!$f['owner_id']) { ?><option value="">Choose an owner</option><?php } ?>
                        <?php foreach ($owners as $owner) { ?><option value="<?= (int) $owner['user_id'] ?>" <?= (int) ($posted['owner_id'] ?? $f['owner_id']) === (int) $owner['user_id'] ? 'selected' : '' ?>><?= escapeHtml($owner['user_name']) ?></option><?php } ?>
                    </select></label>
                    <label>Next follow-up (<?= escapeHtml(date_default_timezone_get()) ?>)<input class="form-control" name="due_at" type="datetime-local" required value="<?= escapeHtml($posted['due_at'] ?? (new DateTimeImmutable($due))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i')) ?>"></label>
                    <label>Escalate to<select class="form-select" name="escalate_to" data-followup-escalation>
                        <option value="0">Owner only</option>
                        <?php foreach ($owners as $owner) { ?><option value="<?= (int) $owner['user_id'] ?>" <?= (int) ($posted['escalate_to'] ?? $f['escalate_to']) === (int) $owner['user_id'] ? 'selected' : '' ?>><?= escapeHtml($owner['user_name']) ?></option><?php } ?>
                    </select></label>
                    <label data-followup-escalation-date <?= (int) ($posted['escalate_to'] ?? $f['escalate_to']) ? '' : 'hidden' ?>>Escalate after (<?= escapeHtml(date_default_timezone_get()) ?>)<input class="form-control" name="escalate_at" type="datetime-local" value="<?= escapeHtml($posted['escalate_at'] ?? (new DateTimeImmutable($escalation))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i')) ?>"></label>
                    <label>Next step and reason<textarea class="form-control" name="note" aria-label="Next step and reason" required minlength="5" maxlength="1000" rows="3"><?= escapeHtml($posted['note'] ?? $f['note']) ?></textarea></label>
                    <p class="small text-muted">Original due: <?= escapeHtml(assistanceDate($f['source_due_at'])) ?>. Completing the source removes this follow-up.</p>
                    <button class="btn btn-primary">Save follow-up plan</button>
                </form>
            </details><?php } ?>
            <?php assistanceHistoryMarkup(assistanceHistory($ticket_id, 'followup', $f['key'])); ?>
        </div><?php } ?>
        <?php if ($followups['next'] !== null) { ?><a href="/agent/ticket.php?ticket_id=<?= $ticket_id ?>&amp;followup_offset=<?= $followups['next'] ?>#followups">More follow-ups</a><?php } ?>
    </details>
</section>
<script src="/js/ticket_followups.js"></script>
