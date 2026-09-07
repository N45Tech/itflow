<?php

function knowledgeTerms(string $text): array
{
    $text = mb_strtolower(strip_tags($text));
    preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_-]{2,49}/u', $text, $matches);
    $stop = ['the','and','for','with','this','that','from','have','has','was','were','are','not','but',
        'ticket','issue','please','client','user','users','when','after','before','into','your','our','can','cannot'];
    return array_slice(array_values(array_diff(array_unique($matches[0]), $stop)), 0, 12);
}

function knowledgeMatch(array $terms, string $text, bool $asset = false, bool $service = false): array
{
    // Candidate bodies may be longer than the query's twelve-term budget.
    preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_-]{2,49}/u', mb_strtolower(strip_tags($text)), $all);
    $shared = array_values(array_intersect($terms, array_unique($all[0])));
    $reasons = [];
    if ($asset) { $reasons[] = 'Same asset'; }
    if ($service) { $reasons[] = 'Same service'; }
    if ($shared) { $reasons[] = 'Matching terms: ' . implode(', ', array_slice($shared, 0, 4)); }
    return ['score' => count($shared) * 3 + ($asset ? 8 : 0) + ($service ? 5 : 0), 'reasons' => $reasons];
}

function knowledgeSourceHash(array $ticket): string
{
    $keys = [
        'ticket_client_id','ticket_subject','ticket_details','ticket_resolution_code',
        'ticket_resolution_summary','ticket_root_cause','ticket_asset_id',
    ];
    return hash('sha256', json_encode(array_map(static fn ($key) => $ticket[$key] ?? null, $keys), JSON_THROW_ON_ERROR));
}

function knowledgeDocumentHash(string $title, string $content): string
{
    return hash('sha256', $title . "\n" . $content);
}

