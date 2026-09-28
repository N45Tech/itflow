/**
 * Re-run <script> elements that arrived via innerHTML, strictly in order.
 *
 * External scripts are awaited before the next one starts; inline scripts run
 * synchronously. Order matters because a modal's own script is emitted before
 * modal_footer.php's http.js / autocomplete.js / app.js, and depends on them.
 */
function runScriptsInOrder(scripts) {
    return scripts.reduce(function (chain, old) {
        return chain.then(function () {
            return new Promise(function (resolve) {
                const s = document.createElement('script');
                for (const attr of old.attributes) {
                    s.setAttribute(attr.name, attr.value);
                }
                if (old.src) {
                    const resolvedSrc = new URL(old.getAttribute('src'), document.baseURI).href;
                    const alreadyLoaded = Array.from(document.scripts).some(function (existing) {
                        return existing !== old && existing.src === resolvedSrc;
                    });
                    if (alreadyLoaded) {
                        old.remove();
                        resolve();
                        return;
                    }
                    s.async = false;
                    s.onload = resolve;
                    s.onerror = function () {
                        console.error('ajax-modal: failed to load', old.src);
                        resolve();
                    };
                    old.replaceWith(s);
                } else {
                    s.textContent = old.textContent;
                    old.replaceWith(s);
                    resolve();
                }
            });
        });
    }, Promise.resolve());
}

