<?php

$logging = file_get_contents(__DIR__ . '/../functions/logging.php');
$sla_cron = file_get_contents(__DIR__ . '/../cron/ticket_sla.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    str_contains($logging, '$recipient_sql = $recipient_user_id ? " AND user_id = $recipient_user_id"'),
    'Targeted notifications do not restrict the active-agent query to the requested recipient'
);
$assert(
    substr_count($sla_cron, 'ticket_number, ticket_assigned_to,') === 2,
    'Both SLA ticket queries must select the assigned agent'
);
$assert(
    str_contains($sla_cron, '$client_id, $ticket_id, $assigned_user_id);'),
    'SLA notifications are not targeted to the assigned agent'
);
$assert(
    str_contains($sla_cron, 'if ($assigned_user_id) {'),
    'Unassigned SLA tickets could trigger a broadcast notification'
);

echo "SLA notification scope validation passed.\n";
