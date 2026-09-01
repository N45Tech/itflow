<?php

/*
 * N45 branded transactional email renderer.
 *
 * Callers pass the same SQL-safe scalar values used by ITFlow's existing mail
 * queue. Rich message fragments, such as public ticket replies, must already be
 * sanitized by the workflow that stores them.
 */

const N45_EMAIL_LOGO_URL = 'https://n45tech.com/assets/n45-syncro-logo.png';
const N45_EMAIL_WEBSITE_URL = 'https://n45tech.com/';
const N45_TICKET_REPLY_MARKER = '##- Please type your reply above this line -##';

function n45EmailScalar(array $context, string $key, string $default = ''): string
{
    if (!array_key_exists($key, $context) || $context[$key] === null) {
        return $default;
    }

    return trim(strval($context[$key]));
}

function n45EmailHtml(string $value): string
{
    // ITFlow callers traditionally pass SQL-escaped scalars into the mail queue.
    // Remove those transport-only slashes before encoding text for display.
    return htmlspecialchars(stripslashes($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function n45EmailTrustedHtml(array $context, string $key): string
{
    return strval($context[$key] ?? '');
}

function n45EmailHtmlToText(string $html): string
{
    $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
    $text = preg_replace('/<\/(p|div|tr|h[1-6])\s*>/i', "\n", strval($text));
    $text = preg_replace('/<\/td\s*>/i', "\t", strval($text));
    $text = strip_tags(strval($text));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+\n/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", strval($text));

    return trim(strval($text));
}

function n45EmailFact(string $label, string $value): array
{
    return ['label' => $label, 'value' => $value];
}

function n45EmailRenderFacts(array $facts): string
{
    $rows = '';

    foreach ($facts as $fact) {
        $label = n45EmailHtml(strval($fact['label'] ?? ''));
        $value = n45EmailHtml(strval($fact['value'] ?? ''));
        if ($label === '' || $value === '') {
            continue;
        }

        $rows .= '<tr>'
            . '<td class="detail-label" valign="top" style="padding:10px 16px 10px 0;color:#62736e;font-family:Segoe UI,Arial,Helvetica,sans-serif;font-size:13px;line-height:19px;white-space:nowrap">'
            . $label
            . '</td>'
            . '<td class="detail-value" valign="top" style="padding:10px 0;color:#0a2423;font-family:Segoe UI,Arial,Helvetica,sans-serif;font-size:14px;font-weight:600;line-height:20px;text-align:right">'
            . $value
            . '</td>'
            . '</tr>';
    }

    if ($rows === '') {
        return '';
    }

    return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-top:1px solid #dde8e2;border-bottom:1px solid #dde8e2">'
        . $rows
        . '</table>';
}

function n45EmailRenderPlainText(array $view): string
{
    $lines = [];

    if (!empty($view['reply_enabled'])) {
        $lines[] = N45_TICKET_REPLY_MARKER;
        $lines[] = '';
    }

    $lines[] = strval($view['heading']);
    $lines[] = '';
    $lines[] = strval($view['intro']);

    foreach ($view['facts'] as $fact) {
        $label = trim(strval($fact['label'] ?? ''));
        $value = trim(strval($fact['value'] ?? ''));
        if ($label !== '' && $value !== '') {
            $lines[] = $label . ': ' . $value;
        }
    }

    $message_text = n45EmailHtmlToText(strval($view['message_html']));
    if ($message_text !== '') {
        $lines[] = '';
        $lines[] = $message_text;
    }

    if ($view['action_url'] !== '') {
        $lines[] = '';
        $lines[] = strval($view['action_label']) . ': ' . strval($view['action_url']);
    }

    $lines[] = '';
    $lines[] = strval($view['company_name']);
    if ($view['footer_email'] !== '') {
        $lines[] = strval($view['footer_email']);
    }
    if ($view['footer_phone'] !== '') {
        $lines[] = strval($view['footer_phone']);
    }

    return trim(implode("\n", $lines));
}

function n45EmailRenderShell(array $view): string
{
    $preheader = n45EmailHtml(strval($view['preheader']));
    $label = n45EmailHtml(strval($view['label']));
    $heading = n45EmailHtml(strval($view['heading']));
    $intro = n45EmailHtml(strval($view['intro']));
    $company_name = n45EmailHtml(strval($view['company_name']));
    $footer_email = n45EmailHtml(strval($view['footer_email']));
    $footer_phone = n45EmailHtml(strval($view['footer_phone']));
    $message_html = strval($view['message_html']);
    $facts_html = n45EmailRenderFacts($view['facts']);

    $reply_marker = '';
    if (!empty($view['reply_enabled'])) {
        $reply_marker = '<i style="display:block;padding:12px 16px;color:#62736e;font-family:Segoe UI,Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;text-align:center">'
            . N45_TICKET_REPLY_MARKER
            . '</i>';
    }

    $message_block = '';
    if ($message_html !== '') {
        $message_block = '<div style="margin:24px 0 0;padding:18px 20px;background-color:#f4f0e8;border-radius:10px;color:#0a2423;font-family:Segoe UI,Arial,Helvetica,sans-serif;font-size:15px;line-height:24px">'
            . $message_html
            . '</div>';
    }

    $action_block = '';
    if ($view['action_url'] !== '') {
        $action_url = n45EmailHtml(strval($view['action_url']));
        $action_label = n45EmailHtml(strval($view['action_label']));
        $action_block = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0 0"><tr><td class="button-cell" bgcolor="#167F70" style="border-radius:8px">'
            . '<a class="button-link" href="' . $action_url . '" target="_blank" style="display:inline-block;padding:13px 22px;color:#ffffff;font-family:Segoe UI,Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;line-height:20px;text-decoration:none">'
            . $action_label
            . '</a></td></tr></table>';
    }

    return '<!doctype html>'
        . '<html lang="en" xmlns="http://www.w3.org/1999/xhtml"><head>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="x-apple-disable-message-reformatting"><meta name="color-scheme" content="light">'
        . '<meta name="supported-color-schemes" content="light">'
        . '<title>' . $heading . '</title>'
        . '<style>html,body{margin:0!important;padding:0!important;width:100%!important}table,td{border-collapse:collapse!important}img{border:0;display:block;height:auto;line-height:100%;outline:none;text-decoration:none}a{color:#167f70}@media screen and (max-width:680px){.outer-pad{padding-left:0!important;padding-right:0!important}.email-shell{width:100%!important;max-width:100%!important;table-layout:fixed!important;border-left:0!important;border-right:0!important;border-radius:0!important}.mobile-pad{padding-left:22px!important;padding-right:22px!important;word-break:break-word!important}.brand-link{display:block!important;width:100%!important}.brand-logo{width:100%!important;max-width:360px!important}.detail-label,.detail-value{display:block!important;width:100%!important;text-align:left!important}.detail-label{padding-bottom:0!important}.detail-value{padding-top:2px!important;padding-bottom:12px!important}.button-cell,.button-link{display:block!important;width:100%!important}.button-link{box-sizing:border-box!important;text-align:center!important}}</style>'
        . '</head><body style="margin:0;padding:0;background-color:#dde8e2;color:#0a2423">'
        . $reply_marker
        . '<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;color:transparent;line-height:1px;font-size:1px">'
        . $preheader
        . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#DDE8E2" style="width:100%;background-color:#dde8e2"><tr><td class="outer-pad" align="center" style="padding:28px 12px">'
        . '<table role="presentation" class="email-shell" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;table-layout:fixed;overflow:hidden;background-color:#ffffff;border:1px solid #cbd9d2;border-radius:16px">'
        . '<tr><td class="mobile-pad" style="padding:26px 36px 22px;background-color:#ffffff;border-top:6px solid #0a2423">'
        . '<a class="brand-link" href="' . N45_EMAIL_WEBSITE_URL . '" target="_blank" style="display:inline-block;text-decoration:none">'
        . '<img class="brand-logo" src="' . N45_EMAIL_LOGO_URL . '" width="360" alt="N45 Technology Solutions" style="display:block;width:360px;max-width:100%;height:auto;border:0"></a>'
        . '</td></tr><tr><td height="4" bgcolor="#49C8B1" style="height:4px;background-color:#49c8b1;font-size:0;line-height:0">&nbsp;</td></tr>'
        . '<tr><td class="mobile-pad" style="padding:34px 36px 36px">'
        . '<p style="margin:0 0 10px;color:#167f70;font-family:Segoe UI,Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;line-height:18px">'
        . $label
        . '</p>'
        . '<h1 style="margin:0 0 16px;color:#0a2423;font-family:Georgia,Times New Roman,serif;font-size:30px;font-weight:700;line-height:38px">'
        . $heading
        . '</h1>'
        . '<p style="margin:0 0 24px;color:#334f4c;font-family:Segoe UI,Arial,Helvetica,sans-serif;font-size:16px;line-height:25px">'
        . $intro
        . '</p>'
        . $facts_html
        . $message_block
        . $action_block
        . '</td></tr>'
        . '<tr><td class="mobile-pad" style="padding:22px 36px;background-color:#f4f0e8;border-top:1px solid #dde8e2">'
        . '<p style="margin:0;color:#334f4c;font-family:Segoe UI,Arial,Helvetica,sans-serif;font-size:13px;line-height:20px"><strong>'
        . $company_name
        . '</strong>'
        . ($footer_email !== '' ? '<br><a href="mailto:' . $footer_email . '" style="color:#167f70;text-decoration:none">' . $footer_email . '</a>' : '')
        . ($footer_phone !== '' ? '<br>' . $footer_phone : '')
        . '</p></td></tr></table></td></tr></table></body></html>';
}

function renderN45Email(string $template_key, array $context = []): array
{
    $company_name = n45EmailScalar($context, 'company_name', 'N45 Technology Solutions');
    $contact_name = n45EmailScalar($context, 'contact_name');
    $greeting_name = $contact_name !== '' ? $contact_name : 'there';
    $is_collaborator = n45EmailScalar($context, 'recipient_role') === 'collaborator';
    $facts = [];
    $message_html = n45EmailTrustedHtml($context, 'message_html');
    $action_url = n45EmailScalar($context, 'action_url');
    $footer_email = n45EmailScalar($context, 'footer_email');
    $footer_phone = n45EmailScalar($context, 'footer_phone');
    $reply_enabled = false;

    switch ($template_key) {
        case 'ticket.created':
            $ticket_number = n45EmailScalar($context, 'ticket_number');
            $ticket_subject = n45EmailScalar($context, 'ticket_subject');
            $ticket_status = n45EmailScalar($context, 'ticket_status', 'Open');
            $subject = "We created Ticket $ticket_number for you — $ticket_subject";
            $preheader = "Your support request is in our queue as Ticket $ticket_number.";
            $label = 'Request received';
            $heading = 'Your support request is in good hands.';
            $intro = $is_collaborator
                ? 'You are receiving this update because you are a collaborator on the support ticket.'
                : "Hello $greeting_name. We created a support ticket and will keep you updated as work progresses.";
            $facts = [
                n45EmailFact('Ticket', $ticket_number),
                n45EmailFact('Subject', $ticket_subject),
                n45EmailFact('Status', $ticket_status),
            ];
            $action_label = 'View ticket';
            $reply_enabled = true;
            break;

        case 'ticket.updated':
            $ticket_number = n45EmailScalar($context, 'ticket_number');
            $ticket_subject = n45EmailScalar($context, 'ticket_subject');
            $ticket_status = n45EmailScalar($context, 'ticket_status');
            $subject = "Update on Ticket $ticket_number — $ticket_status";
            $preheader = "There is new activity on support Ticket $ticket_number.";
            $label = 'Support update';
            $heading = 'We have an update on your request.';
            $intro = $is_collaborator
                ? 'New activity was added to a support ticket you are following as a collaborator.'
                : "Hello $greeting_name. New activity has been added to your support ticket.";
            $facts = [
                n45EmailFact('Ticket', $ticket_number),
                n45EmailFact('Subject', $ticket_subject),
                n45EmailFact('Status', $ticket_status),
            ];
            $action_label = 'View ticket';
            $reply_enabled = true;
            break;

        case 'ticket.resolved':
            $ticket_number = n45EmailScalar($context, 'ticket_number');
            $ticket_subject = n45EmailScalar($context, 'ticket_subject');
            $subject = "Resolved — Ticket $ticket_number: $ticket_subject";
            $preheader = "Ticket $ticket_number has been marked resolved and is pending closure.";
            $label = 'Resolution update';
            $heading = 'Your request has been marked resolved.';
            $intro = $is_collaborator
                ? 'A support ticket you are following has been marked resolved and is pending closure.'
                : "Hello $greeting_name. If everything is working as expected, no action is needed. Reply to this email or reopen the ticket if you still need help.";
            $facts = [
                n45EmailFact('Ticket', $ticket_number),
                n45EmailFact('Subject', $ticket_subject),
                n45EmailFact('Status', n45EmailScalar($context, 'ticket_status', 'Resolved')),
            ];
            $action_label = 'Review or reopen ticket';
            $reply_enabled = true;
            break;

        case 'invoice.issued':
            $invoice_number = n45EmailScalar($context, 'invoice_number');
            $is_paid = !empty($context['is_paid']);
            $subject = $is_paid ? "Receipt — Invoice $invoice_number" : "Invoice $invoice_number — " . n45EmailScalar($context, 'balance_due') . ' due';
            $preheader = $is_paid ? "Invoice $invoice_number is marked paid." : "Invoice $invoice_number is ready for review.";
            $label = $is_paid ? 'Paid invoice' : 'Billing notice';
            $heading = $is_paid ? 'Your paid invoice is ready.' : 'Your invoice is ready.';
            $intro = $is_paid
                ? "Hello $greeting_name. This invoice is marked paid and is available for your records."
                : "Hello $greeting_name. Please review the invoice details and submit payment by the due date.";
            $facts = [
                n45EmailFact('Invoice', $invoice_number),
                n45EmailFact('Description', n45EmailScalar($context, 'invoice_scope')),
                n45EmailFact('Issue date', n45EmailScalar($context, 'issue_date')),
                n45EmailFact('Due date', n45EmailScalar($context, 'due_date')),
                n45EmailFact('Total', n45EmailScalar($context, 'total')),
                n45EmailFact('Balance due', n45EmailScalar($context, 'balance_due')),
            ];
            $action_label = 'Review invoice';
            break;

        case 'invoice.overdue':
            $invoice_number = n45EmailScalar($context, 'invoice_number');
            $subject = "Action needed — Invoice $invoice_number is overdue";
            $preheader = "A balance remains due on Invoice $invoice_number.";
            $label = 'Payment reminder';
            $heading = 'This invoice is past due.';
            $intro = "Hello $greeting_name. Our records show a remaining balance on this invoice. Please review it and arrange payment when possible.";
            $facts = [
                n45EmailFact('Invoice', $invoice_number),
                n45EmailFact('Description', n45EmailScalar($context, 'invoice_scope')),
                n45EmailFact('Due date', n45EmailScalar($context, 'due_date')),
                n45EmailFact('Amount paid', n45EmailScalar($context, 'amount_paid')),
                n45EmailFact('Balance due', n45EmailScalar($context, 'balance_due')),
                n45EmailFact('Overdue by', n45EmailScalar($context, 'overdue_by')),
            ];
            $action_label = 'Review invoice';
            break;

        case 'payment.received':
            $invoice_number = n45EmailScalar($context, 'invoice_number');
            $is_partial = !empty($context['is_partial']);
            $multiple_invoices = !empty($context['multiple_invoices']);
            $subject = $multiple_invoices
                ? 'Payment received — Multiple invoices'
                : ($is_partial ? 'Partial payment received' : 'Payment received') . " — Invoice $invoice_number";
            $preheader = $multiple_invoices
                ? 'We recorded your payment across multiple invoices.'
                : "We recorded a payment for Invoice $invoice_number.";
            $label = $is_partial ? 'Partial payment' : 'Payment confirmation';
            $heading = $is_partial ? 'We received your partial payment.' : 'Thank you. Your payment was received.';
            $intro = $multiple_invoices
                ? "Hello $greeting_name. We applied your payment across the invoices listed below. Keep this email for your records."
                : "Hello $greeting_name. We applied your payment to the invoice below. Keep this email for your records.";
            $facts = [
                n45EmailFact($multiple_invoices ? 'Applied to' : 'Invoice', $multiple_invoices ? 'Multiple invoices' : $invoice_number),
                n45EmailFact('Amount paid', n45EmailScalar($context, 'amount_paid')),
                n45EmailFact('Payment method', n45EmailScalar($context, 'payment_method')),
                n45EmailFact('Reference', n45EmailScalar($context, 'payment_reference')),
                n45EmailFact('Remaining balance', n45EmailScalar($context, 'remaining_balance')),
            ];
            $action_label = 'View invoice';
            break;

        case 'portal.password_reset':
            $subject = 'Reset your N45 client portal password';
            $preheader = 'Use the secure link to choose a new client portal password.';
            $label = 'Client portal security';
            $heading = 'Reset your portal password.';
            $intro = "Hello $greeting_name. We received a request to reset your client portal password. Use the secure link below to continue.";
            $message_html = '<p style="margin:0;color:#334f4c;font-family:Segoe UI,Arial,Helvetica,sans-serif;font-size:14px;line-height:22px">If you did not request this change, you can safely ignore this email. The link will expire automatically.</p>';
            $action_label = 'Reset password';
            break;

        case 'portal.password_reset_confirmation':
            $subject = 'Your N45 client portal password was changed';
            $preheader = 'Your client portal password reset is complete.';
            $label = 'Security confirmation';
            $heading = 'Your portal password has been changed.';
            $intro = "Hello $greeting_name. Your client portal password was reset successfully. You can now sign in with the new password.";
            $message_html = '<p style="margin:0;color:#334f4c;font-family:Segoe UI,Arial,Helvetica,sans-serif;font-size:14px;line-height:22px">If you did not make this change, contact N45 support immediately so we can secure your account.</p>';
            $action_label = 'Open client portal';
            break;

        default:
            throw new InvalidArgumentException("Unknown N45 email template: $template_key");
    }

    $view = [
        'preheader' => $preheader,
        'label' => $label,
        'heading' => $heading,
        'intro' => $intro,
        'facts' => $facts,
        'message_html' => $message_html,
        'action_url' => $action_url,
        'action_label' => $action_label,
        'company_name' => $company_name,
        'footer_email' => $footer_email,
        'footer_phone' => $footer_phone,
        'reply_enabled' => $reply_enabled,
    ];

    return [
        'template_key' => $template_key,
        'subject' => $subject,
        'html' => n45EmailRenderShell($view),
        'text' => n45EmailRenderPlainText($view),
    ];
}

function n45EmailQueueFields(array $message): array
{
    return [
        'subject' => strval($message['subject'] ?? ''),
        'body' => strval($message['html'] ?? ''),
        'body_plain' => strval($message['text'] ?? ''),
        'template_key' => strval($message['template_key'] ?? ''),
    ];
}
