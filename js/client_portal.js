(function () {
    'use strict';

    var body = document.body;
    var sidebar = document.getElementById('clientPortalSidebar');
    var menuButton = document.querySelector('.n45-portal-menu-button');
    var scrim = document.querySelector('.n45-portal-scrim');
    var stage = document.querySelector('.n45-portal-stage');

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
            sidebar.removeAttribute('aria-hidden');
            stage.setAttribute('inert', '');
            var firstLink = sidebar.querySelector('a, summary');
            if (firstLink) {
                firstLink.focus();
            }
        } else {
            stage.removeAttribute('inert');
            if (isMobileLayout()) {
                sidebar.setAttribute('aria-hidden', 'true');
            } else {
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
        if (event.key === 'Escape' && body.classList.contains('n45-portal-menu-open')) {
            setMenuOpen(false);
            menuButton.focus();
        }
    });

    window.addEventListener('resize', function () {
        setMenuOpen(false);
    });
}());
