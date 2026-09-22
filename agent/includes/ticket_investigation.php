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
<section class="n45-investigation" aria-labelledby="investigation-heading">
    <div class="n45-investigation-header">
        <h6 id="investigation-heading"><i class="fas fa-fw fa-search mr-2"></i>AI investigation <span class="badge badge-secondary">Read only</span></h6>
    </div>
    <div class="n45-investigation-summary small">
        <p class="text-muted small">AI-generated advisory from retained alert telemetry. No live checks or remediation were performed. Verify before acting.</p>
        <?php if (empty($investigation_advisory['current_signal'])) { ?>
            <p class="text-warning small"><strong>Historical advisory:</strong> the incident changed or recovered after this investigation.</p>
        <?php } ?>
        <?php if ($investigation_result) { ?>
            <p><?= nl2br(escapeHtml($investigation_result['summary'])) ?></p>
            <div class="small mb-2"><strong>Possible cause, not confirmed</strong><br><?= nl2br(escapeHtml($investigation_result['likely_cause'])) ?></div>
            <p class="small text-muted">Model-reported confidence: <?= escapeHtml($investigation_result['confidence']) ?>. This is not an independently calibrated probability.</p>
            <?php if ($investigation_result['recommended_checks']) { ?>
                <div class="n45-investigation-list">
                    <strong>Suggested technician checks</strong>
                    <ul><?php foreach ($investigation_result['recommended_checks'] as $item) { ?><li><?= escapeHtml($item) ?></li><?php } ?></ul>
                </div>
            <?php } ?>
            <?php if ($investigation_result['uncertainties']) { ?>
                <details class="n45-investigation-unknowns">
                    <summary>Missing evidence and unknowns</summary>
                    <ul><?php foreach ($investigation_result['uncertainties'] as $item) { ?><li><?= escapeHtml($item) ?></li><?php } ?></ul>
                </details>
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
        <div class="n45-investigation-footnote">Run <?= intval($investigation_advisory['investigation_id']) ?> · <?= escapeHtml($investigation_advisory['status']) ?> · <?= escapeHtml($investigation_advisory['created_at']) ?> UTC<?php if ($investigation_advisory['model_name']) { ?> · <?= escapeHtml($investigation_advisory['model_name']) ?><?php } ?></div>
    </div>
</section>
<?php unset($investigation_advisory, $investigation_result); ?>
