<?php

require_once dirname(__DIR__) . '/functions/ticket_discipline.php';
require_once dirname(__DIR__) . '/functions/ticket_retention.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$reject = static function (callable $operation, string $message): void {
    try {
        $operation();
    } catch (DomainException $exception) {
        return;
    }
    throw new RuntimeException($message);
};

foreach ([
    ['low', 'low', 'Low'], ['low', 'medium', 'Medium'], ['low', 'high', 'Medium'],
    ['medium', 'low', 'Medium'], ['medium', 'medium', 'Medium'], ['medium', 'high', 'High'],
    ['high', 'low', 'Medium'], ['high', 'medium', 'High'], ['high', 'high', 'Urgent'],
] as [$impact, $urgency, $priority]) {
    $assert(ticketPriorityFromImpactUrgency($impact, $urgency) === $priority,
        "Incorrect priority for $impact impact and $urgency urgency");
}
$reject(fn () => ticketPriorityFromImpactUrgency('unknown', 'high'), 'Invalid impact was accepted');
$reject(fn () => ticketDisciplineDateTime('2030-02-30T09:00'), 'An impossible date was accepted');
$assert(ticketDisciplineDateTime('2030-02-28T09:00') === '2030-02-28 09:00:00',
    'Minute-precision dates contain nondeterministic seconds');
$reject(fn () => ticketDisciplineFutureDateTime('2000-01-01T09:00'), 'An overdue new commitment was accepted');
$assessment = ['work_type' => 'incident', 'impact' => 'high', 'urgency' => 'medium', 'waiting_on' => 'client'];
$reject(fn () => ticketDisciplineAssessmentInput($assessment), 'Waiting work has no required next action');
$assessment['next_action'] = 'Follow up on the requested access list';
$reject(fn () => ticketDisciplineAssessmentInput($assessment), 'Waiting work has no required due date');
$assessment['next_action_due_at'] = date('Y-m-d\TH:i', time() + 86400);
$assert(ticketDisciplineAssessmentInput($assessment)['priority'] === 'High', 'Valid waiting work was rejected');
$reject(fn () => ticketDisciplineResolutionInput('fixed', 'Restored access', '', 'problem'),
    'Problem investigation resolved without a root cause');
$reject(fn () => ticketDisciplineResolutionInput('legacy_completed', 'Skip structured tracking', '', 'incident'),
    'An interactive user can claim a reserved migration resolution');
$assert(ticketDisciplineResolutionInput('fixed', 'Restored access', 'Expired credentials', 'problem')['code'] === 'fixed',
    'Valid problem resolution was rejected');
$note = ticketDisciplineWorkNoteInput([
    'work_action' => '<b>Checked</b> the account', 'work_result' => 'Found an expired password',
    'work_next_step' => 'Ask the owner to sign in',
    'customer_promise_summary' => 'Call the owner with an update',
    'customer_promise_due_at' => date('Y-m-d\TH:i', time() + 86400),
]);
$assert($note['action'] === 'Checked the account', 'Work notes retain submitted HTML');
$note['result'] = '<script>alert("unsafe")</script>';
$assert(!str_contains(ticketDisciplineWorkNoteHtml($note), '<script>'), 'Work note display permits executable HTML');
foreach ([0, -1, 3651, '30.5', 'invalid'] as $days) {
    $reject(fn () => ticketDeletionRetentionDays($days), 'Invalid retention period was accepted');
}
$assert(ticketDeletionRetentionDays('90') === 90, 'Client retention period was rejected');
$reject(fn () => ticketDeletionRequirePurgeEligible(['ticket_archived_at' => null]), 'Active ticket could be purged');
$reject(fn () => ticketDeletionRequirePurgeEligible([
    'ticket_archived_at' => date('Y-m-d H:i:s'), 'ticket_restore_until' => date('Y-m-d H:i:s', time() + 86400),
]), 'Ticket could be purged during its minimum retention period');

echo "Ticket operational discipline validation passed.\n";
