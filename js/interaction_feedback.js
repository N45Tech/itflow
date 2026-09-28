/**
 * Shared progress feedback for deliberate form actions.
 *
 * Forms opt in with `data-itflow-submit`; this avoids changing search,
 * pagination and other forms that are intentionally submitted repeatedly.
 * The listener is delegated so forms injected by an AJAX modal receive the
 * same protection without reinitialisation.
 */
(function () {
    'use strict';

    const pendingControls = new WeakMap();

    function pendingLabel(control, fallback) {
        if (!control) {
            return fallback || 'Working…';
        }
        return control.dataset.busyLabel || fallback || 'Working…';
    }

    function setPending(control, label) {
        if (!control || pendingControls.has(control)) {
            return;
        }

        pendingControls.set(control, {
            html: control instanceof HTMLButtonElement ? control.innerHTML : null,
            value: control instanceof HTMLInputElement ? control.value : null,
            disabled: 'disabled' in control ? control.disabled : null,
            ariaBusy: control.getAttribute('aria-busy'),
            ariaDisabled: control.getAttribute('aria-disabled')
        });

        control.dataset.itflowPending = 'true';
        control.classList.add('itflow-action-pending');
        control.setAttribute('aria-busy', 'true');
        control.setAttribute('aria-disabled', 'true');

        if ('disabled' in control) {
            control.disabled = true;
        }

        const text = pendingLabel(control, label);
        if (control instanceof HTMLButtonElement) {
            const spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm';
            spinner.setAttribute('aria-hidden', 'true');

            const copy = document.createElement('span');
            copy.textContent = text;

            control.replaceChildren(spinner, copy);
        } else if (control instanceof HTMLInputElement) {
            control.value = text;
        }
    }

    function restoreAttribute(control, name, value) {
        if (value === null) {
            control.removeAttribute(name);
        } else {
            control.setAttribute(name, value);
        }
    }

    function clearPending(control) {
        const original = control ? pendingControls.get(control) : null;
        if (!control || !original) {
            return;
        }

        if (control instanceof HTMLButtonElement) {
            control.innerHTML = original.html;
        } else if (control instanceof HTMLInputElement) {
            control.value = original.value;
        }

        if ('disabled' in control) {
            control.disabled = original.disabled;
        }
        restoreAttribute(control, 'aria-busy', original.ariaBusy);
        restoreAttribute(control, 'aria-disabled', original.ariaDisabled);
        control.classList.remove('itflow-action-pending');
        delete control.dataset.itflowPending;
        pendingControls.delete(control);
    }

    function preserveSubmitter(form, submitter) {
        form.querySelectorAll('[data-itflow-submitter-mirror]').forEach(function (field) {
            field.remove();
        });

        if (!submitter || !submitter.name) {
            return;
        }

        // Disabled submit controls are omitted from the request. Carry the
        // selected action explicitly before disabling it so PHP still receives
        // e.g. add_ticket, resolve_ticket or add_client.
        const carried = document.createElement('input');
        carried.type = 'hidden';
        carried.name = submitter.name;
        carried.value = submitter.value;
        carried.dataset.itflowSubmitterMirror = 'true';
        form.appendChild(carried);
    }

    function resetForm(form) {
        form.removeAttribute('aria-busy');
        delete form.dataset.itflowSubmitting;
        form.querySelectorAll('[data-itflow-pending]').forEach(clearPending);
        form.querySelectorAll('[data-itflow-submitter-mirror]').forEach(function (field) {
            field.remove();
        });
    }

    window.itflowActionFeedback = {
        setPending: setPending,
        clearPending: clearPending,
        resetForm: resetForm
    };

    // Listen on window so form- and document-level validation handlers run
    // first. A prevented submit must not leave a control looking busy.
    window.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-itflow-submit]')) {
            return;
        }

        if (form.dataset.itflowSubmitting === 'true') {
            event.preventDefault();
            return;
        }

        if (event.defaultPrevented || !form.checkValidity()) {
            return;
        }

        const submitter = event.submitter || form.querySelector(
            'button[type="submit"]:not([disabled]), input[type="submit"]:not([disabled])'
        );

        preserveSubmitter(form, submitter);
        form.dataset.itflowSubmitting = 'true';
        form.setAttribute('aria-busy', 'true');
        setPending(submitter, 'Working…');
    });

    // A back-forward cache restore returns the existing DOM, including any
    // disabled button. Put it back into an actionable state before the user
    // sees the page again.
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('[data-itflow-submit][data-itflow-submitting]').forEach(resetForm);
        document.querySelectorAll('[data-itflow-pending]').forEach(clearPending);
    });
})();
