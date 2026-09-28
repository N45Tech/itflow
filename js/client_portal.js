(function () {
    'use strict';

    var body = document.body;
    var sidebar = document.getElementById('clientPortalSidebar');
    var menuButton = document.querySelector('.n45-portal-menu-button');
    var scrim = document.querySelector('.n45-portal-scrim');
    var stage = document.querySelector('.n45-portal-stage');

    // Portal pages do not load the technician app initializer.
    function labelModalExits(root) {
        root.querySelectorAll('.modal-header button.close, .modal-header button.btn-close').forEach(function (button) {
            button.type = 'button';
            button.setAttribute('data-bs-dismiss', 'modal');
            if (!button.getAttribute('aria-label')) button.setAttribute('aria-label', 'Close dialog');
        });
    }
    labelModalExits(document);
    document.addEventListener('show.bs.modal', function (event) { labelModalExits(event.target); });

    // The portal's self-only Content Security Policy blocks inline scripts.
    // Initialize only on pages with an editor so ordinary portal routes stay quiet.
    if (document.querySelector('.tinymce') && window.tinymce) {
        window.tinymce.init({
            selector: '.tinymce',
            browser_spellcheck: true,
            resize: true,
            min_height: 300,
            max_height: 600,
            promotion: false,
            branding: false,
            menubar: false,
            statusbar: false,
            license_key: 'gpl',
            toolbar: [
                {name: 'styles', items: ['styles']},
                {name: 'formatting', items: ['bold', 'italic', 'forecolor']},
                {name: 'lists', items: ['bullist', 'numlist']},
                {name: 'alignment', items: ['alignleft', 'aligncenter', 'alignright', 'alignjustify']},
                {name: 'indentation', items: ['outdent', 'indent']},
                {name: 'table', items: ['table']},
                {name: 'extra', items: ['fullscreen']}
            ],
            mobile: {
                menubar: false,
                plugins: 'autosave lists autolink',
                toolbar: 'undo bold italic styles'
            },
            plugins: 'link image lists table code codesample fullscreen autoresize'
        });
    }

    document.querySelectorAll('.n45-portal-route .table').forEach(function (table) {
        var tableBody = table.querySelector('tbody');
        var emptyState = null;
        if (tableBody && tableBody.children.length === 0) {
            // An empty row inherits the table's desktop minimum width and clips
            // the message on phones. Keep the empty message outside the table.
            emptyState = document.createElement('div');
            emptyState.className = 'n45-table-empty';
            emptyState.setAttribute('role', 'status');
            emptyState.innerHTML = '<i class="far fa-folder-open" aria-hidden="true"></i><div><strong>Nothing to show here yet</strong><span>New items will appear here when they are available.</span></div>';
            table.hidden = true;
        }

        if (table.parentElement && table.parentElement.classList.contains('n45-table-scroll')) {
            if (emptyState) table.parentElement.appendChild(emptyState);
            return;
        }

        var wrapper = document.createElement('div');
        wrapper.className = 'n45-table-scroll';
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-label', 'Scrollable data table');
        wrapper.setAttribute('tabindex', '0');
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
        if (emptyState) wrapper.appendChild(emptyState);
    });

    if (!body || !sidebar || !menuButton || !scrim || !stage) {
        return;
    }

    function isMobileLayout() {
        return window.matchMedia('(max-width: 1023.98px)').matches;
    }

    function setMenuOpen(isOpen) {
        body.classList.toggle('n45-portal-menu-open', isOpen);
        menuButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        menuButton.querySelector('.sr-only').textContent = isOpen ? 'Close navigation' : 'Open navigation';
        scrim.setAttribute('tabindex', isOpen ? '0' : '-1');

        if (isOpen) {
            sidebar.removeAttribute('inert');
            sidebar.removeAttribute('aria-hidden');
            stage.setAttribute('inert', '');
            var firstLink = sidebar.querySelector('a, summary');
            if (firstLink) {
                firstLink.focus();
            }
        } else {
            stage.removeAttribute('inert');
            if (isMobileLayout()) {
                sidebar.setAttribute('inert', '');
                sidebar.setAttribute('aria-hidden', 'true');
            } else {
                sidebar.removeAttribute('inert');
                sidebar.removeAttribute('aria-hidden');
            }
        }
    }

    setMenuOpen(false);

    menuButton.addEventListener('click', function () {
        setMenuOpen(!body.classList.contains('n45-portal-menu-open'));
    });

    scrim.addEventListener('click', function () {
        setMenuOpen(false);
        menuButton.focus();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Tab' && body.classList.contains('n45-portal-menu-open')) {
            var focusable = Array.from(sidebar.querySelectorAll('a[href], summary, button:not([disabled]), [tabindex="0"]'))
                .filter(function (element) { return element.getClientRects().length > 0; });
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (first && event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (last && !event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
        if (event.key === 'Escape' && body.classList.contains('n45-portal-menu-open')) {
            setMenuOpen(false);
            menuButton.focus();
        }
    });

    window.addEventListener('resize', function () {
        setMenuOpen(false);
    });
}());
