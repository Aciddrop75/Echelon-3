/* Map editor: live counters, picture upload (drag and drop) and picture removal. */
(function () {
    'use strict';
    var $ = function (id) { return document.getElementById(id); };

    /* ---- counters ---- */
    var msg = $('startmessage'), msgCount = $('msg-count');
    if (msg && msgCount) {
        var upd = function () { msgCount.textContent = msg.value.length; };
        msg.addEventListener('input', upd); upd();
    }
    var form = $('map-form'), lenEl = $('modes-len');
    function modesLen() {
        var codes = [];
        Array.prototype.forEach.call(form.querySelectorAll('input[name="gamemode[]"]:checked'), function (c) { codes.push(c.value); });
        ($('gamemodes_other').value || '').split(',').forEach(function (m) {
            m = m.trim().toLowerCase();
            if (m && codes.indexOf(m) < 0) { codes.push(m); }
        });
        var n = codes.join(',').length;
        lenEl.textContent = n;
        lenEl.parentNode.style.color = n > 50 ? '#c92a2a' : '';
        return n;
    }
    if (form && lenEl) {
        form.addEventListener('change', modesLen);
        $('gamemodes_other').addEventListener('input', modesLen);
        modesLen();
        form.addEventListener('submit', function (e) {
            var name = $('mapname');
            if (!/^[A-Za-z0-9_.\-]{1,50}$/.test(name.value.trim())) {
                e.preventDefault(); name.focus();
                name.setCustomValidity('Use letters, numbers, _ - and . only.'); name.reportValidity(); name.setCustomValidity('');
            } else if (modesLen() > 50) {
                e.preventDefault(); alert('Too many game types selected: the list has to fit in 50 characters.');
            }
        });
    }

    /* ---- picture ---- */
    /* ---- game types from the map's .arena file ---- */
    (function () {
        var btn = $('mc-arena'), msg = $('mc-arena-msg');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            var name = ($('mapname').value || '').trim();
            if (!name) { msg.textContent = 'Enter the map name first.'; return; }
            msg.textContent = 'Reading\u2026';
            fetch(window.ECH.path + 'actions/b3/mapconfig-import.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.ECH.csrf, 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ t: 'modes', name: name }).toString() })
                .then(function (r) { return r.json(); }).then(function (r) {
                    if (!r.ok) { msg.textContent = r.error || 'That did not work.'; return; }
                    if (!r.found) { msg.textContent = 'This map is not in your game files.'; return; }
                    if (!r.modes.length) { msg.textContent = 'Found in ' + r.file + ', but no .arena file or readme lists its game types.'; return; }
                    Array.prototype.forEach.call(document.querySelectorAll('input[name="gamemode[]"]'), function (c) { c.checked = r.modes.indexOf(c.value) > -1; c.dispatchEvent(new Event('change', { bubbles: true })); });
                    msg.textContent = 'Set from the ' + r.src + ' in ' + r.file + ': ' + r.modes.join(', ') + '. Save to keep it.';
                }).catch(function () { msg.textContent = 'Could not reach the server.'; });
        });
    })();

    if (!window.MAP_EDIT) { return; }
    var drop = $('mc-drop'), file = $('mc-file'), thumb = $('mc-hero-thumb'), bar = $('mc-progress'),
        txt = $('mc-drop-text'), rm = $('mc-img-remove'), orig = txt.innerHTML;

    function say(html, bad) { txt.innerHTML = html; drop.style.color = bad ? '#c92a2a' : ''; }
    function setImage(url) {
        if (url) {
            thumb.innerHTML = '<img src="' + url.replace(/"/g, '&quot;') + '" alt="">';
        } else {
            thumb.innerHTML = '<div class="mc-ph"><div><b>' + ($('mapname').value.replace(/^ut4_/i, '').replace(/_/g, ' ').replace(/[<>&"]/g, '') || 'Map') + '</b><i>No picture</i></div></div>';
        }
        rm.hidden = !url;
    }
    function send(fields, fileObj) {
        var fd = new FormData();
        fd.append('id', window.MAP_EDIT.id);
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        if (fileObj) { fd.append('image', fileObj); }
        return new Promise(function (resolve) {
            var x = new XMLHttpRequest();
            x.open('POST', window.ECH.path + 'actions/b3/mapconfig-image.php');
            x.setRequestHeader('X-CSRF-Token', window.ECH.csrf);
            x.setRequestHeader('Accept', 'application/json');
            x.upload.onprogress = function (e) {
                if (e.lengthComputable) { bar.hidden = false; bar.firstElementChild.style.width = Math.round(e.loaded / e.total * 100) + '%'; }
            };
            x.onload = function () {
                var r; try { r = JSON.parse(x.responseText); } catch (e) { r = { ok: false, error: 'Unexpected answer from the server (HTTP ' + x.status + ').' }; }
                resolve(r);
            };
            x.onerror = function () { resolve({ ok: false, error: 'Could not reach the server.' }); };
            x.send(fd);
        });
    }
    function upload(f) {
        if (!f) { return; }
        if (!/^image\/(jpeg|png|gif|webp)$/.test(f.type)) { say('That is not a JPEG, PNG, GIF or WebP picture.', true); return; }
        if (f.size > 8 * 1024 * 1024) { say('That picture is larger than 8 MB.', true); return; }
        say('Uploading&hellip;');
        send({ t: 'upload' }, f).then(function (r) {
            bar.hidden = true; bar.firstElementChild.style.width = '0';
            if (r.ok) { setImage(r.url); say('Saved. Drop another picture to replace it.'); }
            else { say(String(r.error || 'Upload failed.').replace(/[<>&]/g, ''), true); }
            file.value = '';
        });
    }

    file.addEventListener('change', function () { upload(file.files[0]); });
    ['dragenter', 'dragover'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
    drop.addEventListener('drop', function (e) { upload(e.dataTransfer.files[0]); });
    drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); file.click(); } });

    /* ---- level shot from the map's .pk3 ---- */
    var scan = $('mc-img-scan'), pk3 = $('mc-pk3-file');
    function extracted(r) {
        scan.disabled = false;
        if (!r.ok) { say(String(r.error || 'That did not work.').replace(/[<>&]/g, ''), true); return; }
        if ((r.saved || []).length) {
            // the picture was written under the map name: reload to show it
            location.reload();
        } else if ((r.errors || []).length) {
            say(String(r.errors[0]).replace(/[<>&]/g, ''), true);
        } else {
            say('No level shot for this map was found there.', true);
        }
    }
    scan.addEventListener('click', function () {
        scan.disabled = true; say('Looking through the map files&hellip;');
        var fd = new FormData(); fd.append('t', 'scan'); fd.append('id', window.MAP_EDIT.id); fd.append('replace', '1');
        fetch(window.ECH.path + 'actions/b3/mapconfig-extract.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.ECH.csrf, 'Accept': 'application/json' }, body: fd })
            .then(function (r) { return r.json(); }).then(extracted).catch(function () { scan.disabled = false; say('Could not reach the server.', true); });
    });
    pk3.addEventListener('change', function () {
        if (!pk3.files[0]) { return; }
        say('Reading ' + pk3.files[0].name.replace(/[<>&]/g, '') + '&hellip;');
        var fd = new FormData(); fd.append('t', 'upload'); fd.append('id', window.MAP_EDIT.id); fd.append('replace', '1'); fd.append('pk3', pk3.files[0]);
        fetch(window.ECH.path + 'actions/b3/mapconfig-extract.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.ECH.csrf, 'Accept': 'application/json' }, body: fd })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The upload was refused (HTTP ' + r.status + '). The file may be larger than the server allows.' }; }); })
            .then(extracted).catch(function () { say('Could not reach the server.', true); });
        pk3.value = '';
    });

    /* ---- is the map file in the game files folder? offer to download it ---- */
    (function () {
        var box = $('mc-dl-one');
        if (!box || !window.MAP_EDIT || !window.MAP_EDIT.name) { return; }
        var txt = $('mc-dl-one-text'), go = $('mc-dl-one-go');
        function send(fields) {
            return fetch(window.ECH.path + 'actions/b3/mapconfig-download.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.ECH.csrf, 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields).toString() })
                .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server answered with an error (HTTP ' + r.status + ').' }; }); });
        }
        send({ t: 'status', name: window.MAP_EDIT.name }).then(function (r) {
            if (!r.ok || !r.missing || !r.missing.length) { return; }
            box.hidden = false;
            if (!r.sources) { txt.textContent = 'This map is not in your game files folder, and no download site is set up (Settings > Maps & World Map).'; go.hidden = true; }
            else if (!r.writable) { txt.textContent = 'This map is not in your game files folder, and the web server may not write there.'; go.hidden = true; }
        });
        go.addEventListener('click', function () {
            go.disabled = true; txt.textContent = 'Downloading\u2026';
            send({ t: 'get', name: window.MAP_EDIT.name }).then(function (r) {
                if (r.ok) { txt.textContent = 'Saved ' + r.file + ' (' + r.size_mb + ' MB) from ' + r.host + '.'; go.hidden = true; setTimeout(function () { location.reload(); }, 1200); }
                else { go.disabled = false; txt.textContent = r.error || 'The download failed.'; }
            }).catch(function () { go.disabled = false; txt.textContent = 'Could not reach the server.'; });
        });
    })();

    rm.addEventListener('click', function () {
        if (!window.confirm('Remove the picture of this map?')) { return; }
        send({ t: 'delete' }).then(function (r) {
            if (r.ok) { setImage(null); say(orig); } else { say(String(r.error || 'Could not remove the picture.').replace(/[<>&]/g, ''), true); }
        });
    });
})();
