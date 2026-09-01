<?php

$endpoint_summary = is_array($endpoint_record['summary'] ?? null) ? $endpoint_record['summary'] : [];
$endpoint_sources = is_array($endpoint_record['sources'] ?? null) ? $endpoint_record['sources'] : [];
$endpoint_identities = is_array($endpoint_record['identities'] ?? null) ? $endpoint_record['identities'] : [];
$endpoint_network_current = is_array($endpoint_record['network_current'] ?? null)
    ? $endpoint_record['network_current']
    : [];
$endpoint_network_history = is_array($endpoint_record['network_history'] ?? null)
    ? $endpoint_record['network_history']
    : [];
$endpoint_timeline = is_array($endpoint_record['timeline'] ?? null) ? $endpoint_record['timeline'] : [];
$endpoint_evidence = is_array($endpoint_record['evidence'] ?? null) ? $endpoint_record['evidence'] : [];

$endpointLabel = static function ($value): string {
    $value = trim((string) $value);
    return $value === '' ? 'Unknown' : ucwords(str_replace('_', ' ', $value));
};
$endpointBadge = static function ($value): string {
    $value = strtolower((string) $value);
    if (in_array($value, ['healthy', 'compliant', 'encrypted', 'enabled', 'active', 'deployed'], true)) {
        return 'success';
    }
    if (in_array($value, ['warning', 'stale', 'grace_period', 'partial', 'due_soon', 'maintenance'], true)) {
        return 'warning';
    }
    if (in_array($value, ['critical', 'offline', 'noncompliant', 'unencrypted', 'disabled', 'expired', 'lost'], true)) {
        return 'danger';
    }
    return 'secondary';
};
$endpointStateBySource = [];
foreach ($endpoint_sources as $endpoint_source) {
    $source_key = (string) ($endpoint_source['endpoint_state_source'] ?? '');
    $external_key = (string) ($endpoint_source['endpoint_state_external_id'] ?? '');
    $identity_key = $source_key . "\0" . $external_key;
    if ($source_key !== '' && $external_key !== '' && !isset($endpointStateBySource[$identity_key])) {
        $endpointStateBySource[$identity_key] = $endpoint_source;
    }
}
?>

