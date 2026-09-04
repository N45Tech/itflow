(function () {
    const toggle = document.getElementById('ticketActivityToggle');
    if (!toggle) {
        return;
    }

    const earlierUpdates = Array.from(document.querySelectorAll('.ticket-reply-older'));
    const collapsedLabel = toggle.innerHTML;
    let expanded = false;

    toggle.addEventListener('click', function () {
        expanded = !expanded;
        earlierUpdates.forEach(function (update) {
            update.hidden = !expanded;
        });
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.innerHTML = expanded
            ? '<i class="fas fa-chevron-up me-1"></i>Show recent activity only'
            : collapsedLabel;
    });
})();
