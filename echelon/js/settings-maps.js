/* Settings > Maps & World Map */
(function () {
    'use strict';
    var $ = function (id) { return document.getElementById(id); };

    function post(url, fields) {
        return fetch(window.ECH.path + url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-CSRF-Token': window.ECH.csrf, 'Accept': 'application/json' },
            body: new URLSearchParams(fields)
        }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected answer from the server (HTTP ' + r.status + ').' }; }); });
    }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    /* ---- game files folder ---- */
    var dirs = $('sm-dirs'), dmsg = $('sm-dirs-msg');
    var sug = $('sm-suggest');
    if (sug) {
        sug.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-path]');
            if (!b) { return; }
            var lines = dirs.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
            if (lines.indexOf(b.dataset.path) < 0) { lines.push(b.dataset.path); }
            dirs.value = lines.join('\n');
        });
    }
    function saveDirs(scan) {
        dmsg.innerHTML = scan ? 'Saving and looking through the map files&hellip;' : 'Saving&hellip;';
        var f = { t: 'setdir', dirs: dirs.value }; if (scan) { f.scan = '1'; }
        post('actions/b3/mapconfig-extract.php', f).then(function (r) {
            if (!r.ok) { dmsg.innerHTML = '<span class="text-danger">' + (r.error || 'Could not save.') + '</span>'; return; }
            var total = 0; (r.dirs || []).forEach(function (d) { total += d.pk3; });
            var txt = (r.dirs || []).length ? '<i class="fas fa-check-circle text-success"></i> Saved. ' + total + ' map file' + (total === 1 ? '' : 's') + ' found.' : 'Folder cleared.';
            if (scan) {
                var n = (r.saved || []).length;
                txt += ' ' + (n ? 'Added ' + n + ' map picture' + (n === 1 ? '' : 's') + '.' : 'No new map pictures were found.');
                if ((r.errors || []).length) { txt += ' ' + r.errors.length + ' problem(s): ' + esc(r.errors[0]); }
            }
            dmsg.innerHTML = txt;
        }).catch(function () { dmsg.textContent = 'Could not reach the server.'; });
    }
    $('sm-save-dirs').addEventListener('click', function () { saveDirs(false); });
    $('sm-save-scan').addEventListener('click', function () { saveDirs(true); });

    /* ---- world map provider ---- */
    var radios = document.querySelectorAll('input[name="sm-provider"]');
    function provider() { var v = 'geolite2'; Array.prototype.forEach.call(radios, function (r) { if (r.checked) { v = r.value; } }); return v; }
    Array.prototype.forEach.call(radios, function (r) {
        r.addEventListener('change', function () {
            $('sm-geolite').hidden = provider() !== 'geolite2';
            $('sm-legacy').hidden = provider() !== 'legacy';
        });
    });

    var gmsg = $('sm-geo-msg');
    function showStatus(s) {
        if (!s) { return; }
        $('sm-geo-status-text').innerHTML = s.installed
            ? '<strong>GeoLite2 is installed.</strong> Database built ' + esc(s.built) + ' (' + s.size_mb + ' MB).'
            : '<strong>Not set up yet.</strong> Follow the three steps below.';
        $('sm-remove').hidden = !s.installed;
        if ($('sm-auto') && typeof s.auto === 'boolean') { $('sm-auto').checked = s.auto; }
        if ($('sm-updated')) {
            $('sm-updated').innerHTML = (s.updated ? 'Last updated ' + esc(s.updated) + '.' : 'Not downloaded yet.') +
                (s.last_error ? ' <span class="text-danger">Last automatic update failed: ' + esc(s.last_error) + '</span>' : '');
        }
        $('sm-download-label').textContent = s.installed ? 'Update database' : 'Save and download database';
    }
    $('sm-save-provider').addEventListener('click', function () {
        gmsg.textContent = '';
        post('actions/settings-maps.php', { t: 'geo_save', provider: provider(), account: $('sm-account').value, key: $('sm-key').value, auto: $('sm-auto') && $('sm-auto').checked ? '1' : '0' }).then(function (r) {
            if (!r.ok) { gmsg.innerHTML = '<span class="text-danger">' + esc(r.error || 'Could not save.') + '</span>'; return; }
            showStatus(r.status);
            gmsg.innerHTML = r.status && r.status.provider ? '<i class="fas fa-check-circle text-success"></i> Saved.' : 'Saved. The World Map will not show anything until the database is installed.';
        });
    });
    $('sm-download').addEventListener('click', function () {
        var btn = this;
        if (!$('sm-account').value.trim() || !$('sm-key').value.trim()) { gmsg.innerHTML = '<span class="text-danger">Enter your Account ID and License key first.</span>'; return; }
        btn.disabled = true;
        gmsg.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Downloading from MaxMind &mdash; this can take a minute&hellip;';
        post('actions/settings-maps.php', { t: 'geo_download', provider: 'geolite2', account: $('sm-account').value, key: $('sm-key').value }).then(function (r) {
            btn.disabled = false;
            if (!r.ok) { gmsg.innerHTML = '<span class="text-danger">' + esc(r.error || 'The download failed.') + '</span>'; return; }
            showStatus(r.status);
            $('sm-key').value = '••••••••••••';
            gmsg.innerHTML = '<i class="fas fa-check-circle text-success"></i> ' + esc(r.message) + ' The World Map now uses it.';
        }).catch(function () { btn.disabled = false; gmsg.textContent = 'The request was interrupted. If the server has a short time limit, try again.'; });
    });
    $('sm-remove').addEventListener('click', function () {
        if (!window.confirm('Remove the GeoLite2 database from this server?')) { return; }
        post('actions/settings-maps.php', { t: 'geo_remove' }).then(function (r) { if (r.ok) { showStatus(r.status); gmsg.textContent = 'Removed.'; } });
    });

    /* ---- map downloads ---- */
    var dlSave = $('sm-dl-save');
    if (dlSave) {
        var dlMsg = $('sm-dl-msg');
        dlSave.addEventListener('click', function () {
            dlMsg.textContent = 'Saving\u2026';
            post('actions/settings-maps.php', { t: 'dl_save', sources: $('sm-dl-sources').value, lan: $('sm-dl-lan').checked ? '1' : '0', max_mb: $('sm-dl-max').value }).then(function (r) {
                dlMsg.innerHTML = r.ok ? '<i class="fas fa-check-circle text-success"></i> Saved (' + r.count + ' site' + (r.count === 1 ? '' : 's') + ').' : '<span class="text-danger">' + esc(r.error || 'Could not save.') + '</span>';
            }).catch(function () { dlMsg.textContent = 'Could not reach the server.'; });
        });
        $('sm-dl-test').addEventListener('click', function () {
            var first = $('sm-dl-sources').value.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean)[0];
            if (!first) { dlMsg.textContent = 'Enter a download site first.'; return; }
            dlMsg.textContent = 'Checking\u2026';
            post('actions/settings-maps.php', { t: 'dl_test', source: first, map: $('sm-dl-testmap').value }).then(function (r) {
                if (!r.ok) { dlMsg.innerHTML = '<span class="text-danger">' + esc(r.error) + '</span>'; return; }
                dlMsg.innerHTML = r.found ? '<i class="fas fa-check-circle text-success"></i> ' + esc(r.message) : '<span class="text-danger"><i class="fas fa-times-circle"></i> ' + esc(r.message) + '</span>';
            }).catch(function () { dlMsg.textContent = 'Could not reach the server.'; });
        });
    }

    /* ---- map style ---- */
    var styleRadios = document.querySelectorAll('input[name="sm-style"]');
    function style() { var v = 'osm'; Array.prototype.forEach.call(styleRadios, function (r) { if (r.checked) { v = r.value; } }); return v; }
    Array.prototype.forEach.call(styleRadios, function (r) {
        r.addEventListener('change', function () { if (style() === 'custom') { $('sm-custom').hidden = false; $('sm-custom-url').focus(); } });
    });
    $('sm-save-style').addEventListener('click', function () {
        var m = $('sm-style-msg'); m.textContent = 'Saving\u2026';
        post('actions/settings-maps.php', {
            t: 'style_save', style: style(), switcher: $('sm-switcher').checked ? '1' : '0',
            custom_url: $('sm-custom-url').value, custom_attr: $('sm-custom-attr').value
        }).then(function (r) {
            m.innerHTML = r.ok ? '<i class="fas fa-check-circle text-success"></i> Saved. <a href="map.php">Open the World Map</a>' : '<span class="text-danger">' + esc(r.error || 'Could not save.') + '</span>';
        }).catch(function () { m.textContent = 'Could not reach the server.'; });
    });

    /* ---- mapcycle files: pick the real file, Echelon links to it ---- */
    var mcfRows = $('mcf-rows');
    if (mcfRows) {
        var mcfSlots = JSON.parse($('mcf-data').textContent || '[]');
        var mcfSlot = null, mcfSel = '', mcfSep = '/', mcfParent = '', mcfCur = '';

        var viaText = { link: 'linked', setting: 'saved location', server: 'from Server Settings' };
        var mcfRender = function () {
            mcfRows.innerHTML = mcfSlots.map(function (t) {
                var cell, note = '';
                if (!t.path) { cell = '<span class="text-muted">Not set</span>'; }
                else {
                    cell = '<span class="mcf-path">' + esc(t.target || t.path) + '</span>';
                    if (t.broken || !t.exists) { note = '<span class="mcf-note bad"><i class="fas fa-exclamation-triangle"></i> The file is not there. Choose it again.</span>'; }
                    else if (!t.readable) { note = '<span class="mcf-note bad"><i class="fas fa-exclamation-triangle"></i> The web server cannot read this file.</span>'; }
                    else if (!t.writable) { note = '<span class="mcf-note warn"><i class="fas fa-exclamation-triangle"></i> Read only: the web server user is not allowed to write it, so Write file will fail until you give it write access.</span>'; }
                    else { note = '<span class="mcf-note ok"><i class="fas fa-check-circle"></i> Can be read and written (' + esc(viaText[t.via] || t.via) + ')</span>'; }
                }
                var rm = (t.via === 'link' || t.via === 'setting') ? ' <button type="button" class="btn btn-light btn-sm" data-rm="' + esc(t.slot) + '" title="Remove"><i class="fas fa-unlink"></i></button>' : '';
                var cu = t.custom ? ' <button type="button" class="btn btn-light btn-sm" data-rename="' + esc(t.slot) + '" title="Rename this game type"><i class="fas fa-pen"></i></button> <button type="button" class="btn btn-light btn-sm text-danger" data-del="' + esc(t.slot) + '" title="Delete this game type"><i class="fas fa-trash-alt"></i></button>' : '';
                return '<tr><td class="mcf-name">' + esc(t.label.replace(/ \(one file\)$/, '')) + (t.slot === 'default' ? '' : '<code>' + esc(t.slot) + '</code>') + (t.custom ? '<span class="mcf-tag">custom</span>' : '') + '</td>' +
                    '<td>' + cell + note + '</td>' +
                    '<td class="mcf-actions"><button type="button" class="btn btn-outline-secondary btn-sm" data-pick="' + esc(t.slot) + '"><i class="fas fa-folder-open mr-1"></i> Browse&hellip;</button>' + rm + cu + '</td></tr>';
            }).join('');
        };
        mcfRender();

        var mcfShow = function (r) {
            var list = $('mcf-list');
            if (!r.ok) { list.innerHTML = '<div class="mcf-empty text-danger">' + esc(r.error || 'Could not open that folder.') + '</div>'; if (r.path) { $('mcf-path').value = r.path; mcfCur = r.path; } return; }
            mcfCur = r.path; mcfParent = r.parent || ''; mcfSep = r.sep || '/';
            $('mcf-path').value = r.path; $('mcf-up').disabled = !mcfParent;
            var html = (r.roots || []).length ? '<div style="padding:4px 8px">' + r.roots.map(function (d) { return '<button type="button" class="btn btn-light btn-sm mr-1" data-dir="' + esc(d) + '">' + esc(d) + '</button>'; }).join('') + '</div>' : '';
            html += (r.entries || []).map(function (e) {
                if (e.type === 'dir') { return '<button type="button" class="mcf-item" data-dir="' + esc(e.path) + '"><i class="fas fa-folder"></i><span>' + esc(e.name) + '</span></button>'; }
                var kb = e.size < 1024 ? e.size + ' B' : Math.round(e.size / 1024) + ' KB';
                return '<button type="button" class="mcf-item' + (e.path === mcfSel ? ' sel' : '') + '" data-file="' + esc(e.path) + '" role="option"><i class="far fa-file-alt"></i><span>' + esc(e.name) + '</span><span class="sz">' + (e.writable ? '' : 'read only &middot; ') + kb + '</span></button>';
            }).join('');
            if (!(r.entries || []).length) { html += '<div class="mcf-empty">No folders or .txt / .cfg files here.</div>'; }
            if (r.truncated) { html += '<div class="mcf-empty">Only the first 800 entries are shown. Type a path above to go further.</div>'; }
            list.innerHTML = html;
        };
        var mcfBrowse = function (path) {
            $('mcf-list').innerHTML = '<div class="mcf-empty">Loading&hellip;</div>';
            post('actions/mapcycle-files.php', { t: 'browse', path: path || '' }).then(mcfShow).catch(function () { $('mcf-list').innerHTML = '<div class="mcf-empty text-danger">Could not reach the server.</div>'; });
        };
        var mcfChoose = function (path) {
            mcfSel = path || '';
            $('mcf-chosen').textContent = mcfSel || 'No file chosen';
            $('mcf-use').disabled = !mcfSel;
            Array.prototype.forEach.call($('mcf-list').querySelectorAll('[data-file]'), function (b) { b.classList.toggle('sel', b.dataset.file === mcfSel); });
        };
        var mcfUse = function () {
            if (!mcfSel || !mcfSlot) { return; }
            $('mcf-use').disabled = true;
            post('actions/mapcycle-files.php', { t: 'link', slot: mcfSlot, path: mcfSel }).then(function (r) {
                if (!r.ok) { $('mcf-chosen').innerHTML = '<span class="text-danger">' + esc(r.error || 'Could not use that file.') + '</span>'; $('mcf-use').disabled = false; return; }
                mcfSlots = r.slots; mcfRender(); window.jQuery('#mcf-modal').modal('hide');
            }).catch(function () { $('mcf-chosen').textContent = 'Could not reach the server.'; $('mcf-use').disabled = false; });
        };

        mcfRows.addEventListener('click', function (e) {
            var p = e.target.closest('[data-pick]'), rm = e.target.closest('[data-rm]'), rn = e.target.closest('[data-rename]'), dl = e.target.closest('[data-del]');
            if (rn) {
                var cur = mcfSlots.filter(function (x) { return x.slot === rn.dataset.rename; })[0];
                var nn = window.prompt('New name for this game type:', cur ? cur.label : '');
                if (nn === null || !nn.trim()) { return; }
                post('actions/mapcycle-files.php', { t: 'custom_rename', slot: rn.dataset.rename, name: nn }).then(function (r) {
                    if (r.ok) { mcfSlots = r.slots; mcfRender(); } else { window.alert(r.error || 'Could not rename it.'); }
                });
            } else if (dl) {
                if (!window.confirm('Delete this game type? Its mapcycle link is removed. The real mapcycle file and the game types already ticked on your maps are not touched.')) { return; }
                post('actions/mapcycle-files.php', { t: 'custom_remove', slot: dl.dataset.del }).then(function (r) {
                    if (r.ok) { mcfSlots = r.slots; mcfRender(); } else { window.alert(r.error || 'Could not delete it.'); }
                });
            } else if (p) {
                mcfSlot = p.dataset.pick; mcfSel = '';
                var t = mcfSlots.filter(function (x) { return x.slot === mcfSlot; })[0];
                $('mcf-title').textContent = 'Choose the mapcycle file for ' + t.label.replace(/ \(one file\)$/, '');
                mcfChoose('');
                window.jQuery('#mcf-modal').modal('show');
                mcfBrowse(t && t.target ? t.target : '');
            } else if (rm) {
                if (!window.confirm('Remove this mapcycle link? The real file is not touched.')) { return; }
                post('actions/mapcycle-files.php', { t: 'unlink', slot: rm.dataset.rm }).then(function (r) {
                    if (r.ok) { mcfSlots = r.slots; mcfRender(); } else { window.alert(r.error || 'Could not remove it.'); }
                });
            }
        });
        $('mcf-list').addEventListener('click', function (e) {
            var d = e.target.closest('[data-dir]'), f = e.target.closest('[data-file]');
            if (d) { mcfBrowse(d.dataset.dir); } else if (f) { mcfChoose(f.dataset.file); }
        });
        $('mcf-list').addEventListener('dblclick', function (e) { if (e.target.closest('[data-file]')) { mcfUse(); } });
        $('mcf-up').addEventListener('click', function () { if (mcfParent) { mcfBrowse(mcfParent); } });
        var mcfGo = function () { var v = $('mcf-path').value.trim(); if (/\.(txt|cfg)$/i.test(v)) { mcfChoose(v); } mcfBrowse(v); };
        $('mcf-go').addEventListener('click', mcfGo);
        $('mcf-path').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); mcfGo(); } });
        $('mcf-use').addEventListener('click', mcfUse);

        /* ---- add your own game type ---- */
        var newName = $('mcf-new-name'), newCode = $('mcf-new-code'), newMsg = $('mcf-add-msg'), codeTouched = false;
        if (newName && newCode) {
            var suggest = function (v) { return v.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 12); };
            newCode.addEventListener('input', function () { codeTouched = newCode.value !== ''; });
            newName.addEventListener('input', function () { if (!codeTouched) { newCode.value = suggest(newName.value); } });
            var addType = function () {
                if (!newName.value.trim()) { newMsg.innerHTML = '<span class="text-danger">Give the game type a name first.</span>'; newName.focus(); return; }
                newMsg.textContent = 'Adding\u2026';
                post('actions/mapcycle-files.php', { t: 'custom_add', name: newName.value, code: newCode.value }).then(function (r) {
                    if (!r.ok) { newMsg.innerHTML = '<span class="text-danger">' + esc(r.error || 'Could not add it.') + '</span>'; return; }
                    mcfSlots = r.slots; mcfRender();
                    newName.value = ''; newCode.value = ''; codeTouched = false;
                    newMsg.innerHTML = '<i class="fas fa-check-circle text-success"></i> Added <b>' + esc(r.label) + '</b>. Now choose its mapcycle file with Browse.';
                    var b = mcfRows.querySelector('[data-pick="' + r.code + '"]');
                    if (b) { b.focus(); b.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                }).catch(function () { newMsg.textContent = 'Could not reach the server.'; });
            };
            $('mcf-add').addEventListener('click', addType);
            [newName, newCode].forEach(function (i) { i.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addType(); } }); });
        }
    }
})();
