// bulk_actions.js
// Shared bulk helper: selection count + simple URL enrichment for bulk actions

// --- Helpers ---
function getBulkCheckboxes() {
    // Always query fresh in case rows are re-rendered
    return Array.from(document.querySelectorAll('input[type="checkbox"].bulk-select'));
}

function getSelectedCount() {
    return getBulkCheckboxes().filter(cb => cb.checked).length;
}

function updateSelectedCount() {
    const count = getSelectedCount();

    const selectedCountEl = document.getElementById('selectedCount');
    if (selectedCountEl) {
        selectedCountEl.textContent = count;
    }

    const bulkBtn = document.getElementById('bulkActionButton');
    if (bulkBtn) {
        bulkBtn.hidden = count === 0;
    }
}

// --- Select All Handling ---
function checkAll(source) {
    getBulkCheckboxes().forEach(cb => {
        cb.checked = source.checked;
    });
    updateSelectedCount();
}

function blockBulkAction(event, trigger) {
    // Stop both native navigation and the downstream AJAX modal handler.
    event.preventDefault();
    event.stopImmediatePropagation();
    trigger.removeAttribute('href');
    trigger.removeAttribute('data-modal-url');
}

// --- Wire up once DOM is ready ---
document.addEventListener('DOMContentLoaded', function () {

    // Initialize count
    updateSelectedCount();

    // Wire select-all checkbox if present
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('click', function () {
            checkAll(this);
        });
    }

    // Per-row checkbox handling
    document.addEventListener('click', function (e) {
        const cb = e.target.closest('input[type="checkbox"].bulk-select');
        if (!cb) return;
        updateSelectedCount();
    });

    // ------------------------------------------------------
    // Generic bulk handler
    //
    // For ANY element with data-bulk="true":
    //   - Reads base URL from data-modal-url or href
    //   - Collects ALL checked .bulk-select checkboxes
    //   - Appends:
    //        <cb.name> = cb.value
    //     e.g. file_ids[]=12, document_ids[]=27, client_ids[]=3, etc.
    //   - Writes URL back to data-modal-url or href
    //   - DOES NOT prevent default, so ajax-modal / normal links still work
    // ------------------------------------------------------
    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-bulk="true"]');
        if (!trigger) return;

        const base = trigger.getAttribute('data-modal-url') || trigger.getAttribute('href');
        if (!base || base === '#') return;

        let url;
        try {
            url = new URL(base, window.location.href);
        } catch (err) {
            blockBulkAction(e, trigger);
            return;
        }

        // Bulk selections must never be sent to another origin or an executable URL.
        if ((url.protocol !== 'https:' && url.protocol !== 'http:') ||
            url.origin !== window.location.origin || url.username || url.password) {
            blockBulkAction(e, trigger);
            return;
        }

        const params = url.searchParams;
        const checked = getBulkCheckboxes().filter(cb => cb.checked);

        if (!checked.length) {
            // Nothing selected; do nothing (no ids appended)
            return;
        }

        // Collect all unique names (file_ids[], document_ids[], client_ids[], etc.)
        const namesToClear = new Set();
        checked.forEach(cb => {
            if (cb.name) {
                namesToClear.add(cb.name);
            }
        });

        // Clear any existing values for those names
        namesToClear.forEach(name => params.delete(name));

        // Append each checked checkbox as name=value
        checked.forEach(cb => {
            if (!cb.name) return;
            params.append(cb.name, cb.value);
        });

        // Preserve the validated absolute URL. Rebuilding from pathname can turn
        // a same-origin //path into an external destination or expose a scheme.
        const finalUrl = url.href;
        if (!/^https?:\/\//i.test(finalUrl)) {
            blockBulkAction(e, trigger);
            return;
        }

        if (trigger.hasAttribute('data-modal-url')) {
            trigger.setAttribute('data-modal-url', finalUrl);
        } else {
            trigger.setAttribute('href', finalUrl);
        }

        // IMPORTANT:
        // We do NOT call preventDefault().
        // Your existing ajax-modal handler / normal link click will continue normally,
        // now using the updated URL.
    }, true); // capture so we run before other click handlers
});
