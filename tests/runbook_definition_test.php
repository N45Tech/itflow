<?php

require_once __DIR__ . '/../functions/runbooks.php';

$failures = [];

$assertSame = function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};

$assertTrue = function ($actual, string $message) use (&$failures): void {
    if ($actual !== true) {
        $failures[] = $message;
    }
};

$assertContains = function ($needle, array $haystack, string $message) use (&$failures): void {
    if (!in_array($needle, $haystack, true)) {
        $failures[] = $message . ' (missing ' . var_export($needle, true) . ')';
    }
};

$assertSame('gdap-cipp-validation', runbookNormalizeKey('  GDAP / CIPP Validation  '), 'Runbook key normalization changed');
$assertSame('fallback-task', runbookNormalizeKey(' --- ', 'fallback-task'), 'Empty keys did not use their fallback');
$assertSame('a-b-c', runbookNormalizeKey('A___B...C'), 'Repeated separators were not collapsed');
$assertSame(100, strlen(runbookNormalizeKey(str_repeat('A', 120))), 'Runbook keys were not capped at 100 characters');

$approval_token = 'one-time-bearer-value';
$approval_token_hash = runbookApprovalTokenHash($approval_token);
$assertTrue(str_starts_with($approval_token_hash, 'sha256:'), 'Approval bearer tokens are not stored as versioned hashes');
$assertTrue(runbookApprovalTokenMatches($approval_token_hash, $approval_token), 'A stored approval token hash did not match its bearer value');
$assertSame(false, runbookApprovalTokenMatches($approval_token_hash, 'different-value'), 'An unrelated approval bearer value matched');
$assertTrue(strtotime(runbookApprovalTokenExpiry(1)) > time(), 'Approval bearer links do not receive a future expiry');

$definition = [
    'key' => 'client-onboarding',
    'type' => 'onboarding',
    'name' => 'Client onboarding',
    'description' => 'Provision and validate the managed environment.',
    'subject' => 'Onboard {{client}}',
    'details' => 'Use the published workflow.',
    'tasks' => [
        [
            'source_id' => 101,
            'key' => 'validate-gdap',
            'name' => 'Validate GDAP and CIPP',
            'instructions' => 'Record the tenant relationship and validation result.',
            'order' => 10,
            'estimate' => 15,
            'condition_type' => 'always',
            'condition_value' => '',
            'owner_type' => 'ticket_assignee',
            'owner_user_id' => 0,
            'due_offset_minutes' => 60,
            'initial_state' => 'Ready',
            'approval_scope' => '',
            'approval_type' => '',
            'approval_user_id' => 0,
            'evidence_type' => 'note',
            'evidence_prompt' => 'Record the tenant and validation result.',
            'depends_on' => [],
        ],
        [
            'source_id' => 102,
            'key' => 'deploy-agents',
            'name' => 'Deploy management agents',
            'instructions' => 'Install and verify Level and SentinelOne.',
            'order' => 20,
            'estimate' => 45,
            'condition_type' => 'client_has_asset_type',
            'condition_value' => 'Workstation',
            'owner_type' => 'specific_user',
            'owner_user_id' => 42,
            'due_offset_minutes' => 180,
            'initial_state' => 'Waiting',
            'approval_scope' => 'internal',
            'approval_type' => 'specific',
            'approval_user_id' => 7,
            'evidence_type' => 'any',
            'evidence_prompt' => 'Attach or link the coverage report.',
            'depends_on' => ['validate-gdap'],
        ],
    ],
];

$assertSame([], runbookValidateDefinition($definition), 'A valid runbook definition was rejected');

$equivalent = $definition;
$equivalent['tasks'] = array_reverse($equivalent['tasks']);
$equivalent['tasks'][0]['source_id'] = 9999;
$equivalent['tasks'][0]['depends_on'] = ['validate-gdap', 'validate-gdap'];
$canonical = runbookCanonicalDefinition($equivalent);

