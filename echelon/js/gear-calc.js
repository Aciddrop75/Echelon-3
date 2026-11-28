/* Map editor: gear calculator. Ticked = allowed. g_gear holds the letters of what is NOT allowed ("0" = everything). */
(function () {
    'use strict';
    var box = document.getElementById('gear-calc');
    if (!box) { return; }
    var input = document.getElementById(box.getAttribute('data-target'));
    var boxes = Array.prototype.slice.call(box.querySelectorAll('input[data-letter]'));
    var known = boxes.map(function (b) { return b.getAttribute('data-letter'); });
    var extraEl = document.getElementById('gear-extra'), countEl = document.getElementById('gear-count');

    var SNIPERS = ['N', 'Z'];
    function lettersOf(group) {
        return boxes.filter(function (b) { return b.getAttribute('data-group') === group; }).map(function (b) { return b.getAttribute('data-letter'); });
    }
    function groupByIndex(i) { var seen = []; boxes.forEach(function (b) { var g = b.getAttribute('data-group'); if (seen.indexOf(g) < 0) { seen.push(g); } }); return seen[i]; }
    var weapons = lettersOf(groupByIndex(1)), grenades = lettersOf(groupByIndex(2));

    function parse(v) {
        v = (v || '').trim();
        return (v === '' || v === '0') ? [] : v.split('');
    }
    // letters in the text field that the calculator does not know (newer items): kept as they are
    function extras(v) { return parse(v).filter(function (c) { return known.indexOf(c) < 0; }); }

    function fromText() {
        var off = parse(input.value);
        boxes.forEach(function (b) { b.checked = off.indexOf(b.getAttribute('data-letter')) < 0; });
        refresh();
    }
    function toText() {
        var off = boxes.filter(function (b) { return !b.checked; }).map(function (b) { return b.getAttribute('data-letter'); });
        var all = off.concat(extras(input.value));
        input.value = all.length ? all.join('') : '0';
        refresh();
    }
    function refresh() {
        var allowed = boxes.filter(function (b) { return b.checked; }).length;
        countEl.textContent = allowed + ' of ' + boxes.length + ' allowed';
        var ex = extras(input.value);
        extraEl.hidden = !ex.length;
        extraEl.textContent = ex.length ? 'Other letters kept as typed: ' + ex.join('') : '';
        boxes.forEach(function (b) { b.parentNode.classList.toggle('off', !b.checked); });
    }
    function preset(name) {
        var off = [];
        if (name === 'pistols') { off = weapons.concat(grenades); }
        if (name === 'snipers') { off = weapons.filter(function (l) { return SNIPERS.indexOf(l) < 0; }).concat(grenades); }
        boxes.forEach(function (b) { b.checked = off.indexOf(b.getAttribute('data-letter')) < 0; });
        input.value = off.length ? off.join('') : '0';
        refresh();
    }

    box.addEventListener('change', function (e) { if (e.target.matches('input[data-letter]')) { toText(); } });
    box.addEventListener('click', function (e) {
        var b = e.target.closest('[data-preset]');
        if (b) { preset(b.getAttribute('data-preset')); }
    });
    input.addEventListener('input', fromText);
    fromText();
})();
