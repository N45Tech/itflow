<?php

if (!$endpoint_network_current && !$endpoint_network_history) {
    return;
}

$network_observation_count = count($endpoint_network_current);
$network_history_count = count($endpoint_network_history);
$network_evidence_id = 'assetNetworkEvidence' . intval($asset_id);

?>

<div class="border-top">
    <div class="d-flex flex-wrap align-items-center justify-content-between px-3 py-2 bg-light">
        <div class="mr-3">
            <button class="btn btn-link btn-sm text-left p-0" type="button"
                data-toggle="collapse" data-target="#<?= $network_evidence_id ?>"
                aria-expanded="false" aria-controls="<?= $network_evidence_id ?>">
                <i class="fas fa-fw fa-satellite-dish mr-1"></i>Source observations
            </button>
            <span class="badge badge-secondary ml-1"><?= $network_observation_count ?> current</span>
            <span class="badge badge-light ml-1"><?= $network_history_count ?> historical</span>
        </div>
        <span class="small text-secondary">Read-only discovery evidence; edit interfaces above.</span>
    </div>

    <div class="collapse" id="<?= $network_evidence_id ?>">
        <div class="px-3 pt-3">
            <p class="small text-secondary mb-3">
                Latest network facts reported by connected tools. These observations do not replace the canonical interface inventory or topology above.
            </p>

            <h4 class="h6 text-bold mb-2">Current observations</h4>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-borderless mb-0">
                    <thead>
                    <tr>
                        <th>Observed interface</th>
                        <th>Type</th>
                        <th>MAC / addresses</th>
                        <th>VLAN</th>
                        <th>Neighbor</th>
                        <th>Source / seen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$endpoint_network_current) { ?>
                        <tr><td colspan="6" class="text-center text-secondary py-3">No active source observations remain. Historical evidence is retained below.</td></tr>
                    <?php } ?>
                    <?php foreach ($endpoint_network_current as $network_row) {
                        $network_state = $network_row['network_observation_state'];
                        $ipv4 = is_array($network_state['ipv4'] ?? null) ? $network_state['ipv4'] : [];
                        $ipv6 = is_array($network_state['ipv6'] ?? null) ? $network_state['ipv6'] : [];
                        $neighbor_name = $network_state['neighbor_name']
                            ?: ($network_row['neighbor_asset_name'] ?? '');
                        $neighbor_port = $network_state['neighbor_port']
                            ?: ($network_row['neighbor_interface_name'] ?? '');
                        $network_source_status = (string) (
                            $endpointStateBySource[$network_row['network_observation_source']]['endpoint_state_status']
                            ?? 'unknown'
                        );
                        ?>
                        <tr>
                            <td>
                                <div class="text-bold"><?= escapeHtml($network_state['interface_name'] ?? $network_row['network_observation_key']) ?></div>
                                <div class="small text-secondary text-monospace"><?= escapeHtml($network_row['network_observation_key']) ?></div>
                            </td>
                            <td>
                                <?= escapeHtml($network_state['interface_type'] ?: 'Unknown') ?>
                                <?php if (!empty($network_state['virtual'])) { ?><span class="badge badge-info ml-1">Virtual</span><?php } ?>
                            </td>
                            <td class="text-monospace small">
                                <div><?= escapeHtml($network_state['mac'] ?: 'No MAC') ?></div>
                                <?php foreach (array_slice(array_merge($ipv4, $ipv6), 0, 5) as $address) { ?>
                                    <div class="text-secondary"><?= escapeHtml($address) ?></div>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if (!empty($network_state['vlan_id'])) { ?>
                                    <span class="badge badge-primary">VLAN <?= intval($network_state['vlan_id']) ?></span>
                                    <div class="small text-secondary mt-1"><?= escapeHtml($network_state['vlan_name']) ?></div>
                                <?php } elseif (!empty($network_row['network_name'])) { ?>
                                    <?= escapeHtml($network_row['network_name']) ?>
                                <?php } else { ?>-<?php } ?>
                            </td>
                            <td>
                                <?php if ($neighbor_name || $neighbor_port) { ?>
                                    <div><?= escapeHtml($neighbor_name ?: 'Unknown neighbor') ?></div>
                                    <div class="small text-secondary"><?= escapeHtml($neighbor_port ?: 'Unknown port') ?></div>
                                    <span class="badge badge-light text-uppercase"><?= escapeHtml($network_state['neighbor_protocol'] ?? 'unknown') ?></span>
                                <?php } else { ?>-<?php } ?>
                            </td>
                            <td>
                                <div><?= escapeHtml(ucfirst($network_row['network_observation_source'])) ?></div>
                                <span class="badge badge-<?= $endpointBadge($network_source_status === 'conflicting' ? 'critical' : $network_source_status) ?>">
                                    <?= escapeHtml($endpointLabel($network_source_status)) ?>
                                </span>
                                <div class="small text-secondary" title="<?= escapeHtml($network_row['network_observation_last_seen_at']) ?>">
                                    <?= escapeHtml(timeAgo($network_row['network_observation_last_seen_at'])) ?>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($endpoint_network_history) { ?>
            <div class="border-top mt-3 px-3 py-3">
                <h4 class="h6 text-bold mb-2">Observation history</h4>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered bg-white mb-0">
                        <thead>
                        <tr>
                            <th>Observed interface</th>
                            <th>MAC / addresses</th>
                            <th>VLAN / neighbor</th>
                            <th>Observed period</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($endpoint_network_history, 0, 50) as $history_row) {
                            $history_state = $history_row['network_observation_state'];
                            $history_neighbor_name = ($history_state['neighbor_name'] ?? '')
                                ?: ($history_row['neighbor_asset_name'] ?? '');
                            $history_neighbor_port = ($history_state['neighbor_port'] ?? '')
                                ?: ($history_row['neighbor_interface_name'] ?? '');
                            $history_addresses = array_merge(
                                is_array($history_state['ipv4'] ?? null) ? $history_state['ipv4'] : [],
                                is_array($history_state['ipv6'] ?? null) ? $history_state['ipv6'] : []
                            );
                            ?>
                            <tr>
                                <td>
                                    <div class="text-bold"><?= escapeHtml($history_state['interface_name'] ?? $history_row['network_observation_key']) ?></div>
                                    <div class="small text-secondary"><?= escapeHtml(ucfirst($history_row['network_observation_source'])) ?></div>
                                </td>
                                <td class="small text-monospace">
                                    <div><?= escapeHtml(($history_state['mac'] ?? '') ?: 'No MAC') ?></div>
                                    <?php foreach (array_slice($history_addresses, 0, 6) as $history_address) { ?>
                                        <div class="text-secondary"><?= escapeHtml($history_address) ?></div>
                                    <?php } ?>
                                </td>
                                <td class="small">
                                    <?php if (!empty($history_state['vlan_id'])) { ?>
                                        <div>VLAN <?= intval($history_state['vlan_id']) ?> <?= escapeHtml($history_state['vlan_name'] ?? '') ?></div>
                                    <?php } ?>
                                    <?php if ($history_neighbor_name || $history_neighbor_port) { ?>
                                        <div><?= escapeHtml($history_neighbor_name ?: 'Unknown neighbor') ?> · <?= escapeHtml($history_neighbor_port ?: 'Unknown port') ?></div>
                                    <?php } ?>
                                    <?php if (empty($history_state['vlan_id']) && !$history_neighbor_name && !$history_neighbor_port) { ?>-<?php } ?>
                                </td>
                                <td class="small">
                                    <div><?= escapeHtml($history_row['network_observation_first_seen_at']) ?></div>
                                    <div class="text-secondary">to <?= escapeHtml($history_row['network_observation_ended_at'] ?: $history_row['network_observation_last_seen_at']) ?></div>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php } ?>
    </div>
</div>