$assertSame(['validate-gdap', 'deploy-agents'], array_column($canonical['tasks'], 'key'), 'Canonical task ordering is not stable');
$assertSame(['validate-gdap'], $canonical['tasks'][1]['depends_on'], 'Canonical dependencies were not sorted and deduplicated');
$assertSame(false, array_key_exists('source_id', $canonical['tasks'][0]), 'Transport database IDs entered the immutable definition');
$assertSame(
    runbookDefinitionHash($definition),
    runbookDefinitionHash($equivalent),
    'Task input order, duplicate dependencies, or source IDs changed the definition hash'
);
$assertTrue(
    preg_match('/^[a-f0-9]{64}$/', runbookDefinitionHash($definition)) === 1,
    'Runbook definitions no longer produce a SHA-256 hash'
);

$changed = $definition;
$changed['tasks'][1]['instructions'] = 'Install agents without validation.';
$assertSame(
    false,
    hash_equals(runbookDefinitionHash($definition), runbookDefinitionHash($changed)),
    'A material instruction change reused the published definition hash'
);

$normalized_numbers = $definition;
$normalized_numbers['tasks'][0]['estimate'] = -15;
$normalized_numbers['tasks'][0]['owner_user_id'] = -42;
$normalized_numbers['tasks'][0]['due_offset_minutes'] = -60;
$normalized_numbers['tasks'][0]['approval_user_id'] = -7;
$normalized_task = runbookCanonicalDefinition($normalized_numbers)['tasks'][0];
$assertSame(0, $normalized_task['estimate'], 'Canonical task estimates accepted a negative value');
$assertSame(0, $normalized_task['owner_user_id'], 'Canonical owner IDs accepted a negative value');
$assertSame(0, $normalized_task['due_offset_minutes'], 'Canonical due offsets accepted a negative value');
$assertSame(0, $normalized_task['approval_user_id'], 'Canonical approver IDs accepted a negative value');

$missing_dependency = $definition;
$missing_dependency['tasks'][1]['depends_on'] = ['inventory-assets'];
$assertContains(
    'Task deploy-agents depends on missing task inventory-assets.',
    runbookValidateDefinition($missing_dependency),
    'A missing dependency was accepted'
);

$self_dependency = $definition;
$self_dependency['tasks'][0]['depends_on'] = ['validate-gdap'];
$assertContains(
    'Task validate-gdap cannot depend on itself.',
    runbookValidateDefinition($self_dependency),
    'A self-dependency was accepted'
);

$cycle = $definition;
$cycle['tasks'][0]['depends_on'] = ['deploy-agents'];
$cycle_errors = runbookValidateDefinition($cycle);
$assertTrue(
    count(array_filter($cycle_errors, static fn ($error) => str_starts_with($error, 'Dependency cycle detected at task '))) === 1,
    'A dependency cycle was not detected exactly once'
);

$duplicate = $definition;
$duplicate['tasks'][1]['key'] = 'validate-gdap';
$duplicate['tasks'][1]['depends_on'] = [];
$assertContains(
    'Duplicate task key: validate-gdap',
    runbookValidateDefinition($duplicate),
    'A duplicate task key was accepted'
);

$invalid_rules = $definition;
$invalid_rules['tasks'][0]['owner_type'] = 'specific_user';
$invalid_rules['tasks'][0]['owner_user_id'] = 0;
$invalid_rules['tasks'][0]['approval_scope'] = 'client';
$invalid_rules['tasks'][0]['approval_type'] = 'specific';
$invalid_rules['tasks'][0]['approval_user_id'] = 0;
$invalid_rules['tasks'][1]['condition_type'] = 'shell_command';
$invalid_rules['tasks'][1]['evidence_type'] = 'password';
$rule_errors = runbookValidateDefinition($invalid_rules);
$assertContains('Task validate-gdap requires a specific owner.', $rule_errors, 'A missing specific owner was accepted');
$assertContains('Task validate-gdap has an unsupported approval rule.', $rule_errors, 'An invalid client approval rule was accepted');
$assertContains('Task validate-gdap requires a specific internal approver.', $rule_errors, 'A missing specific approver was accepted');
$assertContains('Task deploy-agents has an unsupported condition.', $rule_errors, 'An unsupported condition was accepted');
$assertContains('Task deploy-agents has an unsupported evidence rule.', $rule_errors, 'An unsupported evidence rule was accepted');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Runbook definition tests passed.\n";
