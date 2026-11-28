/* World Map: Leaflet, one circle per place sized by the number of players, switchable map styles,
   and (for administrators) a background refresh of the GeoLite2 database when it is due. */
(function () {
    'use strict';
    var el = document.getElementById('world-map'), dataEl = document.getElementById('wm-data');
    if (!el || !dataEl || !window.L) { return; }
    var data = JSON.parse(dataEl.textContent);
    var points = data.points || [], styles = data.styles || [];

    /* ---- remembered choice (per browser); the administrator's default applies until someone picks ---- */
    var KEY = 'ech-map-style';
    function saved() { try { return window.localStorage.getItem(KEY); } catch (e) { return null; } }
    function remember(id) { try { window.localStorage.setItem(KEY, id); } catch (e) { /* private mode: fine */ } }
    function byId(id) { for (var i = 0; i < styles.length; i++) { if (styles[i].id === id) { return styles[i]; } } return null; }

    var startId = data.style;
    if (data.switcher && byId(saved())) { startId = saved(); }
    if (!byId(startId) && styles.length) { startId = styles[0].id; }

    var map = L.map(el, { worldCopyJump: true, minZoom: 2, maxZoom: 18, zoomSnap: 0.5 });

    var failed = 0, warn = null, activeHost = '';
    function hostOf(u) { var m = /^https?:\/\/([^\/{]+)/.exec(u); return m ? m[1].replace(/^\{s\}\./, '') : 'the map server'; }

    var layers = {}, firstLayer = null;
    styles.forEach(function (st) {
        var opts = { maxZoom: st.max || 19, attribution: st.attr };
        if (st.sub) { opts.subdomains = st.sub; }
        var layer = L.tileLayer(st.url, opts);
        layer.on('tileerror', function () {
            if (map.hasLayer(layer) && ++failed === 4 && !warn) {
                warn = document.createElement('div');
                warn.className = 'wm-tilewarn';
                warn.textContent = 'Map tiles could not be loaded. Your browser needs internet access to ' + hostOf(st.url) + '.';
                el.appendChild(warn);
            }
        });
        layers[st.name] = layer;
        layer._echId = st.id;
        if (st.id === startId) { firstLayer = layer; }
    });
    if (!firstLayer && styles.length) { firstLayer = layers[styles[0].name]; }
    if (firstLayer) { firstLayer.addTo(map); }

    if (data.switcher && styles.length > 1) {
        L.control.layers(layers, null, { position: 'topright', collapsed: true }).addTo(map);
        map.on('baselayerchange', function (e) {
            remember(e.layer._echId);
            failed = 0;
            if (warn) { warn.remove(); warn = null; }
            el.classList.toggle('wm-darkmap', e.layer._echId === 'esri_imagery');
        });
    }
    el.classList.toggle('wm-darkmap', startId === 'esri_imagery');

    /* ---- players ---- */
    var max = 1;
    points.forEach(function (p) { if (p.n > max) { max = p.n; } });

    var group = L.featureGroup();
    points.forEach(function (p) {
        var r = 5 + Math.sqrt(p.n / max) * 19;
        var c = L.circleMarker([p.lat, p.lng], { radius: r, color: '#c2410c', weight: 1.5, fillColor: '#f76707', fillOpacity: 0.6 });
        var box = document.createElement('div');
        var b = document.createElement('strong'); b.textContent = p.label; box.appendChild(b);
        box.appendChild(document.createElement('br'));
        box.appendChild(document.createTextNode(p.n + ' player connection' + (p.n === 1 ? '' : 's')));
        c.bindPopup(box);
        c.bindTooltip(p.label + ': ' + p.n, { direction: 'top' });
        c.addTo(group);
    });
    group.addTo(map);

    if (points.length) {
        map.fitBounds(group.getBounds().pad(0.25), { maxZoom: 5 });
    } else {
        map.setView([25, 10], 2);
    }

    /* ---- background refresh of the location database (administrators only; the server decides if it is due) ---- */
    if (data.autoUpdate && window.ECH) {
        var box2 = document.getElementById('wm-update'), txt = document.getElementById('wm-update-text');
        if (box2 && txt) {
            box2.hidden = false;
            txt.textContent = 'Updating the location database from MaxMind in the background…';
            fetch(window.ECH.path + 'actions/settings-maps.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-CSRF-Token': window.ECH.csrf, 'Accept': 'application/json' },
                body: new URLSearchParams({ t: 'geo_auto_run' })
            }).then(function (r) { return r.json(); }).then(function (r) {
                if (!r.ok || !r.ran) { box2.hidden = true; return; }
                if (r.updated) {
                    txt.innerHTML = '';
                    txt.appendChild(document.createTextNode('The location database was updated. '));
                    var a = document.createElement('a'); a.href = window.location.href; a.textContent = 'Reload the map';
                    txt.appendChild(a); txt.appendChild(document.createTextNode(' to use it.'));
                } else {
                    txt.textContent = 'The automatic update failed: ' + (r.message || 'unknown error') + ' (see Settings › Maps & World Map).';
                }
            }).catch(function () { box2.hidden = true; });
        }
    }
})();