// Ajax Modal Load Script
(function () {
    'use strict';

    const activeModals = new WeakMap();
    let modalSequence = 0;

    function actionFeedback() {
        return window.itflowActionFeedback || {
            setPending: function () {},
            clearPending: function () {}
        };
    }

    function returnFocusTarget(trigger) {
        const dropdown = trigger.closest('.dropdown');
        if (dropdown && trigger.closest('.dropdown-menu')) {
            return dropdown.querySelector('[data-bs-toggle="dropdown"]') || trigger;
        }
        return trigger;
    }

    function focusWhenAvailable(control) {
        if (control && control.isConnected && typeof control.focus === 'function') {
            try {
                control.focus({ preventScroll: true });
            } catch (error) {
                control.focus();
            }
        }
    }

    function statusCopyFor(error) {
        if (error && error.userMessage) {
            return error.userMessage;
        }
        return 'We could not load this panel. Check your connection and try again.';
    }

    function responseError(status) {
        const error = new Error('HTTP ' + status);
        if (status === 403) {
            error.userMessage = 'You no longer have permission to open this panel.';
        } else if (status === 404 || status === 409) {
            error.userMessage = 'This item is no longer available in its previous state.';
        }
        return error;
    }

    function serverError(message) {
        const error = new Error('Modal endpoint returned an error');
        error.userMessage = String(message || 'This panel could not be loaded.');
        return error;
    }

    function createStateHeader(titleId, title, iconClass) {
        const header = document.createElement('div');
        header.className = 'modal-header';

        const heading = document.createElement('h5');
        heading.className = 'modal-title';
        heading.id = titleId;

        const icon = document.createElement('i');
        icon.className = iconClass + ' me-2';
        icon.setAttribute('aria-hidden', 'true');
        heading.appendChild(icon);
        heading.appendChild(document.createTextNode(title));

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('data-bs-dismiss', 'modal');
        close.setAttribute('aria-label', 'Close dialog');

        header.appendChild(heading);
        header.appendChild(close);
        return header;
    }

    function renderLoading(wrapper, content, titleId) {
        wrapper.setAttribute('aria-busy', 'true');
        wrapper.setAttribute('aria-labelledby', titleId);
        content.replaceChildren();
        content.appendChild(createStateHeader(titleId, 'Loading', 'fas fa-spinner fa-spin'));

        const body = document.createElement('div');
        body.className = 'modal-body n45-modal-state';
        body.setAttribute('role', 'status');
        body.setAttribute('aria-live', 'polite');

        const message = document.createElement('p');
        message.className = 'mb-0';
        message.textContent = 'Loading details…';
        body.appendChild(message);
        content.appendChild(body);
    }

    function renderFailure(wrapper, content, titleId, error, retry) {
        wrapper.removeAttribute('aria-busy');
        wrapper.setAttribute('aria-labelledby', titleId);
        content.replaceChildren();
        content.appendChild(createStateHeader(titleId, 'Could not load panel', 'fas fa-exclamation-circle text-danger'));

        const body = document.createElement('div');
        body.className = 'modal-body n45-modal-state n45-modal-state-error';
        body.setAttribute('role', 'alert');
        body.setAttribute('aria-live', 'assertive');

        const message = document.createElement('p');
        message.className = 'mb-0';
        message.textContent = statusCopyFor(error);
        body.appendChild(message);
        content.appendChild(body);

        const footer = document.createElement('div');
        footer.className = 'modal-footer';

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn btn-outline-secondary';
        close.setAttribute('data-bs-dismiss', 'modal');
        close.textContent = 'Close';

        const retryButton = document.createElement('button');
        retryButton.type = 'button';
        retryButton.className = 'btn btn-primary';
        retryButton.innerHTML = '<i class="fas fa-redo-alt me-2" aria-hidden="true"></i>Retry';
        retryButton.addEventListener('click', retry);

        footer.appendChild(close);
        footer.appendChild(retryButton);
        content.appendChild(footer);
        focusWhenAvailable(retryButton);
    }

    function focusModalContent(wrapper) {
        const target = wrapper.querySelector('[autofocus]') ||
            wrapper.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])') ||
            wrapper.querySelector('button:not([disabled]), a[href]:not([aria-disabled="true"])');
        focusWhenAvailable(target || wrapper);
    }

    function labelLoadedModal(wrapper, fallbackId) {
        const title = wrapper.querySelector('.modal-title');
        if (!title) {
            wrapper.setAttribute('aria-label', 'Dialog');
            wrapper.removeAttribute('aria-labelledby');
            return;
        }
        if (!title.id) {
            title.id = fallbackId + '_content';
        }
        wrapper.setAttribute('aria-labelledby', title.id);
        wrapper.removeAttribute('aria-label');
    }

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('.ajax-modal');
        if (!trigger) {
            return;
        }
        event.preventDefault();

        if (trigger.classList.contains('disabled') || trigger.getAttribute('aria-disabled') === 'true') {
            return;
        }

        const existing = activeModals.get(trigger);
        if (existing && existing.isConnected) {
            bootstrap.Modal.getOrCreateInstance(existing).show();
            focusModalContent(existing);
            return;
        }

        // Prefer data-modal-url, fallback to href.
        const modalUrl = trigger.dataset.modalUrl || trigger.getAttribute('href') || '#';
        const requestedSize = trigger.dataset.modalSize || 'md';
        const modalSize = ['sm', 'md', 'lg', 'xl'].includes(requestedSize) ? requestedSize : 'md';
        const modalId = 'ajaxModal_' + Date.now() + '_' + (++modalSequence);
        const titleId = modalId + '_title';

        if (!modalUrl || modalUrl === '#') {
            console.warn('ajax-modal: No modal URL found on trigger:', trigger);
            return;
        }

        const host = document.querySelector('.app-main') || document.body;
        const wrapper = document.createElement('div');
        wrapper.className = 'modal fade';
        wrapper.id = modalId;
        wrapper.tabIndex = -1;

        const dialog = document.createElement('div');
        dialog.className = 'modal-dialog modal-' + modalSize;

        const content = document.createElement('div');
        content.className = 'modal-content';
        dialog.appendChild(content);
        wrapper.appendChild(dialog);
        host.appendChild(wrapper);
        activeModals.set(trigger, wrapper);

        let controller = null;

        function setTriggerBusy(busy) {
            if (busy) {
                actionFeedback().setPending(trigger, trigger.dataset.modalBusyLabel || 'Loading…');
            } else {
                actionFeedback().clearPending(trigger);
            }
        }

        function loadModal() {
            if (controller) {
                controller.abort();
            }
            controller = typeof AbortController === 'undefined' ? null : new AbortController();
            renderLoading(wrapper, content, titleId);
            setTriggerBusy(true);

            const options = {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            };
            if (controller) {
                options.signal = controller.signal;
            }

            fetch(modalUrl, options)
                .then(function (res) {
                    if (!res.ok) {
                        throw responseError(res.status);
                    }
                    return res.json();
                })
                .then(function (response) {
                    if (response.error) {
                        throw serverError(response.error);
                    }
                    if (typeof response.content !== 'string') {
                        throw serverError('The server returned an incomplete panel.');
                    }

                    wrapper.removeAttribute('aria-busy');
                    content.innerHTML = response.content;
                    labelLoadedModal(wrapper, modalId);
                    if (typeof itflowNormalizeModalControls === 'function') {
                        itflowNormalizeModalControls(wrapper);
                    }

                    // innerHTML does not execute <script> tags, so they have to
                    // be re-injected in source order. Global helpers already on
                    // the page are skipped by runScriptsInOrder().
                    return runScriptsInOrder(Array.from(wrapper.querySelectorAll('script')))
                        .then(function () {
                            setTriggerBusy(false);
                            focusModalContent(wrapper);
                        });
                })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }
                    setTriggerBusy(false);
                    renderFailure(wrapper, content, titleId, error, loadModal);
                    console.error('Modal AJAX Error:', error);
                });
        }

        wrapper.addEventListener('hidden.bs.modal', function () {
            if (controller) {
                controller.abort();
            }
            setTriggerBusy(false);
            activeModals.delete(trigger);
            wrapper.remove();
            focusWhenAvailable(returnFocusTarget(trigger));
        }, { once: true });

        renderLoading(wrapper, content, titleId);
        bootstrap.Modal.getOrCreateInstance(wrapper).show();
        loadModal();
    });
})();