<div class="card card-outline card-primary">
    <div class="card-header py-2">
        <h3 class="card-title mt-1">
            <i class="fas fa-fw fa-shield-alt mr-2"></i>Unified Endpoint &amp; Network Record
        </h3>
        <div class="card-tools">
            <span class="badge badge-light"><?= count($endpoint_sources) ?> source<?= count($endpoint_sources) === 1 ? '' : 's' ?></span>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-lg-3 col-md-6">
                <div class="small text-uppercase text-secondary text-bold">Assigned user</div>
                <div class="mt-1 text-bold">
                    <i class="fas fa-fw fa-user mr-1 text-secondary"></i>
                    <?= escapeHtml(($endpoint_summary['assigned_user_name'] ?? '') ?: 'Unassigned') ?>
                </div>
                <?php if (!empty($endpoint_summary['assigned_user_email'])) { ?>
                    <div class="small text-secondary mt-1"><?= escapeHtml($endpoint_summary['assigned_user_email']) ?></div>
                <?php } ?>
            </div>
            <div class="col-lg-3 col-md-6 mt-3 mt-md-0">
                <div class="small text-uppercase text-secondary text-bold">Device posture</div>
                <?php foreach ([
                    'compliance_state' => 'Compliance',
                    'encryption_state' => 'Encryption',
                    'secure_boot_state' => 'Secure Boot',
                ] as $posture_key => $posture_label) {
                    $posture_value = $endpoint_summary[$posture_key] ?? 'unknown'; ?>
                    <div class="mt-1">
                        <span class="badge badge-<?= $endpointBadge($posture_value) ?>"><?= escapeHtml($endpointLabel($posture_value)) ?></span>
                        <span class="small text-secondary ml-1"><?= $posture_label ?></span>
                    </div>
                <?php } ?>
            </div>
            <div class="col-lg-3 col-md-6 mt-3 mt-lg-0">
                <div class="small text-uppercase text-secondary text-bold">Management coverage</div>
                <?php foreach ([
                    'level_health' => 'Level.io',
                    'sentinelone_health' => 'SentinelOne',
                ] as $health_key => $health_label) {
                    $health_value = $endpoint_summary[$health_key] ?? 'unmanaged'; ?>
                    <div class="mt-1">
                        <span class="badge badge-<?= $endpointBadge($health_value) ?>"><?= escapeHtml($endpointLabel($health_value)) ?></span>
                        <span class="small text-secondary ml-1"><?= $health_label ?></span>
                    </div>
                <?php } ?>
            </div>
            <div class="col-lg-3 col-md-6 mt-3 mt-lg-0">
                <div class="small text-uppercase text-secondary text-bold">Lifecycle</div>
                <div class="mt-1">
                    <span class="badge badge-<?= $endpointBadge($endpoint_summary['lifecycle_state'] ?? 'unknown') ?>">
                        <?= escapeHtml($endpointLabel($endpoint_summary['lifecycle_state'] ?? 'unknown')) ?>
                    </span>
                    <span class="small text-secondary ml-1">Asset</span>
                </div>
                <div class="mt-1">
                    <span class="badge badge-<?= $endpointBadge($endpoint_summary['warranty_state'] ?? 'unknown') ?>">
                        <?= escapeHtml($endpointLabel($endpoint_summary['warranty_state'] ?? 'unknown')) ?>
                    </span>
                    <span class="small text-secondary ml-1">Warranty<?= !empty($endpoint_summary['warranty_expire']) ? ' · ' . escapeHtml($endpoint_summary['warranty_expire']) : '' ?></span>
                </div>
            </div>
        </div>

        <div class="border-top mt-3 pt-3">
            <div class="row">
                <div class="col-md-8">
                    <div class="small text-uppercase text-secondary text-bold">Operating system</div>
                    <div class="mt-1">
                        <?= escapeHtml(($endpoint_summary['os_name'] ?? '') ?: 'Unknown') ?>
                        <?php if (!empty($endpoint_summary['os_version'])) { ?>
                            <span class="text-secondary ml-1"><?= escapeHtml($endpoint_summary['os_version']) ?></span>
                        <?php } ?>
                        <?php if (!empty($endpoint_summary['os_build'])) { ?>
                            <span class="badge badge-light ml-1">Build <?= escapeHtml($endpoint_summary['os_build']) ?></span>
                        <?php } ?>
                    </div>
                </div>
                <div class="col-md-4 mt-3 mt-md-0">
                    <div class="small text-uppercase text-secondary text-bold">Directory identities</div>
                    <div class="small mt-1 text-monospace">
                        Entra: <?= escapeHtml(($endpoint_summary['entra_device_id'] ?? '') ?: 'Not mapped') ?>
                    </div>
                    <div class="small mt-1 text-monospace">
                        Intune: <?= escapeHtml(($endpoint_summary['intune_device_id'] ?? '') ?: 'Not mapped') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-1"><i class="fas fa-fw fa-fingerprint mr-2"></i>Management Identities</h3>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped table-borderless mb-0">
            <thead>
            <tr>
                <th>Source</th>
                <th>External identity</th>
                <th>Mapping</th>
                <th>Health</th>
                <th>Compliance</th>
                <th>Last observed</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$endpoint_identities && !$endpoint_sources) { ?>
                <tr><td colspan="6" class="text-center text-secondary py-3">No mapped management identity has reported yet.</td></tr>
            <?php } ?>
            <?php foreach ($endpoint_identities as $identity) {
                $identity_source = (string) $identity['automation_mapping_source'];
                $identity_external_id = (string) $identity['automation_mapping_external_id'];
                $source_state = $endpointStateBySource[$identity_source . "\0" . $identity_external_id] ?? [];
                $mapping_state = (string) $identity['automation_mapping_state'];
                $mapping_badge_state = match ($mapping_state) {
                    'automatic', 'confirmed' => 'healthy',
                    'stale' => 'warning',
                    'conflicting' => 'critical',
                    default => 'unmanaged',
                };
                $health = (string) ($source_state['endpoint_state_health'] ?? 'unknown');
                $compliance = (string) ($source_state['endpoint_state_compliance'] ?? 'unknown');
                $last_observed = $source_state['endpoint_state_observed_at']
                    ?? $identity['automation_mapping_last_seen_at']
                    ?? null;
                ?>
                <tr>
                    <td class="text-bold"><?= escapeHtml(ucfirst($identity_source)) ?></td>
                    <td>
                        <div class="text-monospace small"><?= escapeHtml($identity['automation_mapping_external_id']) ?></div>
                        <?php if (!empty($identity['automation_mapping_external_name'])) { ?>
                            <div class="small text-secondary"><?= escapeHtml($identity['automation_mapping_external_name']) ?></div>
                        <?php } ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= $endpointBadge($mapping_badge_state) ?>">
                            <?= escapeHtml($endpointLabel($mapping_state)) ?>
                        </span>
                        <div class="small text-secondary mt-1"><?= escapeHtml(number_format((float) $identity['automation_mapping_confidence'], 0)) ?>% confidence</div>
                    </td>
                    <td><span class="badge badge-<?= $endpointBadge($health) ?>"><?= escapeHtml($endpointLabel($health)) ?></span></td>
                    <td><span class="badge badge-<?= $endpointBadge($compliance) ?>"><?= escapeHtml($endpointLabel($compliance)) ?></span></td>
                    <td title="<?= escapeHtml($last_observed) ?>"><?= $last_observed ? escapeHtml(timeAgo($last_observed)) : 'Never' ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row">
    <div class="col-lg-7">
        <div class="card card-dark h-100">
            <div class="card-header py-2">
                <h3 class="card-title mt-1"><i class="fas fa-fw fa-history mr-2"></i>Device &amp; Network Timeline</h3>
            </div>
            <div class="card-body p-0">
                <?php if (!$endpoint_timeline) { ?>
                    <div class="text-center text-secondary py-4">No endpoint changes have been recorded.</div>
                <?php } ?>
                <div class="list-group list-group-flush">
                    <?php foreach (array_slice($endpoint_timeline, 0, 20) as $timeline_item) { ?>
                        <div class="list-group-item px-3 py-2">
                            <div class="d-flex justify-content-between">
                                <span class="text-bold"><?= escapeHtml($timeline_item['summary']) ?></span>
                                <span class="badge badge-light ml-2"><?= escapeHtml(ucfirst($timeline_item['source'])) ?></span>
                            </div>
                            <div class="small text-secondary mt-1" title="<?= escapeHtml($timeline_item['occurred_at']) ?>">
                                <?= escapeHtml(timeAgo($timeline_item['occurred_at'])) ?> · <?= escapeHtml($endpointLabel($timeline_item['type'])) ?>
                                <?php if (intval($timeline_item['ticket_id'] ?? 0) > 0) { ?>
                                    · <?php if (intval($timeline_item['live_ticket_id'] ?? 0) > 0) { ?><a href="ticket.php?client_id=<?= $client_id ?>&ticket_id=<?= intval($timeline_item['ticket_id']) ?>"><?= escapeHtml(($timeline_item['ticket_label'] ?? '') ?: 'Ticket') ?></a><?php } else { ?><span title="The referenced ticket was deleted"><?= escapeHtml(($timeline_item['ticket_label'] ?? '') ?: 'Deleted ticket #' . intval($timeline_item['ticket_id'])) ?></span><?php } ?>
                                <?php } ?>
                                <?php if (intval($timeline_item['document_id'] ?? 0) > 0) { ?>
                                    · <?php if (intval($timeline_item['live_document_id'] ?? 0) > 0) { ?><a href="document.php?client_id=<?= $client_id ?>&document_id=<?= intval($timeline_item['document_id']) ?>"><?= escapeHtml(($timeline_item['document_label'] ?? '') ?: 'Document') ?></a><?php } else { ?><span title="The referenced document was deleted"><?= escapeHtml(($timeline_item['document_label'] ?? '') ?: 'Deleted document #' . intval($timeline_item['document_id'])) ?></span><?php } ?>
                                <?php } ?>
                                <?php if (intval($timeline_item['evidence_id'] ?? 0) > 0) { ?>
                                    <?php if (intval($timeline_item['evidence_ticket_id'] ?? 0) > 0) { ?>
                                        · <a href="ticket.php?client_id=<?= $client_id ?>&ticket_id=<?= intval($timeline_item['evidence_ticket_id']) ?>"><?= escapeHtml(($timeline_item['evidence_label'] ?? '') ?: 'Evidence') ?></a>
                                    <?php } else { ?>
                                        · <span title="The referenced evidence was deleted"><?= escapeHtml(($timeline_item['evidence_label'] ?? '') ?: 'Deleted evidence #' . intval($timeline_item['evidence_id'])) ?></span>
                                    <?php } ?>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5 mt-3 mt-lg-0">
        <div class="card card-dark h-100">
            <div class="card-header py-2">
                <h3 class="card-title mt-1"><i class="fas fa-fw fa-paperclip mr-2"></i>Related Evidence</h3>
            </div>
            <div class="card-body p-0">
                <?php if (!$endpoint_evidence) { ?>
                    <div class="text-center text-secondary py-4">No runbook evidence is linked through this device's tickets.</div>
                <?php } ?>
                <div class="list-group list-group-flush">
                    <?php foreach (array_slice($endpoint_evidence, 0, 12) as $evidence_item) { ?>
                        <div class="list-group-item px-3 py-2">
                            <div class="text-bold"><?= escapeHtml($evidence_item['task_name']) ?></div>
                            <div class="small text-secondary mt-1">
                                <a href="ticket.php?client_id=<?= $client_id ?>&ticket_id=<?= intval($evidence_item['ticket_id']) ?>">
                                    <?= escapeHtml($evidence_item['ticket_prefix']) ?><?= intval($evidence_item['ticket_number']) ?>
                                </a>
                                · <?= escapeHtml($endpointLabel($evidence_item['task_evidence_type'])) ?>
                                · <?= escapeHtml(timeAgo($evidence_item['task_evidence_created_at'])) ?>
                            </div>
                            <?php if (!empty($evidence_item['task_evidence_note'])) { ?>
                                <div class="small mt-1"><?= escapeHtml(truncate($evidence_item['task_evidence_note'], 140)) ?></div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="mb-3"></div>
