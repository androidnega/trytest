<?php

declare(strict_types=1);

/**
 * Student UI dark mode (Tailwind `class` strategy).
 * Preference: localStorage trytest_student_theme ("dark"|"light") else prefers-color-scheme.
 */

function trytest_student_theme_head_early(): void
{
    ?>
<meta name="color-scheme" content="light dark">
<script>
(function () {
    try {
        var k = 'trytest_student_theme';
        var v = localStorage.getItem(k);
        var dark = false;
        if (v === 'dark') {
            dark = true;
        } else if (v === 'light') {
            dark = false;
        } else {
            dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        }
        var r = document.documentElement;
        if (dark) {
            r.classList.add('dark');
        } else {
            r.classList.remove('dark');
        }
        var meta = document.getElementById('trytest-theme-color');
        if (!meta) {
            meta = document.createElement('meta');
            meta.id = 'trytest-theme-color';
            meta.name = 'theme-color';
            document.head.appendChild(meta);
        }
        meta.setAttribute('content', dark ? '#09090b' : '#fafafa');
    } catch (e) {}
})();
</script>
<?php
}

function trytest_student_theme_tailwind_config_script(): void
{
    echo '<script>tailwind.config={darkMode:\'class\'};</script>' . "\n";
}

function trytest_student_theme_toggle_button(): void
{
    ?>
<button type="button" id="trytestThemeToggle" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-700 shadow-sm ring-1 ring-zinc-200/80 hover:bg-zinc-200/90 active:bg-zinc-300 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-600/80 dark:hover:bg-zinc-700 dark:active:bg-zinc-600" aria-label="Toggle dark mode" title="Dark mode">
    <i class="fa-solid fa-moon text-[15px]" id="trytestThemeIconMoon" aria-hidden="true"></i>
    <i class="fa-solid fa-sun text-[15px] hidden" id="trytestThemeIconSun" aria-hidden="true"></i>
</button>
<?php
}

function trytest_student_theme_controller_script(): void
{
    ?>
<script>
(function () {
    var k = 'trytest_student_theme';
    function isDark() {
        return document.documentElement.classList.contains('dark');
    }
    function syncChrome() {
        var meta = document.getElementById('trytest-theme-color');
        if (meta) {
            meta.setAttribute('content', isDark() ? '#09090b' : '#fafafa');
        }
        var moon = document.getElementById('trytestThemeIconMoon');
        var sun = document.getElementById('trytestThemeIconSun');
        var d = isDark();
        if (moon) moon.classList.toggle('hidden', d);
        if (sun) sun.classList.toggle('hidden', !d);
        var btn = document.getElementById('trytestThemeToggle');
        if (btn) btn.setAttribute('aria-pressed', d ? 'true' : 'false');
    }
    function setDark(on) {
        document.documentElement.classList.toggle('dark', on);
        try {
            localStorage.setItem(k, on ? 'dark' : 'light');
        } catch (e) {}
        syncChrome();
    }
    document.getElementById('trytestThemeToggle')?.addEventListener('click', function () {
        setDark(!isDark());
    });
    try {
        window.addEventListener('storage', function (ev) {
            if (ev.key !== k || ev.newValue === null) return;
            setDark(ev.newValue === 'dark');
        });
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (ev) {
            try {
                var fixed = localStorage.getItem(k);
                if (fixed === 'dark' || fixed === 'light') return;
                setDark(ev.matches);
            } catch (x) {}
        });
    } catch (e) {}
    syncChrome();
})();
</script>
<?php
}
