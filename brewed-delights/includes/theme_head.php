<script>
(function () {
    try {
        var savedTheme = localStorage.getItem('brewed-delights-theme');
        var systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        var theme = savedTheme === 'dark' || (savedTheme !== 'light' && systemPrefersDark) ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.style.colorScheme = theme;
    } catch (error) {
        document.documentElement.setAttribute('data-theme', 'light');
        document.documentElement.style.colorScheme = 'light';
    }
})();
</script>
