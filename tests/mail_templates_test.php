<?php

require_once __DIR__ . '/../functions/mail_templates.php';

$failures = [];

function assertMailTemplate(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$common = [
    'company_name' => 'N45 Technology Solutions',
    'contact_name' => 'Jordan',
    'footer_email' => 'support@n45tech.com',
    'footer_phone' => '(828) 555-0147',
];

$escaped_name_preview = renderN45Email('ticket.created', array_merge($common, [
    'contact_name' => "Jordan O\\'Brien",
]));
assertMailTemplate(str_contains($escaped_name_preview['html'], 'Jordan O&#039;Brien'), 'SQL-escaped names should render without transport slashes.');
assertMailTemplate(!str_contains($escaped_name_preview['html'], "O\\&#039;Brien"), 'SQL-escaped names should not expose a slash in HTML.');

$fixtures = [
    'ticket.created' => [
        'ticket_number' => 'N45-1042',
        'ticket_subject' => 'New workstation setup',
        'ticket_status' => 'Open',
        'message_html' => '<p>Please prepare the new laptop for Casey.</p>',
        'action_url' => 'https://portal.example.test/guest/ticket/1042',
    ],
    'ticket.updated' => [
        'ticket_number' => 'N45-1042',
        'ticket_subject' => 'New workstation setup',
        'ticket_status' => 'Waiting on Client',
        'message_html' => '<p>The workstation is ready for pickup.</p>',
        'action_url' => 'https://portal.example.test/guest/ticket/1042',
    ],
    'ticket.resolved' => [
        'ticket_number' => 'N45-1042',
        'ticket_subject' => 'New workstation setup',
        'ticket_status' => 'Resolved',
        'message_html' => '<p>Setup and validation are complete.</p>',
        'action_url' => 'https://portal.example.test/guest/ticket/1042',
    ],
    'invoice.issued' => [
        'invoice_number' => 'INV-2048',
        'invoice_scope' => 'Managed technology services',
        'issue_date' => 'August 27, 2026',
        'due_date' => 'September 10, 2026',
        'total' => '$1,250.00',
        'balance_due' => '$1,250.00',
        'action_url' => 'https://portal.example.test/guest/invoice/2048',
    ],
    'invoice.overdue' => [
        'invoice_number' => 'INV-2048',
        'due_date' => 'August 1, 2026',
        'amount_paid' => '$250.00',
        'balance_due' => '$1,000.00',
        'overdue_by' => '26 days',
        'action_url' => 'https://portal.example.test/guest/invoice/2048',
    ],
    'payment.received' => [
        'invoice_number' => 'INV-2048',
        'amount_paid' => '$1,250.00',
        'payment_method' => 'ACH',
        'payment_reference' => 'PAY-8842',
        'action_url' => 'https://portal.example.test/guest/invoice/2048',
    ],
    'portal.password_reset' => [
        'action_url' => 'https://portal.example.test/client/reset?token=fixture',
    ],
    'portal.password_reset_confirmation' => [
        'action_url' => 'https://portal.example.test/login.php',
    ],
];

foreach ($fixtures as $template_key => $fixture) {
    $message = renderN45Email($template_key, array_merge($common, $fixture));

    assertMailTemplate($message['template_key'] === $template_key, "$template_key did not preserve its template key");
    assertMailTemplate($message['subject'] !== '', "$template_key returned an empty subject");
    assertMailTemplate(str_starts_with($message['html'], '<!doctype html>'), "$template_key did not return a complete HTML document");
    assertMailTemplate(str_contains($message['html'], N45_EMAIL_LOGO_URL), "$template_key omitted the N45 logo");
    assertMailTemplate(str_contains($message['html'], 'width="640"'), "$template_key omitted the 640-pixel email shell");
    assertMailTemplate(!str_contains($message['html'], '{{'), "$template_key contains an unresolved token");
    assertMailTemplate($message['text'] !== '', "$template_key returned an empty plain-text body");

    if (!empty($fixture['action_url'])) {
        assertMailTemplate(str_contains($message['html'], htmlspecialchars($fixture['action_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), "$template_key omitted its action URL from HTML");
        assertMailTemplate(str_contains($message['text'], $fixture['action_url']), "$template_key omitted its action URL from plain text");
    }

    $is_ticket = str_starts_with($template_key, 'ticket.');
    assertMailTemplate(str_contains($message['html'], N45_TICKET_REPLY_MARKER) === $is_ticket, "$template_key reply-marker behavior is incorrect");
    assertMailTemplate(str_contains($message['text'], N45_TICKET_REPLY_MARKER) === $is_ticket, "$template_key plain-text reply-marker behavior is incorrect");

    if ($is_ticket) {
        assertMailTemplate(
            preg_match('/<i[^>]*>##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##<\/i>.*$/is', $message['html']) === 1,
            "$template_key no longer matches ITFlow's inbound reply parser"
        );

        $quoted_reply = 'Thanks, this works for me.<br><br>' . $message['html'];
        $parsed_reply = preg_replace(
            '/<i[^>]*>##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##<\/i>.*$/is',
            '',
            $quoted_reply
        );
        $parsed_reply = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', strval($parsed_reply));
        $parsed_reply = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', strval($parsed_reply));
        assertMailTemplate(str_contains(strval($parsed_reply), 'Thanks, this works for me.'), "$template_key removed the client reply during parser cleanup");
        assertMailTemplate(!str_contains(strval($parsed_reply), '.email-shell'), "$template_key left branded CSS in the parsed client reply");
    }
}

$queue_fields = n45EmailQueueFields(renderN45Email('ticket.updated', array_merge($common, $fixtures['ticket.updated'])));
assertMailTemplate(array_keys($queue_fields) === ['subject', 'body', 'body_plain', 'template_key'], 'Queue fields are incomplete or out of order');

$unknown_template_failed_closed = false;
try {
    renderN45Email('unknown.template');
} catch (InvalidArgumentException $exception) {
    $unknown_template_failed_closed = true;
}
assertMailTemplate($unknown_template_failed_closed, 'Unknown templates do not fail closed');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'N45 email template tests passed (' . count($fixtures) . ' templates).' . PHP_EOL;
