<?php

define('FROM_STARTER_CONTENT', true);

require_once __DIR__ . '/../admin/post/starter_content_model.php';
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

$templates_by_key = [];
foreach (starterContentTicketTemplates() as $template) {
    if (!empty($template['runbook_key'])) {
        $templates_by_key[$template['runbook_key']] = $template;
    }
}

$contracts = [
    'access-change' => [
        'name' => 'Access Change',
        'type' => 'standard',
        'prefix' => 'ACC',
        'last' => 70,
        'count' => 7,
    ],
    'scheduled-work' => [
        'name' => 'Scheduled Work',
        'type' => 'standard',
        'prefix' => 'SCH',
        'last' => 80,
        'count' => 8,
    ],
    'managed-care-onboarding' => [
        'name' => 'Managed Care Onboarding',
        'type' => 'onboarding',
        'prefix' => 'ONB',
        'last' => 430,
        'count' => 43,
    ],
    'client-offboarding' => [
        'name' => 'Client Offboarding',
        'type' => 'offboarding',
        'prefix' => 'OFF',
        'last' => 250,
        'count' => 25,
    ],
];

foreach ($contracts as $runbook_key => $contract) {
    $assertTrue(isset($templates_by_key[$runbook_key]), "Starter content is missing $runbook_key");
    if (!isset($templates_by_key[$runbook_key])) {
        continue;
    }

    $template = $templates_by_key[$runbook_key];
    $expected_source_keys = [];
    for ($number = 10; $number <= $contract['last']; $number += 10) {
        $expected_source_keys[] = $contract['prefix'] . '-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
    $expected_keys = array_map('strtolower', $expected_source_keys);
    $source_keys = array_column($template['tasks'], 'key');

    $assertSame($contract['name'], $template['name'] ?? '', "$runbook_key has the wrong canonical name");
    $assertSame($contract['type'], $template['runbook_type'] ?? '', "$runbook_key has the wrong runbook type");
    $assertSame(true, $template['publish_runbook'] ?? false, "$runbook_key is not marked for publication");
    $assertSame($contract['count'], count($template['tasks'] ?? []), "$runbook_key has the wrong task count");
    $assertSame($expected_source_keys, $source_keys, "$runbook_key stable source-key sequence changed");
    $assertTrue(trim((string) ($template['subject'] ?? '')) !== '', "$runbook_key has no ticket subject");
    $assertTrue(trim((string) ($template['details'] ?? '')) !== '', "$runbook_key has no execution guidance");

    $tasks = [];
    foreach ($template['tasks'] as $index => $source_task) {
        $task = starterRunbookTaskDefinition($source_task, $index + 1);
        $source_key = $expected_source_keys[$index];
        $key = $expected_keys[$index];

        $assertSame($key, $task['key'], "$runbook_key task $source_key did not normalize to its stable key");
        $assertSame($index + 1, $task['order'], "$runbook_key task $source_key has an unstable order");
        $assertTrue(str_starts_with($task['name'], $source_key . ' '), "$runbook_key task $source_key name lost its key prefix");
        $assertTrue(trim($task['instructions']) !== '', "$runbook_key task $source_key has no completion instructions");
        $assertTrue($task['estimate'] > 0, "$runbook_key task $source_key has no estimate");
        $assertTrue($task['due_offset_minutes'] > 0, "$runbook_key task $source_key has no due offset");
        $assertTrue($task['owner_type'] !== 'unassigned', "$runbook_key task $source_key has no owner rule");
        $assertTrue($task['evidence_type'] !== 'none', "$runbook_key task $source_key has no evidence rule");
        $assertTrue(trim($task['evidence_prompt']) !== '', "$runbook_key task $source_key has no evidence prompt");
        $assertSame(
            count($task['depends_on']),
            count(array_unique($task['depends_on'])),
            "$runbook_key task $source_key repeats a dependency"
        );

        foreach ($task['depends_on'] as $dependency_key) {
            $dependency_position = array_search($dependency_key, $expected_keys, true);
            $assertTrue($dependency_position !== false, "$runbook_key task $source_key has missing dependency $dependency_key");
            $assertTrue(
                $dependency_position !== false && $dependency_position < $index,
                "$runbook_key task $source_key does not depend only on an earlier stable task"
            );
        }
        $tasks[] = $task;
    }

    $assertSame($expected_keys, array_column($tasks, 'key'), "$runbook_key normalized stable-key sequence changed");

    $definition = [
        'key' => $runbook_key,
        'type' => $template['runbook_type'],
        'name' => $template['name'],
        'description' => $template['description'],
        'subject' => $template['subject'],
        'details' => $template['details'],
        'tasks' => $tasks,
    ];
    $assertSame([], runbookValidateDefinition($definition), "$runbook_key metadata or dependency graph is invalid");
    $assertTrue(
        preg_match('/^[a-f0-9]{64}$/', runbookDefinitionHash($definition)) === 1,
        "$runbook_key does not produce a publishable definition hash"
    );

    $condition_types = array_values(array_unique(array_column($tasks, 'condition_type')));
    $evidence_types = array_values(array_unique(array_column($tasks, 'evidence_type')));
    $assertTrue(count($condition_types) > 1, "$runbook_key lost its conditional workflow metadata");
    $assertTrue(count($evidence_types) > 1, "$runbook_key lost its evidence-type coverage");
    $assertTrue(
        count(array_filter($tasks, static fn ($task) => $task['initial_state'] === 'Waiting')) > 0,
        "$runbook_key no longer contains a waiting gate"
    );
    $assertTrue(
        count(array_filter($tasks, static fn ($task) => $task['approval_scope'] !== '')) > 0,
        "$runbook_key no longer contains an approval gate"
    );

    if ($runbook_key === 'access-change') {
        $tasks_by_key = array_column($tasks, null, 'key');
        $validation = $tasks_by_key['acc-060'] ?? [];
        $assertTrue(
            str_contains(strtolower((string) ($validation['instructions'] ?? '')), 'execute the approved rollback')
                && str_contains(strtolower((string) ($validation['instructions'] ?? '')), 'revalidate the restored baseline')
                && str_contains(strtolower((string) ($validation['evidence_prompt'] ?? '')), 'rollback'),
            'access-change validation no longer executes, revalidates and records the approved rollback path'
        );
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Canonical portal, onboarding and offboarding starter runbooks passed.\n";
