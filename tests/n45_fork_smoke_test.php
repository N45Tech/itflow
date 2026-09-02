<?php

$failures = [];
$root = dirname(__DIR__);

$assertTrue = function ($condition, string $message) use (&$failures): void {
    if ($condition !== true) {
        $failures[] = $message;
    }
};

$assertContains = function (string $needle, string $contents, string $message) use (&$failures): void {
    if (!str_contains($contents, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};

$assertNotContains = function (string $needle, string $contents, string $message) use (&$failures): void {
    if (str_contains($contents, $needle)) {
        $failures[] = $message . " (found '$needle')";
    }
};

$assertOrdered = function (string $contents, array $needles, string $message) use (&$failures): void {
    $position = -1;
    foreach ($needles as $needle) {
        $position = strpos($contents, $needle, $position + 1);
        if ($position === false) {
            $failures[] = $message . " (missing or out of order '$needle')";
            return;
        }
    }
};

$read = function (string $relative_path) use ($root, &$failures): string {
    $contents = @file_get_contents($root . '/' . $relative_path);
    if ($contents === false) {
        $failures[] = "Could not read $relative_path";
        return '';
    }
    return $contents;
};

$section = function (string $contents, string $start, string $end, string $label) use (&$failures): string {
    $start_at = strpos($contents, $start);
    $end_at = $start_at === false ? false : strpos($contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false || $end_at <= $start_at) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr($contents, $start_at, $end_at - $start_at);
};

require_once $root . '/n45/bootstrap.php';
$manifest = n45ForkManifest();

// Boundary and manifest smoke checks.
$assertTrue(intval($manifest['schema_version'] ?? 0) === 2, 'The N45 manifest schema version changed unexpectedly');
$assertTrue(($manifest['maintenance']['upstream_review_script'] ?? '') === 'scripts/n45-upstream-review.sh', 'The manifest does not identify the upstream review tool');
$assertTrue(($manifest['maintenance']['diff_check_allowlist'] ?? '') === 'n45/upstream-diff-check.allowlist', 'The manifest does not identify the exact whitespace allowlist');
$assertTrue(($manifest['maintenance']['security_sensitive_paths'] ?? '') === 'n45/security-sensitive-paths.regex', 'The manifest does not identify security-sensitive overlap rules');
$assertTrue(($manifest['maintenance']['upstream_marker_base'] ?? '') === '2.6.7', 'The N45 namespace does not preserve the upstream marker base');
$expected_legacy_migrations = [
    'n45-0001-entra-agent-sso' => '2.6.8',
    'n45-0002-level-integration' => '2.6.9',
    'n45-0003-automation-integration' => '2.7.0',
    'n45-0004-mail-template-metadata' => '2.7.1',
    'n45-0005-portal-access-scopes' => '2.7.2',
    'n45-0006-operations-ticket-delete-integrity' => '2.7.3',
    'n45-0007-level-interface-links' => '2.7.4',
    'n45-0008-external-identity-lifecycle' => '2.7.5',
    'n45-0009-automation-event-lifecycle' => '2.7.6',
    'n45-0010-versioned-runbooks' => '2.7.7',
    'n45-0011-documentation-readiness' => '2.7.8',
    'n45-0012-unified-endpoint-network' => '2.7.9',
    'n45-0013-portal-request-catalog' => '2.8.0',
    'n45-0014-agreement-entitlements' => '2.8.1',
];
$expected_integration_reservations = [
    '2.7.8' => 'n45-0011-documentation-readiness',
    '2.7.9' => 'n45-0012-unified-endpoint-network',
    '2.8.0' => 'n45-0013-portal-request-catalog',
    '2.8.1' => 'n45-0014-agreement-entitlements',
];
$integration_reservations = $manifest['maintenance']['integration_migration_reservations'] ?? [];
$assertTrue(
    array_map(static fn (array $reservation): string => (string) ($reservation['id'] ?? ''), $integration_reservations)
        === $expected_integration_reservations,
    'The final feature migration reservations changed or are out of order'
);
$post_integration_reservations = $manifest['maintenance']['post_integration_migration_reservations'] ?? [];
$assertTrue(
    array_keys($post_integration_reservations) === [
        'n45-0015-documentation-evidence-reference-index',
        'n45-0016-ticket-operational-discipline',
        'n45-0018-retention-controls',
    ],
    'The post-integration compatibility, ticket-operation, and retention migrations are not reserved'
);
$required_migration_ids = array_keys($expected_legacy_migrations);
$manifest_migration_ids = array_keys($manifest['migrations'] ?? []);
$assertTrue(($manifest_migration_ids[0] ?? '') === 'n45-0000-namespace-foundation', 'The durable N45 namespace foundation must remain first');
$assertTrue(array_slice($manifest_migration_ids, 1, count($required_migration_ids)) === $required_migration_ids, 'The ordered legacy N45 migration inventory is incomplete');
$assertTrue(($manifest_migration_ids[11] ?? '') === 'n45-0011-documentation-readiness', 'The documentation readiness migration is not the next stable N45 ID');
$assertTrue(($manifest_migration_ids[12] ?? '') === 'n45-0012-unified-endpoint-network', 'The unified endpoint migration is not the reserved stable N45 ID');
$assertTrue(($manifest_migration_ids[13] ?? '') === 'n45-0013-portal-request-catalog', 'The portal request migration is not the reserved stable N45 ID');
$assertTrue(isset($manifest['migrations']['n45-0013-portal-request-catalog']), 'The released portal request migration is missing from the manifest');
$assertTrue(
    ($manifest['modules']['portal_requests']['runtime_files'] ?? []) === ['functions/portal_requests.php']
        && ($manifest['modules']['portal_requests']['migrations'] ?? []) === ['n45-0013-portal-request-catalog'],
    'The portal request service and schema are not owned by the portal_requests module boundary'
);
$assertTrue(($manifest_migration_ids[14] ?? '') === 'n45-0014-agreement-entitlements', 'The agreement migration is not the final reserved feature ID');
$assertTrue(
    isset($manifest['migrations']['n45-0015-documentation-evidence-reference-index']),
    'The documentation evidence-index repair is missing from the stable N45 stream'
);
$assertTrue(
    ($manifest_migration_ids[16] ?? '') === 'n45-0016-ticket-operational-discipline',
    'Ticket operational discipline is missing or out of order in the stable N45 stream'
);
$assertTrue(
    ($manifest_migration_ids[array_key_last($manifest_migration_ids)] ?? '') === 'n45-0018-retention-controls',
    'Recoverable deletion is not the final reserved stable N45 migration'
);
$repair_migration = $manifest['migrations']['n45-0015-documentation-evidence-reference-index'] ?? [];
$assertTrue(
    array_key_exists('legacy_version', $repair_migration) && $repair_migration['legacy_version'] === null,
    'The documentation evidence-index repair must not claim a legacy marker'
);
$assertTrue(
    ($repair_migration['fingerprint']['indexes']['documentation_evidence_locker']['documentation_evidence_reference'] ?? null)
        === ($post_integration_reservations['n45-0015-documentation-evidence-reference-index']['altered_indexes']['documentation_evidence_locker']['documentation_evidence_reference'] ?? null),
    'The documentation evidence-index repair does not match its exact final reservation'
);

$manifest_migration_files = array_map(
    static fn (array $migration): string => (string) ($migration['file'] ?? ''),
    array_values($manifest['migrations'] ?? [])
);
$disk_migration_files = glob($root . '/n45/migrations/*.php') ?: [];
$disk_migration_files = array_map(static fn (string $file): string => 'n45/migrations/' . basename($file), $disk_migration_files);
sort($manifest_migration_files);
sort($disk_migration_files);
$assertTrue($manifest_migration_files === $disk_migration_files, 'Every N45 migration file must appear exactly once in the manifest');
foreach (glob($root . '/admin/database_updates/*.php') ?: [] as $upstream_migration_file) {
    $assertTrue(!str_contains((string) file_get_contents($upstream_migration_file), 'FROM_N45_DB_UPDATER'), 'An N45 migration leaked into the upstream namespace: ' . basename($upstream_migration_file));
}
foreach ($integration_reservations as $legacy_version => $reservation) {
    $migration_id = (string) ($reservation['id'] ?? '');
    $assertTrue(
        !is_file($root . '/admin/database_updates/' . $legacy_version . '.php'),
        "Reserved N45 migration $legacy_version leaked into the upstream namespace; move it to $migration_id"
    );
    if (isset($manifest['migrations'][$migration_id])) {
        $migration = $manifest['migrations'][$migration_id];
        $assertTrue(($migration['legacy_version'] ?? null) === $legacy_version, "$migration_id does not bridge legacy marker $legacy_version");
        $assertTrue(($migration['module'] ?? null) === ($reservation['module'] ?? null), "$migration_id has the wrong module reservation");
        $assertTrue(($migration['data_change'] ?? null) === ($reservation['data_change'] ?? null), "$migration_id has the wrong data-impact reservation");
        $assertTrue(($migration['rollback'] ?? null) === ($reservation['rollback'] ?? null), "$migration_id has the wrong rollback reservation");
    }
}
foreach (($manifest['modules'] ?? []) as $module => $definition) {
    $assertTrue(is_array($definition), "Module $module has an invalid manifest definition");
    $assertTrue(isset($definition['toggleable']) && is_bool($definition['toggleable']), "Module $module does not state whether it is toggleable");
    $assertTrue(trim((string) ($definition['reason'] ?? '')) !== '', "Module $module does not explain its flag policy");
    foreach (($definition['runtime_files'] ?? []) as $runtime_file) {
        $assertTrue(is_file($root . '/' . $runtime_file), "Module $module runtime file is missing: $runtime_file");
    }
    foreach (($definition['migrations'] ?? []) as $version) {
        $assertTrue(isset($manifest['migrations'][$version]), "Module $module references unknown migration $version");
        $assertTrue(($manifest['migrations'][$version]['module'] ?? '') === $module, "Migration $version is assigned to two different modules");
    }
}

foreach (($manifest['migrations'] ?? []) as $version => $migration) {
    $module = (string) ($migration['module'] ?? '');
    $assertTrue(isset($manifest['modules'][$module]), "Migration $version references unknown module $module");
    $assertTrue(in_array($version, $manifest['modules'][$module]['migrations'] ?? [], true), "Migration $version is not declared by module $module");
    $assertTrue(is_file($root . '/' . ($migration['file'] ?? '')), "Migration $version file is missing");
    $assertTrue(isset($migration['data_change']) && is_bool($migration['data_change']), "Migration $version does not classify its data impact");
    $assertTrue(trim((string) ($migration['summary'] ?? '')) !== '', "Migration $version has no manifest summary");
    $assertTrue(trim((string) ($migration['rollback'] ?? '')) !== '', "Migration $version has no rollback instruction");
    if (isset($expected_legacy_migrations[$version])) {
        $assertTrue(($migration['legacy_version'] ?? null) === $expected_legacy_migrations[$version], "Migration $version has the wrong legacy bridge marker");
    } else {
        $legacy_version = $migration['legacy_version'] ?? null;
        $assertTrue($legacy_version === null || preg_match('/^\d+(\.\d+)+$/', (string) $legacy_version) === 1, "Migration $version has invalid optional legacy metadata");
    }
    $assertTrue(!empty($migration['fingerprint']) && is_array($migration['fingerprint']), "Migration $version has no bridge fingerprint");
    $migration_source = $read((string) ($migration['file'] ?? ''));
    $assertContains("defined('FROM_N45_DB_UPDATER')", $migration_source, "Migration $version bypasses the N45 runner guard");
}
$migration_documentation = $read('docs/n45/migrations.md');
foreach ($manifest_migration_ids as $version) {
    $assertContains($version, $migration_documentation, "Migration $version is missing from operator rollback documentation");
}

$assertTrue(
    ($manifest['modules']['endpoint']['runtime_files'] ?? []) === [
        'functions/endpoint.php',
        'functions/device_source.php',
    ],
    'The endpoint boundary must load the canonical service before source adapters'
);
foreach (array_keys($manifest['modules'] ?? []) as $module) {
    try {
        n45RequireModule($module);
    } catch (Throwable $e) {
        $failures[] = "Module $module could not load through the boundary: " . $e->getMessage();
    }
}
$loaded_modules = n45LoadedModules();
foreach (array_keys($manifest['modules'] ?? []) as $module) {
    $assertTrue(in_array($module, $loaded_modules, true), "Module $module was not recorded by the boundary loader");
}
try {
    n45RequireModule('not-a-module');
    $failures[] = 'The module boundary accepted an undeclared module';
} catch (InvalidArgumentException $e) {
    // Expected: undeclared modules fail closed.
}

$functions = $read('functions.php');
$assertOrdered($functions, [
    "require_once __DIR__ . '/functions/security.php';",
    "require_once __DIR__ . '/n45/bootstrap.php';",
    "n45RequireModule('schema');",
    "n45RequireModule('entra');",
    "n45RequireModule('external_identity');",
    "n45RequireModule('endpoint');",
    "n45RequireModule('level');",
    "n45RequireModule('automation');",
    "n45RequireModule('mail_templates');",
    "n45RequireModule('documentation');",
    "n45RequireModule('runbooks');",
    "n45RequireModule('portal_requests');",
    "n45RequireModule('agreements');",
    "n45RequireModule('retention');",
    "require_once __DIR__ . '/functions/app.php';",
], 'Fork runtime modules are not loaded through the stable boundary in dependency order');
$assertNotContains("require_once __DIR__ . '/functions/endpoint.php';", $functions, 'Endpoint runtime bypasses the stable module boundary');
$assertNotContains("require_once __DIR__ . '/functions/device_source.php';", $functions, 'Device-source runtime bypasses the stable module boundary');

// Migration status is read-only; only setup and explicit maintenance paths mutate the ledger.
$schema_service = $read('functions/n45_schema.php');
$baseline_schema = $read('db.sql');
$seed_data = $read('setup/seed_data.php');
$update_page = $read('admin/update.php');
$update_handler = $read('admin/post/update.php');
$update_cli = $read('scripts/update_cli.php');
$assertContains('function n45MigrationStatus($mysqli): array', $schema_service, 'Read-only N45 pending detection is missing');
$status_contract = $section($schema_service, 'function n45MigrationStatus($mysqli): array', 'function n45WithMigrationLock(', 'N45 status function');
$assertTrue(!str_contains($status_contract, 'n45EnsureMigrationLedger(') && !str_contains($status_contract, 'n45RecordMigration('), 'N45 pending detection performs maintenance writes');
$assertContains("hash_file('sha256'", $schema_service, 'N45 migrations are not checksum verified');
$assertContains("SELECT GET_LOCK('\$lock_sql', 0)", $schema_service, 'N45 migrations do not serialize on the database update lock');
$assertContains('function n45ValidateMigrationFingerprint(', $schema_service, 'Legacy marker bridge does not validate schema/data fingerprints');
$assertContains('function n45MigrationRunnerFingerprintNames(', $schema_service, 'Normal migrations do not define their exact post-migration fingerprint choices');
$assertContains('$fingerprint_failures = n45ValidateMigrationRunnerFingerprint($mysqli, $id, $definition);', $schema_service, 'The migration runner does not validate exact final or immediate post-migration state');
$assertContains('function n45MigrationBridgeFingerprintName(', $schema_service, 'Legacy bridge does not define compatibility fingerprint selection');
$assertContains('n45MigrationBridgeFingerprintName($definition, $legacy_marker)', $schema_service, 'Legacy bridge does not select an exact compatibility fingerprint');
$assertContains('Required post-integration migration', $schema_service, 'Final assembly does not require the documentation index repair');
$assertContains("'Legacy bridge refused: '", $schema_service, 'Legacy marker bridge is not fail closed');
$assertContains("WHERE company_id = 1 AND config_current_database_version = '\$legacy_marker_sql'", $schema_service, 'Legacy marker reset is not compare-and-set');
$assertContains('`n45_schema_migrations`', $baseline_schema, 'Fresh-install schema omits the N45 checksum ledger');
$assertContains('n45SeedFreshInstallMigrations($mysqli)', $seed_data, 'Fresh installs do not verify and seed the N45 ledger');
$assertContains('n45MigrationStatus($mysqli)', $update_page, 'Admin update UI does not report N45 pending state');
$assertContains('bridge_n45_migrations', $update_page, 'Admin update UI omits the explicit legacy marker bridge');
$assertContains('n45RunMigrations($mysqli)', $update_handler, 'Admin database update does not run the N45 migration stream');
$assertContains('n45PrepareMigrationNamespace($mysqli)', $update_handler, 'Admin update does not establish the N45 namespace before upstream advances');
$assertContains('n45BridgeLegacyMigrations($mysqli)', $update_handler, 'Admin maintenance handler omits the legacy marker bridge');
$assertContains("'bridge_n45_migrations'", $update_cli, 'CLI does not expose the explicit legacy marker bridge');
$assertContains('n45RunMigrations($mysqli)', $update_cli, 'CLI database update omits the N45 migration stream');
$assertContains('n45PrepareMigrationNamespace($mysqli)', $update_cli, 'CLI update does not establish the N45 namespace before upstream advances');
$assertTrue(!str_contains($functions, 'n45RunMigrations(') && !str_contains($functions, 'n45BridgeLegacyMigrations('), 'Normal application bootstrap mutates N45 migration state');

$synthetic_runner_ledger = [];
foreach (($manifest['migrations'] ?? []) as $migration_id => $migration) {
    if (is_string($migration['legacy_version'] ?? null)
        && version_compare($migration['legacy_version'], '2.6.8', '<=')) {
        $synthetic_runner_ledger[$migration_id] = ['migration_applied_by' => 'runner'];
    }
}
$assertTrue(n45LegacyBridgeRequired('2.6.8', $manifest['migrations'], []) === true, 'An unbridged legacy N45 marker was not detected');
$assertTrue(n45LegacyBridgeRequired('2.7.8', $manifest['migrations'], []) === true, 'The documentation legacy marker was not detected');
$assertTrue(n45LegacyBridgeRequired('2.6.8', $manifest['migrations'], $synthetic_runner_ledger) === false, 'A future upstream marker with an established N45 ledger was misclassified as legacy');
$foundation_ledger = ['n45-0000-namespace-foundation' => ['migration_applied_by' => 'runner']];
$assertTrue(n45LegacyBridgeRequired('2.6.8', $manifest['migrations'], $foundation_ledger) === false, 'The namespace foundation did not prevent a future upstream marker collision');
$partial_runner_ledger = ['n45-0001-entra-agent-sso' => ['migration_applied_by' => 'runner']];
$assertTrue(n45LegacyBridgeRequired('2.6.9', $manifest['migrations'], $partial_runner_ledger) === false, 'A partial normal ledger was misclassified when upstream reused a legacy fork version');
$synthetic_bridge_ledger = $synthetic_runner_ledger;
$synthetic_bridge_ledger['n45-0001-entra-agent-sso']['migration_applied_by'] = 'legacy-bridge';
$assertTrue(n45LegacyBridgeRequired('2.6.8', $manifest['migrations'], $synthetic_bridge_ledger) === true, 'An interrupted legacy marker reset cannot be resumed');
$synthetic_bridge_ledger['n45-0001-entra-agent-sso']['migration_applied_by'] = 'bridge-complete';
$assertTrue(n45LegacyBridgeRequired('2.6.8', $manifest['migrations'], $synthetic_bridge_ledger) === false, 'A completed bridge was mistaken for a future upstream version collision');
$reserved_definitions = $manifest['migrations'];
foreach ($integration_reservations as $legacy_version => $reservation) {
    $reserved_definitions[$reservation['id']] = ['legacy_version' => $legacy_version];
}
$assertTrue(n45LegacyBridgeRequired('2.7.8', $reserved_definitions, []) === true, 'The documentation migration reservation lost its legacy bridge marker');
$assertTrue(n45LegacyBridgeRequired('2.8.1', $reserved_definitions, []) === true, 'The final agreement migration reservation is outside the legacy bridge range');

// Only optional ingress/processing can be disabled, and environment values win.
$old_flags_present = array_key_exists('n45_feature_flags', $GLOBALS);
$old_flags = $GLOBALS['n45_feature_flags'] ?? null;
$old_level_environment = getenv('N45_FEATURE_LEVEL');
$old_automation_environment = getenv('N45_FEATURE_AUTOMATION');
putenv('N45_FEATURE_LEVEL');
putenv('N45_FEATURE_AUTOMATION');
$GLOBALS['n45_feature_flags'] = [];
$assertTrue(n45FeatureEnabled('level'), 'Level should default to enabled');
$assertTrue(n45FeatureEnabled('automation'), 'Automation should default to enabled');
$assertTrue(!n45FeatureEnabled('unknown'), 'Unknown feature flags must fail closed');
$GLOBALS['n45_feature_flags'] = ['level' => false, 'automation' => 'off'];
$assertTrue(!n45FeatureEnabled('level'), 'Configured Level kill switch was ignored');
$assertTrue(!n45FeatureEnabled('automation'), 'Configured automation kill switch was ignored');
putenv('N45_FEATURE_LEVEL=1');
$assertTrue(n45FeatureEnabled('level'), 'Deployment environment did not override the Level config flag');
$GLOBALS['n45_feature_flags'] = ['level' => true];
putenv('N45_FEATURE_LEVEL=invalid');
$assertTrue(!n45FeatureEnabled('level'), 'An invalid environment flag must fail closed even when configuration enables the feature');
putenv('N45_FEATURE_LEVEL');
$GLOBALS['n45_feature_flags'] = ['level' => 'invalid'];
$assertTrue(!n45FeatureEnabled('level'), 'An invalid configured flag must fail closed instead of using the enabled default');

if ($old_level_environment === false) {
    putenv('N45_FEATURE_LEVEL');
} else {
    putenv('N45_FEATURE_LEVEL=' . $old_level_environment);
}
if ($old_automation_environment === false) {
    putenv('N45_FEATURE_AUTOMATION');
} else {
    putenv('N45_FEATURE_AUTOMATION=' . $old_automation_environment);
}
if ($old_flags_present) {
    $GLOBALS['n45_feature_flags'] = $old_flags;
} else {
    unset($GLOBALS['n45_feature_flags']);
}

// Login smoke: host separation, Entra service, and recovery behavior stay wired.
$login = $read('login.php');
$login_surface = $read('functions/login_surface.php');
$agent_login = $read('agent/login_microsoft.php');
$assertContains("n45LoginSurfaceForHost(\$_SERVER['HTTP_HOST'] ?? '')", $login, 'Login does not select a hostname-specific surface');
$assertContains('n45LocalLoginAllowed($login_surface)', $login, 'Login does not enforce the surface local-login policy');
$assertContains("return 'customer';", $login_surface, 'Customer portal hostname mapping is missing');
$assertContains("return 'technician';", $login_surface, 'Technician hostname mapping is missing');
$assertContains('entraGuidIsValid($azure_tenant_id)', $agent_login, 'Technician Entra login no longer validates the tenant');

// Portal smoke: capabilities are centralized, tenant-scoped, and fail closed.
$portal_functions = $read('client/functions.php');
$portal_header = $read('client/includes/header.php');
$schema = $read('db.sql');
$assertContains('function contactCan($capability)', $portal_functions, 'Portal capability service is missing');
$assertContains("default:             // unknown capability -> deny (fail closed)", $portal_functions, 'Unknown portal capabilities do not fail closed');
$assertContains("contactCan('contacts')", $portal_header, 'Portal navigation bypasses the contact capability');
$assertContains('`contact_portal_ticket_scope`', $schema, 'Portal ticket scope is missing from the baseline schema');
$assertContains('`contact_portal_asset_scope`', $schema, 'Portal asset scope is missing from the baseline schema');

// Ticket smoke: UI, portal, and API lifecycle transitions share the runbook gate.
$ticket_post = $read('agent/post/ticket.php');
$client_post = $read('client/post.php');
$api_ticket_resolve = $read('api/v1/tickets/resolve.php');
$assertContains('runbookTicketCanResolve($ticket_id)', $ticket_post, 'Agent ticket transitions bypass the shared lifecycle gate');
$assertContains('runbookTicketCanResolve($ticket_id)', $client_post, 'Portal ticket transitions bypass the shared lifecycle gate');
$assertContains('runbookTicketCanResolve($ticket_id)', $api_ticket_resolve, 'API ticket resolution bypasses the shared lifecycle gate');
$assertTrue(is_file($root . '/tests/ticket_state_input_contract_test.php'), 'Ticket input-state regression contract is missing');

// Template smoke: branded mail and immutable runbook publication remain connected.
$mail_templates = $read('functions/mail_templates.php');
$app = $read('functions/app.php');
$starter_content = $read('admin/post/starter_content_model.php');
$template_post = $read('admin/post/ticket_template.php');
$assertContains('function renderN45Email(string $template_key, array $context = [])', $mail_templates, 'Branded mail renderer is missing');
$assertContains('return instantiateRunbookForTicket($ticket_id, $ticket_template_id, [', $app, 'Ticket templates bypass immutable runbook instantiation');
$assertContains('publishRunbookVersion(', $starter_content, 'Starter runbooks are not published as immutable versions');
$assertContains('publishRunbookVersion($ticket_template_id, $session_user_id, $notes)', $template_post, 'Template publishing handler is disconnected');

// API smoke: bearer credentials, shared RBAC, method gates, and disabled responses.
$api_key = $read('api/v1/validate_api_key.php');
$api_rbac = $read('api/v1/enforce_api_rbac.php');
$automation_event_api = $read('api/v1/integrations/automation/event.php');
$automation_resolve_api = $read('api/v1/integrations/automation/resolve.php');
$automation_service = $read('functions/automation.php');
$automation_events = $read('functions/automation_events.php');
$assertContains("preg_match('/^Bearer\\s+([^\\s]+)$/i'", $api_key, 'API bearer authentication is missing');
$assertContains("require __DIR__ . '/enforce_api_rbac.php';", $api_key, 'API authentication no longer enters shared RBAC');
$assertContains("'automation'    => 'module_support'", $api_rbac, 'Automation API is not mapped to support permissions');
$assertContains("require_once '../../require_post_method.php';", $automation_event_api, 'Automation event endpoint accepts unrestricted methods');
$assertContains("n45FeatureEnabled('automation')", $automation_event_api, 'Automation event endpoint ignores the deployment kill switch');
$assertContains("n45FeatureEnabled('automation')", $automation_resolve_api, 'Automation identity endpoint ignores the deployment kill switch');
$assertContains("function automationResolveIdentity(array \$input): array", $automation_service, 'Automation identity service is missing');
$assertContains("n45FeatureEnabled('automation')", $automation_service, 'Automation identity service ignores the deployment kill switch');
$assertTrue(substr_count($automation_events, "n45FeatureEnabled('automation')") >= 5, 'Automation queue, processing, and replay services do not share the deployment kill switch');

// Integration smoke: HMAC, redaction, flags, and processors remain wired.
$level = $read('functions/level.php');
$level_webhook = $read('api/v1/integrations/level/webhook.php');
$level_sync = $read('cron/level_sync.php');
$level_processor = $read('cron/level_webhook_processor.php');
$automation_processor = $read('cron/automation_event_processor.php');
$assertContains("hash_hmac('sha256', \$raw_body, \$secret)", $level, 'Level webhook signature is not HMAC based');
$assertContains('automationEventRedact($event)', $level_webhook, 'Level webhook payload is stored without shared redaction');
$assertContains("n45FeatureEnabled('level')", $level_webhook, 'Level webhook ignores the deployment kill switch');
$assertContains("n45FeatureEnabled('level')", $level_sync, 'Level reconciliation ignores the deployment kill switch');
$assertContains("n45FeatureEnabled('level')", $level_processor, 'Level webhook processor ignores the deployment kill switch');
$assertContains("n45FeatureEnabled('automation')", $automation_processor, 'Automation processor ignores the deployment kill switch');
$mirror_start = strpos($automation_events, 'function automationMirrorProcessedEvent(');
$mirror_service = $mirror_start === false ? '' : substr($automation_events, $mirror_start);
$assertOrdered($mirror_service, [
    "if (!n45FeatureEnabled('automation'))",
    "'reason' => 'automation_disabled'",
    '$event = automationEventEnvelope($input)',
    'mysqli_begin_transaction($mysqli)',
], 'Level-to-Operations mirroring is not centrally skipped before database work when Automation is disabled');
$compose = $read('deploy/psa/compose.yml');
$environment_example = $read('deploy/psa/.env.example');
$assertTrue(substr_count($compose, 'N45_FEATURE_LEVEL: ${N45_FEATURE_LEVEL:-1}') === 2, 'Level flag is not passed to both web and cron containers');
$assertTrue(substr_count($compose, 'N45_FEATURE_AUTOMATION: ${N45_FEATURE_AUTOMATION:-1}') === 2, 'Automation flag is not passed to both web and cron containers');
$assertContains('N45_FEATURE_LEVEL=1', $environment_example, 'Deployment environment example omits the Level flag');
$assertContains('N45_FEATURE_AUTOMATION=1', $environment_example, 'Deployment environment example omits the automation flag');

// Deletion smoke: legacy destructive routes must defer to recoverable retention.
$single_delete = $section($ticket_post, "if (isset(\$_GET['delete_ticket']))", "if (isset(\$_POST['bulk_delete_tickets']))", 'single ticket deletion');
$bulk_delete = $section($ticket_post, "if (isset(\$_POST['bulk_delete_tickets']))", "if (isset(\$_POST['bulk_assign_ticket']))", 'bulk ticket deletion');
$assertOrdered($single_delete, [
    'enforceAdminPermission()',
    'redirect("/admin/retention.php?record_type=ticket&record_id=$ticket_id")',
], 'Single ticket deletion does not defer to the recoverable retention workflow');
$assertTrue(!str_contains($single_delete, 'DELETE FROM tickets') && !str_contains($single_delete, 'removeDirectory('),
    'Single ticket deletion still exposes a permanent-delete side path');
$assertOrdered($bulk_delete, [
    'enforceAdminPermission()',
    'Bulk ticket deletion is disabled',
    "redirect('/admin/retention.php')",
], 'Bulk ticket deletion does not fail closed into the retention workflow');
$assertTrue(!str_contains($bulk_delete, 'DELETE FROM tickets') && !str_contains($bulk_delete, 'removeDirectory('),
    'Bulk ticket deletion still exposes a permanent-delete side path');

$client_handler = $read('agent/post/client.php');
$client_delete = $section($client_handler, "if (isset(\$_GET['delete_client']))", "if (isExportRequest('export_clients'))", 'client deletion');
$assertOrdered($client_delete, [
    'enforceAdminPermission()',
    'Permanent client deletion is disabled by retention policy',
    'redirect("client_overview.php?client_id=$client_id")',
], 'Client deletion does not fail closed under retention policy');
$assertTrue(!str_contains($client_delete, 'DELETE FROM clients') && !str_contains($client_delete, 'removeDirectory('),
    'Client deletion still exposes a permanent-delete side path');

foreach (glob($root . '/api/v1/tickets/*.php') ?: [] as $api_ticket_file) {
    $assertTrue(!str_contains((string) file_get_contents($api_ticket_file), 'DELETE FROM tickets'), 'Ticket deletion was added to the API without the shared Operations cleanup contract: ' . basename($api_ticket_file));
}

// Repeatable parity review must remain repository native and read-only.
$review_script = $read('scripts/n45-upstream-review.sh');
$review_workflow = $read('.github/workflows/upstream-parity.yml');
$security_sensitive_paths = $read('n45/security-sensitive-paths.regex');
$assertContains('git merge-base', $review_script, 'Upstream review does not calculate a merge base');
$assertContains('comm -12', $review_script, 'Upstream review does not identify path overlap');
$assertContains('sensitive-overlap-paths', $review_script, 'Upstream review does not isolate security-sensitive overlap');
$assertContains('Security-sensitive path rules are invalid', $review_script, 'Malformed security-sensitive path rules can silently disable overlap detection');
$assertContains('N45_REVIEWED_BASE_SHA', $review_script, 'Upstream review does not bind approval to the exact upstream SHA');
$assertContains('N45_REVIEWED_HEAD_SHA', $review_script, 'Upstream review does not bind approval to the exact fork SHA');
$assertContains('sha_review_gate="Fail"', $review_script, 'Security-sensitive overlap is not a blocking parity gate');
$assertContains('php -l "$lint_file"', $review_script, 'Parity tooling does not lint changed PHP when available');
$assertContains('node --check "$lint_file"', $review_script, 'Parity tooling does not check changed JavaScript when available');
$assertContains('bash -n "$lint_file"', $review_script, 'Parity tooling does not check changed shell scripts');
$assertContains('git diff --check', $review_script, 'Upstream review does not validate the fork diff');
$assertContains('grep -Fvxf', $review_script, 'Upstream review does not reject whitespace errors outside the exact allowlist');
$assertTrue(is_file($root . '/n45/upstream-diff-check.allowlist'), 'The exact historical whitespace allowlist is missing');
$assertTrue(is_file($root . '/n45/security-sensitive-paths.regex'), 'Security-sensitive path rules are missing');
$assertContains('^(guest|cron)/', $security_sensitive_paths, 'Security-sensitive parity review omits unauthenticated guest or background-writer paths');
$assertContains('^libs/composer\\.(json|lock)$', $security_sensitive_paths, 'Security-sensitive parity review omits the application dependency manifest and lockfile');
$assertContains('https://github.com/itflow-org/itflow.git', $review_workflow, 'Parity workflow does not fetch authoritative ITFlow upstream');
$assertContains('for test_file in tests/*_test.php', $review_workflow, 'Parity workflow does not run the full regression suite');
$assertContains("vars.N45_MONITORED_REF || 'codex/itflow-all-goals'", $review_workflow,
    'Scheduled/manual parity does not default to the final all-goals release branch');
$assertContains("github.event_name == 'schedule' || github.event_name == 'workflow_dispatch'", $review_workflow,
    'Parity workflow does not distinguish release monitoring from push/PR validation');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "N45 fork boundary and smoke contracts passed." . PHP_EOL;
