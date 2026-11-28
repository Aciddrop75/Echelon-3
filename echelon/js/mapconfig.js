/* Map Config tile board: filter, sort, group by game type, and the mapcycle builder.
 * All maps are loaded once (see #mc-data) and the board is re-rendered in the browser, so filtering is instant
 * and costs the server nothing. Needs jQuery-free code only; relies on window.ECH from the footer. */
(function () {
    'use strict';

    var dataEl = document.getElementById('mc-data');
    if (!dataEl) { return; }
    var D = JSON.parse(dataEl.textContent);
    var MAPS = D.maps, MODES = D.modes, NEVER = '2000-01-01';
    var MODE_KEYS = Object.keys(MODES);
    var NONE = '_none';

    var $ = function (id) { return document.getElementById(id); };
    var board = $('mc-board'), chipsBox = $('mc-chips'), qInput = $('mc-q'), sortSel = $('mc-sort'),
        srcSel = $('mc-src'), countEl = $('mc-count'), emptyEl = $('mc-empty'), emptyTxt = $('mc-empty-text'),
        cycle = $('mc-cycle'), drawer = $('mc-drawer');

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function store(key, val) {
        try { if (val === null) { sessionStorage.removeItem(key); } else { sessionStorage.setItem(key, val); } } catch (e) { /* private mode */ }
    }
    function load(key) { try { return sessionStorage.getItem(key); } catch (e) { return null; } }

    /* ------------------------------------------------------------ state (kept in the URL so it can be shared) */
    var state = { q: '', sort: 'name', modes: [], match: 'any', src: 'all' };
    (function readUrl() {
        var p = new URLSearchParams(location.search);
        state.q = p.get('q') || '';
        if (sortSel.querySelector('option[value="' + (p.get('sort') || '') + '"]')) { state.sort = p.get('sort'); }
        state.modes = (p.get('modes') || '').split(',').filter(function (m) { return m === NONE || MODE_KEYS.indexOf(m) > -1 || /^[a-z0-9_]{1,12}$/.test(m); }).filter(Boolean);
        state.match = p.get('match') === 'all' ? 'all' : 'any';
        var sv = p.get('src'); state.src = (sv === 'config' || sv === 'found') ? sv : 'all';
        qInput.value = state.q;
        sortSel.value = state.sort;
    })();
    function writeUrl() {
        var p = new URLSearchParams();
        if (state.q) { p.set('q', state.q); }
        if (state.sort !== 'name') { p.set('sort', state.sort); }
        if (state.modes.length) { p.set('modes', state.modes.join(',')); }
        if (state.match === 'all' && state.modes.length > 1) { p.set('match', 'all'); }
        if (state.src !== 'all') { p.set('src', state.src); }
        var qs = p.toString();
        history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
    }

    /* ------------------------------------------------------------ helpers */
    // mode codes present in the data that this site does not know a label for (custom B3 modes)
    var extra = [];
    MAPS.forEach(function (m) { m.modes.forEach(function (c) { if (!MODES[c] && extra.indexOf(c) < 0) { extra.push(c); } }); });
    extra.sort();
    var ALL_MODES = MODE_KEYS.concat(extra);
    function modeLabel(c) { return MODES[c] || c.toUpperCase(); }
    var COLORS = D.colors || {};
    function modeColor(c) { return COLORS[c] || (MODES[c] ? 'var(--m-' + c + ', #868e96)' : '#868e96'); }

    function prettyName(n) { return n.replace(/^ut4_/i, '').replace(/_/g, ' ').replace(/\b\w/g, function (x) { return x.toUpperCase(); }); }

    var today = new Date(); today.setHours(0, 0, 0, 0);
    function usedText(iso) {
        if (!iso || iso <= NEVER) { return 'Never used'; }
        var p = iso.split('-'), d = new Date(+p[0], +p[1] - 1, +p[2]);
        var days = Math.round((today - d) / 86400000);
        if (isNaN(days)) { return iso; }
        if (days <= 0) { return 'Used today'; }
        if (days === 1) { return 'Used yesterday'; }
        if (days < 60) { return 'Used ' + days + ' days ago'; }
        if (days < 700) { return 'Used ' + Math.round(days / 30.4) + ' months ago'; }
        return 'Used ' + Math.round(days / 365) + ' years ago';
    }

    function matchesText(m) {
        if (!state.q) { return true; }
        var hay = (m.name + ' ' + prettyName(m.name) + ' ' + m.msg + ' ' + m.modes.join(' ')).toLowerCase();
        var terms = state.q.toLowerCase().split(/\s+/).filter(Boolean);
        for (var i = 0; i < terms.length; i++) { if (hay.indexOf(terms[i]) < 0) { return false; } }
        return true;
    }
    function matchesModes(m) {
        if (!state.modes.length) { return true; }
        var has = function (c) { return c === NONE ? m.modes.length === 0 : m.modes.indexOf(c) > -1; };
        return state.match === 'all' ? state.modes.every(has) : state.modes.some(has);
    }
    function matchesSrc(m) { return state.src === 'all' || (state.src === 'found' ? !!m.found : !m.found); }
    function matches(m) { return matchesText(m) && matchesModes(m) && matchesSrc(m); }

    function cmp(a, b) { return a < b ? -1 : (a > b ? 1 : 0); }
    var sorters = {
        'name':      function (a, b) { return cmp(a.name.toLowerCase(), b.name.toLowerCase()); },
        'name-desc': function (a, b) { return cmp(b.name.toLowerCase(), a.name.toLowerCase()); },
        'used-old':  function (a, b) { return cmp(a.last, b.last) || cmp(a.name.toLowerCase(), b.name.toLowerCase()); },
        'used-new':  function (a, b) { return cmp(b.last, a.last) || cmp(a.name.toLowerCase(), b.name.toLowerCase()); },
        'id-new':    function (a, b) { return b.id - a.id; }
    };

    /* ------------------------------------------------------------ rendering */
    var queued = {}; // mapname -> times it is in the textarea

    function tile(m) {
        var pretty = prettyName(m.name);
        var prefix = /^ut4_/i.test(m.name) ? m.name.slice(0, 4) : '';
        var rest = prefix ? m.name.slice(4) : m.name;
        var q = queued[m.name] || 0;
        var pills = m.modes.map(function (c) {
            var on = state.modes.indexOf(c) > -1 ? ' on' : '';
            return '<span class="mc-pill' + on + '" style="--c:' + modeColor(c) + '" title="' + esc(modeLabel(c)) + '">' + esc(c) + '</span>';
        }).join('');
        var thumb = m.img
            ? '<img src="' + esc(m.img) + '" alt="" loading="lazy" decoding="async">'
            : '<div class="mc-ph"><div><b>' + esc(pretty || m.name) + '</b><i>No picture</i></div></div>';
        var href = m.found ? 'mapconfigdetails.php?new=1&mapname=' + encodeURIComponent(m.name) + '&modes=' + encodeURIComponent(m.modes.join(',')) : 'mapconfigdetails.php?id=' + m.id;
        return '<article class="mc-tile' + (q ? ' is-queued' : '') + (m.found ? ' is-found' : '') + '" data-id="' + m.id + '">' +
            '<a class="mc-link" href="' + href + '" aria-label="' + (m.found ? 'Add ' : 'Edit settings for ') + esc(m.name) + (m.found ? ' to Map Config' : '') + '">' +
            '<div class="mc-thumb" style="--h:' + m.hue + '">' + thumb + '<div class="mc-shade"></div>' +
            (m.bot ? '<span class="mc-badge" title="Bots are enabled on this map"><i class="fas fa-robot"></i>BOTS</span>' : '') +
            (m.found ? '<span class="mc-badge mc-new" title="Found in your game files; not in Map Config yet. Click to add it' + (m.src ? ' (game types from the ' + m.src + ')' : ' (no .arena file or readme listed its game types)') + '"><i class="fas fa-folder-open"></i>NOT ADDED</span>' : '') +
            (m.nf ? '<span class="mc-badge mc-nf" title="This map is not in your game files folder"><i class="fas fa-exclamation-triangle"></i>NO FILE</span>' : '') +
            '<div class="mc-modes">' + pills + '</div></div>' +
            '<div class="mc-body"><div class="mc-name" title="' + esc(m.name) + '"><span>' + esc(prefix) + '</span>' + esc(rest) + '</div>' +
            '<div class="mc-meta"><span title="Time limit"><i class="far fa-clock"></i>' + m.tl + ' min</span>' +
            '<span class="mc-used" title="' + esc(m.last) + '"><i class="far fa-calendar"></i>' + esc(usedText(m.last)) + '</span></div></div></a>' +
            '<button type="button" class="mc-add" data-add="' + m.id + '" title="' + (q ? 'In mapcycle ' + q + 'x - add again' : 'Add to mapcycle') + '" aria-label="Add ' + esc(m.name) + ' to the mapcycle">' +
            '<i class="fas ' + (q ? 'fa-check' : 'fa-plus') + '"></i></button></article>';
    }

    function grid(list, withNew) {
        var h = '<div class="mc-grid">';
        if (withNew) {
            h += '<a class="mc-tile mc-tile-new" href="mapconfigdetails.php?new=1"><span><i class="fas fa-plus"></i>Add a map</span></a>';
        }
        for (var i = 0; i < list.length; i++) { h += tile(list[i]); }
        return h + '</div>';
    }

    function render() {
        var list = MAPS.filter(matches);
        var html = '';

        if (state.sort === 'mode') {
            // one section per game type; a map shows up in each type it belongs to
            var order = ALL_MODES.slice().concat([NONE]);
            var shown = 0;
            order.forEach(function (c) {
                if (state.modes.length && state.modes.indexOf(c) < 0) { return; }
                var inSec = list.filter(function (m) { return c === NONE ? m.modes.length === 0 : m.modes.indexOf(c) > -1; })
                    .sort(sorters.name);
                if (!inSec.length) { return; }
                shown += inSec.length;
                html += '<section class="mc-section-wrap"><div class="mc-section" style="--c:' + (c === NONE ? '#adb5bd' : modeColor(c)) + '"><h2>' +
                    esc(c === NONE ? 'No game type set' : modeLabel(c)) + '</h2><small>' + inSec.length + ' map' + (inSec.length === 1 ? '' : 's') + '</small></div>' +
                    grid(inSec, false) + '</section>';
            });
            countEl.innerHTML = '<strong>' + list.length + '</strong> of ' + MAPS.length + ' maps';
        } else {
            list.sort(sorters[state.sort] || sorters.name);
            var filtered = state.q || state.modes.length;
            html = list.length || !filtered ? grid(list, !filtered) : '';
            countEl.innerHTML = '<strong>' + list.length + '</strong> of ' + MAPS.length + ' maps';
        }

        board.innerHTML = html;
        var none = !list.length;
        emptyEl.hidden = !none;
        if (none) {
            emptyTxt.textContent = MAPS.length ? 'No maps match your filters.' : 'There are no maps yet. Use "Add map" to create the first one.';
        }
        renderChips();
    }

    function renderChips() {
        var counts = {}; counts[NONE] = 0;
        ALL_MODES.forEach(function (c) { counts[c] = 0; });
        // counts respect the search box, so a chip shows how many maps it would add/keep
        MAPS.forEach(function (m) {
            if (!matchesText(m)) { return; }
            if (!m.modes.length) { counts[NONE]++; }
            m.modes.forEach(function (c) { counts[c]++; });
        });
        var h = '<span class="mc-chips-label">Game type</span>';
        ALL_MODES.forEach(function (c) {
            var on = state.modes.indexOf(c) > -1;
            h += '<button type="button" class="mc-chip" style="--c:' + modeColor(c) + '" data-mode="' + esc(c) + '" aria-pressed="' + on + '" title="' + esc(modeLabel(c)) + '"' +
                (!counts[c] && !on ? ' disabled' : '') + '>' + esc(c.toUpperCase()) + ' <span class="n">' + counts[c] + '</span></button>';
        });
        if (counts[NONE]) {
            var onN = state.modes.indexOf(NONE) > -1;
            h += '<button type="button" class="mc-chip" style="--c:#adb5bd" data-mode="' + NONE + '" aria-pressed="' + onN + '" title="Maps without any game type">None <span class="n">' + counts[NONE] + '</span></button>';
        }
        if (state.modes.length > 1) {
            h += '<span class="mc-matchmode" role="group" aria-label="Match"><button type="button" data-match="any" aria-pressed="' + (state.match === 'any') + '" title="Maps that are in at least one of the selected types">Any</button>' +
                '<button type="button" data-match="all" aria-pressed="' + (state.match === 'all') + '" title="Maps that are in every selected type">All</button></span>';
        }
        if (state.modes.length || state.q) {
            h += '<button type="button" class="mc-chip is-clear" data-clear="1"><i class="fas fa-times"></i> Clear filters</button>';
        }
        chipsBox.innerHTML = h;
    }

    /* ------------------------------------------------------------ toolbar events */
    var qTimer = null;
    qInput.addEventListener('input', function () {
        clearTimeout(qTimer);
        qTimer = setTimeout(function () { state.q = qInput.value.trim(); writeUrl(); render(); }, 120);
    });
    if (srcSel) { srcSel.value = state.src; srcSel.addEventListener('change', function () { state.src = srcSel.value; writeUrl(); render(); }); }
    sortSel.addEventListener('change', function () { state.sort = sortSel.value; writeUrl(); render(); });

    chipsBox.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) { return; }
        if (b.dataset.mode) {
            var i = state.modes.indexOf(b.dataset.mode);
            if (i > -1) { state.modes.splice(i, 1); } else { state.modes.push(b.dataset.mode); }
        } else if (b.dataset.match) {
            state.match = b.dataset.match;
        } else if (b.dataset.clear) {
            state.modes = []; state.q = ''; qInput.value = '';
        } else { return; }
        writeUrl(); render();
    });

    /* ------------------------------------------------------------ mapcycle builder */
    var drawerToggle = $('mc-drawer-toggle');
    function openDrawer(open) {
        drawer.classList.toggle('open', open);
        document.body.classList.toggle('drawer-open', open);
        drawerToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    drawerToggle.addEventListener('click', function () { openDrawer(!drawer.classList.contains('open')); });

    // names that are in the cycle (lines outside { } blocks)
    function parseQueued() {
        queued = {};
        var depth = 0, n = 0;
        cycle.value.split(/\r?\n/).forEach(function (line) {
            var t = line.trim();
            if (t === '') { return; }
            if (t.indexOf('{') === 0) { depth++; return; }
            if (t.indexOf('}') === 0) { depth = Math.max(0, depth - 1); return; }
            if (depth === 0 && t.charAt(0) !== '/' && t.charAt(0) !== '#') { queued[t] = (queued[t] || 0) + 1; n++; }
        });
        $('mc-queued').textContent = n;
        return n;
    }
    function cycleChanged(rerender) {
        store('ech.mapcycle', cycle.value === '' ? null : cycle.value);
        parseQueued();
        if (rerender) { render(); }
    }
    var cTimer = null;
    cycle.addEventListener('input', function () { clearTimeout(cTimer); cTimer = setTimeout(function () { cycleChanged(true); }, 300); });

    var saved = load('ech.mapcycle');
    if (saved) { cycle.value = saved; openDrawer(false); }
    parseQueued();

    // which mapcycle file the buttons use (game type links set up under Settings > Maps & World Map)
    var target = $('mc-target');
    function slot() { return target ? target.value : 'default'; }
    if (target) {
        var wanted = load('ech.mapcycle.slot');
        if (wanted && Array.prototype.some.call(target.options, function (o) { return o.value === wanted; })) { target.value = wanted; }
        target.addEventListener('change', function () { store('ech.mapcycle.slot', target.value); hint('Using the ' + target.options[target.selectedIndex].text + ' file. Press "Load current" to see what is in it.'); });
    }

    function hint(msg, bad) {
        var h = $('mc-cycle-hint');
        h.textContent = msg;
        h.style.color = bad ? '#ff8787' : '';
    }

    function post(url, fields) {
        var body = new URLSearchParams(fields);
        return fetch(window.ECH.path + url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-CSRF-Token': window.ECH.csrf, 'Accept': 'application/json' },
            body: body
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'The server sent an unexpected answer (HTTP ' + r.status + ').' }; });
        });
    }

    function addToCycle(m) {
        var t = cycle.value.replace(/\s+$/, '');
        if (t !== '') { t += '\n'; }
        if ($('mc-withconfig').checked) {
            t += m.name + '\n{\n' +
                '    capturelimit "' + m.cl + '"\n' +
                '    g_suddendeath "' + m.sd + '"\n' +
                '    g_gear "' + m.ge + '"\n' +
                '    g_gravity "' + m.gr + '"\n' +
                '    g_friendlyfire "' + m.ff + '"\n' +
                '    timelimit "' + m.tl + '"\n}\n';
        } else {
            t += m.name + '\n';
        }
        cycle.value = t;
        cycle.scrollTop = cycle.scrollHeight;
        cycleChanged(false);
        openDrawer(true);

        // stamp "last added" on the server, then refresh just this tile's text
        if (m.id < 0) { render(); return; } // not in Map Config yet: nothing to stamp
        post(D.endpoints.stamp, { id: m.id }).then(function (r) {
            if (!r.ok) { hint(r.error || 'Could not update the "last used" date.', true); return; }
            m.last = r.date;
            render();
        }).catch(function () { hint('Could not reach the server to update the "last used" date.', true); });
        render();
    }

    board.addEventListener('click', function (e) {
        var b = e.target.closest('[data-add]');
        if (!b) { return; }
        e.preventDefault();
        var id = +b.dataset.add;
        for (var i = 0; i < MAPS.length; i++) { if (MAPS[i].id === id) { addToCycle(MAPS[i]); break; } }
    });

    $('mc-clear').addEventListener('click', function () {
        if (cycle.value.trim() !== '' && !window.confirm('Clear the mapcycle text? This does not change the file on the server.')) { return; }
        cycle.value = ''; cycleChanged(true); hint('Cleared.');
    });

    $('mc-load').addEventListener('click', function () {
        if (cycle.value.trim() !== '' && !window.confirm('Replace the text below with the current mapcycle file?')) { return; }
        hint('Loading...');
        post(D.endpoints.cycle, { t: 'read', slot: slot() }).then(function (r) {
            if (r.ok) { return r.data; }
            if (!D.cycleUrl || slot() !== 'default') { throw new Error(r.error || 'No mapcycle file is chosen yet.'); }
            // the file is not readable by the web server: try the public URL instead
            return fetch(D.cycleUrl, { cache: 'no-store' }).then(function (x) {
                if (!x.ok) { throw new Error('HTTP ' + x.status); }
                return x.text();
            });
        }).then(function (text) {
            cycle.value = text; cycleChanged(true); openDrawer(true);
            hint('Loaded the current mapcycle.');
        }).catch(function (err) { hint('Could not load the mapcycle: ' + err.message, true); openDrawer(true); });
    });

    $('mc-write').addEventListener('click', function () {
        var text = cycle.value;
        if (text.trim() === '') { hint('There is nothing to write.', true); openDrawer(true); return; }
        var tl = target ? target.options[target.selectedIndex].text : 'mapcycle';
        if (!window.confirm('Overwrite the ' + tl + ' mapcycle file on the server with this text (' + parseQueued() + ' maps)?')) { return; }
        hint('Writing...');
        post(D.endpoints.cycle, { t: 'write', slot: slot(), data: text }).then(function (r) {
            if (r.ok) { store('ech.mapcycle', null); hint(r.message || 'The mapcycle file has been written.'); }
            else { hint(r.error || 'The file could not be written.', true); }
            openDrawer(true);
        }).catch(function () { hint('Could not reach the server.', true); });
    });

    /* ------------------------------------------------------------ pictures from map files (.pk3) */
    var picsBox = $('mc-pics');
    if (picsBox) {
        var picsText = $('mc-pics-text'), scanBtn = $('mc-pics-scan'), picsFile = $('mc-pics-file');
        var busy = function (on, msg) {
            Array.prototype.forEach.call(picsBox.querySelectorAll('button'), function (b) { b.disabled = on; });
            picsFile.disabled = on;
            if (msg) { picsText.textContent = msg; }
        };
        var done = function (r) {
            if (!r.ok) { busy(false, r.error || 'That did not work.'); return; }
            var n = (r.saved || []).length;
            var msg = n ? 'Added ' + n + ' picture' + (n === 1 ? '' : 's') + '.' : 'No new pictures were found.';
            if (r.files !== undefined) { msg += ' (' + r.files + ' map files checked)'; }
            if ((r.errors || []).length) { msg += ' ' + r.errors.length + ' problem(s): ' + r.errors.slice(0, 2).join(' '); }
            picsText.textContent = msg;
            if (n) { setTimeout(function () { location.reload(); }, 1200); } else { busy(false); }
        };
        if (scanBtn) {
            scanBtn.addEventListener('click', function () {
                busy(true, 'Looking through the map files...');
                post(D.endpoints.extract, { t: 'scan' }).then(done).catch(function () { busy(false, 'Could not reach the server.'); });
            });
        }
        var dirSave = $('mc-dir-save');
        if (dirSave) {
            Array.prototype.forEach.call(picsBox.querySelectorAll('.mc-dir-pick'), function (a) {
                a.addEventListener('click', function (e) { e.preventDefault(); $('mc-dir').value = a.dataset.path; });
            });
            dirSave.addEventListener('click', function () {
                if (!$('mc-dir').value.trim()) { picsText.textContent = 'Enter the folder first.'; return; }
                busy(true, 'Saving and looking through the map files...');
                post(D.endpoints.extract, { t: 'setdir', dirs: $('mc-dir').value, scan: '1' }).then(done).catch(function () { busy(false, 'Could not reach the server.'); });
            });
        }
        picsFile.addEventListener('change', function () {
            if (!picsFile.files[0]) { return; }
            busy(true, 'Uploading and reading ' + picsFile.files[0].name + '...');
            var fd = new FormData(); fd.append('t', 'upload'); fd.append('pk3', picsFile.files[0]);
            fetch(window.ECH.path + D.endpoints.extract, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.ECH.csrf, 'Accept': 'application/json' }, body: fd })
                .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The upload was refused by the server (HTTP ' + r.status + '). The file may be larger than the server allows.' }; }); })
                .then(done).catch(function () { busy(false, 'Could not reach the server.'); });
            picsFile.value = '';
        });
    }

    /* ------------------------------------------------------------ maps found in the game files */
    var fBox = $('mc-found');
    if (fBox && D.found && D.found.on && (D.found.new || D.found.fill)) {
        var fText = $('mc-found-text'), fAdd = $('mc-found-add'), fFill = $('mc-found-fill'), F = D.found;
        var plural = function (n, w) { return n + ' ' + w + (n === 1 ? '' : 's'); };
        var fSay = function () {
            var h = 'Your game files hold <strong>' + F.files + '</strong> maps; Map Config has ' + F.inConfig + '. ';
            if (F.new) { h += '<strong>' + plural(F.new, 'map') + '</strong> ' + (F.new === 1 ? 'is' : 'are') + ' listed below, marked NOT ADDED'; if (F.unknown) { h += ', ' + F.unknown + ' of them without a game type because no .arena file or readme listed one'; } h += '. '; }
            if (F.fill) { h += plural(F.fill, 'map') + ' in Map Config ' + (F.fill === 1 ? 'has' : 'have') + ' no game type, but the game files list one.'; }
            fText.innerHTML = h;
            fAdd.hidden = !F.new; fFill.hidden = !F.fill;
            fBox.hidden = false;
        };
        fSay();
        var importPost = function (fields, btn, busyMsg, doneMsg) {
            btn.disabled = true; fText.textContent = busyMsg;
            return post(D.importUrl, fields).then(function (r) {
                if (!r.ok) { btn.disabled = false; fText.innerHTML = '<span class="text-danger">' + esc(r.error || 'That did not work.') + '</span>'; return; }
                fText.textContent = doneMsg(r);
                setTimeout(function () { location.reload(); }, 1500);
            }).catch(function () { btn.disabled = false; fText.textContent = 'Could not reach the server (it may have run out of time on a large folder; try again).'; });
        };
        fAdd.addEventListener('click', function () {
            importPost({ t: 'import' }, fAdd, 'Adding ' + plural(F.new, 'map') + ' and taking their pictures\u2026', function (r) { return 'Added ' + plural(r.added, 'map') + (r.pictures ? ' and ' + plural(r.pictures, 'picture') : '') + '.'; });
        });
        fFill.addEventListener('click', function () {
            importPost({ t: 'fillmodes' }, fFill, 'Reading the game types\u2026', function (r) { return 'Game types filled in for ' + plural(r.filled, 'map') + '.'; });
        });
    }

    /* ------------------------------------------------------------ maps that are not in the game files folder */
    var dlBox = $('mc-dl');
    if (dlBox && D.dl && D.dl.on) {
        var dlText = $('mc-dl-text'), dlGo = $('mc-dl-go'), missing = [];
        var send = function (fields) {
            return fetch(window.ECH.path + D.endpoints.download, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.ECH.csrf, 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields).toString() })
                .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server answered with an error (HTTP ' + r.status + ').' }; }); });
        };
        var shown = function (info) {
            missing = info.missing || [];
            var set = {}; missing.forEach(function (n) { set[n.toLowerCase()] = 1; });
            MAPS.forEach(function (m) { m.nf = !m.found && !!set[m.name.toLowerCase()]; });
            render();
            if (!missing.length) { dlBox.hidden = true; return; }
            dlBox.hidden = false;
            var n = missing.length, base = '<strong>' + n + '</strong> map' + (n === 1 ? ' is' : 's are') + ' not in your game files folder (marked NO FILE). ';
            if (!D.dl.sources) { dlText.innerHTML = base + (D.dl.settings ? 'Add a download site under <a href="settings-maps.php#map-downloads">Settings &gt; Maps &amp; World Map</a> to fetch them.' : 'Ask an administrator to set up a download site.'); dlGo.hidden = true; }
            else if (!info.writable) { dlText.innerHTML = base + 'The web server may not write to <code>' + esc(info.folder) + '</code>, so they cannot be saved there.'; dlGo.hidden = true; }
            else { dlText.innerHTML = base + 'Echelon can download them from your download site.'; dlGo.hidden = false; }
        };
        send({ t: 'status' }).then(function (r) { if (r.ok) { shown(r); } });
        dlGo.addEventListener('click', function () {
            var queue = missing.slice(), ok = 0, bad = [];
            dlGo.disabled = true;
            (function next() {
                if (!queue.length) {
                    dlText.innerHTML = 'Downloaded <strong>' + ok + '</strong> map' + (ok === 1 ? '' : 's') + (bad.length ? '; ' + bad.length + ' could not be found: ' + esc(bad.slice(0, 3).join('; ')) + (bad.length > 3 ? '; ...' : '') : '.');
                    dlGo.disabled = false;
                    if (ok) { setTimeout(function () { location.reload(); }, bad.length ? 5000 : 1500); }
                    return;
                }
                var name = queue.shift();
                dlText.innerHTML = 'Downloading <strong>' + esc(name) + '</strong> (' + (missing.length - queue.length) + ' of ' + missing.length + ')&hellip;';
                send({ t: 'get', name: name }).then(function (r) {
                    if (r.ok) { ok++; } else { bad.push(name + ': ' + (r.error || 'failed')); }
                    next();
                }).catch(function () { bad.push(name + ': connection lost'); next(); });
            })();
        });
    }

    render();
})();