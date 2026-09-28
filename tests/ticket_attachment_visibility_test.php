<?php

$ticket_post = file_get_contents(__DIR__ . '/../agent/post/ticket.php');
if ($ticket_post === false) {
    fwrite(STDERR, "Could not read the agent ticket handler.\n");
    exit(1);
}

$handler_start = strpos($ticket_post, "if (isset(\$_POST['add_ticket_reply']))");
$handler_end = $handler_start === false
    ? false
    : strpos($ticket_post, "if (isset(\$_GET['delete_ticket_attachment']))", $handler_start);
if ($handler_start === false || $handler_end === false) {
    fwrite(STDERR, "Could not isolate the agent ticket reply handler.\n");
    exit(1);
}
$handler = substr($ticket_post, $handler_start, $handler_end - $handler_start);

$required = [
    "\$has_reply_attachments = in_array(",
    "(array) (\$_FILES['attachments']['error'] ?? [])",
    "if (!empty(\$ticket_reply) || \$has_reply_attachments)",
    'if ($ticket_reply_id)',
    'saveTicketAttachments($ticket_id, $ticket_reply_id)',
];
foreach ($required as $needle) {
    if (!str_contains($handler, $needle)) {
        fwrite(STDERR, "Attachment-only replies do not retain visibility (missing: $needle).\n");
        exit(1);
    }
}

if (str_contains($handler, 'saveTicketAttachments($ticket_id, null)')) {
    fwrite(STDERR, "Agent reply attachments can still be stored without visibility metadata.\n");
    exit(1);
}

echo "Ticket attachment visibility contracts passed.\n";
