(function () {
    'use strict';

    var STORAGE_KEY = 'n45-sidebar-density';
    var DEFAULT_DENSITY = 'compact';

    function normalizeDensity(value) {
        return value === 'comfortable' ? 'comfortable' : DEFAULT_DENSITY;
    }

    function storedDensity() {
        try {
            return normalizeDensity(window.localStorage.getItem(STORAGE_KEY));
        } catch (error) {
            return DEFAULT_DENSITY;
        }
    }

    function syncControls(density) {
        document.querySelectorAll('[data-n45-sidebar-density-option]').forEach(function (control) {
            var selected = control.getAttribute('data-n45-sidebar-density-option') === density;
            control.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
    }

    function applyDensity(value, persist) {
        var density = normalizeDensity(value);
        document.documentElement.setAttribute('data-n45-sidebar-density', density);

        if (persist) {
            try {
                window.localStorage.setItem(STORAGE_KEY, density);
            } catch (error) {
                // Storage can be unavailable in privacy modes; the current page still updates.
            }
        }

        syncControls(density);
    }

    applyDensity(storedDensity(), false);

    function bindDensityControls() {
        syncControls(normalizeDensity(document.documentElement.getAttribute('data-n45-sidebar-density')));

        document.addEventListener('click', function (event) {
            var control = event.target.closest('[data-n45-sidebar-density-option]');
            if (!control) {
                return;
            }

            applyDensity(control.getAttribute('data-n45-sidebar-density-option'), true);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindDensityControls);
    } else {
        bindDensityControls();
    }

    window.addEventListener('storage', function (event) {
        if (event.key === STORAGE_KEY) {
            applyDensity(event.newValue, false);
        }
    });
}());
