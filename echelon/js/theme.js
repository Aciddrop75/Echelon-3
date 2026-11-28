/* Echelon 3 - theme mode: light | dark | system
   Loaded in <head> (before the stylesheet paints) so the page never flashes the wrong
   theme. The choice is kept in localStorage ("ech-theme"); "system" follows the OS
   setting live. The resolved theme is written to <html data-theme="light|dark">. */
(function () {
    'use strict';
    var KEY = 'ech-theme', root = document.documentElement;
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function stored() {
        try { var v = localStorage.getItem(KEY); return (v === 'light' || v === 'dark') ? v : 'system'; }
        catch (e) { return 'system'; }
    }
    function resolve(mode) {
        if (mode === 'system') { return (mq && mq.matches) ? 'dark' : 'light'; }
        return mode;
    }
    function apply(mode) {
        var t = resolve(mode);
        root.setAttribute('data-theme', t);
        root.setAttribute('data-theme-mode', mode);
        var m = document.querySelector('meta[name="color-scheme"]');
        if (m) { m.setAttribute('content', t); }
        document.dispatchEvent(new CustomEvent('ech:theme', { detail: { mode: mode, theme: t } }));
    }
    function paint(mode) {
        var btns = document.querySelectorAll('.theme-switch [data-theme-set]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].setAttribute('aria-pressed', btns[i].getAttribute('data-theme-set') === mode ? 'true' : 'false');
        }
    }

    // 1. as early as possible: no flash
    root.classList.add('theme-boot');
    apply(stored());

    // 2. follow the OS while in "system" mode
    var onOs = function () { if (stored() === 'system') { apply('system'); } };
    if (mq) { if (mq.addEventListener) { mq.addEventListener('change', onOs); } else if (mq.addListener) { mq.addListener(onOs); } }

    // 3. keep several open tabs in step
    window.addEventListener('storage', function (e) { if (e.key === KEY) { var m = stored(); apply(m); paint(m); } });

    // 4. switcher buttons
    document.addEventListener('DOMContentLoaded', function () {
        paint(stored());
        document.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-theme-set]') : null;
            if (!b) { return; }
            var mode = b.getAttribute('data-theme-set');
            try { if (mode === 'system') { localStorage.removeItem(KEY); } else { localStorage.setItem(KEY, mode); } } catch (err) {}
            apply(mode); paint(mode);
        });
        setTimeout(function () { root.classList.remove('theme-boot'); }, 60);
    });

    window.EchTheme = { mode: stored, theme: function () { return root.getAttribute('data-theme'); } };
})();