function knowledgeDocumentState(array $knowledge): ?array
{
    $id = (int) $knowledge['knowledge_document_id'];
    $client = (int) $knowledge['knowledge_client_id'];
    if (!$id) { return null; }
    return mysqli_fetch_assoc(fieldDb("SELECT document_id, document_name,
        SHA2(CONCAT(document_name,CHAR(10),document_content),256) hash FROM documents
        WHERE document_id = $id AND document_client_id = $client AND document_archived_at IS NULL")) ?: null;
}

function knowledgeReviewers(int $client): array
{
    $eligible = [];
    foreach (followupOwners($client) as $owner) {
        if (!$owner['can_escalate']) { continue; }
        $id = (int) $owner['user_id'];
        if (mysqli_fetch_row(fieldDb("SELECT r.role_is_admin = 1 OR EXISTS (
            SELECT 1 FROM user_role_permissions p JOIN modules m ON m.module_id = p.module_id
            WHERE p.user_role_id = u.user_role_id AND m.module_name = 'module_client'
            AND p.user_role_permission_level >= 2) FROM users u JOIN user_roles r ON r.role_id = u.user_role_id
            WHERE u.user_id = $id"))[0]) { $eligible[] = $owner; }
    }
    return $eligible;
}

function knowledgePublishDocument(array $row, array $ticket, int $actor): int
{
    $client = (int) $row['knowledge_client_id']; $doc = (int) $row['knowledge_document_id'];
    $content = '<h2>Problem and applicability</h2><p>' . nl2br(escapeHtml($row['knowledge_problem']))
        . '</p><h2>Resolution</h2><p>' . nl2br(escapeHtml($row['knowledge_solution']))
        . '</p><h2>Checks and cautions</h2><p>' . nl2br(escapeHtml($row['knowledge_cautions'])) . '</p>';
    if ($doc) {
        // Client is already locked. Invalidate linked documentation evidence before the document lock.
        documentationInvalidateDocumentLocked($doc, $client, $actor, 'document_changed');
        $old = mysqli_fetch_assoc(fieldDb("SELECT * FROM documents WHERE document_id = $doc
            AND document_client_id = $client AND document_archived_at IS NULL FOR UPDATE"));
        if (!$old || !hash_equals((string) $row['knowledge_document_base_hash'], knowledgeDocumentHash($old['document_name'], $old['document_content']))) {
            throw new DomainException('The document changed during review. Return the draft so its author can check those changes.');
        }
        fieldDb('INSERT INTO document_versions SET document_version_document_id = ' . $doc
            . ', document_version_name = ' . fieldSql($old['document_name'])
            . ', document_version_description = ' . fieldSql($old['document_description'])
            . ', document_version_content = ' . fieldSql($old['document_content'])
            . ', document_version_created_at = ' . fieldSql($old['document_updated_at'] ?: $old['document_created_at'])
            . ', document_version_created_by = ' . (int) ($old['document_updated_by'] ?: $old['document_created_by']));
        $statement = 'UPDATE documents SET ';
    } else { $statement = 'INSERT INTO documents SET '; }
    fieldDb($statement . 'document_name = ' . fieldSql($row['knowledge_title'])
        . ', document_description = ' . fieldSql('Reviewed resolution for ' . $ticket['ticket_prefix'] . $ticket['ticket_number'])
        . ', document_content = ' . fieldSql($content) . ', document_content_raw = ' . fieldSql(fieldPlainText($content))
        . ", document_client_visible = 0, document_updated_by = $actor"
        . ($doc ? " WHERE document_id = $doc AND document_client_id = $client" : ", document_client_id = $client, document_created_by = $actor"));
    if (!$doc) {
        $doc = (int) mysqli_insert_id($GLOBALS['mysqli']);
        $asset = (int) $ticket['ticket_asset_id'];
        if ($asset && fieldRows("SELECT asset_id FROM assets WHERE asset_id = $asset AND asset_client_id = $client AND asset_archived_at IS NULL")) {
            fieldDb("INSERT INTO asset_documents SET asset_id = $asset, document_id = $doc");
        }
    }
    $id = (int) $row['knowledge_id'];
    fieldDb("UPDATE service_knowledge SET knowledge_state = 'published', knowledge_document_id = $doc,
        knowledge_published_hash = " . fieldSql(knowledgeDocumentHash($row['knowledge_title'], $content))
        . ", knowledge_document_base_hash = NULL, knowledge_reviewed_by = $actor, knowledge_reviewed_at = UTC_TIMESTAMP(),
        knowledge_revision = knowledge_revision + 1, knowledge_updated_at = UTC_TIMESTAMP() WHERE knowledge_id = $id");
    return $doc;
}

function knowledgeSourceEligible(array $ticket): bool
{
    return in_array((int) $ticket['ticket_status'], [4, 5], true)
        && in_array($ticket['ticket_resolution_code'], ['fixed','workaround','configuration_changed',
            'access_restored','request_fulfilled','client_confirmed'], true)
        && mb_strlen(trim((string) $ticket['ticket_resolution_summary'])) >= 5;
}

function knowledgeSuggestions(int $ticket_id): array
{
    $ticket = assistanceTicket($ticket_id);
    $client = (int) $ticket['ticket_client_id']; $asset = (int) $ticket['ticket_asset_id'];
    $terms = knowledgeTerms($ticket['ticket_subject'] . ' ' . mb_substr(fieldPlainText($ticket['ticket_details']), 0, 2000));
    $termSql = static function (string $column) use ($terms): string {
        if (!$terms) { return '0=1'; }
        return '(' . implode(' OR ', array_map(static fn ($term) => "$column LIKE "
            . fieldSql('%' . addcslashes($term, '%_\\') . '%'), $terms)) . ')';
    };
    $results = [];
    $rows = fieldRows("SELECT ticket_id, ticket_subject, ticket_asset_id, ticket_resolution_summary,
        ticket_resolution_code, COALESCE(ticket_closed_at,ticket_resolved_at) solved_at,
        EXISTS(SELECT 1 FROM service_assets sa JOIN service_assets sb ON sa.service_id = sb.service_id
            JOIN services sv ON sv.service_id = sa.service_id AND sv.service_client_id = $client
            WHERE sa.asset_id = $asset AND sb.asset_id = t.ticket_asset_id) same_service
        FROM tickets t WHERE ticket_client_id = $client AND ticket_archived_at IS NULL
        AND ticket_status IN (4,5) AND ticket_id <> $ticket_id AND ticket_resolution_summary IS NOT NULL
        AND ticket_resolution_code IN ('fixed','workaround','configuration_changed','access_restored','request_fulfilled','client_confirmed')
        AND (($asset > 0 AND ticket_asset_id = $asset) OR " . $termSql('ticket_subject')
        . ' OR ' . $termSql('ticket_resolution_summary') . " OR EXISTS(SELECT 1 FROM service_assets sa
            JOIN service_assets sb ON sa.service_id = sb.service_id
            JOIN services sv ON sv.service_id = sa.service_id AND sv.service_client_id = $client
            WHERE sa.asset_id = $asset AND sb.asset_id = t.ticket_asset_id))
        ORDER BY solved_at DESC, ticket_id DESC LIMIT 201");
    $limited = count($rows) > 200;
    foreach (array_slice($rows, 0, 200) as $row) {
        $match = knowledgeMatch($terms, $row['ticket_subject'] . ' ' . $row['ticket_resolution_summary'],
            $asset > 0 && (int) $row['ticket_asset_id'] === $asset, (bool) $row['same_service']);
        if (!$match['score']) { continue; }
        $results[] = $match + ['kind' => 'ticket', 'id' => (int) $row['ticket_id'], 'title' => $row['ticket_subject'],
            'excerpt' => mb_substr(fieldPlainText($row['ticket_resolution_summary']), 0, 700),
            'updated_at' => fieldLocalTime($row['solved_at']), 'label' => 'Previous resolution'];
    }
    if (lookupUserPermission('module_client') >= 1) {
        $docs = fieldRows("SELECT d.document_id, d.document_name, LEFT(d.document_content_raw,12000) content,
            SHA2(CONCAT(d.document_name,CHAR(10),d.document_content),256) content_hash,
            COALESCE(d.document_updated_at,d.document_created_at) changed_at,
            k.knowledge_id, k.knowledge_state, k.knowledge_published_hash, k.knowledge_ticket_id, k.knowledge_source_hash,
            EXISTS(SELECT 1 FROM asset_documents ad WHERE ad.document_id = d.document_id AND ad.asset_id = $asset) same_asset,
            EXISTS(SELECT 1 FROM service_documents sd JOIN service_assets sa ON sa.service_id = sd.service_id
                JOIN services sv ON sv.service_id = sd.service_id AND sv.service_client_id = $client
                WHERE sd.document_id = d.document_id AND sa.asset_id = $asset) same_service
            FROM documents d LEFT JOIN service_knowledge k ON k.knowledge_document_id = d.document_id
            WHERE d.document_client_id = $client AND d.document_archived_at IS NULL
            AND (" . $termSql('d.document_name') . ' OR ' . $termSql('d.document_content_raw') . "
                OR EXISTS(SELECT 1 FROM asset_documents ad WHERE ad.document_id = d.document_id AND ad.asset_id = $asset)
                OR EXISTS(SELECT 1 FROM service_documents sd JOIN service_assets sa ON sa.service_id = sd.service_id
                    WHERE sd.document_id = d.document_id AND sa.asset_id = $asset))
            ORDER BY changed_at DESC, d.document_id DESC LIMIT 201");
        $limited = $limited || count($docs) > 200;
        foreach (array_slice($docs, 0, 200) as $doc) {
            if ($doc['knowledge_id'] && ($doc['knowledge_state'] !== 'published'
                || !hash_equals((string) $doc['knowledge_published_hash'], (string) $doc['content_hash']))) { continue; }
            if ($doc['knowledge_id']) {
                $source_id = (int) $doc['knowledge_ticket_id'];
                $source = mysqli_fetch_assoc(fieldDb("SELECT * FROM tickets WHERE ticket_id = $source_id
                    AND ticket_client_id = $client AND ticket_archived_at IS NULL"));
                if (!$source || !knowledgeSourceEligible($source)
                    || !hash_equals($doc['knowledge_source_hash'], knowledgeSourceHash($source))) { continue; }
            }
            $match = knowledgeMatch($terms, $doc['document_name'] . ' ' . $doc['content'], (bool) $doc['same_asset'], (bool) $doc['same_service']);
            if (!$match['score']) { continue; }
            $results[] = $match + ['kind' => 'document', 'id' => (int) $doc['document_id'], 'title' => $doc['document_name'],
                'excerpt' => mb_substr(fieldPlainText($doc['content']), 0, 700), 'updated_at' => fieldLocalTime($doc['changed_at']),
                'label' => $doc['knowledge_id'] ? 'Reviewed knowledge' : 'Client document'];
        }
    }
    usort($results, static fn ($a, $b) => [$b['score'], $b['updated_at'], $b['id']] <=> [$a['score'], $a['updated_at'], $a['id']]);
    return ['items' => array_slice($results, 0, 8), 'limited' => $limited, 'terms' => $terms,
        'can_capture' => knowledgeSourceEligible($ticket) && lookupUserPermission('module_support') >= 2
            && lookupUserPermission('module_client') >= 2];
}

function knowledgeLoad(int $id, ?int $ticket_id = null): array
{
    $row = mysqli_fetch_assoc(fieldDb("SELECT k.*, c.client_name, t.ticket_subject, t.ticket_prefix, t.ticket_number
        FROM service_knowledge k JOIN clients c ON c.client_id = k.knowledge_client_id AND c.client_archived_at IS NULL
        JOIN tickets t ON t.ticket_id = k.knowledge_ticket_id AND t.ticket_client_id = k.knowledge_client_id AND t.ticket_archived_at IS NULL
        WHERE k.knowledge_id = $id"));
    if (!$row || ($ticket_id !== null && (int) $row['knowledge_ticket_id'] !== $ticket_id)) {
        throw new DomainException('This knowledge record is unavailable.');
    }
    assistanceRequire((int) $row['knowledge_client_id'], 1, 1);
    $row['source_current'] = hash_equals($row['knowledge_source_hash'], knowledgeSourceHash(assistanceTicket((int) $row['knowledge_ticket_id'])));
    $doc = knowledgeDocumentState($row);
    $row['document_hash'] = $doc['hash'] ?? '';
    $row['document_available'] = (bool) $doc;
    $row['document_current'] = !$row['knowledge_document_id'] || ($doc
        && hash_equals((string) $row['knowledge_published_hash'], $doc['hash']));
    $row['reviewer_count'] = count(array_filter(knowledgeReviewers((int) $row['knowledge_client_id']),
        static fn ($user) => !in_array((int) $user['user_id'], [(int) $row['knowledge_created_by'], (int) $row['knowledge_edited_by']], true)));
    $row['history'] = assistanceHistory((int) $row['knowledge_ticket_id'], 'knowledge', (string) $id);
    return $row;
}

function knowledgeQueue(array $filters): array
{
    if (lookupUserPermission('module_support') < 1 || lookupUserPermission('module_client') < 1) {
        throw new DomainException('Support and client documentation access are required.');
    }
    $state = in_array($filters['state'] ?? '', ['draft','review','published'], true) ? $filters['state'] : 'review';
    $offset = max(0, min(100000, (int) ($filters['offset'] ?? 0)));
    $where = clientScopeSql('k.knowledge_client_id');
    if (!empty($filters['client_id'])) { $where .= ' AND k.knowledge_client_id = ' . (int) $filters['client_id']; }
    $rows = fieldRows("SELECT k.knowledge_id, k.knowledge_ticket_id, k.knowledge_title, k.knowledge_state,
        k.knowledge_updated_at, c.client_name, u.user_name FROM service_knowledge k
        JOIN clients c ON c.client_id = k.knowledge_client_id AND c.client_archived_at IS NULL
        JOIN tickets t ON t.ticket_id = k.knowledge_ticket_id AND t.ticket_client_id = k.knowledge_client_id AND t.ticket_archived_at IS NULL
        LEFT JOIN users u ON u.user_id = k.knowledge_edited_by
        WHERE k.knowledge_state = " . fieldSql($state) . $where . " ORDER BY k.knowledge_updated_at DESC, k.knowledge_id DESC LIMIT 41 OFFSET $offset");
    return ['items' => array_slice($rows, 0, 40), 'next' => count($rows) > 40 ? $offset + 40 : null, 'state' => $state];
}

function knowledgeCapture(array $input, int $actor): array
{
    global $mysqli;
    $ticket = assistanceTicket((int) ($input['ticket_id'] ?? 0), true);
    $client = (int) $ticket['ticket_client_id']; $ticket_id = (int) $ticket['ticket_id'];
    assistanceRequire($client, 2, 2);
    if (!knowledgeSourceEligible($ticket)) { throw new DomainException('Capture knowledge from a resolved ticket with a successful, recorded resolution.'); }
    $existing = mysqli_fetch_assoc(fieldDb("SELECT knowledge_id FROM service_knowledge WHERE knowledge_ticket_id = $ticket_id FOR UPDATE"));
    if ($existing) { return ['message' => 'Opened the existing knowledge record.', 'knowledge_id' => (int) $existing['knowledge_id']]; }
    $title = mb_substr(fieldPlainText($ticket['ticket_subject']), 0, 200);
    $problem = mb_substr(fieldPlainText($ticket['ticket_details']), 0, 10000);
    $solution = (string) $ticket['ticket_resolution_summary'];
    $hash = knowledgeSourceHash($ticket);
    fieldDb("INSERT INTO service_knowledge SET knowledge_ticket_id = $ticket_id, knowledge_client_id = $client,
        knowledge_title = " . fieldSql($title) . ', knowledge_problem = ' . fieldSql($problem)
        . ', knowledge_solution = ' . fieldSql($solution) . ", knowledge_cautions = '', knowledge_source_hash = " . fieldSql($hash)
        . ", knowledge_created_by = $actor, knowledge_edited_by = $actor,
        knowledge_created_at = UTC_TIMESTAMP(), knowledge_updated_at = UTC_TIMESTAMP()");
    $id = (int) mysqli_insert_id($mysqli);
    assistanceEvent('knowledge', (string) $id, $ticket_id, $client, $actor, 'captured', 'Draft captured from the recorded resolution.',
        ['title' => $title, 'problem' => $problem, 'solution' => $solution, 'source_hash' => $hash]);
    return ['message' => 'Knowledge draft created. Review its applicability and remove client secrets before requesting review.', 'knowledge_id' => $id];
}

function knowledgeSave(array $input, int $actor): array
{
    $ticket = assistanceTicket((int) ($input['ticket_id'] ?? 0), true);
    $client = (int) $ticket['ticket_client_id']; $ticket_id = (int) $ticket['ticket_id'];
    assistanceRequire($client, 2, 2);
    $id = (int) ($input['knowledge_id'] ?? 0);
    fieldDb("SELECT knowledge_id FROM service_knowledge WHERE knowledge_id = $id FOR UPDATE");
    $row = knowledgeLoad($id, $ticket_id);
    if ((int) ($input['expected_revision'] ?? 0) !== (int) $row['knowledge_revision']) {
        throw new DomainException('This draft changed. Reload before editing or reviewing it.');
    }
    $operation = (string) ($input['operation'] ?? 'save');
    if (!in_array($operation, ['save','submit','publish','return','revise'], true)) { throw new DomainException('Choose a valid knowledge action.'); }
    if ($row['knowledge_state'] === 'published' && $operation !== 'revise') { throw new DomainException('Start a revision before changing published knowledge.'); }
    if ($operation === 'revise') {
        if ($row['knowledge_state'] !== 'published' || !$row['document_available']) {
            throw new DomainException('A published article and its active client document are required to start a revision.');
        }
        if (!hash_equals($row['document_hash'], (string) ($input['expected_document_hash'] ?? ''))) {
            throw new DomainException('The document changed. Reload it before starting a revision.');
        }
        $note = ticketDisciplineText($input['note'] ?? '', 'Revision reason', 5, 1000);
        fieldDb("UPDATE service_knowledge SET knowledge_state = 'draft', knowledge_edited_by = $actor,
            knowledge_document_base_hash = " . fieldSql($row['document_hash']) . ",
            knowledge_revision = knowledge_revision + 1, knowledge_updated_at = UTC_TIMESTAMP() WHERE knowledge_id = $id");
    } elseif (in_array($operation, ['publish','return'], true)) {
        assistanceRequire($client, 3, 2);
        if ($row['knowledge_state'] !== 'review' || $actor === (int) $row['knowledge_created_by']
            || $actor === (int) $row['knowledge_edited_by']) {
            throw new DomainException('A different Full Support technician must review the submitted draft.');
        }
        $note = ticketDisciplineText($input['note'] ?? '', 'Review reason', 5, 1000);
        if ($operation === 'publish') {
            if (!$row['source_current'] || !knowledgeSourceEligible($ticket)) {
                throw new DomainException('The source resolution changed. Return the draft for a fresh review of the source.');
            }
            knowledgePublishDocument($row, $ticket, $actor);
        } else {
            fieldDb("UPDATE service_knowledge SET knowledge_state = 'draft', knowledge_revision = knowledge_revision + 1,
                knowledge_updated_at = UTC_TIMESTAMP() WHERE knowledge_id = $id");
        }
    } else {
        if ($row['knowledge_state'] !== 'draft') { throw new DomainException('The submitted draft is awaiting review. Its reviewer can return it for changes.'); }
        $title = ticketDisciplineText($input['title'] ?? '', 'Article title', 5, 200);
        $problem = fieldText($input['problem'] ?? '', 'problem and applicability', 10000);
        $solution = fieldText($input['solution'] ?? '', 'resolution steps', 10000);
        $cautions = fieldText($input['cautions'] ?? '', 'checks and cautions', 5000, $operation === 'submit');
        if (!$row['source_current'] && empty($input['confirm_source'])) {
            throw new DomainException('Review the changed source resolution and confirm the draft still reflects it.');
        }
        if (!knowledgeSourceEligible($ticket)) { throw new DomainException('Resolve the source ticket before submitting reusable knowledge.'); }
        if ($row['knowledge_document_id']) {
            if (!$row['document_available']) { throw new DomainException('Restore the published client document before revising it.'); }
            if (!hash_equals($row['document_hash'], (string) ($input['expected_document_hash'] ?? ''))
                || (!$row['document_current'] && empty($input['confirm_document']))) {
                throw new DomainException('Read the current client document and confirm that this draft includes its relevant changes.');
            }
        }
        $state = $operation === 'submit' ? 'review' : 'draft';
        fieldDb('UPDATE service_knowledge SET knowledge_title = ' . fieldSql($title) . ', knowledge_problem = ' . fieldSql($problem)
            . ', knowledge_solution = ' . fieldSql($solution) . ', knowledge_cautions = ' . fieldSql($cautions)
            . ', knowledge_source_hash = ' . fieldSql(knowledgeSourceHash($ticket)) . ', knowledge_state = ' . fieldSql($state)
            . ', knowledge_document_base_hash = ' . fieldSql($row['knowledge_document_id'] ? $row['document_hash'] : null)
            . ", knowledge_edited_by = $actor, knowledge_revision = knowledge_revision + 1,
            knowledge_updated_at = UTC_TIMESTAMP() WHERE knowledge_id = $id");
        $note = $operation === 'submit' ? 'Submitted for independent review.' : 'Draft updated.';
        if ($operation === 'submit') {
            foreach (knowledgeReviewers($client) as $reviewer) {
                if (in_array((int) $reviewer['user_id'], [$actor, (int) $row['knowledge_created_by']], true)) { continue; }
                assistanceNotify((int) $reviewer['user_id'], $client, $ticket_id, 'Knowledge review requested: ' . $ticket['ticket_prefix'] . $ticket['ticket_number'],
                    '/agent/knowledge.php?id=' . $id);
            }
        }
    }
    $saved = knowledgeLoad($id, $ticket_id);
    assistanceEvent('knowledge', (string) $id, $ticket_id, $client, $actor, $operation, $note,
        ['revision' => (int) $saved['knowledge_revision'], 'state' => $saved['knowledge_state'],
            'title' => $saved['knowledge_title'], 'problem' => $saved['knowledge_problem'],
            'solution' => $saved['knowledge_solution'], 'cautions' => $saved['knowledge_cautions']]);
    return ['message' => ['save' => 'Draft saved.', 'submit' => 'Knowledge submitted for review.',
        'publish' => 'Reviewed knowledge published as an internal client document.', 'return' => 'Draft returned with your review reason.',
        'revise' => 'Revision opened from the last reviewed article. Check the current document before requesting review.'][$operation],
        'knowledge_id' => $id];
}
