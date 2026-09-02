<?php

define('FROM_STARTER_CONTENT', true);
date_default_timezone_set('UTC');

require_once __DIR__ . '/../admin/post/starter_content_model.php';
require_once __DIR__ . '/../functions/runbooks.php';

$failures = [];
$assertSame = static function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};
$assertTrue = static function ($actual, string $message) use (&$failures): void {
    if ($actual !== true) {
        $failures[] = $message;
    }
};
$errorCodes = static function (array $errors): array {
    return array_values(array_unique(array_column($errors, 'code')));
};

$buildFixture = static function (array $definition): array {
    $definition = runbookCanonicalDefinition($definition);
    $definition_hash = runbookDefinitionHash($definition);
    $tasks = [];
    $evidence_by_key = [];
    $approvals_by_key = [];
    $approval_events_by_projection = [];
    $state_events_by_key = [];
    $projection_key = 1000;

    foreach ($definition['tasks'] as $definition_task) {
        $task_key = $definition_task['key'];
        $initial_state = $definition_task['initial_state'];
        $tasks[] = [
            'runbook_version_task_key' => $task_key,
            'task_state' => 'Completed',
            'task_completed_at' => '2026-09-02 12:00:00',
        ];
        $events = [[
            'task_state_event_from_state' => null,
            'task_state_event_to_state' => $initial_state,
            'task_state_event_actor_type' => 'system',
        ]];
        if ($initial_state !== 'Ready') {
            $events[] = [
                'task_state_event_from_state' => $initial_state,
                'task_state_event_to_state' => 'Ready',
                'task_state_event_actor_type' => 'agent',
            ];
        }
        $events[] = [
            'task_state_event_from_state' => 'Ready',
            'task_state_event_to_state' => 'Completed',
            'task_state_event_actor_type' => 'agent',
        ];
        $state_events_by_key[$task_key] = $events;

        $evidence_type = $definition_task['evidence_type'];
        if ($evidence_type !== 'none') {
            $fixture_type = $evidence_type === 'any' ? 'note' : $evidence_type;
            $evidence_by_key[$task_key] = [[
                'task_evidence_type' => $fixture_type,
                'task_evidence_has_value' => in_array($fixture_type, ['note', 'url'], true) ? 1 : 0,
                'task_evidence_attachment_present' => $fixture_type === 'file' ? 1 : 0,
            ]];
        }

        $scope = $definition_task['approval_scope'];
        if ($scope === '') {
            continue;
        }
        $projection_key++;
        $type = $definition_task['approval_type'];
        $required_user_id = $type === 'specific'
            ? intval($definition_task['approval_user_id']) : 0;
        $decision_actor_type = $scope === 'internal' ? 'agent' : 'contact';
        $decision_actor_id = $scope === 'internal' ? 502 : 0;
        $approvals_by_key[$task_key] = [[
            'approval_projection_key' => $projection_key,
            'approval_scope' => $scope,
            'approval_type' => $type,
            'approval_route_user_key' => $required_user_id,
            'approval_status' => 'approved',
            'approval_created_by' => 501,
            'approval_has_decision_actor' => 1,
            'approval_decision_actor_type' => $decision_actor_type,
            'approval_decision_actor_id' => $decision_actor_id,
            'approval_decided_at' => '2026-09-02 11:59:00',
        ]];
        $approval_events_by_projection[$projection_key] = [[
            'runbook_version_task_key' => $task_key,
            'task_approval_event_action' => 'created',
            'task_approval_event_from_status' => null,
            'task_approval_event_to_status' => 'pending',
            'task_approval_event_from_scope' => null,
            'task_approval_event_to_scope' => $scope,
            'task_approval_event_from_type' => null,
            'task_approval_event_to_type' => $type,
            'task_approval_event_from_required_user_id' => 0,
            'task_approval_event_to_required_user_id' => $required_user_id,
            'task_approval_event_actor_type' => 'system',
        ], [
            'runbook_version_task_key' => $task_key,
            'task_approval_event_action' => 'approved',
            'task_approval_event_from_status' => 'pending',
            'task_approval_event_to_status' => 'approved',
            'task_approval_event_from_scope' => $scope,
            'task_approval_event_to_scope' => $scope,
            'task_approval_event_from_type' => $type,
            'task_approval_event_to_type' => $type,
            'task_approval_event_from_required_user_id' => $required_user_id,
            'task_approval_event_to_required_user_id' => $required_user_id,
            'task_approval_event_actor_type' => $decision_actor_type,
        ]];
    }

    return [
        'execution' => [
            'status' => 'Completed',
            'completed_at' => '2026-09-02 12:01:00',
            'snapshot' => $definition,
            'snapshot_hash' => $definition_hash,
            'published_hash' => $definition_hash,
            'published_definition' => $definition,
        ],
        'source_task_count' => count($definition['tasks']),
        'tasks' => $tasks,
        'evidence_by_key' => $evidence_by_key,
        'approvals_by_key' => $approvals_by_key,
        'approval_events_by_projection' => $approval_events_by_projection,
        'state_events_by_key' => $state_events_by_key,
    ];
};

