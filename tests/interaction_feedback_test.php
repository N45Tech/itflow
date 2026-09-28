<?php

$root = dirname(__DIR__);
$feedback = file_get_contents($root . '/js/interaction_feedback.js');
$ajaxModal = file_get_contents($root . '/js/ajax_modal.js');
$confirmModal = file_get_contents($root . '/js/confirm_modal.js');
$footer = file_get_contents($root . '/includes/footer.php');
$theme = file_get_contents($root . '/css/n45_theme.css');
$ticketAdd = file_get_contents($root . '/agent/modals/ticket/ticket_add.php');
$ticketResolve = file_get_contents($root . '/agent/modals/ticket/ticket_resolve.php');
$ticketRecord = file_get_contents($root . '/agent/ticket.php');
$clientAdd = file_get_contents($root . '/agent/modals/client/client_add.php');
$clientEdit = file_get_contents($root . '/agent/modals/client/client_edit.php');
$vendorAdd = file_get_contents($root . '/agent/modals/vendor/vendor_add.php');

$failures = [];
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$assertOrder = static function (string $first, string $second, string $haystack, string $message) use (&$failures): void {
    $firstPosition = strpos($haystack, $first);
    $secondPosition = strpos($haystack, $second);
    if ($firstPosition === false || $secondPosition === false || $firstPosition >= $secondPosition) {
        $failures[] = $message;
    }
};

$feedbackScript = 'interaction_feedback.js?v=<?= filemtime(__DIR__ . \'/../js/interaction_feedback.js\') ?>';
$ajaxScript = 'ajax_modal.js?v=<?= filemtime(__DIR__ . \'/../js/ajax_modal.js\') ?>';
$confirmScript = 'confirm_modal.js?v=<?= filemtime(__DIR__ . \'/../js/confirm_modal.js\') ?>';
$assertContains($feedbackScript, $footer, 'The shared interaction feedback script is not cache-busted');
$assertContains($confirmScript, $footer, 'The confirmation script is not cache-busted');
$assertOrder($feedbackScript, $ajaxScript, $footer,
    'Interaction feedback must load before the AJAX modal loader');
$assertOrder($feedbackScript, $confirmScript, $footer,
    'Interaction feedback must load before confirmation dialogs');

$assertContains("form.matches('[data-itflow-submit]')", $feedback,
    'Form progress handling is not explicitly opt in');
$assertContains("form.dataset.itflowSubmitting === 'true'", $feedback,
    'Repeated form submissions are not blocked');
$assertContains('event.submitter', $feedback,
    'Form progress handling does not preserve the selected submit action');
$assertContains('carried.dataset.itflowSubmitterMirror', $feedback,
    'Disabled submitters are not mirrored into the PHP request');
$assertContains("form.setAttribute('aria-busy', 'true')", $feedback,
    'Submitting forms do not expose their busy state');
$assertContains("window.addEventListener('pageshow'", $feedback,
    'Back-forward cache restores do not clear stale busy states');

$assertContains('const activeModals = new WeakMap();', $ajaxModal,
    'Repeated AJAX modal requests are not associated with their trigger');
$assertContains("trigger.getAttribute('aria-disabled') === 'true'", $ajaxModal,
    'Disabled AJAX modal triggers can still load content');
$assertContains("body.setAttribute('role', 'status')", $ajaxModal,
    'AJAX modal loading state is not announced');
$assertContains("body.setAttribute('role', 'alert')", $ajaxModal,
    'AJAX modal failure state is not announced');
$assertContains("retryButton.addEventListener('click', retry)", $ajaxModal,
    'AJAX modal failures do not offer a working retry action');
$assertContains("wrapper.addEventListener('hidden.bs.modal'", $ajaxModal,
    'AJAX modal teardown does not restore the originating interaction');
$assertContains('controller.abort();', $ajaxModal,
    'Closing or retrying an AJAX modal does not cancel stale requests');
$assertNotContains('alert(', $ajaxModal,
    'AJAX modal errors still use an unthemed browser alert');

$assertContains("link.dataset.itflowConfirmPending === 'true'", $confirmModal,
    'Confirmation dialogs allow repeated activation');
$assertContains('window.itflowActionFeedback.setPending', $confirmModal,
    'Confirmed link actions do not expose a pending state');
$assertContains("link.focus({ preventScroll: true })", $confirmModal,
    'Cancelled confirmations do not restore focus');

$assertContains('.itflow-action-pending {', $theme,
    'The shared pending action state is not themed');
$assertContains('.n45-modal-state-error {', $theme,
    'The AJAX modal error state is not themed');

foreach ([
    'ticket creation' => [$ticketAdd, 'data-busy-label="Creating ticket…"'],
    'ticket resolution' => [$ticketResolve, 'data-busy-label="Resolving ticket…"'],
    'ticket update' => [$ticketRecord, 'data-busy-label="Posting update…"'],
    'client creation' => [$clientAdd, 'data-busy-label="Creating client…"'],
    'client editing' => [$clientEdit, 'data-busy-label="Saving client…"'],
    'vendor creation' => [$vendorAdd, 'data-busy-label="Creating vendor…"'],
] as $workflow => [$source, $label]) {
    $assertContains('data-itflow-submit', $source,
        ucfirst($workflow) . ' does not opt into duplicate-submit protection');
    $assertContains($label, $source,
        ucfirst($workflow) . ' does not provide specific progress copy');
}

if ($failures) {
    fwrite(STDERR, "Interaction feedback contract test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Interaction feedback contracts passed\n";
