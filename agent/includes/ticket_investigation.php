<?php

defined('N45_TICKET_INVESTIGATION_VIEW') || die('Direct file access is not allowed');
$investigation_advisory = investigationTicketAdvisory(intval($ticket_id), intval($client_id));
if (!$investigation_advisory) {
    return;
}
$investigation_result = null;
if ($investigation_advisory['status'] === 'Complete' && !empty($investigation_advisory['result_json'])) {
    try {
        $investigation_result = investigationResult($investigation_advisory['result_json']);
    } catch (Throwable $error) {
        // Malformed stored output is never rendered raw.
    }
}
?>
<div class="card mb-3" aria-labelledby="investigation-heading">
    <div class="card-header px-3 py-2">
        <h5 id="investigation-heading" class="card-title mt-1"><i class="fas fa-fw fa-search mr-2"></i>AI investigation <span class="badge badge-secondary">Read only</span></h5>
    </div>
    <div class="card-body p-3">
        <p class="text-muted small">AI-generated advisory from retained alert telemetry. No live checks or remediation were performed. Verify before acting.</p>
        <?php if (empty($investigation_advisory['current_signal'])) { ?>
            <p class="text-warning small"><strong>Historical advisory:</strong> the incident changed or recovered after this investigation.</p>
        <?php } ?>
        <?php if ($investigation_result) { ?>
            <p><?= nl2br(escapeHtml($investigation_result['summary'])) ?></p>
            <div class="small mb-2"><strong>Possible cause, not confirmed</strong><br><?= nl2br(escapeHtml($investigation_result['likely_cause'])) ?></div>
            <p class="small text-muted">Model-reported confidence: <?= escapeHtml($investigation_result['confidence']) ?>. This is not an independently calibrated probability.</p>
            <?php foreach (['uncertainties' => 'Missing evidence', 'recommended_checks' => 'Suggested technician checks'] as $field => $label) { ?>
                <?php if ($investigation_result[$field]) { ?>
                    <strong class="small"><?= escapeHtml($label) ?></strong>
                    <ul class="small pl-3"><?php foreach ($investigation_result[$field] as $item) { ?><li><?= escapeHtml($item) ?></li><?php } ?></ul>
                <?php } ?>
            <?php } ?>
        <?php } else { ?>
            <p class="small mb-2"><?php
                echo escapeHtml(match ($investigation_advisory['status']) {
                    'Pending' => 'Queued, subject to the provider rate and daily limits.',
                    'Processing' => 'Investigation in progress.',
                    'Superseded' => 'Discarded because the target or authorization changed.',
                    'Failed' => 'No advisory was accepted. A failed or interrupted provider call is not automatically retried.',
                    default => 'The advisory is unavailable or its retention period expired.',
                });
            ?></p>
        <?php } ?>
        <div class="text-muted small border-top pt-2">Run <?= intval($investigation_advisory['investigation_id']) ?> · <?= escapeHtml($investigation_advisory['status']) ?> · <?= escapeHtml($investigation_advisory['created_at']) ?> UTC<?php if ($investigation_advisory['model_name']) { ?> · <?= escapeHtml($investigation_advisory['model_name']) ?><?php } ?></div>
    </div>
</div>
<?php unset($investigation_advisory, $investigation_result); ?>
