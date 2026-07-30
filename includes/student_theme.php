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
        meta.setAttribute('content', dark ? '#0f1014' : '#fafafa');
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
<button type="button" id="trytestThemeToggle" class="tt-dash-icon-btn flex h-9 w-9 shrink-0 items-center justify-center rounded-full sm:h-10 sm:w-10" aria-label="Toggle dark mode" title="Dark mode" aria-pressed="false">
    <i class="fa-solid fa-moon text-[14px]" id="trytestThemeIconMoon" aria-hidden="true"></i>
    <i class="fa-solid fa-sun text-[14px] hidden" id="trytestThemeIconSun" aria-hidden="true"></i>
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
            meta.setAttribute('content', isDark() ? '#0f1014' : '#fafafa');
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

/**
 * Viewport meta value: disable pinch / user zoom on student-facing pages (same intent as quiz.php).
 */
function trytest_student_locked_viewport_content(): string
{
    return 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, shrink-to-fit=no, viewport-fit=cover, user-scalable=no';
}

/** Root class for zoom-lock CSS (student app, not admin). */
function trytest_student_zoom_lock_html_class(): string
{
    return 'student-app-no-zoom';
}

function trytest_student_zoom_lock_styles(): void
{
    ?>
<style>
html.student-app-no-zoom,
html.student-app-no-zoom body {
    touch-action: manipulation;
    -webkit-text-size-adjust: 100%;
    text-size-adjust: 100%;
}
html.student-app-no-zoom input[type='text'],
html.student-app-no-zoom input[type='search'],
html.student-app-no-zoom input[type='email'],
html.student-app-no-zoom input[type='tel'],
html.student-app-no-zoom input[type='number'],
html.student-app-no-zoom input[type='password'],
html.student-app-no-zoom select,
html.student-app-no-zoom textarea {
    font-size: 16px !important;
}
</style>
<?php
}

function trytest_student_zoom_lock_gesture_script(): void
{
    ?>
<script>
(function () {
    function blockGesture(e) {
        e.preventDefault();
    }
    document.addEventListener('gesturestart', blockGesture, { passive: false });
    document.addEventListener('gesturechange', blockGesture, { passive: false });
    document.addEventListener('gestureend', blockGesture, { passive: false });
})();
</script>
<?php
}
