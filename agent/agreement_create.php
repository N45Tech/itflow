<?php

if (intval($_GET['client_id'] ?? 0) > 0) {
    require_once 'includes/inc_all_client.php';
} else {
    require_once 'includes/inc_client_overview_all.php';
    $client_id = 0;
}
enforceUserPermission('module_support', 2);
require_once '../functions/agreement_setup.php';
$client_id = intval($client_id);
$client = null;
if ($client_id > 0) {
    enforceClientAccess($client_id);
    $client = mysqli_fetch_assoc(agreementDbQuery("SELECT client_id, client_name FROM clients
        WHERE client_id = $client_id AND client_archived_at IS NULL " . clientScopeSql('client_id') . ' LIMIT 1',
        'Could not load the agreement client'));
}
$saved = $_SESSION['agreement_setup_input'] ?? [];
if (!is_array($saved) || intval($saved['client_id'] ?? 0) !== $client_id) {
    $saved = [];
}
$setup_error = $saved ? (string) ($_SESSION['agreement_setup_error'] ?? '') : '';
$value = static function (string $path, $default = '') use ($saved): string {
    $node = $saved;
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return (string) $default;
        }
        $node = $node[$key];
    }
    return is_scalar($node) ? (string) $node : (string) $default;
};
$back_url = 'agreements.php' . ($client_id > 0 ? '?client_id=' . $client_id : '');
?>

