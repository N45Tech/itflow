<?php
require_once '../config.php';
require_once '../functions.php';
require_once '../includes/check_login.php';
require_once 'includes/service_assistance.php';
enforceUserPermission('module_support');
enforceUserPermission('module_client');
$error = assistancePagePost(); $article = null; $source = null; $queue = null;
try {
    if (!empty($_GET['id'])) { $article = knowledgeLoad((int) $_GET['id']); $source = assistanceTicket((int) $article['knowledge_ticket_id']); }
    elseif (!empty($_GET['ticket_id'])) { $source = assistanceTicket((int) $_GET['ticket_id']); }
    else { $queue = knowledgeQueue($_GET); }
} catch (DomainException $e) { $error ??= $e->getMessage(); }
require_once 'includes/inc_all.php';
?>
<!-- Operate: review a client-scoped resolution beside its source, then publish a clearly attributed internal document. -->
<link rel="stylesheet" href="/agent/service_assistance.css">
<div class="sa-page">
<header class="sa-heading"><div><h1>Resolution knowledge</h1><p class="text-muted">Turn a successful fix into instructions another technician can trust.</p></div><a class="btn btn-outline-secondary" href="/agent/field/#knowledge">Open in Field Mode</a></header>
<?php if ($error) { ?><p role="alert" class="alert alert-danger"><?= escapeHtml($error) ?></p><?php } ?>
<?php if ($source) { ?>
<section class="sa-plan">
<a href="/agent/knowledge.php">Back to knowledge review</a>
<p class="mt-3"><strong><?= escapeHtml($source['client_name']) ?></strong> · Source: <a href="/agent/ticket.php?ticket_id=<?= (int) $source['ticket_id'] ?>"><?= escapeHtml($source['ticket_prefix'] . $source['ticket_number'] . ' · ' . $source['ticket_subject']) ?></a></p>
<details><summary>Read the recorded resolution</summary><p class="sa-prose"><?= escapeHtml($source['ticket_resolution_summary'] ?? '') ?></p></details>
<?php if (!$article) { ?>
    <h2>Capture this resolution</h2><p>Start an internal draft from the ticket. Check where the fix applies, remove secrets, and record validation steps before requesting independent review.</p>
    <?php if (knowledgeSourceEligible($source) && lookupUserPermission('module_support') >= 2 && lookupUserPermission('module_client') >= 2) { ?>
    <form method="post"><?php assistanceFields((int) $source['ticket_id'], 'knowledge_capture'); ?><button class="btn btn-primary">Create knowledge draft</button></form>
    <?php } else { ?><p class="text-muted">A successful recorded resolution and support/documentation write access are required.</p><?php } ?>
<?php } else { $k = $article; $can_edit = lookupUserPermission('module_support') >= 2 && lookupUserPermission('module_client') >= 2; ?>
    <h2><?= escapeHtml($k['knowledge_title']) ?></h2><p><span class="badge badge-light"><?= escapeHtml(ucfirst($k['knowledge_state'])) ?></span> · Revision <?= (int) $k['knowledge_revision'] ?> · Internal to this client</p>
    <?php if (!$k['source_current']) { ?><p class="alert alert-warning">The source resolution has changed. Recheck it before this draft can be published.</p><?php } ?>
    <?php if ($k['knowledge_document_id']) { ?>
        <?php if ($k['document_available']) { ?><p><a href="/agent/document.php?document_id=<?= (int) $k['knowledge_document_id'] ?>">Read current client document</a></p><?php } ?>
        <?php if (!$k['document_current']) { ?><p class="alert alert-warning">The client document changed or is unavailable. It is excluded from reviewed suggestions until a new revision is published.</p><?php } ?>
    <?php } ?>
    <?php if ($k['knowledge_state'] === 'draft' && $can_edit) { ?>
    <form class="sa-form" method="post">
        <?php assistanceFields((int) $k['knowledge_ticket_id'], 'knowledge_save'); ?>
        <input type="hidden" name="knowledge_id" value="<?= (int) $k['knowledge_id'] ?>">
        <input type="hidden" name="expected_revision" value="<?= (int) ($_POST['expected_revision'] ?? $k['knowledge_revision']) ?>">
        <input type="hidden" name="expected_document_hash" value="<?= escapeHtml($k['document_hash']) ?>">
        <label>Article title<input class="form-control" name="title" minlength="5" maxlength="200" required value="<?= escapeHtml($_POST['title'] ?? $k['knowledge_title']) ?>"></label>
        <?php foreach (['problem'=>['Problem and applicability',10000],'solution'=>['Resolution steps',10000],'cautions'=>['Checks and cautions',5000]] as $field=>[$title,$max]) { ?>
        <label><?= $title ?><textarea aria-label="<?= $title ?>" class="form-control" name="<?= $field ?>" rows="5" maxlength="<?= $max ?>" <?= $field !== 'cautions' ? 'required' : '' ?>><?= escapeHtml($_POST[$field] ?? $k['knowledge_' . $field]) ?></textarea></label>
        <?php } ?>
        <?php if (!$k['source_current']) { ?><label><input type="checkbox" name="confirm_source" value="1" required> I checked the changed source resolution and updated this draft.</label><?php } ?>
        <?php if (!$k['document_current']) { ?><label><input type="checkbox" name="confirm_document" value="1" required> I read the current client document and included its relevant changes in this draft.</label><?php } ?>
        <p class="text-muted">Include how to validate the fix and when it should not be used. A different Full Support technician with documentation write access must review it.</p>
        <div class="sa-review-actions"><button class="btn btn-outline-secondary" name="operation" value="save">Save draft</button><button class="btn btn-primary" name="operation" value="submit">Request review</button></div>
    </form>
    <?php } else { ?>
    <div class="sa-review"><?php foreach (['problem'=>'Problem and applicability','solution'=>'Resolution steps','cautions'=>'Checks and cautions'] as $field=>$title) { ?><section><h3><?= $title ?></h3><p class="sa-prose"><?= escapeHtml($k['knowledge_' . $field]) ?></p></section><?php } ?></div>
    <?php if ($k['knowledge_state'] === 'review' && $can_edit && lookupUserPermission('module_support') >= 3
        && !in_array((int) $session_user_id, [(int) $k['knowledge_created_by'], (int) $k['knowledge_edited_by']], true)) { ?>
    <form class="sa-form" method="post">
        <?php assistanceFields((int) $k['knowledge_ticket_id'], 'knowledge_save'); ?>
        <input type="hidden" name="knowledge_id" value="<?= (int) $k['knowledge_id'] ?>">
        <input type="hidden" name="expected_revision" value="<?= (int) $k['knowledge_revision'] ?>">
        <label>Review reason<textarea class="form-control" name="note" minlength="5" maxlength="1000" required rows="3"><?= escapeHtml($_POST['note'] ?? '') ?></textarea></label>
        <div class="sa-review-actions"><button class="btn btn-primary" name="operation" value="publish" <?= !$k['source_current'] ? 'disabled' : '' ?>>Publish internal article</button><button class="btn btn-outline-secondary" name="operation" value="return">Return for changes</button></div>
    </form>
    <?php } elseif ($k['knowledge_state'] === 'review') { ?><p class="text-muted">Awaiting review by a different authorized technician.</p><?php } ?>
    <?php if ($k['knowledge_state'] === 'published' && $can_edit && $k['document_available']) { ?>
    <form method="post" class="sa-form">
        <?php assistanceFields((int) $k['knowledge_ticket_id'], 'knowledge_save'); ?>
        <input type="hidden" name="knowledge_id" value="<?= (int) $k['knowledge_id'] ?>">
        <input type="hidden" name="expected_revision" value="<?= (int) $k['knowledge_revision'] ?>">
        <input type="hidden" name="expected_document_hash" value="<?= escapeHtml($k['document_hash']) ?>">
        <label>Revision reason<textarea class="form-control" name="note" minlength="5" maxlength="1000" required rows="2"></textarea></label>
        <p class="text-muted">Start from the last reviewed article. The new revision requires an independent review and preserves the previous document version.</p>
        <button class="btn btn-outline-secondary" name="operation" value="revise">Start a revision</button>
    </form>
    <?php } ?>
    <?php } ?>
    <?php assistanceHistoryMarkup($k['history']); ?>
    <?php if ($k['knowledge_state'] !== 'published' && !$k['reviewer_count']) { ?><p class="alert alert-warning">No independent reviewer currently has Full Support and documentation write access for this client. An administrator must assign that access before publication.</p><?php } ?>
<?php } ?>
</section>
<?php } elseif ($queue) { ?>
<form method="get" class="sa-filters"><label>Show<select name="state" class="form-control"><?php foreach (['review'=>'Awaiting review','draft'=>'Drafts','published'=>'Published'] as $key=>$label) { ?><option value="<?= $key ?>" <?= $queue['state'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php } ?></select></label><button class="btn btn-primary">Apply filter</button></form>
<div class="sa-list"><?php foreach ($queue['items'] as $row) { ?>
    <article class="sa-row"><div class="sa-main"><h2><a href="?id=<?= (int) $row['knowledge_id'] ?>"><?= escapeHtml($row['knowledge_title']) ?></a></h2><p class="text-muted"><?= escapeHtml($row['client_name']) ?> · Last edited by <?= escapeHtml($row['user_name'] ?: 'Former technician') ?></p></div><div class="sa-due"><?= escapeHtml(assistanceDate(fieldUtc($row['knowledge_updated_at']))) ?></div></article>
<?php } ?>
<?php if (!$queue['items']) { ?><div class="sa-empty"><h2>No articles in this view</h2><p>Open Suggested fixes on a resolved ticket and choose Capture this resolution to start a knowledge draft.</p></div><?php } ?></div>
<?php if ($queue['next'] !== null) { ?><a class="btn btn-outline-secondary mt-3" href="?<?= escapeHtml(http_build_query(array_merge($_GET,['offset'=>$queue['next']]))) ?>">Next page</a><?php } ?>
<?php } ?>
</div>
<?php require_once '../includes/footer.php'; ?>
