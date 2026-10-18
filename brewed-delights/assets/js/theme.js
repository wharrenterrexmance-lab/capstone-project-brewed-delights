(function () {
    'use strict';

    var storageKey = 'brewed-delights-theme';
    var root = document.documentElement;

    function applyTheme(theme, persist) {
        var dark = theme === 'dark';
        root.setAttribute('data-theme', dark ? 'dark' : 'light');
        root.style.colorScheme = dark ? 'dark' : 'light';
        if (document.body) document.body.setAttribute('data-theme', dark ? 'dark' : 'light');
        document.querySelectorAll('[data-theme-toggle]').forEach(function (toggle) {
            toggle.checked = dark;
        });
        if (persist) {
            try {
                localStorage.setItem(storageKey, dark ? 'dark' : 'light');
            } catch (error) {
                // Theme still applies for this page when storage is unavailable.
            }
        }
    }

    function initializeThemeControls() {
        var theme = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        applyTheme(theme, false);
        document.addEventListener('change', function (event) {
            if (event.target.matches('[data-theme-toggle]')) {
                applyTheme(event.target.checked ? 'dark' : 'light', true);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeThemeControls, { once: true });
    } else {
        initializeThemeControls();
    }
})();