<div class="n45-agreement-setup">
    <header class="n45-agreement-heading">
        <div><h1>New agreement</h1><p>Set the service expectations in one place, then review the draft before it becomes active.</p></div>
        <a href="<?= $back_url ?>" class="btn btn-light">Back to agreements</a>
    </header>

    <?php if (!$client) {
        $clients = agreementDbQuery('SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL '
            . clientScopeSql('client_id') . ' ORDER BY client_name', 'Could not load clients'); ?>
        <form method="get" class="n45-agreement-client-picker">
            <?php if ($client_id > 0) { ?><p role="alert">This client is unavailable. Choose an active client.</p><?php } ?>
            <label for="setup-client">Who is this agreement for?</label>
            <select id="setup-client" name="client_id" class="form-control" required>
                <option value="">Choose a client</option>
                <?php while ($row = mysqli_fetch_assoc($clients)) { ?>
                    <option value="<?= intval($row['client_id']) ?>"><?= escapeHtml($row['client_name']) ?></option>
                <?php } ?>
            </select>
            <button type="submit" class="btn btn-primary mt-3">Set up agreement</button>
        </form>
    <?php } else {
        $calendar = slaCurrentCalendarSnapshot();
        $profiles = [];
        $profiles_sql = agreementDbQuery('SELECT sla_id, sla_name, sla_response_minutes, sla_resolution_minutes
            FROM slas WHERE sla_archived_at IS NULL ORDER BY sla_name', 'Could not load SLA profiles');
        while ($row = mysqli_fetch_assoc($profiles_sql)) {
            $profiles[intval($row['sla_id'])] = $row;
        }
        $records = [];
        foreach (['users' => ['contacts', 'contact', ' AND contact_archived_at IS NULL'],
            'devices' => ['assets', 'asset', ' AND asset_archived_at IS NULL'],
            'services' => ['services', 'service', ''],
            'locations' => ['locations', 'location', ' AND location_archived_at IS NULL']] as $scope => $map) {
            [$table, $prefix, $active] = $map;
            $record_sql = agreementDbQuery("SELECT {$prefix}_id AS id, {$prefix}_name AS name FROM $table
                WHERE {$prefix}_client_id = $client_id$active ORDER BY {$prefix}_name",
                'Could not load client coverage records');
            $records[$scope] = mysqli_fetch_all($record_sql, MYSQLI_ASSOC);
        }
        $classifications = ['included' => 'Included', 'billable' => 'Billable', 'excluded' => 'Excluded'];
        $render_exception = static function ($index, array $row = []) use ($records, $classifications): void { ?>
            <div class="n45-agreement-exception" data-exception-row>
                <div><label for="exception-<?= $index ?>-record">Client record</label>
                    <select id="exception-<?= $index ?>-record" name="exceptions[<?= $index ?>][record]" class="form-control" data-exception-record>
                        <option value="">Choose a record</option>
                        <?php foreach (agreementSetupScopes() as $scope => $label) { ?>
                            <optgroup label="<?= $label ?>">
                                <?php foreach ($records[$scope] as $record) { $key = $scope . ':' . intval($record['id']); ?>
                                    <option value="<?= $key ?>" <?= ($row['record'] ?? '') === $key ? 'selected' : '' ?>><?= escapeHtml($record['name']) ?></option>
                                <?php } ?>
                            </optgroup>
                        <?php } ?>
                    </select>
                </div>
                <div><label for="exception-<?= $index ?>-class">Coverage</label>
                    <select id="exception-<?= $index ?>-class" name="exceptions[<?= $index ?>][classification]" class="form-control">
                        <?php foreach ($classifications as $key => $label) { ?>
                            <option value="<?= $key ?>" <?= ($row['classification'] ?? 'excluded') === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div><label for="exception-<?= $index ?>-notes">Notes</label>
                    <input id="exception-<?= $index ?>-notes" name="exceptions[<?= $index ?>][notes]" class="form-control" maxlength="1000"
                        value="<?= escapeHtml(is_string($row['notes'] ?? null) ? $row['notes'] : '') ?>" placeholder="Why this record differs">
                </div>
                <button type="button" class="btn btn-outline-danger" data-remove-exception hidden aria-label="Remove coverage exception"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
        <?php };
        ?>
        <p class="n45-agreement-client"><strong><?= escapeHtml($client['client_name']) ?></strong> <span>New draft</span></p>
        <?php if ($setup_error !== '') { ?><div class="alert alert-danger" role="alert"><?= escapeHtml($setup_error) ?> Your entries have been kept below.</div><?php } ?>
        <nav class="n45-agreement-steps" aria-label="Agreement setup" hidden data-setup-navigation>
            <?php foreach (['Agreement', 'Coverage', 'Service levels', 'Reviews', 'Check & save'] as $i => $label) { ?>
                <button type="button" data-go-step="<?= $i ?>"><span><?= $i + 1 ?></span><?= escapeHtml($label) ?></button>
            <?php } ?>
        </nav>
        <form action="post.php" method="post" id="agreement-setup-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="add_agreement" value="1">
            <input type="hidden" name="setup_version" value="1">
            <input type="hidden" name="client_id" value="<?= $client_id ?>">

            <section data-setup-step aria-labelledby="setup-agreement-title">
                <h2 id="setup-agreement-title" tabindex="-1">Agreement &amp; expectations</h2>
                <p>Start with the term and how you will work together. Required fields are marked with an asterisk.</p>
                <div class="n45-agreement-fields">
                    <div><label for="setup-name">Agreement name *</label><input id="setup-name" class="form-control" name="name" maxlength="255" required value="<?= escapeHtml($value('name')) ?>" placeholder="Managed Services Agreement"></div>
                    <div><label for="setup-type">Service model *</label><select id="setup-type" name="type" class="form-control" required>
                        <option value="">Choose a service model</option>
                        <?php foreach (['Fully Managed', 'Partially Managed', 'Project', 'Break/Fix', 'Other'] as $type) { ?>
                            <option <?= $value('type') === $type ? 'selected' : '' ?>><?= $type ?></option>
                        <?php } ?>
                    </select></div>
                    <div><label for="setup-from">Start date *</label><input id="setup-from" type="date" class="form-control" name="effective_from" required value="<?= escapeHtml($value('effective_from', date('Y-m-d'))) ?>"><small>Future-dated agreements stay drafts until their start date.</small></div>
                    <div><label for="setup-until">End date</label><input id="setup-until" type="date" class="form-control" name="effective_until" value="<?= escapeHtml($value('effective_until')) ?>"><small>Leave blank for an evergreen agreement.</small></div>
                    <div class="n45-agreement-wide"><label for="setup-responsibilities">Client responsibilities</label><textarea id="setup-responsibilities" class="form-control" name="responsibilities" rows="3" maxlength="4000" placeholder="Client contacts, access requirements, approvals and responsibilities"><?= escapeHtml($value('responsibilities')) ?></textarea></div>
                </div>
            </section>

            <section data-setup-step aria-labelledby="setup-coverage-title">
                <h2 id="setup-coverage-title" tabindex="-1">What is covered?</h2>
                <p>Set the baseline for each area. Specific records can have different coverage below.</p>
                <div class="n45-agreement-coverage">
                    <?php foreach (agreementSetupScopes() as $scope => $label) { ?>
                        <div class="n45-agreement-coverage-row">
                            <strong><?= $label ?><small><?= count($records[$scope]) ?> current records</small></strong>
                            <div><label for="coverage-<?= $scope ?>">Coverage *</label><select id="coverage-<?= $scope ?>" name="coverage[<?= $scope ?>][classification]" class="form-control" required>
                                <option value="">Choose coverage</option>
                                <?php foreach ($classifications as $key => $text) { ?><option value="<?= $key ?>" <?= $value("coverage.$scope.classification") === $key ? 'selected' : '' ?>><?= $text ?></option><?php } ?>
                            </select></div>
                            <div><label for="limit-<?= $scope ?>">Quantity limit</label><input id="limit-<?= $scope ?>" type="number" name="coverage[<?= $scope ?>][limit]" class="form-control" min="0" max="9999999999.99" step="0.01" value="<?= escapeHtml($value("coverage.$scope.limit")) ?>" placeholder="No limit"></div>
                        </div>
                    <?php } ?>
                </div>
                <p class="n45-agreement-help">Limits count the client's full active population, not individual ticket usage. Exceeding a limit makes that area's ticket coverage billable; excluded coverage carries no SLA.</p>
                <h3>Specific exceptions</h3>
                <p>For example, exclude an unmanaged device while keeping other devices included. Exact records override the baseline for their area.</p>
                <div data-exception-list>
                    <?php $exceptions = is_array($saved['exceptions'] ?? null) ? array_values(array_slice($saved['exceptions'], 0, 20)) : [];
                    if (!$exceptions) { $exceptions = [[]]; }
                    foreach ($exceptions as $index => $row) { $render_exception($index, is_array($row) ? $row : []); } ?>
                </div>
                <template id="agreement-exception-template"><?php $render_exception('__INDEX__'); ?></template>
                <button type="button" class="btn btn-light" data-add-exception hidden><i class="fas fa-plus mr-2" aria-hidden="true"></i>Add exception</button>
                <div class="mt-4"><label for="setup-scope-notes">Service scope &amp; exclusions</label><textarea id="setup-scope-notes" class="form-control" name="scope_notes" rows="3" maxlength="4000" placeholder="Describe included work, exclusions, project boundaries and commercial assumptions"><?= escapeHtml($value('scope_notes')) ?></textarea><small>These notes describe the agreement. Automated ticket classification uses the coverage choices above, not free text.</small></div>
            </section>

            <section data-setup-step aria-labelledby="setup-sla-title">
                <h2 id="setup-sla-title" tabindex="-1">Support hours &amp; service levels</h2>
                <p>These are this agreement's service commitments. Changes here do not alter your shared SLA profiles.</p>
                <div class="n45-agreement-fields">
                    <div><label for="setup-calendar">Support calendar *</label><select id="setup-calendar" class="form-control" name="calendar[mode]" required>
                        <option value="business_hours" <?= $value('calendar.mode', $calendar['calendar_mode']) === 'business_hours' ? 'selected' : '' ?>>Business hours</option>
                        <option value="24x7" <?= $value('calendar.mode', $calendar['calendar_mode']) === '24x7' ? 'selected' : '' ?>>24/7</option>
                    </select></div>
                    <div><label for="setup-timezone">Support timezone *</label><select id="setup-timezone" class="form-control" name="calendar[timezone]" required>
                        <?php foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $timezone) { ?><option <?= $value('calendar.timezone', $calendar['timezone']) === $timezone ? 'selected' : '' ?>><?= escapeHtml($timezone) ?></option><?php } ?>
                    </select></div>
                </div>
                <div data-business-calendar class="mt-3">
                    <fieldset class="n45-agreement-days"><legend>Support days *</legend>
                        <?php $saved_days = $saved['calendar']['days'] ?? $calendar['business_days'];
                        $saved_days = is_array($saved_days) ? array_filter($saved_days, 'is_scalar') : [];
                        $saved_days = array_map('strval', $saved_days);
                        foreach ([1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day => $label) { ?>
                            <label><input type="checkbox" name="calendar[days][]" value="<?= $day ?>" <?= in_array((string) $day, $saved_days, true) ? 'checked' : '' ?>> <?= $label ?></label>
                        <?php } ?>
                    </fieldset>
                    <div class="n45-agreement-fields">
                        <div><label for="setup-hours-start">Start time *</label><input id="setup-hours-start" type="time" class="form-control" name="calendar[start]" value="<?= escapeHtml($value('calendar.start', substr($calendar['business_hours_start'] ?? '09:00', 0, 5))) ?>"></div>
                        <div><label for="setup-hours-end">End time *</label><input id="setup-hours-end" type="time" class="form-control" name="calendar[end]" value="<?= escapeHtml($value('calendar.end', substr($calendar['business_hours_end'] ?? '17:00', 0, 5))) ?>"></div>
                    </div>
                    <small>Use a same-day window. Business-hours targets pause outside this window and on configured company holidays.</small>
                </div>
                <h3 class="mt-4">Targets by priority</h3>
                <p>Choose a starting profile, then enter this agreement's targets in minutes. A zero or blank resolution target means no resolution commitment. Choose No SLA explicitly for best-effort service.</p>
                <?php if (!$profiles) { ?><div class="alert alert-warning">There are no active SLA profiles. An administrator must add a profile before timed commitments can be configured. No SLA is still available.</div><?php } ?>
                <div class="n45-agreement-slas">
                    <?php foreach (array_reverse(ticketPriorityDefinitions(), true) as $priority => $definition) {
                        $profile_id = $value("sla.$priority.profile_id");
                        $profile = $profiles[intval($profile_id)] ?? null;
                        ?>
                        <div class="n45-agreement-sla-row" data-priority="<?= $priority ?>">
                            <div><strong><?= $priority ?></strong><small><?= escapeHtml($definition['short']) ?></small></div>
                            <div><label for="sla-<?= $priority ?>-profile">Starting profile *</label><select id="sla-<?= $priority ?>-profile" class="form-control" name="sla[<?= $priority ?>][profile_id]" required data-sla-profile>
                                <option value="">Choose a profile</option>
                                <option value="0" <?= $profile_id === '0' ? 'selected' : '' ?>>No SLA (best effort)</option>
                                <?php foreach ($profiles as $id => $sla) { ?>
                                    <option value="<?= $id ?>" data-response="<?= intval($sla['sla_response_minutes']) ?>" data-resolution="<?= is_null($sla['sla_resolution_minutes']) ? '' : intval($sla['sla_resolution_minutes']) ?>" <?= $profile_id === (string) $id ? 'selected' : '' ?>><?= escapeHtml($sla['sla_name']) ?></option>
                                <?php } ?>
                            </select></div>
                            <div><label for="sla-<?= $priority ?>-response">Response (minutes)</label><input id="sla-<?= $priority ?>-response" class="form-control" type="number" min="0" max="1051200" name="sla[<?= $priority ?>][response]" value="<?= escapeHtml($value("sla.$priority.response", $profile['sla_response_minutes'] ?? '')) ?>" data-sla-response></div>
                            <div><label for="sla-<?= $priority ?>-resolution">Resolution (minutes)</label><input id="sla-<?= $priority ?>-resolution" class="form-control" type="number" min="0" max="1051200" name="sla[<?= $priority ?>][resolution]" value="<?= escapeHtml($value("sla.$priority.resolution", $profile['sla_resolution_minutes'] ?? '')) ?>" placeholder="No target" data-sla-resolution></div>
                        </div>
                    <?php } ?>
                </div>
                <p class="n45-agreement-help">Targets apply when coverage permits an SLA. Response zero means no response target. Existing tickets keep their saved terms unless explicitly re-stamped.</p>
                <div class="mt-3"><label for="setup-escalation">Escalation &amp; after-hours process</label><textarea id="setup-escalation" class="form-control" name="escalation" rows="3" maxlength="4000" placeholder="How to report an emergency, escalation contacts, onsite arrangements and any after-hours charges"><?= escapeHtml($value('escalation')) ?></textarea><small>Process notes are not automatic paging or billing rules. Advanced request-type and hours-specific rules remain available on the draft.</small></div>
            </section>

            <section data-setup-step aria-labelledby="setup-reviews-title">
                <h2 id="setup-reviews-title" tabindex="-1">Onboarding &amp; business reviews</h2>
                <p>Keep this practical: establish the documentation during onboarding, then review it with the client at a regular business review.</p>
                <div class="n45-agreement-fields">
                    <div><label for="setup-cadence">Business review every (months) *</label><input id="setup-cadence" class="form-control" type="number" name="review_cadence_months" min="1" max="24" required value="<?= escapeHtml($value('review_cadence_months', '3')) ?>"><small>3 = quarterly, 6 = twice yearly, 12 = annually. The first review is due one interval after activation.</small></div>
                    <div><label for="setup-notice">Renewal notice (days) *</label><input id="setup-notice" class="form-control" type="number" name="renewal_notice_days" min="0" max="365" required value="<?= escapeHtml($value('renewal_notice_days', '90')) ?>"><small>Records the agreed notice period; it does not send a renewal notice.</small></div>
                    <div class="n45-agreement-wide"><label for="setup-review-notes">Onboarding handoff &amp; review agenda</label><textarea id="setup-review-notes" class="form-control" name="review_notes" rows="4" maxlength="4000" placeholder="Onboarding documents, client stakeholders, business goals and what to revisit at each review"><?= escapeHtml($value('review_notes')) ?></textarea></div>
                </div>
                <p class="n45-agreement-help">The active agreement schedules review drafts. Publishing a completed review and granting portal review permission remain separate, explicit actions. This setup does not create onboarding tickets or per-document audits.</p>
            </section>

            <section data-setup-step aria-labelledby="setup-summary-title">
                <h2 id="setup-summary-title" tabindex="-1">Check the agreement</h2>
                <p>Saving creates one complete draft with its coverage and SLA rules. It will not change client commitments until you publish it.</p>
                <div data-setup-summary></div>
                <noscript><p>Review the sections above before saving. All sections are available without JavaScript.</p></noscript>
            </section>

            <footer class="n45-agreement-actions">
                <a class="btn btn-light" href="<?= $back_url ?>">Cancel</a>
                <div><button type="button" class="btn btn-secondary" data-setup-back hidden>Back</button>
                    <button type="button" class="btn btn-primary" data-setup-next hidden>Continue</button>
                    <button type="submit" class="btn btn-primary" data-setup-save>Save complete draft</button></div>
            </footer>
            <p class="n45-agreement-help" role="status" data-setup-status></p>
        </form>
        <script src="/js/agreement_setup.js?v=<?= filemtime(__DIR__ . '/../js/agreement_setup.js') ?>" defer></script>
    <?php } ?>
</div>

<?php require_once '../includes/footer.php'; ?>
