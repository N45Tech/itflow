<?php

/** Canned responses are maintained by administrators and inserted into an unsent reply. */
function cannedResponseTicket(int $ticket_id): array
{
    if (lookupUserPermission('module_support') < 1) { throw new DomainException('Support access is required.'); }
    $ticket = mysqli_fetch_assoc(fieldDb("SELECT ticket_category, ticket_client_id FROM tickets
        WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL"));
    if (!$ticket || ((int) $ticket['ticket_client_id'] > 0 && !hasClientAccess((int) $ticket['ticket_client_id']))) {
        throw new DomainException('This ticket is unavailable.');
    }
    return $ticket;
}

function cannedResponseChoices(int $ticket_id): array
{
    $category = (int) cannedResponseTicket($ticket_id)['ticket_category'];
    return fieldRows("SELECT canned_response_id, canned_response_name, canned_response_category_id
        FROM canned_responses WHERE canned_response_archived_at IS NULL
        AND canned_response_category_id IN (0, $category) ORDER BY canned_response_name, canned_response_id");
}

function cannedResponseForTicket(int $ticket_id, int $response_id): array
{
    $category = (int) cannedResponseTicket($ticket_id)['ticket_category'];
    if (lookupUserPermission('module_support') < 2) { throw new DomainException('Ticket write access is required.'); }
    $row = mysqli_fetch_assoc(fieldDb("SELECT canned_response_body FROM canned_responses
        WHERE canned_response_id = $response_id AND canned_response_archived_at IS NULL
        AND canned_response_category_id IN (0, $category) LIMIT 1"));
    if (!$row) { throw new DomainException('This canned response is no longer available for this ticket.'); }
    require_once dirname(__DIR__) . '/libs/htmlpurifier/HTMLPurifier.standalone.php';
    $config = HTMLPurifier_Config::createDefault();
    $config->set('Cache.DefinitionImpl', null);
    $purifier = new HTMLPurifier($config);
    $body = $purifier->purify($row['canned_response_body']);
    return ['body' => $body, 'text' => fieldPlainText($body)];
}
