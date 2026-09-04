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

    document.querySelectorAll('.n45-portal-route .table').forEach(function (table) {
        var tableBody = table.querySelector('tbody');
        if (tableBody && tableBody.children.length === 0) {
            var emptyRow = document.createElement('tr');
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = Math.max(table.querySelectorAll('thead th').length, 1);
            emptyCell.innerHTML = '<div class="n45-table-empty"><i class="far fa-folder-open" aria-hidden="true"></i><div><strong>Nothing to show here yet</strong><span>New items will appear here when they are available.</span></div></div>';
            emptyRow.appendChild(emptyCell);
            tableBody.appendChild(emptyRow);
        }

        if (table.parentElement && table.parentElement.classList.contains('n45-table-scroll')) {
            return;
        }

        var wrapper = document.createElement('div');
        wrapper.className = 'n45-table-scroll';
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-label', 'Scrollable data table');
        wrapper.setAttribute('tabindex', '0');
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
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
