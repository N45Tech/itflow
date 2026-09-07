<?php
if (empty($ticket['ticket_archived_at']) && $client_id > 0) {
    require_once __DIR__ . '/service_assistance.php';
    ?>
    <link rel="stylesheet" href="/agent/service_assistance.css">
    <section class="card" id="suggested-fixes" aria-labelledby="suggested-fixes-title">
        <div class="card-header"><h2 class="card-title" id="suggested-fixes-title">Suggested fixes</h2></div>
        <div class="card-body"><p class="text-muted small">Relevant records from this client. Verify applicability before using a previous fix.</p>
        <?php try { assistanceSuggestionsMarkup(knowledgeSuggestions($ticket_id), $ticket_id); }
        catch (Throwable $e) { error_log('Ticket suggestions: ' . $e->getMessage()); ?><p class="text-muted">Suggestions could not be loaded. Refresh to try again.</p><?php } ?>
        <a class="d-block mt-3" href="/agent/followups.php?ticket_id=<?= $ticket_id ?>&amp;scope=all&amp;due=all">Review this ticket’s follow-ups</a>
        </div>
    </section>
    <?php
}
