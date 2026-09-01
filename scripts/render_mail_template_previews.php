<?php

require_once __DIR__ . '/../functions/mail_templates.php';

$output_directory = $argv[1] ?? (__DIR__ . '/../tmp/mail-template-previews');
if (!is_dir($output_directory) && !mkdir($output_directory, 0775, true) && !is_dir($output_directory)) {
    throw new RuntimeException("Could not create preview directory: $output_directory");
}

$common = [
    'company_name' => 'N45 Technology Solutions',
    'contact_name' => 'Jordan',
    'footer_email' => 'support@n45tech.com',
    'footer_phone' => '(828) 555-0147',
];

$previews = [
    'ticket-created' => ['ticket.created', [
        'ticket_number' => 'N45-1042',
        'ticket_subject' => 'New workstation setup',
        'ticket_status' => 'Open',
        'message_html' => '<p style="margin:0">Please prepare the new laptop for Casey and confirm the preferred pickup time.</p>',
        'action_url' => 'https://n45tech.com/',
    ]],
    'ticket-updated' => ['ticket.updated', [
        'ticket_number' => 'N45-1042',
        'ticket_subject' => 'New workstation setup',
        'ticket_status' => 'Waiting on Client',
        'message_html' => '<p style="margin:0">The workstation is configured and ready. Please let us know whether Thursday afternoon works for pickup.</p>',
        'action_url' => 'https://n45tech.com/',
    ]],
    'ticket-resolved' => ['ticket.resolved', [
        'ticket_number' => 'N45-1042',
        'ticket_subject' => 'New workstation setup',
        'ticket_status' => 'Resolved',
        'message_html' => '<p style="margin:0">Setup, security validation, and user sign-in testing are complete.</p>',
        'action_url' => 'https://n45tech.com/',
    ]],
    'invoice-issued' => ['invoice.issued', [
        'invoice_number' => 'INV-2048',
        'invoice_scope' => 'Managed technology services',
        'issue_date' => 'August 27, 2026',
        'due_date' => 'September 10, 2026',
        'total' => '$1,250.00',
        'balance_due' => '$1,250.00',
        'action_url' => 'https://n45tech.com/',
    ]],
    'invoice-overdue' => ['invoice.overdue', [
        'invoice_number' => 'INV-2048',
        'due_date' => 'August 1, 2026',
        'amount_paid' => '$250.00',
        'balance_due' => '$1,000.00',
        'overdue_by' => '26 days',
        'action_url' => 'https://n45tech.com/',
    ]],
    'payment-received' => ['payment.received', [
        'invoice_number' => 'INV-2048',
        'amount_paid' => '$1,250.00',
        'payment_method' => 'ACH',
        'payment_reference' => 'PAY-8842',
        'action_url' => 'https://n45tech.com/',
    ]],
    'password-reset' => ['portal.password_reset', [
        'action_url' => 'https://n45tech.com/',
    ]],
    'password-reset-confirmation' => ['portal.password_reset_confirmation', [
        'action_url' => 'https://n45tech.com/',
    ]],
];

foreach ($previews as $name => [$template_key, $context]) {
    $message = renderN45Email($template_key, array_merge($common, $context));
    $path = rtrim($output_directory, '/\\') . DIRECTORY_SEPARATOR . $name . '.html';
    if (file_put_contents($path, $message['html']) === false) {
        throw new RuntimeException("Could not write preview: $path");
    }
    echo $path . PHP_EOL;
}
