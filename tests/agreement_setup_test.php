<?php

require_once __DIR__ . '/../functions/sla.php';
require_once __DIR__ . '/../functions/agreement_setup.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$reject = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message;
    } catch (InvalidArgumentException $expected) {
        // User input should fail validation, not reach a database operation.
    }
};

$calendar = agreementSetupCalendar(['mode' => 'business_hours', 'timezone' => 'America/New_York',
    'days' => ['5', '1', '3', '1'], 'start' => '08:30', 'end' => '17:00']);
$assert($calendar['business_days'] === [1, 3, 5], 'Support days must be normalized and deduplicated');
$assert($calendar['business_hours_start'] === '08:30:00', 'Support time must be a canonical snapshot');
$assert($calendar['timezone'] === 'America/New_York', 'Agreement timezone must survive setup');
$assert(agreementSetupCalendarLabel($calendar) === 'Mon,Wed,Fri 08:30-17:00 America/New_York',
    'The readable support-hours label must describe the actual saved calendar');
$always = agreementSetupCalendar(['mode' => '24x7', 'timezone' => 'UTC', 'days' => ['1'],
    'start' => 'bad', 'end' => 'bad']);
$assert($always['business_days'] === [] && $always['business_hours_start'] === null,
    '24/7 must not retain an old business-hours window');
$assert(formatSlaMinutes(120, $always) === '2 hours', '24/7 notices must not promise business hours');
$assert(formatSlaMinutes(120, $calendar) === '2 business hours', 'Business-calendar notices must use saved terms');

foreach ([
    ['mode' => 'unknown', 'timezone' => 'UTC'],
    ['mode' => '24x7', 'timezone' => 'Not/AZone'],
    ['mode' => '24x7', 'timezone' => '+04:00'],
    ['mode' => 'business_hours', 'timezone' => 'UTC', 'days' => [], 'start' => '09:00', 'end' => '17:00'],
    ['mode' => 'business_hours', 'timezone' => 'UTC', 'days' => ['8'], 'start' => '09:00', 'end' => '17:00'],
    ['mode' => 'business_hours', 'timezone' => 'UTC', 'days' => ['1'], 'start' => '25:00', 'end' => '27:00'],
    ['mode' => 'business_hours', 'timezone' => 'UTC', 'days' => ['1'], 'start' => '09:61', 'end' => '17:00'],
    ['mode' => 'business_hours', 'timezone' => 'UTC', 'days' => ['1'], 'start' => '17:00', 'end' => '09:00'],
    ['mode' => 'business_hours', 'timezone' => 'UTC', 'days' => ['1'], 'start' => '09:00', 'end' => '09:00'],
] as $invalid) {
    $reject(static fn() => agreementSetupCalendar($invalid), 'Invalid support calendar was accepted');
}
foreach (['', '-1', '1.5', '2e2', '1000000000000', ['1']] as $invalid) {
    $reject(static fn() => agreementSetupInteger($invalid, 'Review cadence', 1, 24), 'Invalid numeric input was accepted');
}
$assert(agreementSetupInteger('24', 'Review cadence', 1, 24) === 24, 'Upper review-cadence boundary must remain supported');
$assert(agreementSetupInteger('0', 'Renewal notice', 0, 365) === 0, 'Explicit zero notice must remain supported');
$saved = agreementSetupRememberInput(['client_id' => '3', 'name' => 'Draft', 'csrf_token' => 'private', 'unexpected' => 'ignored']);
$assert($saved === ['client_id' => '3', 'name' => 'Draft'], 'Retry state must retain only whitelisted input, not secrets');
$assert(agreementSetupRememberInput(['scope_notes' => str_repeat('x', 65537)]) === [], 'Retry state must be bounded');
$reject(static fn() => agreementCreateFromSetup([], 1, 1), 'The incomplete legacy creation path must be rejected');

$setup = file_get_contents(__DIR__ . '/../functions/agreement_setup.php');
foreach (['agreementValidateEntitlementScope($client_id, $scope, $id, true)',
    'agreementEntitlementScopeLabel($client_id, $scope, $id, true)',
    "agreementSetupInsert('agreement_entitlements'", "agreementSetupInsert('agreement_sla_rules'",
    'mysqli_rollback($mysqli)', '$profile_id === 0', "'agreement_sla_rule_request_type_key' => '*'",
    'array_keys(ticketPriorityDefinitions())'] as $contract) {
    $assert(str_contains($setup, $contract), 'Setup contract missing: ' . $contract);
}
$page = file_get_contents(__DIR__ . '/../agent/agreement_create.php');
$assert(str_contains($page, 'Save complete draft') && str_contains($page, 'data-setup-summary'),
    'Setup must provide a review stage and an explicit draft-only save');
$assert(!str_contains($page, 'name="publish_agreement_version"'), 'Creation must not silently publish client commitments');

if ($failures) {
    fwrite(STDERR, "Agreement setup failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Agreement setup validation and workflow contracts passed.\n";
