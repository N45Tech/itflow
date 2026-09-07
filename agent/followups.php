<?php
require_once '../config.php';
require_once '../functions.php';
require_once '../includes/check_login.php';
require_once 'includes/service_assistance.php';
enforceUserPermission('module_support');
$error = assistancePagePost();
$selected = null;
try {
    if (!empty($_GET['key'])) { $selected = followupDetail((int) ($_GET['ticket_id'] ?? 0), (string) $_GET['key']); }
    $queue = followupQueue($_GET, (int) $session_user_id);
} catch (DomainException $e) { $error ??= $e->getMessage(); $queue = ['items'=>[], 'next'=>null, 'total'=>0, 'limited'=>false]; }
require_once 'includes/inc_all.php';
?>
<!-- Operate: extend the PSA's dense list and inline action conventions. Owners, original deadlines and the next follow-up stay together. -->
<link rel="stylesheet" href="/agent/service_assistance.css">
<div class="sa-page">
    <header class="sa-heading"><div><h1>Follow-ups</h1><p class="text-muted">Keep promises, approvals and blocked work moving.</p></div><a class="btn btn-outline-secondary" href="/agent/field/#followups">Open in Field Mode</a></header>
    <?php if ($error) { ?><p role="alert" class="alert alert-danger"><?= escapeHtml($error) ?></p><?php } ?>
    <?php if ($selected) { $f = $selected;
        $f['due_at'] = fieldUtc(gmdate('Y-m-d H:i:s', max(time() + 3600, strtotime($f['due_at']))));
        $f['escalate_at'] = fieldUtc(gmdate('Y-m-d H:i:s', max(strtotime($f['due_at']) + 86400, strtotime($f['escalate_at'])))); ?>
    <section class="sa-plan" aria-labelledby="plan-title">
        <a href="/agent/followups.php">Back to follow-ups</a><h2 id="plan-title"><?= escapeHtml($f['summary']) ?></h2>
        <p><a href="/agent/ticket.php?ticket_id=<?= $f['ticket_id'] ?>"><?= escapeHtml($f['reference'] . ' · ' . $f['subject']) ?></a> · <?= escapeHtml($f['client_name']) ?></p>
        <p class="text-muted">Original due: <?= escapeHtml(assistanceDate($f['source_due_at'])) ?>. Completing the source item removes this follow-up automatically.</p>
        <?php if (lookupUserPermission('module_support') >= 2) { ?>
        <form method="post" class="sa-form">
            <?php assistanceFields($f['ticket_id'], 'followup_plan'); ?>
            <input type="hidden" name="key" value="<?= escapeHtml($f['key']) ?>">
            <input type="hidden" name="expected_version" value="<?= escapeHtml($_POST['expected_version'] ?? $f['version']) ?>">
            <div class="sa-columns">
            <?php foreach (['owner_id'=>'Follow-up owner','escalate_to'=>'Escalate to'] as $name=>$label) { ?>
                <label><?= $label ?><select class="form-control" name="<?= $name ?>" required>
                    <?php if ($name === 'escalate_to') { ?><option value="0">Owner only · no other recipient</option><?php } ?>
                    <?php if ($name === 'owner_id' && !$f['owner_id']) { ?><option value="">Choose an owner</option><?php } ?>
                    <?php foreach ($f['owners'] as $owner) { ?><option value="<?= (int) $owner['user_id'] ?>" <?= (int) ($_POST[$name] ?? $f[$name]) === (int) $owner['user_id'] ? 'selected' : '' ?>><?= escapeHtml($owner['user_name']) ?></option><?php } ?>
                </select></label>
            <?php } ?>
            <?php foreach (['due_at'=>'Next follow-up','escalate_at'=>'Escalate after'] as $name=>$label) { ?>
                <label><?= $label ?> (<?= escapeHtml(date_default_timezone_get()) ?>)<input class="form-control" type="datetime-local" name="<?= $name ?>" required value="<?= escapeHtml($_POST[$name] ?? (new DateTimeImmutable($f[$name]))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i')) ?>"></label>
            <?php } ?>
            </div>
            <label>Next step and reason<textarea class="form-control" name="note" minlength="5" maxlength="1000" required rows="3"><?= escapeHtml($_POST['note'] ?? $f['note']) ?></textarea></label>
            <p class="text-muted">Reminders appear in-app, at most once per UTC day for each unchanged plan and recipient. Changing this plan does not extend the original commitment or decide an approval.</p>
            <button class="btn btn-primary">Save follow-up plan</button>
        </form><?php } ?>
        <?php assistanceHistoryMarkup($f['history']); ?>
    </section>
    <?php } else { ?>
    <form class="sa-filters" method="get">
        <label>Assignment<select class="form-control" name="scope"><option value="mine">Mine and escalated to me</option><option value="all" <?= ($_GET['scope'] ?? '') === 'all' ? 'selected' : '' ?>>All accessible work</option></select></label>
        <label>When<select class="form-control" name="due"><option value="due">Due now</option><option value="all" <?= ($_GET['due'] ?? '') === 'all' ? 'selected' : '' ?>>All upcoming follow-ups</option></select></label>
        <label>Type<select class="form-control" name="kind"><option value="">All types</option><?php foreach (followupKinds() as $key=>$label) { ?><option value="<?= $key ?>" <?= ($_GET['kind'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option><?php } ?></select></label>
        <label>Client name<input class="form-control" name="client_query" maxlength="200" value="<?= escapeHtml($_GET['client_query'] ?? '') ?>" placeholder="Any accessible client"></label>
        <?php if (!empty($_GET['client_id'])) { ?><input type="hidden" name="client_id" value="<?= (int) $_GET['client_id'] ?>"><?php } ?>
        <button class="btn btn-primary">Apply filters</button>
    </form>
    <?php if ($queue['limited']) { ?><p class="alert alert-warning">This view reached 5,000 source items. Filter by client or type to narrow the queue.</p><?php } ?>
    <p class="text-muted"><?= $queue['total'] ?> follow-up<?= $queue['total'] === 1 ? '' : 's' ?> match these filters. Pending approvals enter the queue 24 hours after the request.</p>
    <div class="sa-list">
    <?php foreach ($queue['items'] as $f) { ?>
        <article class="sa-row">
            <div class="sa-main"><span class="sa-kind"><?= escapeHtml($f['kind_label']) ?></span>
            <h2><a href="?ticket_id=<?= $f['ticket_id'] ?>&amp;key=<?= rawurlencode($f['key']) ?>"><?= escapeHtml($f['summary']) ?></a></h2>
            <p class="text-muted"><?= escapeHtml($f['client_name']) ?> · <?= escapeHtml($f['reference']) ?> · <?= escapeHtml($f['subject']) ?></p>
            <?php if ($f['planned']) { ?><p><?= escapeHtml($f['note']) ?></p><?php } ?></div>
            <div class="sa-due"><strong><?= escapeHtml($f['owner_name']) ?></strong><p><?= escapeHtml(assistanceDate($f['due_at'])) ?></p>
            <?php if ($f['escalated']) { ?><span class="badge badge-warning">Escalation due</span><?php } elseif ($f['overdue']) { ?><span class="badge badge-warning">Follow-up due</span><?php } else { ?><span class="badge badge-light">Planned</span><?php } ?>
            <small>Escalation: <?= escapeHtml($f['escalate_name']) ?></small>
            <?php if ($f['planned'] && $f['source_overdue']) { ?><small>Original due: <?= escapeHtml(assistanceDate($f['source_due_at'])) ?></small><?php } ?></div>
        </article>
    <?php } ?>
    <?php if (!$queue['items']) { ?><div class="sa-empty"><h2>No follow-ups in this view</h2><p>Choose all upcoming follow-ups or all accessible work to review other commitments.</p></div><?php } ?>
    </div>
    <?php if ($queue['next'] !== null) { ?><a class="btn btn-outline-secondary mt-3" href="?<?= escapeHtml(http_build_query(array_merge($_GET, ['offset'=>$queue['next']]))) ?>">Next page</a><?php } ?>
    <?php } ?>
</div>
<?php require_once '../includes/footer.php'; ?>
