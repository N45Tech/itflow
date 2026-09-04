(function () {
    const activityToggle = document.getElementById('ticketActivityToggle');
    if (activityToggle) {
        const earlierUpdates = Array.from(document.querySelectorAll('.ticket-reply-older'));
        const collapsedLabel = activityToggle.innerHTML;
        let expanded = false;

        activityToggle.addEventListener('click', function () {
            expanded = !expanded;
            earlierUpdates.forEach(function (update) {
                update.hidden = !expanded;
            });
            activityToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            activityToggle.innerHTML = expanded
                ? '<i class="fas fa-chevron-up me-1"></i>Show recent activity only'
                : collapsedLabel;
        });
    }

    function syncDisclosure(toggle) {
        const card = toggle.closest('.card');
        if (!card) {
            return;
        }
        const expanded = !card.classList.contains('collapsed-card');
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.setAttribute('aria-label', expanded ? toggle.dataset.expandedLabel : toggle.dataset.collapsedLabel);
    }

    document.querySelectorAll('.ticket-disclosure-toggle').forEach(function (toggle) {
        syncDisclosure(toggle);
        toggle.addEventListener('click', function () {
            window.setTimeout(function () {
                syncDisclosure(toggle);
            }, 0);
        });
    });
})();
