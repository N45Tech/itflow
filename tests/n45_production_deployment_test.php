<?php

$failures = [];
$read = static function (string $path) use (&$failures): string {
    $content = file_get_contents(__DIR__ . '/../' . $path);
    if ($content === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $content;
};
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$database_workflow = $read('.github/workflows/dbsql-lint.yml');
$php_workflow = $read('.github/workflows/php-lint.yml');
$parity_workflow = $read('.github/workflows/upstream-parity.yml');
$deployment_workflow = $read('.github/workflows/deploy-production.yml');
$wrapper = $read('deploy/psa/n45-psa-deploy-wrapper');
$installer = $read('deploy/psa/install-github-deployer.sh');
$deployment = $read('deploy/psa/deploy-production.sh');
$readme = $read('deploy/psa/README.md');

foreach ([$database_workflow, $php_workflow, $parity_workflow] as $workflow) {
    $assertContains('- main', $workflow, 'A required release workflow does not run on main');
}

$assertContains('workflow_run:', $deployment_workflow, 'Production deployment is not chained to release CI');
$assertContains('SQL Syntax Check for db.sql', $deployment_workflow, 'The database release gate does not trigger deployment');
$assertContains('N45 Upstream Parity', $deployment_workflow, 'Deployment does not require upstream parity for the exact SHA');
$assertContains('PHPLint', $deployment_workflow, 'Deployment does not require PHP regression success for the exact SHA');
$assertContains('head_sha="$TARGET_SHA"', $deployment_workflow, 'Release checks are not queried by exact commit SHA');
$assertContains("head_branch == 'main'", $deployment_workflow, 'Automatic deployment is not restricted to main');
$assertContains('cancel-in-progress: false', $deployment_workflow, 'A newer push can cancel an active production deployment');
$assertContains('name: production', $deployment_workflow, 'Production secrets are not scoped to the production environment');
$assertContains('INFRA01_SSH_KNOWN_HOSTS', $deployment_workflow, 'The workflow does not pin the infra01 SSH host key');
$assertTrue(!str_contains($deployment_workflow, 'ssh-keyscan'), 'The workflow trusts a live SSH key scan instead of a reviewed host key');
$assertContains('/usr/local/sbin/n45-psa-deploy', $deployment_workflow, 'The workflow bypasses the restricted host command');
$assertContains('[ "$GITHUB_EVENT_NAME" = push ] && [ "$GITHUB_REF" = refs/heads/main ]', $parity_workflow, 'Protected-main parity does not bind approval to the push event');
$assertContains('reviewed_base_sha="$(git rev-parse upstream/master)"', $parity_workflow, 'Protected-main parity does not bind review to the fetched upstream commit');
$assertContains('reviewed_head_sha="$GITHUB_SHA"', $parity_workflow, 'Protected-main parity does not bind review to the exact merged commit');
$assertContains('CONFIGURED_REVIEWED_HEAD_SHA', $parity_workflow, 'Non-main parity events lost their explicit reviewed-SHA gate');

$assertContains('^[0-9a-f]{40}$', $wrapper, 'The host wrapper does not require a full immutable SHA');
$assertContains('fetch --no-tags origin main', $wrapper, 'The host wrapper does not refresh origin/main');
$assertContains('REMOTE_SHA', $wrapper, 'The host wrapper does not compare the requested SHA with origin/main');
$assertContains('flock -n 9', $wrapper, 'The host wrapper does not serialize production deployments');
$assertContains('show "${TARGET_SHA}:deploy/psa/deploy-production.sh"', $wrapper, 'The wrapper does not execute the deployment script from the approved commit');
$assertContains('visudo -cf', $installer, 'The installer does not validate its sudoers policy');

$assertContains('mariadb-dump --single-transaction', $deployment, 'Production deployment does not create a transactional database snapshot');
$assertContains('grep -q \'^-- Dump completed on \'', $deployment, 'Database snapshot completeness is not verified');
$assertContains('RESTORE_DB=', $deployment, 'The production database snapshot is not restore-tested');
$assertContains('psa_app_data.tgz', $deployment, 'Application data is not backed up');
$assertContains('--user www-data web php scripts/update_cli.php --update_db', $deployment, 'Database updates do not run as the application file owner');
$assertTrue(substr_count($deployment, 'php scripts/update_cli.php --update_db') >= 2, 'Database update idempotence is not checked');
$assertContains('reconcile deploy/psa/reconcile_templates.php', $deployment, 'Template reconciliation is missing');
$assertContains('reconcile deploy/psa/reconcile_documentation_requirements.php', $deployment, 'Documentation reconciliation is missing');
$assertContains('reconcile deploy/psa/reconcile_ticket_operations.php', $deployment, 'Ticket-operation reconciliation is missing');
$assertContains('reconcile deploy/psa/reconcile_endpoint_records.php', $deployment, 'Endpoint reconciliation is missing');
$assertContains('set_env N45_FEATURE_LEVEL 0', $deployment, 'The web canary does not disable Level ingress');
$assertContains('set_env N45_FEATURE_AUTOMATION 0', $deployment, 'The web canary does not disable automation ingress');
$assertContains('Cron: job .* failed', $deployment, 'The controlled cron cycle does not detect failed jobs');
$assertContains('DB_MUTATED', $deployment, 'Failure handling does not distinguish pre- and post-migration states');
$assertContains('deployed-sha', $deployment, 'Successful releases do not leave an idempotency marker');

foreach ([
    'INFRA01_SSH_HOST',
    'INFRA01_SSH_USER',
    'INFRA01_SSH_PRIVATE_KEY',
    'INFRA01_SSH_KNOWN_HOSTS',
] as $secret_name) {
    $assertContains($secret_name, $readme, "Deployment setup does not document $secret_name");
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "N45 production deployment automation tests passed." . PHP_EOL;