$templates = [];
foreach (starterContentTicketTemplates() as $template) {
    if (in_array($template['runbook_key'] ?? '', [
        'managed-care-onboarding', 'client-offboarding',
    ], true)) {
        $tasks = [];
        foreach ($template['tasks'] as $index => $source_task) {
            $tasks[] = starterRunbookTaskDefinition($source_task, $index + 1);
        }
        $templates[$template['runbook_key']] = [
            'key' => $template['runbook_key'],
            'type' => $template['runbook_type'],
            'name' => $template['name'],
            'description' => $template['description'],
            'subject' => $template['subject'],
            'details' => $template['details'],
            'tasks' => $tasks,
        ];
    }
}

foreach ([
    'managed-care-onboarding' => 43,
    'client-offboarding' => 25,
] as $key => $expected_tasks) {
    $assertTrue(isset($templates[$key]), "Missing canonical $key definition");
    if (!isset($templates[$key])) {
        continue;
    }
    $fixture = $buildFixture($templates[$key]);
    $assertSame($expected_tasks, count($fixture['tasks']), "$key synthetic closeout has the wrong task count");
    $assertSame(
        [],
        runbookCloseoutIntegrityErrors($fixture),
        "$key did not pass a complete integrity-verified closeout"
    );
}

$onboarding_fixture = $buildFixture($templates['managed-care-onboarding']);
$tampered = $onboarding_fixture;
$tampered['execution']['snapshot']['name'] = 'Rewritten after completion';
$assertTrue(
    in_array('execution_snapshot_hash_mismatch', $errorCodes(runbookCloseoutIntegrityErrors($tampered)), true),
    'A rewritten immutable execution snapshot passed closeout integrity'
);

$missing_evidence = $onboarding_fixture;
$evidence_task_key = array_key_first($missing_evidence['evidence_by_key']);
unset($missing_evidence['evidence_by_key'][$evidence_task_key]);
$assertTrue(
    in_array('required_evidence_missing', $errorCodes(runbookCloseoutIntegrityErrors($missing_evidence)), true),
    'A completed canonical task without its required evidence passed closeout'
);

$broken_history = $onboarding_fixture;
$history_task_key = array_key_first($broken_history['state_events_by_key']);
$last_event_index = count($broken_history['state_events_by_key'][$history_task_key]) - 1;
$broken_history['state_events_by_key'][$history_task_key][$last_event_index]['task_state_event_from_state'] = 'Waiting';
$assertTrue(
    in_array('task_state_history_inconsistent', $errorCodes(runbookCloseoutIntegrityErrors($broken_history)), true),
    'A discontinuous task-state event chain passed closeout'
);

$internal_definition = [
    'key' => 'internal-closeout-test',
    'type' => 'standard',
    'name' => 'Internal closeout test',
    'description' => 'Synthetic only',
    'subject' => 'Synthetic closeout',
    'details' => 'Synthetic only',
    'tasks' => [[
        'key' => 'ict-010',
        'name' => 'ICT-010 Verify',
        'instructions' => 'Verify the synthetic action.',
        'order' => 1,
        'estimate' => 5,
        'condition_type' => 'always',
        'condition_value' => '',
        'owner_type' => 'ticket_assignee',
        'owner_user_id' => 0,
        'due_offset_minutes' => 60,
        'initial_state' => 'Ready',
        'approval_scope' => 'internal',
        'approval_type' => 'specific',
        'approval_user_id' => 502,
        'evidence_type' => 'note',
        'evidence_prompt' => 'Record verification.',
        'depends_on' => [],
    ]],
];
$self_approval = $buildFixture($internal_definition);
$self_approval['approvals_by_key']['ict-010'][0]['approval_decision_actor_id'] = 501;
$assertTrue(
    in_array('approval_self_decision', $errorCodes(runbookCloseoutIntegrityErrors($self_approval)), true),
    'An internal requester approved their own closeout gate'
);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Goal 3 onboarding and offboarding closeout acceptance passed.\n";
