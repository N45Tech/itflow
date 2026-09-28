<?php

/* Inbound email HTML must become inert text before it enters a notification
 * sent from ITFlow's trusted support identity. */

$source = file_get_contents(dirname(__DIR__) . '/cron/ticket_email_parser.php');
$start = strpos($source, 'function ticketEmailNotificationText(');
$end = strpos($source, '/** ------------------------------------------------------------------', $start);

if ($start === false || $end === false) {
    throw new RuntimeException('Could not locate the notification text helper');
}

eval(substr($source, $start, $end - $start));

$payload = <<<'HTML'
<style>body { display: none }</style><p>Review <a href="https://attacker.example/login">your account</a></p>
<img src="https://attacker.example/track"><form action="https://attacker.example"><button>Continue</button></form>
&lt;img src="https://attacker.example/encoded-track"&gt;
HTML;
$rendered = ticketEmailNotificationText($payload);

$failures = [];
foreach (['<style', '<a ', '<img', '<form', '<button'] as $active_html) {
    if (stripos($rendered, $active_html) !== false) {
        $failures[] = "Notification retained active HTML: $active_html";
    }
}
if (!str_contains($rendered, 'your account') || !str_contains($rendered, 'Continue')) {
    $failures[] = 'Notification discarded the human-readable reply text';
}
if (str_contains($rendered, 'display: none')) {
    $failures[] = 'Notification retained stylesheet contents';
}
if (!str_contains($rendered, '&lt;img src=&quot;https://attacker.example/encoded-track&quot;&gt;')) {
    $failures[] = 'Entity-encoded markup was not safely re-encoded';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Ticket email notification security assertions passed.\n";
